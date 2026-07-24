#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=_common.sh
source "$script_dir/_common.sh"

if [[ "${1:-}" == '--help' ]]; then
    echo 'Required common controls: PROD05_CONFIRM=PROD05-STAGING BACKUP_APPROVED=YES PROD05_CHANGE_TICKET PROD05_APPROVED_BY PROD05_EVIDENCE_ID PROD05_ENVIRONMENT_ID PROD05_AUTHORIZED_TARGETS_FILE APP_ENV=staging STAGING_FQDN EVIDENCE_DIR.'
    echo 'Required backup: DB_CONNECTION DB_HOST DB_PORT DB_NAME RELATED_RELEASE_COMMIT MYSQL_CNF BACKUP_DIR BACKUP_ID AGE_RECIPIENTS_FILE. DB tuple must match the authorized migration_target.'
    echo 'Optional persistence: PERSISTENCE_PATHS_FILE PERSISTENCE_ROOT APPROVED_PERSISTENCE_INVENTORY. Optional binaries: MYSQLDUMP_BIN AGE_BIN.'
    exit 0
fi

prod05_begin backup BACKUP_APPROVED
prod05_reject_secret_env
prod05_load_migration_target
for name in DB_CONNECTION DB_HOST DB_PORT DB_NAME RELATED_RELEASE_COMMIT; do prod05_require "$name"; done
prod05_reject_local DB_HOST
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || prod05_die 'DB_NAME may contain only letters, digits and underscore'
[[ "$RELATED_RELEASE_COMMIT" =~ ^[0-9a-fA-F]{40}$ ]] || prod05_die 'RELATED_RELEASE_COMMIT must be a full SHA-1'
[[ "$DB_CONNECTION" == "$AUTH_DB_CONNECTION" && "$DB_HOST" == "$AUTH_DB_HOST" && "$DB_PORT" == "$AUTH_DB_PORT" && "$DB_NAME" == "$AUTH_DB_DATABASE" ]] || prod05_die 'backup DB tuple differs from the authorized migration target'
prod05_require_file MYSQL_CNF
prod05_require_file AGE_RECIPIENTS_FILE
[[ -z "$(find "$AGE_RECIPIENTS_FILE" -perm /022 -print -quit)" ]] || prod05_die 'AGE_RECIPIENTS_FILE must not be group/world writable'
prod05_require BACKUP_DIR
prod05_require BACKUP_ID
[[ "$BACKUP_DIR" = /* ]] || prod05_die 'BACKUP_DIR must be an absolute path'
[[ "$BACKUP_ID" =~ ^[A-Za-z0-9._-]+$ ]] || prod05_die 'BACKUP_ID contains unsafe characters'
[[ "$(stat -c '%a' "$MYSQL_CNF")" == '600' ]] || prod05_die 'MYSQL_CNF must have mode 0600'
mkdir -p "$BACKUP_DIR"
[[ -w "$BACKUP_DIR" ]] || prod05_die 'BACKUP_DIR is not writable'

dump_bin=${MYSQLDUMP_BIN:-mariadb-dump}
age_bin=${AGE_BIN:-age}
prod05_require_command "$dump_bin"
prod05_require_command "$age_bin"
prod05_require_command perl
prod05_require_command shred
encrypted_dump="$BACKUP_DIR/${BACKUP_ID}.sql.age"
partial_dump="$encrypted_dump.part"
metadata_file="$BACKUP_DIR/${BACKUP_ID}.meta"
[[ ! -e "$encrypted_dump" && ! -e "$partial_dump" && ! -e "$metadata_file" ]] || prod05_die 'backup output already exists'
cleanup_files=()
cleanup() {
    local file
    for file in "${cleanup_files[@]}"; do
        [[ ! -e "$file" ]] || shred -u -- "$file"
    done
}
trap cleanup EXIT
cleanup_files+=("$partial_dump")

start_epoch=$(date +%s)
"$dump_bin" --defaults-extra-file="$MYSQL_CNF" --host="$DB_HOST" --port="$DB_PORT" --single-transaction --quick --routines --triggers --events --hex-blob "$DB_NAME" \
    | perl -pe 's/DEFINER\s*=\s*(?:`[^`]+`@`[^`]+`|'"'"'[^'"'"']+'"'"'@'"'"'[^'"'"']+'"'"'|[^\s*\/;]+)//ig' \
    | "$age_bin" --encrypt --recipients-file "$AGE_RECIPIENTS_FILE" --output "$partial_dump"
[[ -s "$partial_dump" ]] || prod05_die 'encrypted database dump is empty'
chmod 0600 "$partial_dump"
mv "$partial_dump" "$encrypted_dump"
cleanup_files=("$encrypted_dump")
encrypted_sha=$(sha256sum "$encrypted_dump" | awk '{print $1}')

if [[ -n "${PERSISTENCE_PATHS_FILE:-}" ]]; then
    prod05_require_file PERSISTENCE_PATHS_FILE
    prod05_require_dir PERSISTENCE_ROOT
    prod05_require_file APPROVED_PERSISTENCE_INVENTORY
    persistence_root=$(realpath -e "$PERSISTENCE_ROOT")
    [[ "$persistence_root" != '/' ]] || prod05_die 'PERSISTENCE_ROOT cannot be the filesystem root'
    [[ -z "$(find "$APPROVED_PERSISTENCE_INVENTORY" -perm /022 -print -quit)" ]] || prod05_die 'APPROVED_PERSISTENCE_INVENTORY must not be group/world writable'
    validated_paths=$(mktemp)
    chmod 0600 "$validated_paths"
    cleanup_files+=("$validated_paths")
    while IFS= read -r path || [[ -n "$path" ]]; do
        [[ -n "$path" && "$path" != \#* ]] || continue
        [[ "$path" = /* ]] || prod05_die 'every persistence path must be absolute'
        real_path=$(realpath -e "$path")
        [[ "$real_path" == "$persistence_root"/* ]] || prod05_die "persistence path is outside PERSISTENCE_ROOT: $path"
        [[ "$real_path" != "$persistence_root" ]] || prod05_die 'PERSISTENCE_ROOT itself cannot be archived'
        if [[ "$real_path" =~ (^|/)(\.env([^/]*)?|logs?|caches?|sessions?|backups?|vendor|\.git)(/|$) ]]; then
            prod05_die "forbidden persistence path: $path"
        fi
        grep -Fxq "$real_path" "$APPROVED_PERSISTENCE_INVENTORY" || prod05_die "path is absent from the approved persistence inventory: $path"
        printf '%s\n' "$real_path" >> "$validated_paths"
    done < "$PERSISTENCE_PATHS_FILE"
    [[ -s "$validated_paths" ]] || prod05_die 'no approved persistence paths were selected'

    encrypted_persistence="$BACKUP_DIR/${BACKUP_ID}-persistence.tar.age"
    partial_persistence="$encrypted_persistence.part"
    [[ ! -e "$encrypted_persistence" && ! -e "$partial_persistence" ]] || prod05_die 'persistence backup output already exists'
    cleanup_files+=("$partial_persistence")
    tar --verbatim-files-from --files-from="$validated_paths" -cf - \
        | "$age_bin" --encrypt --recipients-file "$AGE_RECIPIENTS_FILE" --output "$partial_persistence"
    [[ -s "$partial_persistence" ]] || prod05_die 'encrypted persistence backup is empty'
    chmod 0600 "$partial_persistence"
    mv "$partial_persistence" "$encrypted_persistence"
    cleanup_files=("$encrypted_dump" "$encrypted_persistence" "$validated_paths")
    persistence_sha=$(sha256sum "$encrypted_persistence" | awk '{print $1}')
fi

duration=$(( $(date +%s) - start_epoch ))
{
    printf 'format=nova-prod05-approved-backup-v2\n'
    printf 'ticket=%s\n' "$PROD05_CHANGE_TICKET"
    printf 'evidence_id=%s\n' "$PROD05_EVIDENCE_ID"
    printf 'backup_id=%s\n' "$BACKUP_ID"
    printf 'environment_id=%s\n' "$PROD05_ENVIRONMENT_ID"
    printf 'related_commit=%s\n' "$RELATED_RELEASE_COMMIT"
    printf 'created_at_utc=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'source_database=%s\n' "$DB_NAME"
    printf 'encrypted_payload=%s\n' "$encrypted_dump"
    printf 'payload_sha256=%s\n' "$encrypted_sha"
    printf 'encryption=age\n'
    printf 'persistence_sha256=%s\n' "${persistence_sha:-none}"
    printf 'status=CREATED\n'
    printf 'approved_by=PENDING\n'
    printf 'duration_seconds=%s\n' "$duration"
} > "$metadata_file"
chmod 0600 "$metadata_file"
cleanup_files=()
prod05_info "backup created; id=$BACKUP_ID duration_seconds=$duration"
prod05_info 'only encrypted payloads were persisted; transfer them with metadata to approved off-host custody and mark VERIFIED after restore validation'

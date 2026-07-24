#!/usr/bin/env bash
set -euo pipefail

prod05_die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 64
}

prod05_info() {
    printf '[PROD-05] %s\n' "$*"
}

prod05_require() {
    local name=$1
    [[ -n "${!name:-}" ]] || prod05_die "required environment variable is missing: $name"
}

prod05_require_file() {
    local name=$1
    prod05_require "$name"
    [[ "${!name}" = /* ]] || prod05_die "$name must be an absolute path"
    [[ -f "${!name}" ]] || prod05_die "$name does not exist or is not a file"
}

prod05_require_dir() {
    local name=$1
    prod05_require "$name"
    [[ "${!name}" = /* ]] || prod05_die "$name must be an absolute path"
    [[ -d "${!name}" ]] || prod05_die "$name does not exist or is not a directory"
}

prod05_reject_local() {
    local name=$1
    prod05_require "$name"
    local value=${!name,,}
    case "$value" in
        localhost|localhost:*|127.*|0.0.0.0|0.0.0.0:*|::1|\[::1\]|*://localhost|*://localhost/*|*://127.*|*://0.0.0.0*|*://\[::1\]*)
            prod05_die "$name must identify authorized Staging; localhost and loopback are forbidden"
            ;;
    esac
}

prod05_guard_staging() {
    [[ "${PROD05_CONFIRM:-}" == 'PROD05-STAGING' ]] || prod05_die 'set PROD05_CONFIRM=PROD05-STAGING after the change ticket authorizes execution'
    [[ "${APP_ENV:-}" == 'staging' ]] || prod05_die 'APP_ENV must be exactly staging'
    prod05_reject_local STAGING_FQDN
    prod05_require EVIDENCE_DIR
    [[ "$EVIDENCE_DIR" = /* ]] || prod05_die 'EVIDENCE_DIR must be an absolute path'
    [[ -d "$EVIDENCE_DIR" ]] || prod05_die 'EVIDENCE_DIR must already exist; copy the approved evidence templates first'
    prod05_require_file PROD05_AUTHORIZED_TARGETS_FILE
    [[ -z "$(find "$PROD05_AUTHORIZED_TARGETS_FILE" -perm /022 -print -quit)" ]] || prod05_die 'PROD05_AUTHORIZED_TARGETS_FILE must not be group/world writable'
    grep -Fxq "ticket=$PROD05_CHANGE_TICKET" "$PROD05_AUTHORIZED_TARGETS_FILE" || prod05_die 'authorized targets file belongs to a different ticket'
    grep -Fxq "staging_fqdn=$STAGING_FQDN" "$PROD05_AUTHORIZED_TARGETS_FILE" || prod05_die 'STAGING_FQDN is not in the authorized targets file'
}

prod05_load_migration_target() {
    prod05_require PROD05_ENVIRONMENT_ID
    [[ "$PROD05_ENVIRONMENT_ID" =~ ^[A-Za-z0-9._-]+$ ]] || prod05_die 'PROD05_ENVIRONMENT_ID contains unsafe characters'
    local records=()
    mapfile -t records < <(awk -F'|' -v ticket="$PROD05_CHANGE_TICKET" -v evidence="$PROD05_EVIDENCE_ID" -v environment="$PROD05_ENVIRONMENT_ID" '
        $1 == "migration_target" && $2 == ticket && $3 == evidence && $4 == environment { print }
    ' "$PROD05_AUTHORIZED_TARGETS_FILE")
    ((${#records[@]} == 1)) || prod05_die 'authorized targets must contain exactly one migration_target for ticket, evidence and environment'
    IFS='|' read -r record_type record_ticket record_evidence record_environment \
        AUTH_STAGING_FQDN AUTH_APP_URL AUTH_DB_CONNECTION AUTH_DB_HOST AUTH_DB_PORT AUTH_DB_DATABASE extra <<< "${records[0]}"
    [[ -z "${extra:-}" ]] || prod05_die 'authorized migration_target has unexpected fields'
    [[ -n "$AUTH_STAGING_FQDN" && -n "$AUTH_APP_URL" && -n "$AUTH_DB_CONNECTION" && -n "$AUTH_DB_HOST" && -n "$AUTH_DB_PORT" && -n "$AUTH_DB_DATABASE" ]] || prod05_die 'authorized migration_target is incomplete'
    [[ "$AUTH_DB_PORT" =~ ^[0-9]{1,5}$ ]] || prod05_die 'authorized migration DB port is invalid'
    ((AUTH_DB_PORT >= 1 && AUTH_DB_PORT <= 65535)) || prod05_die 'authorized migration DB port is outside 1..65535'
    [[ "$AUTH_STAGING_FQDN" == "$STAGING_FQDN" ]] || prod05_die 'STAGING_FQDN differs from the authorized migration target'
    [[ "$AUTH_APP_URL" == https://* ]] || prod05_die 'authorized Staging APP_URL must use HTTPS'
    prod05_reject_local AUTH_STAGING_FQDN
    prod05_reject_local AUTH_DB_HOST
}

prod05_load_restore_target() {
    prod05_require PROD05_ENVIRONMENT_ID
    [[ "$PROD05_ENVIRONMENT_ID" =~ ^[A-Za-z0-9._-]+$ ]] || prod05_die 'PROD05_ENVIRONMENT_ID contains unsafe characters'
    local records=()
    mapfile -t records < <(awk -F'|' -v ticket="$PROD05_CHANGE_TICKET" -v evidence="$PROD05_EVIDENCE_ID" -v environment="$PROD05_ENVIRONMENT_ID" '
        $1 == "restore_target" && $2 == ticket && $3 == evidence && $4 == environment { print }
    ' "$PROD05_AUTHORIZED_TARGETS_FILE")
    ((${#records[@]} == 1)) || prod05_die 'authorized targets must contain exactly one restore_target for ticket, evidence and environment'
    IFS='|' read -r record_type record_ticket record_evidence record_environment \
        AUTH_RESTORE_BACKUP_ID AUTH_RESTORE_RELATED_COMMIT AUTH_RESTORE_DB_CONNECTION AUTH_RESTORE_HOST AUTH_RESTORE_PORT AUTH_RESTORE_DATABASE AUTH_RESTORE_CREATION_ID extra <<< "${records[0]}"
    [[ -z "${extra:-}" ]] || prod05_die 'authorized restore_target has unexpected fields'
    for value in "$AUTH_RESTORE_BACKUP_ID" "$AUTH_RESTORE_RELATED_COMMIT" "$AUTH_RESTORE_DB_CONNECTION" "$AUTH_RESTORE_HOST" "$AUTH_RESTORE_PORT" "$AUTH_RESTORE_DATABASE" "$AUTH_RESTORE_CREATION_ID"; do
        [[ -n "$value" ]] || prod05_die 'authorized restore_target is incomplete'
    done
    [[ "$AUTH_RESTORE_PORT" =~ ^[0-9]{1,5}$ ]] || prod05_die 'authorized restore DB port is invalid'
    ((AUTH_RESTORE_PORT >= 1 && AUTH_RESTORE_PORT <= 65535)) || prod05_die 'authorized restore DB port is outside 1..65535'
    [[ "$AUTH_RESTORE_BACKUP_ID" =~ ^[A-Za-z0-9._-]+$ && "$AUTH_RESTORE_DATABASE" =~ ^[A-Za-z0-9_]+$ ]] || prod05_die 'authorized restore backup ID or schema name is invalid'
    [[ "$AUTH_RESTORE_RELATED_COMMIT" =~ ^[0-9a-fA-F]{40}$ ]] || prod05_die 'authorized restore related commit is invalid'
    [[ "$AUTH_RESTORE_CREATION_ID" =~ ^[A-Za-z0-9._-]+$ ]] || prod05_die 'authorized schema creation ID is invalid'
    prod05_reject_local AUTH_RESTORE_HOST
}

prod05_require_command() {
    command -v "$1" >/dev/null 2>&1 || prod05_die "required command is unavailable: $1"
}

prod05_reject_secret_env() {
    [[ -z "${DB_PASSWORD:-}" ]] || prod05_die 'DB_PASSWORD in the process environment is forbidden; use MYSQL_CNF with mode 0600'
}

prod05_begin() {
    local stage=$1
    local approval_variable=$2

    for name in PROD05_CHANGE_TICKET PROD05_APPROVED_BY PROD05_EVIDENCE_ID; do
        prod05_require "$name"
    done
    prod05_guard_staging
    [[ "${!approval_variable:-}" == 'YES' ]] || prod05_die "set $approval_variable=YES only after the named approver authorizes this stage"
    [[ "$PROD05_CHANGE_TICKET" =~ ^[A-Za-z0-9._/-]+$ ]] || prod05_die 'PROD05_CHANGE_TICKET contains unsafe characters'
    [[ "$PROD05_EVIDENCE_ID" =~ ^[A-Za-z0-9._-]+$ ]] || prod05_die 'PROD05_EVIDENCE_ID contains unsafe characters'

    local required_evidence=(
        01-preflight.md 02-backup.md 03-upgrade.md 04-migrations.md
        05-smoke.md 06-restore.md 07-rollback.md 08-monitoring.md 09-final-report.md
    )
    local evidence_file
    for evidence_file in "${required_evidence[@]}"; do
        [[ -f "$EVIDENCE_DIR/$evidence_file" ]] || prod05_die "evidence structure is incomplete: missing $evidence_file"
    done
    [[ -w "$EVIDENCE_DIR" ]] || prod05_die 'EVIDENCE_DIR is not writable'
    mkdir -p "$EVIDENCE_DIR/logs"
    [[ -w "$EVIDENCE_DIR/logs" ]] || prod05_die 'EVIDENCE_DIR/logs is not writable'

    umask 077
    local log_file="$EVIDENCE_DIR/logs/${PROD05_EVIDENCE_ID}-${stage}.log"
    [[ ! -e "$log_file" ]] || prod05_die "stage evidence log already exists: $log_file"
    printf '[PROD-05] evidence_log=%s\n' "$log_file"
    exec >> "$log_file" 2>&1
    prod05_info "stage=$stage ticket=$PROD05_CHANGE_TICKET approver=$PROD05_APPROVED_BY evidence=$PROD05_EVIDENCE_ID"
}

prod05_real_dir() {
    local name=$1
    prod05_require_dir "$name"
    realpath -e "${!name}"
}

prod05_validate_release_under_root() {
    local path_name=$1
    local root_name=$2
    local release_path root_path
    release_path=$(prod05_real_dir "$path_name")
    root_path=$(prod05_real_dir "$root_name")
    [[ "$release_path" == "$root_path"/* ]] || prod05_die "$path_name must be located below $root_name"
    [[ "$release_path" != "$root_path" ]] || prod05_die "$path_name cannot be the releases root"
    [[ -f "$release_path/RELEASE_COMMIT" && -f "$release_path/MANIFEST.sha256" ]] || prod05_die "$path_name is not a manifested release"
    printf '%s\n' "$release_path"
}

prod05_validate_current_link() {
    prod05_require CURRENT_LINK
    [[ "$CURRENT_LINK" = /* ]] || prod05_die 'CURRENT_LINK must be an absolute path'
    [[ -L "$CURRENT_LINK" ]] || prod05_die 'CURRENT_LINK must already be a symbolic link'
    local root_path current_path
    root_path=$(prod05_real_dir RELEASES_DIR)
    current_path=$(realpath -e "$CURRENT_LINK")
    [[ "$current_path" == "$root_path"/* ]] || prod05_die 'CURRENT_LINK target is outside RELEASES_DIR'
    printf '%s\n' "$current_path"
}

prod05_validate_current_link_location() {
    prod05_require CURRENT_LINK
    local root_path link_parent
    root_path=$(prod05_real_dir RELEASES_DIR)
    link_parent=$(dirname "$CURRENT_LINK")
    [[ -d "$link_parent" ]] || prod05_die 'CURRENT_LINK parent does not exist'
    [[ "$(realpath -e "$link_parent")" == "$root_path" ]] || prod05_die 'CURRENT_LINK must be directly inside RELEASES_DIR'
}

prod05_acquire_operation_lock() {
    prod05_require_command flock
    prod05_require PROD05_ENVIRONMENT_ID
    local records=()
    mapfile -t records < <(awk -F'|' -v ticket="$PROD05_CHANGE_TICKET" -v evidence="$PROD05_EVIDENCE_ID" -v environment="$PROD05_ENVIRONMENT_ID" '
        $1 == "operation_lock" && $2 == ticket && $3 == evidence && $4 == environment { print }
    ' "$PROD05_AUTHORIZED_TARGETS_FILE")
    ((${#records[@]} == 1)) || prod05_die 'authorized targets must contain exactly one operation_lock for ticket, evidence and environment'
    local record_type record_ticket record_evidence record_environment lock_root lock_dir lock_owner lock_group lock_dir_mode lock_file_mode lock_name extra
    IFS='|' read -r record_type record_ticket record_evidence record_environment lock_root lock_dir lock_owner lock_group lock_dir_mode lock_file_mode lock_name extra <<< "${records[0]}"
    [[ -z "${extra:-}" && -n "$lock_root" && -n "$lock_dir" && -n "$lock_owner" && -n "$lock_group" && -n "$lock_name" ]] || prod05_die 'authorized operation_lock is incomplete'
    [[ "$lock_root" = /* && "$lock_dir" = /* ]] || prod05_die 'authorized lock root and directory must be absolute'
    [[ "$lock_name" =~ ^[A-Za-z0-9._-]+$ ]] || prod05_die 'authorized lock filename is invalid'
    [[ "$lock_dir_mode" =~ ^[0-7]{3}$ && "$lock_file_mode" =~ ^[0-7]{3}$ ]] || prod05_die 'authorized lock modes must use three octal digits'
    [[ -d "$lock_root" && ! -L "$lock_root" && "$(realpath -e "$lock_root")" == "$lock_root" ]] || prod05_die 'authorized lock root must be a real canonical directory, not a symlink'
    [[ -d "$lock_dir" && ! -L "$lock_dir" && "$(realpath -e "$lock_dir")" == "$lock_dir" ]] || prod05_die 'authorized lock directory must be a real canonical directory, not a symlink'
    [[ "$lock_dir" == "$lock_root" || "$lock_dir" == "$lock_root"/* ]] || prod05_die 'lock directory is outside the authorized lock root'
    [[ "$(stat -c '%U' "$lock_dir")" == "$lock_owner" && "$(stat -c '%G' "$lock_dir")" == "$lock_group" && "$(stat -c '%a' "$lock_dir")" == "$lock_dir_mode" ]] || prod05_die 'lock directory owner, group or mode differs from authorization'
    [[ -z "$(find "$lock_dir" -maxdepth 0 -perm /022 -print -quit)" ]] || prod05_die 'authorized lock directory must not be group/world writable'
    PROD05_OPERATION_LOCK="$lock_dir/$lock_name"
    if [[ ! -e "$PROD05_OPERATION_LOCK" && ! -L "$PROD05_OPERATION_LOCK" ]]; then
        (umask 077; set -o noclobber; : > "$PROD05_OPERATION_LOCK") || prod05_die 'cannot create operation lock safely'
        chmod "$lock_file_mode" "$PROD05_OPERATION_LOCK"
    fi
    [[ -f "$PROD05_OPERATION_LOCK" && ! -L "$PROD05_OPERATION_LOCK" ]] || prod05_die 'operation lock must be a regular file and never a symlink'
    [[ "$(stat -c '%U' "$PROD05_OPERATION_LOCK")" == "$lock_owner" && "$(stat -c '%G' "$PROD05_OPERATION_LOCK")" == "$lock_group" && "$(stat -c '%a' "$PROD05_OPERATION_LOCK")" == "$lock_file_mode" ]] || prod05_die 'operation lock owner, group or mode differs from authorization'
    [[ "$(stat -c '%h' "$PROD05_OPERATION_LOCK")" == '1' ]] || prod05_die 'operation lock must have exactly one hard link'
    [[ -z "$(find "$PROD05_OPERATION_LOCK" -perm /022 -print -quit)" ]] || prod05_die 'operation lock must not be group/world writable'
    exec 9<>"$PROD05_OPERATION_LOCK"
    [[ ! -L "$PROD05_OPERATION_LOCK" && "$(stat -Lc '%d:%i' /proc/$$/fd/9)" == "$(stat -c '%d:%i' "$PROD05_OPERATION_LOCK")" ]] || prod05_die 'operation lock changed while opening; refusing execution'
    flock -n 9 || prod05_die 'another PROD-05 deploy or rollback operation is active'
    prod05_info "exclusive operation lock acquired: $PROD05_OPERATION_LOCK"
}

prod05_assert_current_unchanged() {
    local expected=$1
    prod05_validate_current_link_location
    local actual
    actual=$(prod05_validate_current_link)
    [[ "$actual" == "$expected" ]] || prod05_die 'CURRENT_LINK changed after initial validation; refusing operation'
}

prod05_release_is_approved() {
    local release_path=$1
    local expected_commit=$2
    prod05_require_file PROD05_APPROVED_RELEASES_FILE
    [[ -z "$(find "$PROD05_APPROVED_RELEASES_FILE" -perm /022 -print -quit)" ]] || prod05_die 'PROD05_APPROVED_RELEASES_FILE must not be group/world writable'
    [[ "$expected_commit" =~ ^[0-9a-fA-F]{40}$ ]] || prod05_die 'approved release commit must be a full SHA-1'
    awk -v commit="$expected_commit" -v path="$release_path" '
        $1 == commit && $2 == path { found = 1 }
        END { exit(found ? 0 : 1) }
    ' "$PROD05_APPROVED_RELEASES_FILE" || prod05_die "release is not listed in PROD05_APPROVED_RELEASES_FILE: $release_path"
}

prod05_verify_release_manifest() {
    local release_path=$1
    local expected_commit=$2
    local actual_commit
    actual_commit=$(tr -d '[:space:]' < "$release_path/RELEASE_COMMIT")
    [[ "$actual_commit" == "$expected_commit" ]] || prod05_die "release commit mismatch for $release_path"
    local forbidden_release_file
    forbidden_release_file=$(find "$release_path" -type f \( -name '.env' -o -name '.env.*' \) -print -quit)
    [[ -z "$forbidden_release_file" ]] || prod05_die "release contains forbidden environment configuration: $forbidden_release_file"
    (cd "$release_path" && sha256sum --check --quiet MANIFEST.sha256) || prod05_die "manifest checksum failed for $release_path"

    local unexpected
    unexpected=$(cd "$release_path" && find . \
        -path './storage' -prune -o \
        -path './bootstrap/cache' -prune -o \
        -name '.env' -prune -o \
        -type f ! -name 'MANIFEST.sha256' -print \
        | sed 's#^./##' \
        | while IFS= read -r file; do
            awk -v expected="$file" '$2 == expected { found = 1 } END { exit(found ? 0 : 1) }' MANIFEST.sha256 || printf '%s\n' "$file"
        done)
    [[ -z "$unexpected" ]] || prod05_die "release contains unmanifested files outside runtime paths: $unexpected"
    local unexpected_link
    unexpected_link=$(cd "$release_path" && find . \
        -path './storage' -prune -o \
        -path './bootstrap/cache' -prune -o \
        -name '.env' -prune -o \
        -type l -print -quit)
    [[ -z "$unexpected_link" ]] || prod05_die "release contains an unapproved symbolic link: $unexpected_link"
}

prod05_run_canonical_release_verifier() {
    local release_path=$1
    local common_dir verifier
    common_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
    verifier=$(realpath -e "$common_dir/../../ops/production/verify-release.sh")
    [[ -x "$verifier" ]] || prod05_die 'canonical release verifier is unavailable or not executable'
    "$verifier" "$release_path" || prod05_die "canonical release verification failed for $release_path"
}

prod05_assert_runtime_config() {
    prod05_require_dir RELEASE_DIR
    prod05_require PHP_BIN
    [[ -x "$PHP_BIN" ]] || prod05_die 'PHP_BIN is not executable'
    prod05_load_migration_target

    EXPECTED_APP_ENV=staging EXPECTED_STAGING_URL="$AUTH_APP_URL" EXPECTED_DB_CONNECTION="$AUTH_DB_CONNECTION" \
        EXPECTED_DB_HOST="$AUTH_DB_HOST" EXPECTED_DB_PORT="$AUTH_DB_PORT" EXPECTED_DB_DATABASE="$AUTH_DB_DATABASE" \
        "$PHP_BIN" -r '
        require getenv("RELEASE_DIR") . "/vendor/autoload.php";
        $app = require getenv("RELEASE_DIR") . "/bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $connection = (string) config("database.default");
        $actual = [
            "env" => (string) $app->environment(),
            "debug" => (bool) config("app.debug"),
            "url" => rtrim((string) config("app.url"), "/"),
            "connection" => $connection,
            "host" => (string) config("database.connections.$connection.host"),
            "port" => (string) config("database.connections.$connection.port"),
            "database" => (string) config("database.connections.$connection.database"),
        ];
        $expected = [
            "env" => (string) getenv("EXPECTED_APP_ENV"),
            "url" => rtrim((string) getenv("EXPECTED_STAGING_URL"), "/"),
            "connection" => (string) getenv("EXPECTED_DB_CONNECTION"),
            "host" => (string) getenv("EXPECTED_DB_HOST"),
            "port" => (string) getenv("EXPECTED_DB_PORT"),
            "database" => (string) getenv("EXPECTED_DB_DATABASE"),
        ];
        $urlHost = strtolower((string) parse_url($actual["url"], PHP_URL_HOST));
        $expectedHost = strtolower(preg_replace("/:\\d+$/", "", (string) getenv("STAGING_FQDN")));
        $ok = $actual["env"] === $expected["env"]
            && $actual["debug"] === false
            && $actual["url"] === $expected["url"]
            && $actual["connection"] === $expected["connection"]
            && $actual["host"] === $expected["host"]
            && $actual["port"] === $expected["port"]
            && $actual["database"] === $expected["database"]
            && $urlHost === $expectedHost;
        fwrite(STDOUT, "effective_config env=".$actual["env"]." debug=".($actual["debug"] ? "true" : "false")." url_host=".$urlHost." db_connection=".$actual["connection"]." db_host=".$actual["host"]." db_port=".$actual["port"]." db_database=".$actual["database"].PHP_EOL);
        exit($ok ? 0 : 78);
    ' || prod05_die 'effective Laravel configuration does not match the authorized Staging values'
}

prod05_assert_stopped_check() {
    local name=$1
    prod05_require_file "$name"
    [[ -x "${!name}" ]] || prod05_die "$name must be executable"
    if [[ -n "${RELEASES_DIR:-}" ]]; then
        local check_path releases_path
        check_path=$(realpath -e "${!name}")
        releases_path=$(prod05_real_dir RELEASES_DIR)
        [[ "$check_path" != "$releases_path"/* ]] || prod05_die "$name must be an external operational check, not release-controlled code"
    fi
    [[ -z "$(find "${!name}" -perm /022 -print -quit)" ]] || prod05_die "$name must not be group/world writable"
    local status
    status=$("${!name}") || prod05_die "$name reported a running or unknown state"
    [[ "$status" == 'STOPPED' ]] || prod05_die "$name must output exactly STOPPED"
}

prod05_assert_change_controls() {
    local related_commit=$1
    prod05_require PROD05_ENVIRONMENT_ID
    prod05_require_file APPROVED_BACKUP_MANIFEST
    [[ "$(stat -c '%a' "$APPROVED_BACKUP_MANIFEST")" == '600' ]] || prod05_die 'APPROVED_BACKUP_MANIFEST must have mode 0600'
    local manifest_format backup_id backup_ticket backup_evidence backup_environment backup_commit backup_created payload payload_sha encryption backup_status backup_approver
    manifest_format=$(awk -F= '$1 == "format" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    backup_id=$(awk -F= '$1 == "backup_id" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    backup_ticket=$(awk -F= '$1 == "ticket" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    backup_evidence=$(awk -F= '$1 == "evidence_id" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    backup_environment=$(awk -F= '$1 == "environment_id" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    backup_commit=$(awk -F= '$1 == "related_commit" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    backup_created=$(awk -F= '$1 == "created_at_utc" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    payload=$(awk -F= '$1 == "encrypted_payload" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    payload_sha=$(awk -F= '$1 == "payload_sha256" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    encryption=$(awk -F= '$1 == "encryption" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    backup_status=$(awk -F= '$1 == "status" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    backup_approver=$(awk -F= '$1 == "approved_by" { print substr($0, index($0, "=") + 1) }' "$APPROVED_BACKUP_MANIFEST")
    [[ "$manifest_format" == 'nova-prod05-approved-backup-v2' ]] || prod05_die 'approved backup manifest format is invalid'
    [[ "$backup_id" =~ ^[A-Za-z0-9._-]+$ ]] || prod05_die 'approved backup ID is missing or invalid'
    [[ "$backup_ticket" == "$PROD05_CHANGE_TICKET" ]] || prod05_die 'approved backup does not belong to this change ticket'
    [[ "$backup_evidence" == "$PROD05_EVIDENCE_ID" ]] || prod05_die 'approved backup belongs to another evidence execution'
    [[ "$backup_environment" == "$PROD05_ENVIRONMENT_ID" ]] || prod05_die 'approved backup belongs to another environment'
    [[ "$backup_commit" == "$related_commit" ]] || prod05_die 'approved backup is unrelated to the active baseline commit'
    [[ "$backup_created" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || prod05_die 'approved backup timestamp is missing or invalid'
    [[ "$payload" = /* && -f "$payload" && -s "$payload" ]] || prod05_die 'approved encrypted backup payload is missing or empty'
    [[ "$(stat -c '%a' "$payload")" == '600' ]] || prod05_die 'approved encrypted backup payload must have mode 0600'
    [[ "$payload_sha" =~ ^[0-9a-fA-F]{64}$ ]] || prod05_die 'approved backup payload SHA-256 is invalid'
    [[ "$(sha256sum "$payload" | awk '{print $1}')" == "$payload_sha" ]] || prod05_die 'approved backup payload hash mismatch'
    [[ "$encryption" == 'age' ]] || prod05_die 'approved backup encryption algorithm must be age'
    local payload_header
    payload_header=$(head -n 1 "$payload")
    [[ "$payload_header" == 'age-encryption.org/v1' || "$payload_header" == '-----BEGIN AGE ENCRYPTED FILE-----' ]] || prod05_die 'approved backup payload does not have an age container header'
    [[ "$backup_status" == 'VERIFIED' ]] || prod05_die 'approved backup is not marked VERIFIED'
    [[ -n "$backup_approver" && "$backup_approver" != 'PENDING' ]] || prod05_die 'approved backup has no verification approver'
    prod05_assert_backup_authorization "$APPROVED_BACKUP_MANIFEST" "$backup_id" "$related_commit" "$backup_approver"
    prod05_assert_stopped_check SCHEDULER_STATUS_CHECK
    prod05_assert_stopped_check TELEGRAM_STATUS_CHECK
    prod05_assert_stopped_check WRITERS_STATUS_CHECK
    local active_release
    active_release=$(prod05_validate_current_link)
    [[ -f "$active_release/storage/framework/down" ]] || prod05_die 'Laravel maintenance mode is not active on CURRENT_LINK'
    prod05_info 'maintenance mode, scheduler, Telegram, writers and approved backup verified'
}

prod05_assert_backup_authorization() {
    local manifest=$1 backup_id=$2 related_commit=$3 manifest_approver=$4
    prod05_require_file PROD05_BACKUP_AUTHORIZATIONS_FILE
    local registry_records=()
    mapfile -t registry_records < <(awk -F'|' -v ticket="$PROD05_CHANGE_TICKET" -v evidence="$PROD05_EVIDENCE_ID" -v environment="$PROD05_ENVIRONMENT_ID" '
        $1 == "backup_registry" && $2 == ticket && $3 == evidence && $4 == environment { print }
    ' "$PROD05_AUTHORIZED_TARGETS_FILE")
    ((${#registry_records[@]} == 1)) || prod05_die 'authorized targets must contain exactly one backup_registry for ticket, evidence and environment'
    local registry_type registry_ticket registry_evidence registry_environment authorized_registry registry_owner registry_group registry_extra
    IFS='|' read -r registry_type registry_ticket registry_evidence registry_environment authorized_registry registry_owner registry_group registry_extra <<< "${registry_records[0]}"
    [[ -z "${registry_extra:-}" && -n "$authorized_registry" && -n "$registry_owner" && -n "$registry_group" ]] || prod05_die 'authorized backup_registry is incomplete'
    [[ "$authorized_registry" = /* && -f "$authorized_registry" ]] || prod05_die 'authorized backup registry must be an existing absolute file'
    [[ "$(realpath -e "$PROD05_BACKUP_AUTHORIZATIONS_FILE")" == "$(realpath -e "$authorized_registry")" ]] || prod05_die 'backup authorization registry path differs from the authorized source'
    [[ ! -L "$PROD05_BACKUP_AUTHORIZATIONS_FILE" && "$(stat -c '%U' "$PROD05_BACKUP_AUTHORIZATIONS_FILE")" == "$registry_owner" && "$(stat -c '%G' "$PROD05_BACKUP_AUTHORIZATIONS_FILE")" == "$registry_group" ]] || prod05_die 'backup authorization registry owner, group or file type differs from authorization'
    [[ "$(stat -c '%a' "$PROD05_BACKUP_AUTHORIZATIONS_FILE")" == '400' ]] || prod05_die 'independent backup authorization registry must have mode 0400'
    [[ "$(stat -c '%h' "$PROD05_BACKUP_AUTHORIZATIONS_FILE")" == '1' ]] || prod05_die 'independent backup authorization registry must have exactly one hard link'
    local registry_path evidence_path manifest_dir
    registry_path=$(realpath -e "$PROD05_BACKUP_AUTHORIZATIONS_FILE")
    evidence_path=$(realpath -e "$EVIDENCE_DIR")
    manifest_dir=$(realpath -e "$(dirname "$manifest")")
    [[ "$registry_path" != "$evidence_path"/* && "$registry_path" != "$manifest_dir"/* ]] || prod05_die 'backup authorization registry must be outside evidence and backup directories'
    local manifest_sha records=()
    manifest_sha=$(sha256sum "$manifest" | awk '{print $1}')
    mapfile -t records < <(awk -F'|' -v sha="$manifest_sha" -v id="$backup_id" -v ticket="$PROD05_CHANGE_TICKET" -v evidence="$PROD05_EVIDENCE_ID" -v environment="$PROD05_ENVIRONMENT_ID" -v commit="$related_commit" -v approver="$manifest_approver" '
        $1 == "backup_authorization" && $2 == sha && $3 == id && $4 == ticket && $5 == evidence && $6 == environment && $7 == commit && $8 == approver { print }
    ' "$PROD05_BACKUP_AUTHORIZATIONS_FILE")
    ((${#records[@]} == 1)) || prod05_die 'no unique independent authorization matches this backup manifest'
    local record_type auth_sha auth_id auth_ticket auth_evidence auth_environment auth_commit auth_approver auth_timestamp extra
    IFS='|' read -r record_type auth_sha auth_id auth_ticket auth_evidence auth_environment auth_commit auth_approver auth_timestamp extra <<< "${records[0]}"
    [[ -z "${extra:-}" && "$auth_timestamp" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || prod05_die 'independent backup authorization timestamp or format is invalid'
}

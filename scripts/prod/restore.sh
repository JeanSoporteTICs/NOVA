#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=_common.sh
source "$script_dir/_common.sh"

if [[ "${1:-}" == '--help' ]]; then
    echo 'Required common controls: PROD05_CONFIRM=PROD05-STAGING RESTORE_APPROVED=YES PROD05_CHANGE_TICKET PROD05_APPROVED_BY PROD05_EVIDENCE_ID PROD05_ENVIRONMENT_ID PROD05_AUTHORIZED_TARGETS_FILE APP_ENV=staging STAGING_FQDN EVIDENCE_DIR.'
    echo 'Required restore: TARGET_DB_IS_EMPTY=YES RESTORE_DBA_APPROVED=YES RESTORE_DB_CONNECTION RESTORE_DB_HOST RESTORE_DB_PORT RESTORE_DB_NAME RESTORE_SCHEMA_CREATION_ID MYSQL_CNF BACKUP_FILE BACKUP_METADATA_FILE PROD05_BACKUP_AUTHORIZATIONS_FILE AGE_IDENTITY_FILE SECURE_TMP_DIR. Restore tuple, backup ID, commit and creation ID come from restore_target.'
    echo 'Optional binaries: MYSQL_BIN AGE_BIN. Target DB must be pre-created, empty and isolated by DBA.'
    exit 0
fi

prod05_begin restore RESTORE_APPROVED
[[ "${TARGET_DB_IS_EMPTY:-}" == 'YES' ]] || prod05_die 'TARGET_DB_IS_EMPTY=YES is required; this script never clears a database'
[[ "${RESTORE_DBA_APPROVED:-}" == 'YES' ]] || prod05_die 'RESTORE_DBA_APPROVED=YES is required after DBA approves the restricted restore account and command'
prod05_reject_secret_env
prod05_load_restore_target
for name in RESTORE_DB_CONNECTION RESTORE_DB_HOST RESTORE_DB_PORT RESTORE_DB_NAME RESTORE_SCHEMA_CREATION_ID; do prod05_require "$name"; done
prod05_reject_local RESTORE_DB_HOST
[[ "$RESTORE_DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || prod05_die 'RESTORE_DB_NAME may contain only letters, digits and underscore'
[[ "$RESTORE_DB_CONNECTION" == "$AUTH_RESTORE_DB_CONNECTION" && "$RESTORE_DB_HOST" == "$AUTH_RESTORE_HOST" && "$RESTORE_DB_PORT" == "$AUTH_RESTORE_PORT" && "$RESTORE_DB_NAME" == "$AUTH_RESTORE_DATABASE" ]] || prod05_die 'restore DB tuple differs from the authorized restore_target'
[[ "$RESTORE_SCHEMA_CREATION_ID" == "$AUTH_RESTORE_CREATION_ID" ]] || prod05_die 'schema creation ID differs from the authorized restore_target'
prod05_require_file MYSQL_CNF
prod05_require_file BACKUP_FILE
prod05_require_file BACKUP_METADATA_FILE
[[ -s "$BACKUP_FILE" ]] || prod05_die 'encrypted backup payload is empty'
[[ "$(stat -c '%a' "$BACKUP_METADATA_FILE")" == '600' ]] || prod05_die 'BACKUP_METADATA_FILE must have mode 0600'
[[ "$(stat -c '%a' "$BACKUP_FILE")" == '600' ]] || prod05_die 'BACKUP_FILE must have mode 0600'
prod05_require_file AGE_IDENTITY_FILE
prod05_require_dir SECURE_TMP_DIR
[[ "$(stat -c '%a' "$MYSQL_CNF")" == '600' ]] || prod05_die 'MYSQL_CNF must have mode 0600'
[[ "$(stat -c '%a' "$AGE_IDENTITY_FILE")" == '600' ]] || prod05_die 'AGE_IDENTITY_FILE must have mode 0600'
[[ "$(stat -c '%a' "$SECURE_TMP_DIR")" == '700' ]] || prod05_die 'SECURE_TMP_DIR must have mode 0700'
tmp_fs=$(findmnt --noheadings --output FSTYPE --target "$SECURE_TMP_DIR" | tr -d '[:space:]')
[[ "$tmp_fs" == 'tmpfs' || "$tmp_fs" == 'ramfs' ]] || prod05_die 'SECURE_TMP_DIR must reside on tmpfs or ramfs; persistent plaintext is forbidden'

grep -Fxq 'format=nova-prod05-approved-backup-v2' "$BACKUP_METADATA_FILE" || prod05_die 'backup format is not the approved encrypted backup format'
grep -Fxq 'status=VERIFIED' "$BACKUP_METADATA_FILE" || prod05_die 'backup metadata is not marked VERIFIED'
grep -Fxq "ticket=$PROD05_CHANGE_TICKET" "$BACKUP_METADATA_FILE" || prod05_die 'backup metadata belongs to a different change ticket'
grep -Fxq "evidence_id=$PROD05_EVIDENCE_ID" "$BACKUP_METADATA_FILE" || prod05_die 'backup metadata belongs to another evidence execution'
grep -Fxq "environment_id=$PROD05_ENVIRONMENT_ID" "$BACKUP_METADATA_FILE" || prod05_die 'backup metadata belongs to another environment'
grep -Fxq "related_commit=$AUTH_RESTORE_RELATED_COMMIT" "$BACKUP_METADATA_FILE" || prod05_die 'backup metadata is unrelated to the authorized restore baseline'
metadata_backup_id=$(awk -F= '$1 == "backup_id" { print substr($0, index($0, "=") + 1) }' "$BACKUP_METADATA_FILE")
[[ "$metadata_backup_id" == "$AUTH_RESTORE_BACKUP_ID" ]] || prod05_die 'backup ID differs from the authorized restore source backup'
metadata_created=$(awk -F= '$1 == "created_at_utc" { print substr($0, index($0, "=") + 1) }' "$BACKUP_METADATA_FILE")
[[ "$metadata_created" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || prod05_die 'backup metadata timestamp is missing or invalid'
expected_sha=$(awk -F= '$1 == "payload_sha256" { print $2 }' "$BACKUP_METADATA_FILE")
[[ "$expected_sha" =~ ^[0-9a-fA-F]{64}$ ]] || prod05_die 'backup metadata has an invalid SHA-256'

actual_sha=$(sha256sum "$BACKUP_FILE" | awk '{print $1}')
[[ "$actual_sha" == "$expected_sha" ]] || prod05_die 'encrypted backup SHA-256 does not match the approved metadata'
metadata_payload=$(awk -F= '$1 == "encrypted_payload" { print substr($0, index($0, "=") + 1) }' "$BACKUP_METADATA_FILE")
[[ "$metadata_payload" = /* && "$(realpath -e "$metadata_payload")" == "$(realpath -e "$BACKUP_FILE")" ]] || prod05_die 'encrypted backup path does not match approved metadata'
grep -Fxq 'encryption=age' "$BACKUP_METADATA_FILE" || prod05_die 'backup metadata does not declare the required age encryption'
payload_header=$(head -n 1 "$BACKUP_FILE")
[[ "$payload_header" == 'age-encryption.org/v1' || "$payload_header" == '-----BEGIN AGE ENCRYPTED FILE-----' ]] || prod05_die 'backup payload does not have an age container header'
metadata_approver=$(awk -F= '$1 == "approved_by" { print substr($0, index($0, "=") + 1) }' "$BACKUP_METADATA_FILE")
[[ -n "$metadata_approver" && "$metadata_approver" != 'PENDING' ]] || prod05_die 'backup metadata has no verification approver'
prod05_assert_backup_authorization "$BACKUP_METADATA_FILE" "$metadata_backup_id" "$AUTH_RESTORE_RELATED_COMMIT" "$metadata_approver"
mysql_bin=${MYSQL_BIN:-mariadb}
age_bin=${AGE_BIN:-age}
prod05_require_command "$mysql_bin"
prod05_require_command "$age_bin"
prod05_require_command shred

mysql_scalar() {
    "$mysql_bin" --defaults-extra-file="$MYSQL_CNF" --host="$RESTORE_DB_HOST" --port="$RESTORE_DB_PORT" --batch --skip-column-names -e "$1"
}

current_role=$(mysql_scalar "SELECT COALESCE(CURRENT_ROLE(), 'NONE');")
[[ "$current_role" == 'NONE' || "$current_role" == 'NULL' ]] || prod05_die 'restore account has an active role; effective least privilege cannot be established'
grantee_expr="CONCAT(QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',1)), '@', QUOTE(SUBSTRING_INDEX(CURRENT_USER(),'@',-1)))"
global_privileges=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=$grantee_expr AND PRIVILEGE_TYPE <> 'USAGE';")
outside_schema_privileges=$(mysql_scalar "SELECT (SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=$grantee_expr AND TABLE_SCHEMA <> '$AUTH_RESTORE_DATABASE') + (SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=$grantee_expr AND TABLE_SCHEMA <> '$AUTH_RESTORE_DATABASE') + (SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=$grantee_expr AND TABLE_SCHEMA <> '$AUTH_RESTORE_DATABASE') + (SELECT COUNT(*) FROM information_schema.ROUTINE_PRIVILEGES WHERE GRANTEE=$grantee_expr AND ROUTINE_SCHEMA <> '$AUTH_RESTORE_DATABASE');")
target_privileges=$(mysql_scalar "SELECT (SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=$grantee_expr AND TABLE_SCHEMA = '$AUTH_RESTORE_DATABASE') + (SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=$grantee_expr AND TABLE_SCHEMA = '$AUTH_RESTORE_DATABASE') + (SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=$grantee_expr AND TABLE_SCHEMA = '$AUTH_RESTORE_DATABASE') + (SELECT COUNT(*) FROM information_schema.ROUTINE_PRIVILEGES WHERE GRANTEE=$grantee_expr AND ROUTINE_SCHEMA = '$AUTH_RESTORE_DATABASE');")
grantable_privileges=$(mysql_scalar "SELECT (SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=$grantee_expr AND IS_GRANTABLE = 'YES') + (SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=$grantee_expr AND IS_GRANTABLE = 'YES') + (SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=$grantee_expr AND IS_GRANTABLE = 'YES') + (SELECT COUNT(*) FROM information_schema.ROUTINE_PRIVILEGES WHERE GRANTEE=$grantee_expr AND IS_GRANTABLE = 'YES');")
[[ "$global_privileges" == '0' ]] || prod05_die 'restore account has global privileges; CREATE USER, GRANT, FILE, SUPER or equivalent capability is forbidden'
[[ "$outside_schema_privileges" == '0' ]] || prod05_die 'restore account has privileges outside the authorized target schema'
[[ "$grantable_privileges" == '0' ]] || prod05_die 'restore account has GRANT OPTION on schema, table, column or routine privileges'
[[ "$target_privileges" =~ ^[1-9][0-9]*$ ]] || prod05_die 'restore account has no explicit privileges on the authorized target schema'

schema_count=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$RESTORE_DB_NAME';")
table_view_count=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$RESTORE_DB_NAME';")
routine_count=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$RESTORE_DB_NAME';")
event_count=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA='$RESTORE_DB_NAME';")
trigger_count=$(mysql_scalar "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$RESTORE_DB_NAME';")
[[ "$schema_count" == '1' ]] || prod05_die 'authorized restore schema does not exist uniquely'
[[ "$table_view_count" == '0' && "$routine_count" == '0' && "$event_count" == '0' && "$trigger_count" == '0' ]] || prod05_die "restore schema is not empty: tables_views=$table_view_count routines=$routine_count events=$event_count triggers=$trigger_count"

# Decrypt only into volatile memory. SQL isolation is enforced by the DBA-approved,
# schema-confined DB account; textual SQL inspection is intentionally not used.
plain_sql=$(mktemp --tmpdir="$SECURE_TMP_DIR" prod05-restore.XXXXXX.sql)
chmod 0600 "$plain_sql"
cleanup() {
    [[ ! -e "$plain_sql" ]] || shred -u -- "$plain_sql"
}
trap cleanup EXIT
"$age_bin" --decrypt --identity "$AGE_IDENTITY_FILE" --output "$plain_sql" "$BACKUP_FILE"
[[ -s "$plain_sql" ]] || prod05_die 'decrypted SQL is empty'

start_epoch=$(date +%s)
"$mysql_bin" --defaults-extra-file="$MYSQL_CNF" --host="$RESTORE_DB_HOST" --port="$RESTORE_DB_PORT" "$RESTORE_DB_NAME" < "$plain_sql"
duration=$(( $(date +%s) - start_epoch ))
shred -u -- "$plain_sql"
trap - EXIT
prod05_info "restore completed; target=$RESTORE_DB_NAME duration_seconds=$duration"
prod05_info 'plaintext existed only in approved volatile memory and was removed; DBA integrity checks, encrypted persistence restore, APP_KEY handling and smoke tests remain mandatory'

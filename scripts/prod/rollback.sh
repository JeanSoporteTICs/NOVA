#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=_common.sh
source "$script_dir/_common.sh"

if [[ "${1:-}" == '--help' ]]; then
    echo 'Required common controls: PROD05_CONFIRM=PROD05-STAGING ROLLBACK_APPROVED=YES PROD05_CHANGE_TICKET PROD05_APPROVED_BY PROD05_EVIDENCE_ID PROD05_ENVIRONMENT_ID PROD05_AUTHORIZED_TARGETS_FILE APP_ENV=staging STAGING_FQDN EVIDENCE_DIR.'
    echo 'Required release controls: RELEASES_DIR CURRENT_LINK PREVIOUS_RELEASE_DIR EXPECTED_CURRENT_COMMIT EXPECTED_PREVIOUS_COMMIT PROD05_APPROVED_RELEASES_FILE PHP_BIN.'
    echo 'Required operational controls: DB_ROLLBACK_STRATEGY=backward-compatible|restored authorized migration_target, operation_lock and backup_registry records APPROVED_BACKUP_MANIFEST PROD05_BACKUP_AUTHORIZATIONS_FILE SCHEDULER_STATUS_CHECK TELEGRAM_STATUS_CHECK WRITERS_STATUS_CHECK.'
    echo 'DB_ROLLBACK_STRATEGY=restored also requires RESTORE_COMPLETED_MANIFEST marked VERIFIED for the same ticket.'
    exit 0
fi

prod05_begin rollback ROLLBACK_APPROVED
[[ "${DB_ROLLBACK_STRATEGY:-}" == 'backward-compatible' || "${DB_ROLLBACK_STRATEGY:-}" == 'restored' ]] || prod05_die 'DB_ROLLBACK_STRATEGY must be backward-compatible or restored'
for name in RELEASES_DIR CURRENT_LINK PREVIOUS_RELEASE_DIR PHP_BIN EXPECTED_CURRENT_COMMIT EXPECTED_PREVIOUS_COMMIT PROD05_APPROVED_RELEASES_FILE; do prod05_require "$name"; done
[[ -x "$PHP_BIN" ]] || prod05_die 'PHP_BIN is not executable'
prod05_validate_current_link_location
prod05_acquire_operation_lock
current=$(prod05_validate_current_link)
previous=$(prod05_validate_release_under_root PREVIOUS_RELEASE_DIR RELEASES_DIR)
[[ "$current" != "$previous" ]] || prod05_die 'previous release is already active'
prod05_release_is_approved "$current" "$EXPECTED_CURRENT_COMMIT"
prod05_release_is_approved "$previous" "$EXPECTED_PREVIOUS_COMMIT"
prod05_verify_release_manifest "$current" "$EXPECTED_CURRENT_COMMIT"
prod05_verify_release_manifest "$previous" "$EXPECTED_PREVIOUS_COMMIT"
prod05_run_canonical_release_verifier "$current"
prod05_run_canonical_release_verifier "$previous"
[[ -f "$previous/artisan" && -f "$previous/vendor/autoload.php" && -f "$previous/public/build/manifest.json" ]] || prod05_die 'previous release is incomplete'
if [[ "$DB_ROLLBACK_STRATEGY" == 'restored' ]]; then
    prod05_require_file RESTORE_COMPLETED_MANIFEST
    grep -Fxq "ticket=$PROD05_CHANGE_TICKET" "$RESTORE_COMPLETED_MANIFEST" || prod05_die 'restore completion belongs to a different ticket'
    grep -Fxq 'status=VERIFIED' "$RESTORE_COMPLETED_MANIFEST" || prod05_die 'restore completion is not VERIFIED'
fi
export RELEASE_DIR="$previous"

prod05_assert_change_controls "$EXPECTED_CURRENT_COMMIT"
prod05_assert_runtime_config
prod05_assert_current_unchanged "$current"
"$PHP_BIN" "$previous/artisan" optimize:clear
prod05_assert_runtime_config
"$PHP_BIN" "$previous/artisan" config:cache
prod05_assert_runtime_config
"$PHP_BIN" "$previous/artisan" route:cache
prod05_assert_runtime_config
"$PHP_BIN" "$previous/artisan" view:cache
prod05_assert_runtime_config
prod05_assert_current_unchanged "$current"
link_parent=$(dirname "$CURRENT_LINK")
temporary_link="$link_parent/.prod05-rollback-$(date -u +%Y%m%dT%H%M%SZ)"
[[ ! -e "$temporary_link" && ! -L "$temporary_link" ]] || prod05_die 'temporary rollback link already exists'
ln -s "$previous" "$temporary_link"
mv -Tf "$temporary_link" "$CURRENT_LINK"
[[ "$(realpath -e "$CURRENT_LINK")" == "$previous" ]] || prod05_die 'CURRENT_LINK post-rollback verification failed'
prod05_info "previous release activated in Staging: $previous"
prod05_info 'reload services gracefully, resume one writer instance, run critical smoke and record total rollback time'

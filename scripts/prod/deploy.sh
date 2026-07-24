#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=_common.sh
source "$script_dir/_common.sh"

if [[ "${1:-}" == '--help' ]]; then
    echo 'Required common controls: PROD05_CONFIRM=PROD05-STAGING DEPLOY_APPROVED=YES PROD05_CHANGE_TICKET PROD05_APPROVED_BY PROD05_EVIDENCE_ID PROD05_ENVIRONMENT_ID PROD05_AUTHORIZED_TARGETS_FILE APP_ENV=staging STAGING_FQDN EVIDENCE_DIR.'
    echo 'Required release controls: RELEASES_DIR RELEASE_DIR CURRENT_LINK PREVIOUS_RELEASE_DIR EXPECTED_RELEASE_COMMIT EXPECTED_PREVIOUS_COMMIT PROD05_APPROVED_RELEASES_FILE PHP_BIN.'
    echo 'Required runtime controls: authorized migration_target, operation_lock and backup_registry records, MIGRATIONS_APPROVED=YES APPROVED_BACKUP_MANIFEST PROD05_BACKUP_AUTHORIZATIONS_FILE SCHEDULER_STATUS_CHECK TELEGRAM_STATUS_CHECK WRITERS_STATUS_CHECK.'
    echo 'The release must already contain vendor and built assets. This script does not install dependencies or run Vite.'
    exit 0
fi

prod05_begin deploy DEPLOY_APPROVED
[[ "${MIGRATIONS_APPROVED:-}" == 'YES' ]] || prod05_die 'set MIGRATIONS_APPROVED=YES after DBA verifies backup and baseline'
for name in RELEASES_DIR RELEASE_DIR CURRENT_LINK PREVIOUS_RELEASE_DIR PHP_BIN EXPECTED_RELEASE_COMMIT EXPECTED_PREVIOUS_COMMIT PROD05_APPROVED_RELEASES_FILE; do prod05_require "$name"; done
[[ -x "$PHP_BIN" ]] || prod05_die 'PHP_BIN is not executable'
prod05_validate_current_link_location
prod05_acquire_operation_lock
candidate=$(prod05_validate_release_under_root RELEASE_DIR RELEASES_DIR)
previous=$(prod05_validate_release_under_root PREVIOUS_RELEASE_DIR RELEASES_DIR)
current=$(prod05_validate_current_link)
[[ "$candidate" != "$current" ]] || prod05_die 'release candidate is already active'
[[ "$candidate" != "$previous" ]] || prod05_die 'release candidate and previous release must differ'
[[ "$current" == "$previous" ]] || prod05_die 'CURRENT_LINK does not point to the approved previous release'
prod05_release_is_approved "$candidate" "$EXPECTED_RELEASE_COMMIT"
prod05_release_is_approved "$previous" "$EXPECTED_PREVIOUS_COMMIT"
prod05_verify_release_manifest "$candidate" "$EXPECTED_RELEASE_COMMIT"
prod05_verify_release_manifest "$previous" "$EXPECTED_PREVIOUS_COMMIT"
prod05_run_canonical_release_verifier "$candidate"
prod05_run_canonical_release_verifier "$previous"
[[ -f "$candidate/vendor/autoload.php" && -f "$candidate/public/build/manifest.json" ]] || prod05_die 'candidate release must contain locked vendor dependencies and built assets'
export RELEASE_DIR="$candidate"

prod05_assert_change_controls "$EXPECTED_PREVIOUS_COMMIT"
prod05_assert_runtime_config
"$PHP_BIN" "$candidate/artisan" migrate:status
prod05_assert_runtime_config
prod05_assert_current_unchanged "$current"
"$PHP_BIN" "$candidate/artisan" migrate --force
prod05_assert_current_unchanged "$current"
"$PHP_BIN" "$candidate/artisan" optimize:clear
prod05_assert_runtime_config
"$PHP_BIN" "$candidate/artisan" config:cache
prod05_assert_runtime_config
"$PHP_BIN" "$candidate/artisan" route:cache
prod05_assert_runtime_config
"$PHP_BIN" "$candidate/artisan" view:cache
prod05_assert_runtime_config
"$PHP_BIN" "$candidate/artisan" route:list >/dev/null

prod05_assert_current_unchanged "$current"
link_parent=$(dirname "$CURRENT_LINK")
temporary_link="$link_parent/.prod05-current-${EXPECTED_RELEASE_COMMIT:0:12}"
[[ ! -e "$temporary_link" && ! -L "$temporary_link" ]] || prod05_die 'temporary activation link already exists'
ln -s "$candidate" "$temporary_link"
mv -Tf "$temporary_link" "$CURRENT_LINK"
[[ "$(realpath -e "$CURRENT_LINK")" == "$candidate" ]] || prod05_die 'CURRENT_LINK post-activation verification failed'
prod05_info "release activated in Staging: $candidate"
prod05_info 'graceful service reload, writer restart, smoke tests and monitoring remain operator-controlled steps'

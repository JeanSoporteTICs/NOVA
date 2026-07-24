#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=_common.sh
source "$script_dir/_common.sh"

if [[ "${1:-}" == '--help' ]]; then
    echo 'Required common controls: PROD05_CONFIRM=PROD05-STAGING PREFLIGHT_APPROVED=YES PROD05_CHANGE_TICKET PROD05_APPROVED_BY PROD05_EVIDENCE_ID PROD05_ENVIRONMENT_ID PROD05_AUTHORIZED_TARGETS_FILE APP_ENV=staging STAGING_FQDN EVIDENCE_DIR.'
    echo 'Required runtime: authorized migration_target inventory record, RELEASES_DIR RELEASE_DIR PREVIOUS_RELEASE_DIR CURRENT_LINK PHP_BIN PROD05_APPROVED_RELEASES_FILE EXPECTED_RELEASE_COMMIT EXPECTED_PREVIOUS_COMMIT.'
    echo 'Optional: MIN_FREE_KB (default 1048576).'
    exit 0
fi

prod05_begin preflight PREFLIGHT_APPROVED
for name in RELEASES_DIR RELEASE_DIR PREVIOUS_RELEASE_DIR CURRENT_LINK PHP_BIN EXPECTED_RELEASE_COMMIT EXPECTED_PREVIOUS_COMMIT PROD05_APPROVED_RELEASES_FILE; do prod05_require "$name"; done
candidate=$(prod05_validate_release_under_root RELEASE_DIR RELEASES_DIR)
previous=$(prod05_validate_release_under_root PREVIOUS_RELEASE_DIR RELEASES_DIR)
current=$(prod05_validate_current_link)
[[ "$candidate" != "$current" ]] || prod05_die 'release candidate is already active'
[[ "$candidate" != "$previous" ]] || prod05_die 'release candidate and previous release must differ'
[[ "$current" == "$previous" ]] || prod05_die 'CURRENT_LINK does not point to PREVIOUS_RELEASE_DIR'
prod05_release_is_approved "$candidate" "$EXPECTED_RELEASE_COMMIT"
prod05_release_is_approved "$previous" "$EXPECTED_PREVIOUS_COMMIT"
prod05_verify_release_manifest "$candidate" "$EXPECTED_RELEASE_COMMIT"
prod05_verify_release_manifest "$previous" "$EXPECTED_PREVIOUS_COMMIT"
[[ ! -e "$candidate/.env" ]] || prod05_die 'the immutable candidate release must not contain .env'
prod05_assert_runtime_config

free_kb=$(df -Pk "$RELEASE_DIR" | awk 'NR==2 {print $4}')
min_free_kb=${MIN_FREE_KB:-1048576}
[[ "$free_kb" =~ ^[0-9]+$ && "$min_free_kb" =~ ^[0-9]+$ ]] || prod05_die 'disk capacity values are invalid'
((free_kb >= min_free_kb)) || prod05_die "insufficient free space: ${free_kb}KB available, ${min_free_kb}KB required"

prod05_info "staging=$STAGING_FQDN release=$(basename "$candidate") previous=$(basename "$previous")"
"$PHP_BIN" -v | head -n 2
"$PHP_BIN" "$RELEASE_DIR/artisan" --version
prod05_info 'preflight structural checks passed; DB/network isolation and approvals still require human evidence'

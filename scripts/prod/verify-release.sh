#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo_root=$(cd "$script_dir/../.." && pwd)
# shellcheck source=_common.sh
source "$script_dir/_common.sh"

if [[ "${1:-}" == '--help' ]]; then
    echo 'Required: PROD05_CONFIRM=PROD05-STAGING VERIFY_APPROVED=YES PROD05_CHANGE_TICKET PROD05_APPROVED_BY PROD05_EVIDENCE_ID PROD05_AUTHORIZED_TARGETS_FILE APP_ENV=staging STAGING_FQDN EVIDENCE_DIR RELEASES_DIR RELEASE_DIR EXPECTED_RELEASE_COMMIT PROD05_APPROVED_RELEASES_FILE.'
    exit 0
fi

prod05_begin verify-release VERIFY_APPROVED
prod05_require RELEASES_DIR
prod05_require EXPECTED_RELEASE_COMMIT
[[ -x "$repo_root/ops/production/verify-release.sh" ]] || prod05_die 'canonical release verifier is unavailable'

release_path=$(prod05_validate_release_under_root RELEASE_DIR RELEASES_DIR)
prod05_release_is_approved "$release_path" "$EXPECTED_RELEASE_COMMIT"
"$repo_root/ops/production/verify-release.sh" "$release_path"
prod05_verify_release_manifest "$release_path" "$EXPECTED_RELEASE_COMMIT"
prod05_info "release verified: commit=$EXPECTED_RELEASE_COMMIT"

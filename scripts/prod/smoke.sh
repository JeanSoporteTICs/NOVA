#!/usr/bin/env bash
set -euo pipefail
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=_common.sh
source "$script_dir/_common.sh"

if [[ "${1:-}" == '--help' ]]; then
    echo 'Required: PROD05_CONFIRM=PROD05-STAGING SMOKE_APPROVED=YES PROD05_CHANGE_TICKET PROD05_APPROVED_BY PROD05_EVIDENCE_ID PROD05_AUTHORIZED_TARGETS_FILE APP_ENV=staging STAGING_FQDN EVIDENCE_DIR STAGING_BASE_URL.'
    echo 'This performs unauthenticated HTTP checks only. Functional authenticated checks remain manual per docs/PROD05_SMOKE_TESTS.md.'
    exit 0
fi

prod05_begin smoke SMOKE_APPROVED
prod05_reject_local STAGING_BASE_URL
[[ "$STAGING_BASE_URL" == https://* ]] || prod05_die 'STAGING_BASE_URL must use HTTPS'
grep -Fxq "staging_url=${STAGING_BASE_URL%/}" "$PROD05_AUTHORIZED_TARGETS_FILE" || prod05_die 'STAGING_BASE_URL is not authorized for this ticket'
prod05_require_command curl
base=${STAGING_BASE_URL%/}
failures=0

check_exact() {
    local path=$1 expected=$2
    local code
    code=$(curl --fail-with-body --silent --show-error --output /dev/null --connect-timeout 10 --max-time 30 --write-out '%{http_code}' "$base$path") || code='000'
    printf '%s %s expected=%s\n' "$path" "$code" "$expected"
    [[ "$code" == "$expected" ]] || failures=$((failures + 1))
}

check_one_of() {
    local path=$1 allowed=$2
    local code
    code=$(curl --silent --show-error --output /dev/null --connect-timeout 10 --max-time 30 --write-out '%{http_code}' "$base$path") || code='000'
    printf '%s %s allowed=%s\n' "$path" "$code" "$allowed"
    [[ ",$allowed," == *",$code,"* ]] || failures=$((failures + 1))
}

check_exact /login 200
check_exact /assets/nova-ui.css 200
for path in / /redmine_tic /redmine-mantencion /emach /telegram /procedimientos /horas-extra /mis-integraciones /administracion; do check_one_of "$path" '302,401,403'; done
for path in /.env /.git/HEAD /composer.json /composer.lock /nova.sql /storage/logs/laravel.log; do check_one_of "$path" '403,404'; done

((failures == 0)) || prod05_die "$failures unauthenticated smoke check(s) failed"
prod05_info 'unauthenticated smoke passed; authenticated, permission, data and browser checks remain mandatory'

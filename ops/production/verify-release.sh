#!/usr/bin/env bash
set -euo pipefail

release_dir=${1:-}
if [[ -z "$release_dir" || "$release_dir" != /* || ! -d "$release_dir" ]]; then
    echo "Usage: $0 <absolute-release-directory>" >&2
    exit 64
fi

failures=0
fail() {
    echo "FAIL: $1" >&2
    failures=$((failures + 1))
}

required=(
    artisan
    bootstrap/app.php
    public/index.php
    public/.htaccess
    vendor/autoload.php
    MANIFEST.sha256
    RELEASE_COMMIT
)
for path in "${required[@]}"; do
    [[ -e "$release_dir/$path" ]] || fail "required path missing: $path"
done

for forbidden in .git tests node_modules public/hot .tools .claude; do
    [[ ! -e "$release_dir/$forbidden" ]] || fail "forbidden path present: $forbidden"
done

if find "$release_dir" \( -name '.env' -o -name '.env.*' \) -print -quit | grep -q .; then
    fail "release contains environment configuration"
fi

if find "$release_dir" -type f \( -iname 'credentials*' -o -iname '*.key' -o -iname '*.pem' -o -iname '*.p12' -o -iname '*.pfx' \) -print -quit | grep -q .; then
    fail "release contains environment, credential, or private-key material"
fi

if find "$release_dir" -type f \( -iname '*.sql' -o -iname '*.dump' -o -iname '*.bak' -o -iname '*.backup' \) -print -quit | grep -q .; then
    fail "release contains a database dump or backup artifact"
fi

while IFS= read -r -d '' path; do
    fail "forbidden artifact present: ${path#"$release_dir/"}"
done < <(find "$release_dir" -path "$release_dir/vendor" -prune -o -type f \( \
    -iname '*.sql' -o -iname '*.bak' -o -iname '*.backup' -o -iname '*.log' \
    -o -iname '*.tmp' -o -iname '*.temp' -o -iname '*.orig' -o -iname '*.swp' \
    -o -iname '*.key' -o -iname '*.pem' -o -iname '*.p12' -o -iname '*.pfx' \
    -o -iname '*.crt' -o -iname '*.cer' -o -name '*~' \
\) -print0)

if find "$release_dir/storage" -type f ! -name '.gitignore' -print -quit | grep -q .; then
    fail "storage contains runtime data"
fi

scan_pattern="BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|(?i:Bearer)[[:space:]]+[A-Za-z0-9._~+/-]{24,}|https?://[^[:space:]\"']+[?&](token|signature|sig|key|expires|md5)=[^&[:space:]\"']{8,}"
if (cd "$release_dir" && rg -l --hidden -g '!vendor/**' -g '!MANIFEST.sha256' "$scan_pattern" . >/dev/null); then
    fail "content scan found a private key, literal bearer token, or signed/tokenized URL"
fi

if [[ -f "$release_dir/MANIFEST.sha256" ]]; then
    if ! (cd "$release_dir" && sha256sum --check --quiet MANIFEST.sha256); then
        fail "manifest checksum verification failed"
    fi
fi

if ((failures > 0)); then
    echo "Release verification failed with $failures finding(s)." >&2
    exit 1
fi

echo "Release verification passed. DocumentRoot must be: $release_dir/public"

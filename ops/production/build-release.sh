#!/usr/bin/env bash
set -euo pipefail

usage() {
    echo "Usage: $0 --ref <commit-or-tag> --output <absolute-directory> [--dependency-source <absolute-directory>]"
}

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
repo_root=$(git -C "$script_dir" rev-parse --show-toplevel 2>/dev/null || true)
release_ref=''
output_dir=''
dependency_source=''

while (($#)); do
    case "$1" in
        --ref)
            release_ref=${2:-}
            shift 2
            ;;
        --output)
            output_dir=${2:-}
            shift 2
            ;;
        --dependency-source)
            dependency_source=${2:-}
            shift 2
            ;;
        *)
            usage >&2
            exit 64
            ;;
    esac
done

if [[ -z "$repo_root" || -z "$release_ref" || -z "$output_dir" || "$output_dir" != /* ]]; then
    usage >&2
    exit 64
fi

if [[ -n "$(git -C "$repo_root" status --porcelain)" ]]; then
    echo "ERROR: the repository must be clean before building a production release." >&2
    exit 65
fi

commit=$(git -C "$repo_root" rev-parse --verify "${release_ref}^{commit}" 2>/dev/null || true)
if [[ -z "$commit" ]]; then
    echo "ERROR: ref is not a valid commit or tag." >&2
    exit 66
fi

case "$output_dir" in
    /|"$repo_root"|"$repo_root"/*)
        echo "ERROR: output must be an absolute directory outside the repository." >&2
        exit 67
        ;;
esac

if [[ -e "$output_dir" ]]; then
    echo "ERROR: output already exists; choose a new empty path." >&2
    exit 68
fi

work_dir=$(mktemp -d)
cleanup() {
    rm -rf -- "$work_dir"
}
trap cleanup EXIT

source_dir="$work_dir/source"
mkdir -p "$source_dir"
git -C "$repo_root" archive --format=tar "$commit" | tar -xf - -C "$source_dir"

allowlist="$source_dir/ops/production/release-allowlist.txt"
if [[ ! -f "$allowlist" ]]; then
    echo "ERROR: the selected ref has no production allowlist." >&2
    exit 69
fi

if [[ -n "$dependency_source" ]]; then
    if [[ "$dependency_source" != /* || ! -f "$dependency_source/vendor/autoload.php" ]]; then
        echo "ERROR: dependency source must be absolute and contain vendor/autoload.php." >&2
        exit 70
    fi
    cp -a "$dependency_source/vendor" "$source_dir/vendor"
    if [[ -d "$dependency_source/public/build" ]]; then
        mkdir -p "$source_dir/public"
        cp -a "$dependency_source/public/build" "$source_dir/public/build"
    fi
else
    php_binary=${PHP_BINARY:-/opt/lampp/bin/php}
    if [[ ! -x "$php_binary" ]]; then
        echo "ERROR: PHP_BINARY is not executable: $php_binary" >&2
        exit 70
    fi
    "$php_binary" "$source_dir/composer.phar" install \
        --working-dir="$source_dir" \
        --no-dev --prefer-dist --no-interaction --classmap-authoritative
    npm --prefix "$source_dir" ci --ignore-scripts
    npm --prefix "$source_dir" run build
fi

mkdir -p "$output_dir"
while IFS= read -r relative_path; do
    [[ -z "$relative_path" || "$relative_path" == \#* ]] && continue
    if [[ ! -e "$source_dir/$relative_path" ]]; then
        echo "ERROR: allowlisted path is missing: $relative_path" >&2
        exit 71
    fi
    mkdir -p "$output_dir/$(dirname "$relative_path")"
    cp -a "$source_dir/$relative_path" "$output_dir/$relative_path"
done < "$allowlist"

cp -a "$source_dir/vendor" "$output_dir/vendor"
if [[ -d "$source_dir/public/build" ]]; then
    cp -a "$source_dir/public/build" "$output_dir/public/build"
fi

# Allowlisted modules may contain historical runtime subtrees in old commits.
# Remove only explicit paths inside the newly-created, validated output directory.
rm -rf -- \
    "$output_dir/bootstrap/cache" \
    "$output_dir/RedmineMantencion/data" \
    "$output_dir/redmine-mantencion/data" \
    "$output_dir/redmine_tic/data"

mkdir -p \
    "$output_dir/storage/app" \
    "$output_dir/storage/framework/cache/data" \
    "$output_dir/storage/framework/sessions" \
    "$output_dir/storage/framework/testing" \
    "$output_dir/storage/framework/views" \
    "$output_dir/storage/logs" \
    "$output_dir/bootstrap/cache"

printf '%s\n' "$commit" > "$output_dir/RELEASE_COMMIT"
find "$output_dir" -type f ! -name MANIFEST.sha256 ! -name RELEASE_COMMIT -print0 \
    | sort -z \
    | xargs -0 sha256sum \
    | sed "s#  $output_dir/#  #" > "$output_dir/MANIFEST.sha256"
sha256sum "$output_dir/RELEASE_COMMIT" | sed "s#  $output_dir/#  #" >> "$output_dir/MANIFEST.sha256"

"$repo_root/ops/production/verify-release.sh" "$output_dir"
echo "Release created at: $output_dir"
echo "Commit: $commit"

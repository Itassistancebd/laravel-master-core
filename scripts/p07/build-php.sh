#!/usr/bin/env bash
set -Eeuo pipefail

version="${1:-}"
case "$version" in
    8.3.37) sha256='cdd2ed867cb104ececff7c0292142c807c68a6b6da1b114ead4d586fba628a4f' ;;
    8.4.28) sha256='deffc88c60fcfb99f683a9f6689e9c91cf41b7cba50bd5f01a232a98c27f2cd4' ;;
    8.5.12) sha256='81a0f450349e84b08eb43faa85f8a4c835684f3cc1c50471775d1317a046c525' ;;
    *)
        printf '%s\n' 'Unsupported P-07 PHP build target.' >&2
        exit 2
        ;;
esac

if [[ -z "${RUNNER_TEMP:-}" || -z "${GITHUB_RUN_ID:-}" || -z "${GITHUB_RUN_ATTEMPT:-}" || -z "${GITHUB_PATH:-}" ]]; then
    printf '%s\n' 'Required GitHub runner build paths or run identity are unavailable.' >&2
    exit 2
fi

prefix="$RUNNER_TEMP/p07-php-${version}-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
if [[ -e "$prefix" ]]; then
    printf '%s\n' 'The isolated P-07 PHP installation prefix already exists.' >&2
    exit 2
fi

archive="$(mktemp "$RUNNER_TEMP/php-${version}.XXXXXX.tar.gz")"
source_dir="$(mktemp -d "$RUNNER_TEMP/p07-php-source-${version}.XXXXXX")"

curl --silent --show-error --proto '=https' --tlsv1.2 --fail --location --retry 3 --retry-delay 2 \
    --output "$archive" "https://www.php.net/distributions/php-${version}.tar.gz"

actual_sha256="$(sha256sum "$archive" | awk '{print $1}')"
if [[ "$actual_sha256" != "$sha256" ]]; then
    printf 'PHP %s source archive SHA-256 verification failed (expected %s, actual %s).\n' "$version" "$sha256" "$actual_sha256" >&2
    exit 1
fi
printf 'PHP %s source archive SHA-256 verified.\n' "$version"

tar --extract --gzip --file "$archive" --directory "$source_dir" --strip-components=1
mkdir --parents "$prefix"
cd "$source_dir"

run_build_stage() {
    local stage="$1"
    shift
    local stage_log="$RUNNER_TEMP/p07-php-${version}-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}-${stage}.log"
    local stage_status

    if "$@" >"$stage_log" 2>&1; then
        printf 'PHP build stage passed: %s.\n' "$stage"
        return 0
    else
        stage_status=$?
    fi

    printf 'PHP build stage failed: %s (exit code %s). Sanitized diagnostics follow.\n' "$stage" "$stage_status" >&2
    if [[ -f "$stage_log" ]]; then
        tail -n 30 "$stage_log" | while IFS= read -r line; do
            line="${line//"$RUNNER_TEMP"/[runner-temp]}"
            line="${line//"$source_dir"/[php-source]}"
            line="${line//"$prefix"/[php-prefix]}"
            if [[ -n "${GITHUB_WORKSPACE:-}" ]]; then
                line="${line//"$GITHUB_WORKSPACE"/[workspace]}"
            fi
            line="$(sed -E 's/((PASS(WORD)?|TOKEN|SECRET|DSN)[A-Z0-9_]*[[:space:]]*[:=][[:space:]]*)[^[:space:],;]+/\1[redacted]/Ig' <<<"$line")"
            printf '%s\n' "$line" >&2
        done
    fi
    return "$stage_status"
}

run_build_stage configure ./configure \
    --prefix="$prefix" \
    --with-config-file-path="$prefix/etc" \
    --with-config-file-scan-dir="$prefix/etc/conf.d" \
    --disable-cgi \
    --enable-cli \
    --enable-pdo \
    --with-pdo-mysql=mysqlnd \
    --with-openssl \
    --with-curl \
    --with-zlib \
    --with-libxml \
    --enable-mbstring \
    --enable-xml \
    --enable-dom \
    --enable-fileinfo \
    --enable-tokenizer \
    --enable-session \
    --enable-ctype \
    --enable-filter

run_build_stage compile make -j2
run_build_stage install make install

php_bin="$prefix/bin/php"
if [[ ! -x "$php_bin" ]]; then
    printf '%s\n' 'The isolated PHP CLI binary was not installed.' >&2
    exit 1
fi

actual_version="$("$php_bin" -r 'echo PHP_VERSION;')"
if [[ "$actual_version" != "$version" ]]; then
    printf '%s\n' 'The source-built PHP runtime does not match the approved matrix target.' >&2
    exit 1
fi

"$php_bin" --version
"$php_bin" -m
"$php_bin" -i | grep -i 'mysqlnd'

"$php_bin" -r '
$required = ["ctype", "curl", "dom", "fileinfo", "filter", "hash", "json", "mbstring", "openssl", "pcre", "PDO", "session", "tokenizer", "xml"];
$missing = array_values(array_filter($required, static fn (string $name): bool => !extension_loaded($name)));
if ($missing !== []) {
    fwrite(STDERR, "Required PHP extensions are missing.\n");
    exit(1);
}
if (!in_array("mysql", PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "PDO_MYSQL is unavailable.\n");
    exit(1);
}
'

export PATH="$prefix/bin:$PATH"
if [[ "$(php -r 'echo PHP_VERSION;')" != "$version" ]]; then
    printf '%s\n' 'The isolated PHP runtime is not first on PATH.' >&2
    exit 1
fi

printf '%s\n' "$prefix/bin" >> "$GITHUB_PATH"
printf '%s\n' 'Exact PHP source build and required extension checks passed.'

#!/usr/bin/env bash
# Strict TaxID release tests for one real PHP CLI interpreter.
set -uo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_CLI="${1:-php}"
EXPECTED="${2:-}"

if [[ "$EXPECTED" != "8.4" && "$EXPECTED" != "8.5" ]]; then
    printf 'Usage: bash scripts/test-php-version.sh PHP_CLI 8.4|8.5\n' >&2
    exit 2
fi

RESULTS_DIR="${TAXID_TEST_RESULTS_DIR:-$ROOT_DIR/var/test-results}"
if ! mkdir -p "$RESULTS_DIR"; then
    printf 'Could not create results directory: %s\n' "$RESULTS_DIR" >&2
    exit 2
fi
chmod 700 "$RESULTS_DIR" 2>/dev/null || true
LOG_FILE="$RESULTS_DIR/php-$EXPECTED.log"

if [[ "$RESULTS_DIR" == "$ROOT_DIR/var/test-results" ]]; then
    # Defense in depth for the usual Apache configuration; use an external
    # TAXID_TEST_RESULTS_DIR if the source tree itself is web-accessible.
    printf 'Require all denied\n' > "$RESULTS_DIR/.htaccess"
fi

run_suite() {
    local fail=0 count=0 passed=0 syntax=0 version_id version_text output error test file

    printf 'TaxID 1.0.0 strict PHP compatibility test\n'
    printf 'Expected branch: PHP %s\n' "$EXPECTED"
    printf 'Runtime command: %s\n' "$PHP_CLI"
    printf 'Start (UTC): %s\n\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

    if ! command -v "$PHP_CLI" >/dev/null 2>&1; then
        printf '[FAIL] PHP CLI executable unavailable: %s\n' "$PHP_CLI"
        return 1
    fi
    "$PHP_CLI" -v || return 1
    version_id="$("$PHP_CLI" -r 'echo PHP_VERSION_ID;' 2>/dev/null)" || return 1
    version_text="$("$PHP_CLI" -r 'echo PHP_VERSION;' 2>/dev/null)" || return 1
    if ! [[ "$version_id" =~ ^[0-9]+$ ]]; then
        printf '[FAIL] PHP_VERSION_ID was not numeric: %s\n' "$version_id"
        return 1
    fi
    if [[ "$EXPECTED" == '8.4' ]]; then
        if (( version_id < 80427 || version_id >= 80500 )); then
            printf '[FAIL] PHP %s is outside >=8.4.27 <8.5\n' "$version_text"
            return 1
        fi
    else
        if (( version_id < 80512 || version_id >= 80600 )); then
            printf '[FAIL] PHP %s is outside >=8.5.12 <8.6\n' "$version_text"
            return 1
        fi
    fi
    printf '[OK] Actual PHP version is allowed: %s\n' "$version_text"
    if ! "$PHP_CLI" -r 'exit(extension_loaded("json") && function_exists("http_get_last_response_headers") ? 0 : 1);'; then
        printf '[FAIL] Required JSON extension or HTTP response headers API missing\n'
        return 1
    fi
    printf '[OK] Required extension and API present\n\n'

    output="$(mktemp)" || return 1
    error="$(mktemp)" || { rm -f "$output"; return 1; }
    while IFS= read -r -d '' file; do
        syntax=$((syntax + 1))
        if ! "$PHP_CLI" -d error_reporting=-1 -d display_errors=stderr -d log_errors=0 -l "$file" >"$output" 2>"$error"; then
            printf '[FAIL] Syntax: %s\n' "${file#"$ROOT_DIR"/}"
            cat "$output" "$error"
            fail=1
        elif [[ -s "$error" ]]; then
            printf '[FAIL] Warning or deprecation during lint: %s\n' "${file#"$ROOT_DIR"/}"
            cat "$error"
            fail=1
        fi
    done < <(find "$ROOT_DIR" -type f -name '*.php' -not -path "$ROOT_DIR/vendor/*" -not -path "$ROOT_DIR/var/*" -not -path "$ROOT_DIR/.git/*" -print0)
    printf '[%s] Syntax checked: %d PHP files\n\n' "$([[ "$fail" -eq 0 ]] && echo OK || echo FAIL)" "$syntax"

    for test in "$ROOT_DIR"/tests/*Test.php; do
        count=$((count + 1))
        if "$PHP_CLI" -d error_reporting=-1 -d display_errors=stderr -d display_startup_errors=1 -d log_errors=0 "$test" >"$output" 2>"$error"; then
            cat "$output"
            if [[ -s "$error" ]]; then
                printf '[FAIL] %s emitted PHP diagnostics:\n' "$(basename "$test")"
                cat "$error"
                fail=1
            else
                passed=$((passed + 1))
                printf '[OK] %s\n' "$(basename "$test")"
            fi
        else
            printf '[FAIL] %s returned a nonzero status:\n' "$(basename "$test")"
            cat "$output" "$error"
            fail=1
        fi
    done
    rm -f "$output" "$error"
    printf '\nRegression: %d/%d test files passed.\n' "$passed" "$count"
    if [[ "$count" -ne 5 ]]; then
        printf '[FAIL] Expected 5 regression test files, found %d\n' "$count"
        fail=1
    fi
    printf 'Finish (UTC): %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    if (( fail == 0 )); then
        printf 'RESULT: PASS PHP %s\n' "$EXPECTED"
        return 0
    fi
    printf 'RESULT: FAIL PHP %s\n' "$EXPECTED"
    return 1
}

run_suite > "$LOG_FILE" 2>&1
status=$?
cat "$LOG_FILE"
printf '\nSaved log: %s\n' "$LOG_FILE"
exit "$status"

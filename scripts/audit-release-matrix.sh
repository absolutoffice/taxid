#!/usr/bin/env bash
# Run all release checks twice with the specified real PHP CLI runtimes.
set -uo pipefail
umask 077
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP84="${1:-php8.4}"
PHP85="${2:-php8.5}"
RESULTS_DIR="${TAXID_TEST_RESULTS_DIR:-$ROOT_DIR/var/test-results}"
if ! mkdir -p "$RESULTS_DIR"; then
    printf 'Cannot create results directory: %s\n' "$RESULTS_DIR" >&2
    exit 2
fi
chmod 700 "$RESULTS_DIR" 2>/dev/null || true
SUMMARY="$RESULTS_DIR/summary-quality.txt"
printf 'TaxID 1.0.0 release-quality matrix\nStarted (UTC): %s\n\n' \
    "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$SUMMARY"
passed=0
for branch in '8.4' '8.5'; do
    if [[ "$branch" == '8.4' ]]; then cli="$PHP84"; else cli="$PHP85"; fi
    printf '\n=== Release-quality audit PHP %s ===\n' "$branch"
    if TAXID_TEST_RESULTS_DIR="$RESULTS_DIR" bash "$ROOT_DIR/scripts/audit-release-version.sh" "$cli" "$branch"; then
        printf 'PHP %s QUALITY: PASS\n' "$branch" >> "$SUMMARY"
        passed=$((passed + 1))
    else
        printf 'PHP %s QUALITY: FAIL\n' "$branch" >> "$SUMMARY"
    fi
done
printf '\nFinished (UTC): %s\nPassed: %d/2\n' \
    "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$passed" >> "$SUMMARY"
printf '\nSummary: %s\n' "$SUMMARY"
cat "$SUMMARY"
(( passed == 2 ))

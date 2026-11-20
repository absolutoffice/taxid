#!/usr/bin/env bash
# Run TaxID's strict release tests under real PHP 8.4 and 8.5 runtimes.
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

passed=0
summary="$RESULTS_DIR/summary.txt"
printf 'TaxID 1.0.0 PHP release compatibility matrix\nStart (UTC): %s\n\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" > "$summary"

for branch in '8.4' '8.5'; do
    if [[ "$branch" == '8.4' ]]; then
        executable="$PHP84"
    else
        executable="$PHP85"
    fi
    printf '\n=== PHP %s ===\n' "$branch"
    if TAXID_TEST_RESULTS_DIR="$RESULTS_DIR" bash "$ROOT_DIR/scripts/test-php-version.sh" "$executable" "$branch"; then
        printf 'PHP %s: PASS\n' "$branch" >> "$summary"
        passed=$((passed + 1))
    else
        printf 'PHP %s: FAIL\n' "$branch" >> "$summary"
    fi
done
printf '\nCompleted (UTC): %s\nTotal: %d/2 PHP runtimes passed.\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$passed" >> "$summary"
printf '\nSummary: %s\n' "$summary"
cat "$summary"
if (( passed == 2 )); then
    exit 0
fi
exit 1

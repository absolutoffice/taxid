#!/usr/bin/env bash
# Release-quality checks under one real, explicitly selected PHP interpreter.
# All Composer work is isolated in /tmp; the source tree is not modified.
set -uo pipefail
umask 077

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_CLI="${1:-php}"
BRANCH="${2:-}"
RESULTS_DIR="${TAXID_TEST_RESULTS_DIR:-$ROOT_DIR/var/test-results}"

if [[ "$BRANCH" != '8.4' && "$BRANCH" != '8.5' ]]; then
    printf 'Usage: bash scripts/audit-release-version.sh PHP_CLI 8.4|8.5\n' >&2
    exit 2
fi
if ! mkdir -p "$RESULTS_DIR"; then
    printf 'Unable to create results directory: %s\n' "$RESULTS_DIR" >&2
    exit 2
fi
chmod 700 "$RESULTS_DIR" 2>/dev/null || true
if [[ "$RESULTS_DIR" == "$ROOT_DIR/var/test-results" ]]; then
    printf 'Require all denied\n' > "$RESULTS_DIR/.htaccess"
fi
LOG_FILE="$RESULTS_DIR/php-$BRANCH-quality.log"

QUALITY_FAILURES=0
WORK_DIR=''
cleanup() {
    if [[ -n "$WORK_DIR" && -d "$WORK_DIR" ]]; then
        rm -rf -- "$WORK_DIR"
    fi
}
trap cleanup EXIT

run_stage() {
    local name="$1"
    shift
    printf '\n===== %s =====\n' "$name"
    if "$@"; then
        printf '[PASS] %s\n' "$name"
    else
        local exit_code=$?
        printf '[FAIL] %s (exit %s)\n' "$name" "$exit_code"
        QUALITY_FAILURES=$((QUALITY_FAILURES + 1))
    fi
}

run_composer() {
    (cd "$1" && "$PHP_CLI" "$COMPOSER_EXECUTABLE" "${@:2}")
}

check_runtime() {
    local id version
    if ! command -v "$PHP_CLI" >/dev/null 2>&1; then
        printf 'PHP interpreter not found: %s\n' "$PHP_CLI"
        return 1
    fi
    "$PHP_CLI" -v
    id="$("$PHP_CLI" -r 'echo PHP_VERSION_ID;' 2>/dev/null)" || return 1
    version="$("$PHP_CLI" -r 'echo PHP_VERSION;' 2>/dev/null)" || return 1
    if ! [[ "$id" =~ ^[0-9]+$ ]]; then
        return 1
    fi
    if [[ "$BRANCH" == '8.4' ]]; then
        (( id >= 80427 && id < 80500 )) || return 1
    else
        (( id >= 80512 && id < 80600 )) || return 1
    fi
    printf 'Allowed PHP runtime: %s\n' "$version"
}

prepare_source_and_archive() {
    mkdir -p "$WORK_DIR/source" "$WORK_DIR/dist" "$WORK_DIR/consumer"
    (cd "$ROOT_DIR" && tar \
        --exclude='./.git' \
        --exclude='./vendor' \
        --exclude='./var' \
        --exclude='./composer.lock' \
        --exclude='./.env' \
        --exclude='./.env.*' \
        -cf - .) | (cd "$WORK_DIR/source" && tar -xf -) || return 1
    # Template is public; it is safe to keep in the snapshot.
    cp "$ROOT_DIR/.env.example" "$WORK_DIR/source/.env.example" || return 1
    git -C "$WORK_DIR/source" init -q || return 1
    git -C "$WORK_DIR/source" add -A || return 1
    git -C "$WORK_DIR/source" -c user.name='TaxID Audit' \
        -c user.email='audit@example.invalid' commit -qm 'Temporary release audit snapshot' || return 1
    (cd "$WORK_DIR/source" && git archive --format=tar HEAD) \
        | (cd "$WORK_DIR/dist" && tar -xf -) || return 1
}

check_archive() {
    local p
    for p in composer.json README.md LICENSE COPYING bootstrap.php \
        src/TaxId/TaxIdFactory.php \
        docs/manual-usuario-taxid.pdf docs/manual-do-utilizador-taxid.pdf \
        docs/taxid-user-manual.pdf docs/manuel-utilisateur-taxid.pdf; do
        if [[ ! -f "$WORK_DIR/dist/$p" ]]; then
            printf 'Distribution missing required file: %s\n' "$p"
            return 1
        fi
    done
    for p in tests .github scripts var vendor composer.lock .env \
        phpstan.neon .php-cs-fixer.dist.php phpdoc.dist.xml; do
        if [[ -e "$WORK_DIR/dist/$p" ]]; then
            printf 'Distribution contains excluded file or directory: %s\n' "$p"
            return 1
        fi
    done
    printf 'git archive includes sources, four manuals and license texts; excludes dev/private files.\n'
}

install_dev() {
    # Try distribution archives first. GitHub may temporarily reject a ZIP with HTTP 429.
    # In that case, retry only once using Git source checkouts and the resolved lock file.
    local install_log="$WORK_DIR/composer-install-dist.log"
    if run_composer "$WORK_DIR/source" install --prefer-dist --no-interaction --no-progress --no-scripts > "$install_log" 2>&1; then
        cat "$install_log"
        return 0
    fi
    cat "$install_log"
    if grep -Eiq '(HTTP/[0-9.]+[[:space:]]+429|429 Too Many Requests|rate.limit)' "$install_log"; then
        printf '[WARN] GitHub download limit (HTTP 429). Retrying once with --prefer-source.\n'
        run_composer "$WORK_DIR/source" install --prefer-source --no-interaction --no-progress --no-scripts
        return $?
    fi
    return 1
}

run_static_analysis() {
    (cd "$WORK_DIR/source" && "$PHP_CLI" vendor/bin/phpstan analyse --no-progress --error-format=raw)
}

run_style_check() {
    (cd "$WORK_DIR/source" && "$PHP_CLI" vendor/bin/php-cs-fixer check --using-cache=no --diff)
}

make_consumer() {
    cat > "$WORK_DIR/consumer/composer.json" <<'JSON'
{
    "name": "taxid-audit/consumer",
    "description": "Isolated TaxID release audit consumer",
    "type": "project",
    "repositories": [
        {
            "type": "path",
            "url": "../dist",
            "options": {
                "symlink": false,
                "versions": {"absolutoffice/taxid": "1.0.0"}
            }
        }
    ],
    "require": {"absolutoffice/taxid": "1.0.0"},
    "minimum-stability": "stable",
    "prefer-stable": true
}
JSON
    run_composer "$WORK_DIR/consumer" install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts || return 1
    run_composer "$WORK_DIR/consumer" check-platform-reqs --no-dev || return 1
    (cd "$WORK_DIR/consumer" && "$PHP_CLI" -d error_reporting=-1 \
        -d display_errors=stderr -d display_startup_errors=1 -r '
        require "vendor/autoload.php";
        $taxid = \TaxId\TaxIdFactory::create();
        if (!$taxid->validate("ES", "12345678Z")->isValid()) {
            throw new RuntimeException("ES validation failed in consumer");
        }
        if (!$taxid->validate("PT", "501442600")->isValid()) {
            throw new RuntimeException("PT validation failed in consumer");
        }
        echo "PASS: isolated Composer consumer and PSR-4 autoload", PHP_EOL;
    ')
}

run_quality() {
    printf 'TaxID 1.0.0 release quality audit\nPHP branch: %s\nStarted (UTC): %s\n' \
        "$BRANCH" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
    if ! check_runtime; then
        printf 'RESULT: FAIL PHP %s QUALITY (unsupported or missing interpreter)\n' "$BRANCH"
        return 1
    fi
    COMPOSER_EXECUTABLE="${TAXID_COMPOSER:-$(command -v composer || true)}"
    if [[ -z "$COMPOSER_EXECUTABLE" || ! -f "$COMPOSER_EXECUTABLE" ]]; then
        printf 'Composer not found. Set TAXID_COMPOSER to a Composer PHP entrypoint or composer.phar.\n'
        printf 'RESULT: FAIL PHP %s QUALITY (no Composer)\n' "$BRANCH"
        return 1
    fi
    if ! "$PHP_CLI" "$COMPOSER_EXECUTABLE" --version --no-ansi; then
        printf 'Cannot run Composer with %s; TAXID_COMPOSER must identify a PHP entrypoint.\n' "$PHP_CLI"
        printf 'RESULT: FAIL PHP %s QUALITY (Composer invocation)\n' "$BRANCH"
        return 1
    fi
    WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/taxid-release-${BRANCH//./}-XXXXXXXX")" || return 1
    # Reuse downloaded package archives between PHP branches and audit reruns.
    # Keep the cache outside the served source directory when possible.
    export COMPOSER_CACHE_DIR="${TAXID_COMPOSER_CACHE_DIR:-$RESULTS_DIR/.composer-cache}"
    if ! mkdir -p -- "$COMPOSER_CACHE_DIR"; then
        printf 'Cannot create Composer cache: %s\n' "$COMPOSER_CACHE_DIR"
        return 1
    fi
    chmod 700 "$COMPOSER_CACHE_DIR" 2>/dev/null || true
    printf 'Composer cache: %s\n' "$COMPOSER_CACHE_DIR"
    export COMPOSER_NO_INTERACTION=1
    if ! prepare_source_and_archive; then
        printf 'RESULT: FAIL PHP %s QUALITY (temporary snapshot)\n' "$BRANCH"
        return 1
    fi
    run_stage 'Strict runtime regression' bash "$ROOT_DIR/scripts/test-php-version.sh" "$PHP_CLI" "$BRANCH"
    run_stage 'Composer validate (strict)' run_composer "$WORK_DIR/source" validate --strict --no-check-lock
    run_stage 'Git archive distribution integrity' check_archive
    if install_dev; then
        printf '[PASS] Composer install (development dependencies)\n'
        run_stage 'Composer platform requirements (real PHP)' run_composer "$WORK_DIR/source" check-platform-reqs
        run_stage 'Composer optimized PSR-4 autoload' run_composer "$WORK_DIR/source" dump-autoload --optimize --strict-psr
        run_stage 'Composer audit (runtime and development)' run_composer "$WORK_DIR/source" audit
        run_stage 'PHPStan maximum-level analysis' run_static_analysis
        run_stage 'PHP-CS-Fixer check (no modifications)' run_style_check
    else
        printf '[FAIL] Composer install (development dependencies)\n'
        QUALITY_FAILURES=$((QUALITY_FAILURES + 1))
        printf '[SKIP] PHPStan, PHP-CS-Fixer, Composer audit and platform checks need installation.\n'
    fi
    run_stage 'Independent consumer installs from git archive' make_consumer
    printf '\nFinished (UTC): %s\nFailed quality stages: %d\n' \
        "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$QUALITY_FAILURES"
    if (( QUALITY_FAILURES != 0 )); then
        printf 'RESULT: FAIL PHP %s QUALITY\n' "$BRANCH"
        return 1
    fi
    printf 'RESULT: PASS PHP %s QUALITY\n' "$BRANCH"
}

run_quality > "$LOG_FILE" 2>&1
status=$?
cat "$LOG_FILE"
printf '\nSaved quality log: %s\n' "$LOG_FILE"
exit "$status"

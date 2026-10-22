#!/usr/bin/env bash
set -euo pipefail
repository="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
php_cli="${PHP_PLAIN:-php}"
if ! "$php_cli" -n -r 'exit(extension_loaded("swoole") ? 1 : 0);'; then
    echo 'Set PHP_PLAIN to a conventional PHP CLI: the unit doubles cannot use a statically built Swoole.' >&2
    exit 1
fi
test_tree="$(mktemp -d)"
trap 'rm -rf -- "$test_tree"' EXIT
cd "$repository"
git archive 894e52c | tar -x -C "$test_tree"
if SECURITY_SOURCE="$test_tree" SECURITY_BASELINE_SUBSET=1 "$php_cli" -n scripts/security/http-regression.php > "$test_tree/before.txt"; then
    echo 'Baseline unexpectedly passed' >&2
    exit 1
fi
rg -q '^18 assertions, 17 failures$' "$test_tree/before.txt"
SECURITY_BASELINE_SUBSET=1 "$php_cli" -n scripts/security/http-regression.php > "$test_tree/after.txt"
rg -q '^18 assertions, 0 failures$' "$test_tree/after.txt"
echo 'Reproduced: original 17/18 failures; identical corrected subset 0/18 failures.'
echo 'The destructive-command spy and every filesystem fixture stay in private temporary directories.'

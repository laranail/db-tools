#!/usr/bin/env bash
# .scripts/init.sh — single bootstrap entrypoint for laranail/db-tools.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

err()  { printf '\033[31m✗\033[0m %s\n' "$*" >&2; }
ok()   { printf '\033[32m✓\033[0m %s\n'   "$*"; }
info() { printf '\033[34mi\033[0m %s\n'   "$*"; }

command -v php >/dev/null      || { err "php not found";       exit 2; }
command -v composer >/dev/null || { err "composer not found";  exit 2; }

php_version=$(php -r 'echo PHP_VERSION;')
php_major_minor=$(printf '%s' "$php_version" | cut -d. -f1-2)
if [ "$(printf '%s\n8.3' "$php_major_minor" | sort -V | head -1)" != "8.3" ]; then
    err "PHP 8.3+ required (found $php_version)"
    exit 2
fi
ok "php $php_version"

if [ "${INIT_PROD:-0}" = "1" ]; then
    composer install --no-dev --no-interaction --prefer-dist
else
    composer install --no-interaction --prefer-dist
fi
ok "composer install complete"

printf '\n──────────────────────────────────────────\n'
printf '\033[1mlaranail/db-tools\033[0m setup complete\n\n'
printf 'Available composer aliases:\n'
printf '  composer test  composer lint  composer audit\n\n'
printf 'Docs: https://opensource.simtabi.com/db-tools/docs/\n'
printf '──────────────────────────────────────────\n'

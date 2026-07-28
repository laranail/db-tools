#!/usr/bin/env bash
# .scripts/check-branch-alias.sh — assert composer.json's branch-alias tracks the release line.
#
# A stale `extra.branch-alias` is silently dangerous: the default branch keeps
# advertising an old minor, so Composer offers `main` to consumers whose
# constraint targets that old minor — and hands them whatever breaking work has
# landed since. It has happened here: v0.5.0 and v0.5.1 shipped while the alias
# still read 0.4.x-dev, so `^0.4` consumers could resolve `main` and receive
# 0.5.0's SchemaReadinessInterface::flush() break.
#
# Two modes, because "correct" differs by context:
#
#   --release <tag>   The alias minor must EQUAL the tag's minor. Used at release
#                     time, where any drift is by definition the bug above.
#   (no arguments)    The alias minor must be >= the newest existing tag's minor.
#                     Used on main, where the alias legitimately runs ahead of the
#                     last tag while the next minor is in development, but must
#                     never fall behind it.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

err()  { printf '\033[31m✗\033[0m %s\n' "$*" >&2; }
ok()   { printf '\033[32m✓\033[0m %s\n'   "$*"; }
info() { printf '\033[34mi\033[0m %s\n'   "$*"; }

command -v php >/dev/null || { err "php not found"; exit 2; }

# --- read the alias -----------------------------------------------------------
# Any dev-* key is accepted; the default branch is whichever one is declared.
read -r ALIAS_BRANCH ALIAS_VERSION <<<"$(php -r '
    $file = "composer.json";
    $json = json_decode((string) file_get_contents($file), true);
    if (! is_array($json)) { fwrite(STDERR, "cannot parse composer.json\n"); exit(2); }
    $aliases = $json["extra"]["branch-alias"] ?? [];
    if (! is_array($aliases) || $aliases === []) { echo "NONE NONE\n"; exit(0); }
    $branch = array_key_first($aliases);
    echo $branch . " " . $aliases[$branch] . "\n";
')"

if [ "$ALIAS_BRANCH" = "NONE" ]; then
    err "composer.json declares no extra.branch-alias"
    err "Add one so the default branch advertises the minor it is actually developing."
    exit 1
fi

# 0.5.x-dev -> 0.5 ; 1.2.x-dev -> 1.2
alias_minor() { printf '%s' "${1%%.x-dev}"; }

ALIAS_MINOR="$(alias_minor "$ALIAS_VERSION")"

if ! printf '%s' "$ALIAS_MINOR" | grep -Eq '^[0-9]+\.[0-9]+$'; then
    err "branch-alias \"$ALIAS_BRANCH\": \"$ALIAS_VERSION\" is not of the form MAJOR.MINOR.x-dev"
    exit 1
fi

# --- resolve what we are comparing against ------------------------------------
MODE="drift"
TARGET=""

if [ "${1:-}" = "--release" ]; then
    MODE="release"
    TARGET="${2:-}"
    [ -n "$TARGET" ] || { err "--release requires a tag, e.g. --release v0.5.2"; exit 2; }
elif [ -n "${1:-}" ]; then
    # Bare argument is shorthand for --release, so `check-branch-alias.sh v0.5.2` works.
    MODE="release"
    TARGET="$1"
else
    TARGET="$(git tag --sort=-v:refname | head -n 1)"
    if [ -z "$TARGET" ]; then
        info "no tags yet — nothing to compare against"
        ok "branch-alias $ALIAS_BRANCH => $ALIAS_VERSION"
        exit 0
    fi
fi

TARGET_VERSION="${TARGET#v}"
TARGET_MINOR="$(printf '%s' "$TARGET_VERSION" | cut -d. -f1,2)"

if ! printf '%s' "$TARGET_MINOR" | grep -Eq '^[0-9]+\.[0-9]+$'; then
    err "cannot read a MAJOR.MINOR out of \"$TARGET\""
    exit 2
fi

# --- compare ------------------------------------------------------------------
if [ "$MODE" = "release" ]; then
    if [ "$ALIAS_MINOR" != "$TARGET_MINOR" ]; then
        err "branch-alias is stale for this release."
        err "  composer.json declares $ALIAS_BRANCH => $ALIAS_VERSION (line $ALIAS_MINOR)"
        err "  but the tag being released is $TARGET (line $TARGET_MINOR)"
        err "Set the alias to ${TARGET_MINOR}.x-dev before tagging, or consumers"
        err "constrained to ^${ALIAS_MINOR} will be offered ${TARGET_MINOR} code from the default branch."
        exit 1
    fi
    ok "branch-alias $ALIAS_BRANCH => $ALIAS_VERSION matches release $TARGET"
    exit 0
fi

# drift mode: the alias may run ahead of the newest tag, never behind it.
LOWEST="$(printf '%s\n%s\n' "$ALIAS_MINOR" "$TARGET_MINOR" | sort -V | head -n 1)"

if [ "$ALIAS_MINOR" != "$TARGET_MINOR" ] && [ "$LOWEST" = "$ALIAS_MINOR" ]; then
    err "branch-alias has fallen behind the newest tag."
    err "  composer.json declares $ALIAS_BRANCH => $ALIAS_VERSION (line $ALIAS_MINOR)"
    err "  but the newest tag is $TARGET (line $TARGET_MINOR)"
    err "The default branch is advertising ${ALIAS_MINOR} while shipping ${TARGET_MINOR} code."
    err "Consumers constrained to ^${ALIAS_MINOR} can resolve main and receive it."
    exit 1
fi

if [ "$ALIAS_MINOR" = "$TARGET_MINOR" ]; then
    ok "branch-alias $ALIAS_BRANCH => $ALIAS_VERSION matches newest tag $TARGET"
else
    ok "branch-alias $ALIAS_BRANCH => $ALIAS_VERSION runs ahead of newest tag $TARGET"
fi

#!/usr/bin/env bash
#
# While pre-1.0, laranail packages keep one tag per line and move it, and
# consumers resolve `^0.1` to whatever it currently points at. That makes "the
# tag is on main" an invariant rather than a preference: a tag left behind does
# not mean consumers get older features, it means they get code without the
# fixes.
#
# Three checks, because this package has now shipped every one of these failures.
#
#   1. CURRENCY — the tag constrained consumers resolve must be on main.
#
#      Anchored on `extra.branch-alias`, NOT on the highest tag. That
#      distinction is the whole point of this rewrite. laranail/enumerator
#      carried v0.1.0 through v0.4.0; v0.4.0 pointed at main HEAD, so a check
#      that took the highest tag reported everything healthy — while all nine
#      consumers, every one of them on `^0.1`, resolved v0.1.0 two commits
#      behind and silently missed a preset and an ordering bugfix. `^0.1` on a
#      0.x package means `>=0.1.0 <0.2.0`; a v0.4.0 they cannot reach says
#      nothing about what they get.
#
#      The package's own `branch-alias` declares which line is live —
#      `0.1.x-dev` here, `0.7.x-dev` in db-tools — so the check reads it rather
#      than guessing.
#
#   2. REACHABILITY — every tag must be an ancestor of the default branch.
#
#      Composer's VCS driver reads tags, not reachability. This package
#      advertised v0.2.0, v0.2.1 and v0.3.0 pointing at abandoned history, so
#      anyone writing `^0.2` got code that had been discarded months earlier,
#      with nothing anywhere saying so.
#
#   3. THE HIGHEST TAG — what an unconstrained `composer require` resolves.
#
#      Anchoring on branch-alias alone traded one blind spot for another. This
#      package kept v0.4.0 three commits behind main while v0.1.0 was current,
#      and a check that only knew about the live line called that healthy — yet
#      v0.4.0 is exactly what a new consumer who names no constraint installs.
#      Both are checked. The third only reports when it names a different tag,
#      so an ordinary single-tag package does not hear it twice.
#
# Runs against the remote rather than the local checkout, so a stale local tag
# cannot make it pass, and so it needs no particular fetch depth in CI.
#
# Only meaningful pre-1.0. From 1.0 the tag is immutable by design and this
# exits without an opinion.

set -euo pipefail

REPO="${1:-}"
BRANCH="${2:-main}"

if [ -z "${REPO}" ]; then
  echo "usage: $(basename "$0") <owner/repo> [branch]" >&2
  exit 2
fi

fail() { printf '\033[31m  x %s\033[0m\n' "$1"; FAILED=1; }
ok()   { printf '\033[32m  ok %s\033[0m\n' "$1"; }
FAILED=0

echo "  Release currency for ${REPO}"
echo

# An empty repository answers 409 to every ref query. Nothing is published, so
# nothing can be behind; treating that as a failure reports a defect where there
# is not even code yet.
if ! gh api "repos/${REPO}/git/refs/heads" >/dev/null 2>&1; then
  ok "No commits yet, so there is nothing to be behind."
  exit 0
fi

head=$(gh api "repos/${REPO}/git/ref/heads/${BRANCH}" --jq '.object.sha')

# Annotated tags point at a tag object; dereference to the commit it wraps.
tag_commit() {
  local ref obj type
  ref=$(gh api "repos/${REPO}/git/ref/tags/$1" 2>/dev/null) || return 1
  obj=$(printf '%s' "${ref}" | jq -r '.object.sha')
  type=$(printf '%s' "${ref}" | jq -r '.object.type')

  if [ "${type}" = "tag" ]; then
    gh api "repos/${REPO}/git/tags/${obj}" --jq '.object.sha'
  else
    printf '%s\n' "${obj}"
  fi
}

# Not mapfile: macOS ships bash 3.2, which does not have it, and this has to run
# on a maintainer's laptop as well as in CI.
tags=$(gh api "repos/${REPO}/git/matching-refs/tags/v" --jq '.[].ref | sub("refs/tags/"; "")' 2>/dev/null | sort -V)

if [ -z "${tags}" ]; then
  ok "No v* tag, so nothing is pinned."
  exit 0
fi

# ---------------------------------------------------------------------------
# 1. Reachability
# ---------------------------------------------------------------------------
echo "  Tags"

for tag in ${tags}; do
  commit=$(tag_commit "${tag}") || { fail "${tag} could not be resolved."; continue; }

  # compare returns "identical", "ahead", "behind" or "diverged". Anything but
  # behind-or-identical means the tag is not on this branch's history.
  status=$(gh api "repos/${REPO}/compare/${commit}...${head}" --jq '.status' 2>/dev/null || echo 'unknown')

  case "${status}" in
    identical|ahead) printf '    %-10s %s  on %s\n' "${tag}" "${commit:0:12}" "${BRANCH}" ;;
    *)               fail "${tag} (${commit:0:12}) is not an ancestor of ${BRANCH} — it points at abandoned history, and Composer will still offer it." ;;
  esac
done
echo

# ---------------------------------------------------------------------------
# 2. Currency, on the line this package declares as live
# ---------------------------------------------------------------------------
line=$(gh api "repos/${REPO}/contents/composer.json" --jq '.content' 2>/dev/null \
        | base64 --decode \
        | jq -r '.extra["branch-alias"]["dev-'"${BRANCH}"'"] // empty' \
        | sed 's/\.x-dev$//')

if [ -z "${line}" ]; then
  ok "No branch-alias for dev-${BRANCH}, so no line is declared live."
  [ "${FAILED}" -eq 0 ] && exit 0 || exit 1
fi

current=$(printf '%s\n' "${tags}" | { grep -E "^v${line}\." || true; } | sort -V | tail -1)

echo "  Live line: ${line}.x (from extra.branch-alias)"

if [ -z "${current}" ]; then
  fail "branch-alias declares ${line}.x-dev but no v${line}.* tag exists, so \`^${line}\` resolves nothing."
  exit 1
fi

commit=$(tag_commit "${current}")

echo "    ${current} -> ${commit:0:12}"
echo "    ${BRANCH}  -> ${head:0:12}"
echo

if [ "${commit}" = "${head}" ]; then
  ok "${current} is on ${BRANCH}; consumers on ^${line} resolve current code."

  # ---------------------------------------------------------------------
  # 3. The highest tag overall, for consumers who named no constraint
  # ---------------------------------------------------------------------
  #
  # `composer require laranail/enumerator` with no constraint resolves the
  # highest stable tag, which is not necessarily the branch-alias line. This
  # package had v0.4.0 sitting two commits behind main while v0.1.0 was current,
  # so a check anchored only on branch-alias called it healthy and said nothing
  # about the tag an unconstrained require would actually install.
  #
  # The original version of this script checked only the highest tag and missed
  # the branch-alias line. Replacing one with the other traded one blind spot
  # for another; both are checked.
  highest=$(printf '%s\n' "${tags}" | sort -V | tail -1)

  if [ "${highest}" != "${current}" ]; then
    hcommit=$(tag_commit "${highest}")

    if [ "${hcommit}" = "${head}" ]; then
      ok "${highest} is also on ${BRANCH}."
    else
      hbehind=$(gh api "repos/${REPO}/compare/${hcommit}...${head}" --jq '.ahead_by' 2>/dev/null || echo '?')
      fail "${highest} is the highest tag and is ${hbehind} commit(s) behind ${BRANCH}. An unconstrained \`composer require ${REPO}\` resolves it, so it must be current or it must not exist."
    fi
  fi
else
  behind=$(gh api "repos/${REPO}/compare/${commit}...${head}" --jq '.ahead_by' 2>/dev/null || echo '?')

  printf '  Commits on %s not in %s: %s\n' "${BRANCH}" "${current}" "${behind}"
  gh api "repos/${REPO}/compare/${commit}...${head}" \
    --jq '.commits[] | "    " + .sha[0:8] + "  " + (.commit.message | split("\n")[0])' 2>/dev/null || true
  echo

  fail "${current} is behind ${BRANCH}. Move it (git tag -f ${current} ${BRANCH} && git push --force origin ${current}) or cut a new one."
fi

exit "${FAILED}"

#!/usr/bin/env bash
#
# While pre-1.0, laranail packages keep one tag and move it, and consumers
# resolve `^0.1` to whatever it currently points at. That makes "the tag is on
# main" an invariant rather than a preference: a tag left behind does not mean
# consumers get older features, it means they get code without the fixes.
#
# This package shipped that. v0.1.0 sat one commit behind main, so `^0.1`
# resolved to a tree without the effect allowlist, which is a security fix.
# Nothing said so; the release page looked perfectly healthy.
#
# Runs against the remote rather than the local checkout, so a stale local tag
# cannot make it pass, and so it needs no particular fetch depth in CI.
#
# Only meaningful pre-1.0. From 1.0 the tag is immutable by design and this
# exits without an opinion.

set -euo pipefail

REPO="${1:-laranail/confetti}"

fail() { printf '\033[31m  x %s\033[0m\n' "$1"; exit 1; }
ok()   { printf '\033[32m  ok %s\033[0m\n' "$1"; }

echo "  Release currency for ${REPO}"
echo

# An empty repository answers 409 to every ref query. Nothing is published, so
# nothing can be behind; treating that as a failure reports a defect where there
# is not even code yet.
if ! gh api "repos/${REPO}/git/refs/heads" >/dev/null 2>&1; then
  ok "No commits yet, so there is nothing to be behind."
  exit 0
fi

# The highest v0.* tag: the moving pre-1.0 tag this convention describes.
tag=$(gh api "repos/${REPO}/git/matching-refs/tags/v0." \
        --jq '.[].ref | sub("refs/tags/"; "")' 2>/dev/null \
      | sort -V | tail -1)

if [ -z "${tag}" ]; then
  ok "No v0.* tag, so nothing is pinned pre-1.0."
  exit 0
fi

# Annotated tags point at a tag object; dereference to the commit.
obj=$(gh api "repos/${REPO}/git/ref/tags/${tag}" --jq '.object.sha')
type=$(gh api "repos/${REPO}/git/ref/tags/${tag}" --jq '.object.type')

if [ "${type}" = "tag" ]; then
  commit=$(gh api "repos/${REPO}/git/tags/${obj}" --jq '.object.sha')
else
  commit="${obj}"
fi

head=$(gh api "repos/${REPO}/git/ref/heads/main" --jq '.object.sha')

echo "  ${tag} -> ${commit:0:12}"
echo "  main  -> ${head:0:12}"
echo

if [ "${commit}" = "${head}" ]; then
  ok "${tag} is on main; consumers on ^0.1 resolve current code."
  exit 0
fi

# Say how far behind, because "behind by a merge you have not tagged yet" and
# "behind by a security fix" read identically otherwise.
behind=$(gh api "repos/${REPO}/compare/${commit}...${head}" --jq '.ahead_by' 2>/dev/null || echo '?')

printf '  Commits on main not in %s: %s\n' "${tag}" "${behind}"
gh api "repos/${REPO}/compare/${commit}...${head}" \
  --jq '.commits[] | "    " + .sha[0:8] + "  " + (.commit.message | split("\n")[0])' 2>/dev/null || true
echo

fail "${tag} is behind main. Move it (git tag -f ${tag} main && git push --force origin ${tag}) or cut a new one."

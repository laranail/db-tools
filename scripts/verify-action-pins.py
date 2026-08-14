#!/usr/bin/env python3
"""Check that every SHA-pinned GitHub Action is honest and current.

Pinning an action to a commit SHA is the supply-chain control; the trailing
`# v1.2.3` is the only thing that makes it readable. Nothing keeps the two in
step, so a hand-edited pin can claim one version and run another, and the
comment is what a reviewer reads. This package shipped exactly that defect:
a CodeQL pin labelled v3.28.0 whose SHA was a v4 commit.

Three failure modes, all reported:

  MISMATCH  the tag in the comment resolves to a different commit
  UNKNOWN   the tag does not exist upstream, so the comment is unverifiable
  STALE     a newer release has been out long enough that it should have been
            taken already

UNKNOWN is not pedantry. `shivammathur/setup-php` tags without a leading `v`,
so a `# v2.34.1` comment on a correct SHA is unverifiable while looking fine.

STALE exists because a pin can be perfectly self-consistent and still fourteen
months old, which is what `setup-php` was here. Dependabot is what should keep
these current; this is the backstop for when it quietly does not. It had not
offered the update because the github-actions ecosystem sat on the default
limit of five open pull requests and had filed exactly five.

The staleness threshold is deliberately generous. Failing the build the day
after an upstream release would punish a pin nobody has had a chance to take,
and a check that cries wolf gets ignored, which is the failure it exists to
prevent. GRACE_DAYS gives Dependabot's weekly schedule several attempts first.

Queries through `gh`, which is present on GitHub runners and already
authenticated locally, so the same command works in both places.
"""

from __future__ import annotations

import json
import re
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

PIN = re.compile(
    r"uses:\s*([\w.-]+)/([\w.-]+)((?:/[\w.-]+)*)@([0-9a-f]{40})\s*#\s*(\S+)"
)

WORKFLOWS = Path(__file__).resolve().parent.parent / ".github" / "workflows"

# How long a newer release may sit untaken before this fails. Dependabot runs
# weekly, so this is several missed chances rather than one.
GRACE_DAYS = 45


class Unavailable(Exception):
    """The API could not answer, as distinct from answering 'no such tag'."""


def api(path: str) -> dict | None:
    result = subprocess.run(
        ["gh", "api", path], capture_output=True, text=True, timeout=60
    )
    if result.returncode == 0:
        return json.loads(result.stdout)
    if "Not Found" in result.stderr or "404" in result.stderr:
        return None
    raise Unavailable(result.stderr.strip().splitlines()[0] if result.stderr else "?")


VERSION = re.compile(r"^v?(\d+(?:\.\d+)*)$")


def version_of(tag: str) -> tuple[int, ...] | None:
    """A comparable version, or None when the tag is not a plain version.

    Guards against comparing across tag series. `github/codeql-action` pins as
    `v4.37.7` but publishes its releases as `codeql-bundle-v2.26.3`, and taking
    the latter as "the latest version" would report a correct pin as years
    behind. A tag that does not parse is simply not compared.
    """
    match = VERSION.match(tag)
    return tuple(int(part) for part in match.group(1).split(".")) if match else None


def staleness(owner: str, repo: str, tag: str, sha: str) -> tuple[str, int] | None:
    """The newest release, and how many days it has been out, if it beats ours.

    Uses releases rather than tags: tags include release candidates and the
    per-major floating aliases (`v4`) that actions publish, and treating one of
    those as "latest" would report every correct pin as behind.

    The decisive comparison is the commit, not the version. A pin commented `# v2`
    against a floating major alias is current whenever that SHA is what the newest
    release points at, however far apart the two names read. Comparing names alone
    reported laranail/package-tools as a year behind while it was pinned to the
    exact commit of the newest release.
    """
    latest = api(f"/repos/{owner}/{repo}/releases/latest")
    if latest is None or latest.get("draft") or latest.get("prerelease"):
        return None

    name = latest.get("tag_name")
    published = latest.get("published_at")
    if not name or not published:
        return None

    # Already on the newest release's commit, whatever the comment calls it.
    if commit_for_tag(owner, repo, name) == sha:
        return None

    # Different commit: only claim "behind" when the versions say so, since a
    # repo can publish releases out of order or from parallel branches.
    ours, theirs = version_of(tag), version_of(name)
    if ours is None or theirs is None or theirs <= ours:
        return None

    age = datetime.now(timezone.utc) - datetime.fromisoformat(
        published.replace("Z", "+00:00")
    )
    return name, age.days


def commit_for_tag(owner: str, repo: str, tag: str) -> str | None:
    """Resolve a tag to a commit SHA, dereferencing annotated tags."""
    ref = api(f"/repos/{owner}/{repo}/git/ref/tags/{tag}")
    if ref is None:
        return None

    obj = ref["object"]
    if obj["type"] != "tag":
        return obj["sha"]

    # An annotated tag points at a tag object, not the commit. Actions
    # resolves the commit, so that is what the pin has to equal.
    return api(f"/repos/{owner}/{repo}/git/tags/{obj['sha']}")["object"]["sha"]


def main() -> int:
    if shutil.which("gh") is None:
        print("  gh is not installed; cannot verify action pins.")
        return 1

    pins: dict[tuple[str, str, str, str, str], set[str]] = {}
    for workflow in sorted(WORKFLOWS.glob("*.yml")):
        for owner, repo, sub, sha, tag in PIN.findall(workflow.read_text()):
            pins.setdefault((owner, repo, sub, sha, tag), set()).add(workflow.name)

    if not pins:
        print("  No SHA-pinned actions found, which is itself suspicious.")
        return 1

    failures = 0
    for (owner, repo, sub, sha, tag), files in sorted(pins.items()):
        name = f"{owner}/{repo}{sub}"
        where = ", ".join(sorted(files))
        try:
            actual = commit_for_tag(owner, repo, tag)
        except Unavailable as error:
            # An outage or a rate limit is not a pin defect, and failing the
            # build for one would train people to ignore this check.
            print(f"  SKIPPED   {name} {tag}: {error}")
            continue

        if actual is None:
            failures += 1
            print(f"  UNKNOWN   {name}: no tag {tag} upstream ({where})")
            continue

        if actual != sha:
            failures += 1
            print(
                f"  MISMATCH  {name}: comment says {tag} ({actual[:12]}) "
                f"but pinned {sha[:12]} ({where})"
            )
            continue

        # The pin is honest. Ask separately whether it is current, so a stale
        # pin is never mistaken for a dishonest one.
        try:
            behind = staleness(owner, repo, tag, sha)
        except Unavailable:
            behind = None

        if behind is None:
            print(f"  OK        {name} {tag}")
        elif behind[1] > GRACE_DAYS:
            failures += 1
            print(
                f"  STALE     {name}: pinned {tag}, but {behind[0]} has been "
                f"out {behind[1]} days ({where})"
            )
        else:
            print(
                f"  OK        {name} {tag} "
                f"({behind[0]} released {behind[1]}d ago, inside the grace period)"
            )

    print()
    if failures:
        print(f"  {failures} pin(s) need attention.")
        return 1

    print(f"  All {len(pins)} action pins are honest and current.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

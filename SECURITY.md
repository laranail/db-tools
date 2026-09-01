# Security

## Supported versions

| Version | Status         |
|---------|----------------|
| 1.x     | Active support |

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security-sensitive
findings. Instead, email **security@simtabi.com** with:

- A description of the vulnerability and its impact.
- Steps to reproduce (proof-of-concept welcome).
- The affected version(s).

We aim to acknowledge reports within 72 hours and triage within 5
business days.

> **Prefer GitHub private vulnerability reporting** when you can: open it from this
> repository's Security tab. The report arrives attached to the repo with a draft advisory
> and a CVE request path already in place. Email is the fallback for anyone who would
> rather not use GitHub.

## Supply-chain posture

- `roave/security-advisories` (dev-latest) is in `require-dev`.
- Weekly `composer audit` runs in CI.
- CycloneDX SBOM emitted as a release artifact on every tag.
- Dependabot updates weekly.

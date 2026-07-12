# Security

## Supported versions

| Version | Status         |
|---------|----------------|
| 1.x     | Active support |

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security-sensitive
findings. Instead, email **opensource@simtabi.com** with:

- A description of the vulnerability and its impact.
- Steps to reproduce (proof-of-concept welcome).
- The affected version(s).

We aim to acknowledge reports within 72 hours and triage within 5
business days.

## Supply-chain posture

- `roave/security-advisories` (dev-latest) is in `require-dev`.
- Weekly `composer audit` runs in CI.
- CycloneDX SBOM emitted as a release artifact on every tag.
- Dependabot updates weekly.

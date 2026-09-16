# Contributing

Thank you for your interest in `laranail/db-tools`.

## Quick start

```bash
git clone https://github.com/laranail/db-tools.git
cd db-tools
bash .scripts/init.sh
composer test
```

## Development workflow

1. Branch off `main`.
2. Write tests first. Each model trait / schema macro / observer must
   have an integration test under `tests/Unit/` running against
   in-memory SQLite (Testbench 11).
3. Run the full local check before opening a PR:
   ```bash
   composer lint     # pint + phpstan + rector --dry-run
   composer test     # vendor/bin/pest
   composer audit    # composer audit (security)
   ```
4. Use [Conventional Commits](https://www.conventionalcommits.org/).

## Coding standards

- PHP `^8.4.1 || ^8.5`, Laravel `^13.0`.
- `declare(strict_types=1);` on every file.
- `#[\Override]` on every overriding method.
- PHPStan level 8 must be clean.

## Dependency posture

This package depends on exactly one Laranail package,
`laranail/package-tools` (the provider base, the command base, and
`ReadsOptions`). It did not always: an **independence invariant**
forbade every `laranail/*` entry
in `require` until 0.9.0 reversed it, and the prose here went on
asserting the invariant after the manifest stopped honouring it. Do
not restore that wording without a test that enforces it.

What survives is the question, not the rule: **before adding a
`laranail/*` dependency, write down what it actually removes.** This
package is pulled in for database utilities, frequently by consumers
who have no use for a package-author toolchain, so each entry needs to
earn its place by deleting code rather than by tidying it. A feature
that belongs to `package-tools` still belongs there, not here.

## Code of conduct

By contributing, you agree to abide by the project's
[Code of Conduct](CODE_OF_CONDUCT.md).

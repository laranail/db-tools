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

- PHP `^8.3`, Laravel `^13.0`.
- `declare(strict_types=1);` on every file.
- `#[\Override]` on every overriding method.
- PHPStan level 8 must be clean.

## Independence invariant

This package has **no dependency** on `laranail/package-tools` or any
other Laranail package. Anything you add must keep it that way —
optional integration with sister packages happens via thin glue traits
*in those packages*, never the reverse. If a feature requires
`package-tools`, it belongs in `package-tools`, not here.

## Code of conduct

By contributing, you agree to abide by the project's
[Code of Conduct](CODE_OF_CONDUCT.md).

# laranail/db-tools

[![Latest version on Packagist](https://img.shields.io/packagist/v/laranail/db-tools.svg)](https://packagist.org/packages/laranail/db-tools)
[![Tests](https://github.com/laranail/db-tools/actions/workflows/tests.yml/badge.svg)](https://github.com/laranail/db-tools/actions/workflows/tests.yml)
[![Static analysis](https://github.com/laranail/db-tools/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/laranail/db-tools/actions/workflows/static-analysis.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> Independent, framework-agnostic database utilities for Laravel — model traits (UUID/NanoID/ULID keys, JSON accessors, slugs, soft-archiving), money & datetime casts, schema macros, an audit observer, backup/restore, a database CLI, cursor/offset pagination, and inspection services.

PHP `^8.4 || ^8.5` on Laravel `^13` — depends only on `illuminate/*` plus a few small libraries, with **no dependency** on other Laranail packages.

## Install

```bash
composer require laranail/db-tools
```

`DbToolsServiceProvider` is auto-discovered and registers the schema macros at boot.

## Documentation

Full documentation is at **[opensource.simtabi.com/documentation/laranail/db-tools](https://opensource.simtabi.com/documentation/laranail/db-tools/)** — installation, getting started, the model traits, casts, schema macros, the audit observer, backup/restore, the database CLI, and configuration.

## Contributing & security

Issues and PRs are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities per
[SECURITY.md](SECURITY.md) (opensource@simtabi.com); participation follows the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

MIT © Simtabi LLC. See [LICENSE](LICENSE).

# Changelog

All notable changes to `laranail/db-tools` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-07-22

### Added
- **Availability guard** (`src/Guard/`): a boot-safe `DatabaseGuard` implementing
  `DatabaseAvailabilityInterface` — `isAvailable()`, connectivity-guarded `hasTable()` (never
  throws), `whenAvailable()`, `flush()`, runtime-swappable `probeUsing()`, `Macroable`, and static
  self-bootstrapping `resolve()`/`reachable()`/`tableExists()`. Layered on the existing
  `DatabaseConnectionTester` + `DatabaseSchemaInspector`.
- `DbTools::guard()/isAvailable()/tableExists()/whenAvailable()` accessors and `DbToolsFacade`
  `@method` hints; `db-tools.guard.memoize` config.

## [0.1.0] - 2026-07-11

Initial public release.

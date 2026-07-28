# Changelog

All notable changes to `laranail/db-tools` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **SQL restore no longer corrupts values containing comment markers.**
  `SqlFileRestorer` stripped comments with a regex pre-pass before its string-aware scan,
  so `--` or `/* */` *inside a string literal* was treated as a comment. A value containing
  `--` lost the rest of the line including its closing quote and delimiter, merging the row
  with the next one; a value containing `/* */` had that span silently removed while
  leaving valid SQL, so the restore succeeded and wrote the wrong data with no error.
  Comment handling now happens inside the scan, where string and dollar-quote state is
  known. An unterminated block comment is also no longer emitted as a bogus statement.
- `Pagination::paginateQuery()` clamps `perPage` and `page` to at least 1, matching its
  sibling `paginate()`. Both values routinely come from request input, and unclamped a
  `perPage` of 0 reached `LengthAwarePaginator`'s `ceil($total / $perPage)` as a raw
  `DivisionByZeroError`, while a `page` of 0 produced a negative SQL offset.
- CI: floor `rector/rector` at `^2.5.8`. Rector 2.5.2–2.5.7 read
  `PHPStan\Parser\RichParser::$container` via `PrivatesAccessor` while declaring
  `phpstan/phpstan: ^2.2.2`. PHPStan 2.2.6 removed that property (it became `nodeVisitors`),
  so any resolution inside Rector's own declared range picked an incompatible pair and
  fatalled with `MissingPrivatePropertyException` instead of reporting findings. Rector
  2.5.8 both fixed the access and tightened its constraint to `^2.2.6`.

  Reproduced and bounded before pinning:

  | rector | phpstan | result |
  |---|---|---|
  | 2.5.7 | 2.2.5 | pass |
  | 2.5.7 | 2.2.6 | **fatal** |
  | 2.5.8 | 2.2.6 | pass |

  Since the package ships no `composer.lock`, CI resolved this pair on 2026-07-27 and the
  static-analysis job died; the same commit was green days later once 2.5.8 shipped. The
  floor makes the known-bad window unreachable — `--prefer-lowest` now resolves 2.5.8 +
  2.2.6. It cannot prevent a *future* upstream regression of the same shape; the resolved
  version logging added alongside is what makes the next one diagnosable.

### Changed
- CI: Rector runs as its own job, independent of Pint/PHPStan, so a toolchain fatal in one
  analyser no longer buries the others' verdicts. Resolved analyser versions are logged
  before each run.

## [0.5.1] - 2026-07-28

### Changed
- `brick/money` now allows `^0.13 || ^0.14` (was `^0.13`). The cast uses only
  `Money::of()`, `Money::ofMinor()`, `getAmount()` and `getCurrency()`, which are unchanged
  across the two lines. Widening rather than moving the constraint means the package does
  not force a `brick/money` upgrade on applications that pin `^0.13` for other reasons —
  and because the CI matrix runs both `--prefer-lowest` and `--prefer-stable`, both ends of
  the range stay continuously tested rather than assumed.

### Fixed
- CI: `actions/checkout` 7.0.0 → 7.0.1 and `softprops/action-gh-release` 3.0.1 → 3.0.2,
  both SHA-pinned.

## [0.5.0] - 2026-07-28

### Fixed
- **The availability probe no longer leaks its connect timeout into application config.**
  `DatabaseConnectionTester::probe()` overlaid a short connect timeout onto
  `database.connections.{name}` and never restored it, so any later rebuild of that
  connection (`DB::purge()`, a reconnect, a recycled worker) silently re-applied the
  probe's timeout as if it had been configured. The overlay is now restored in a
  `finally`; the connection opened by the probe still gets the bounded timeout.
- **`sqlsrv` connect timeouts use `login_timeout`, not `PDO::ATTR_TIMEOUT`.** On SQL Server
  `ATTR_TIMEOUT` is the *query* timeout, so the probe was capping every subsequent query on
  the connection it opened — and that connection is reused — at the probe timeout
  (2s by default). `pgsql` likewise now sets only the `connect_timeout` DSN parameter.
- **`DatabaseGuard` no longer probes the default connection twice.** `isAvailable(null)` and
  `isAvailable('mysql')` address the same connection but were memoized under separate keys,
  so the default connection was probed once per form and `flush('mysql')` left the
  `null`-keyed entry stale. Both now resolve to the configured default name.
- **`DatabaseUnavailable` fires on the transition into unavailability, not on every check.**
  With `guard.memoize` disabled a sustained outage emitted one event per call — an event
  storm exactly when the system is already unhealthy. A `flush()` re-arms the announcement.
- **`DatabaseGuard::resolve()` honours `guard.memoize` / `guard.emit_events`.** The
  self-bootstrapped guard hardcoded the constructor defaults, so it behaved differently from
  the one the service provider builds.
- **A `suspend()` applied before the provider registers is no longer discarded.** The
  provider now binds the guard with `singletonIf`, so it keeps an instance already created
  by a static entry point instead of replacing it (and its state) mid-boot.
- **The `DbTools` alias registration guard was inverted.** `if (class_exists('DbTools'))`
  registered the alias only when it was already resolvable — a no-op — and skipped it
  otherwise. It now registers when the name is free, and targets `DbToolsFacade` to match
  `composer.json`'s `extra.laravel.aliases`.

### Added
- `SchemaReadinessInterface::flush(?string $connection = null)` — drop memoized readiness
  reports and the underlying availability memo. Reports were memoized for the lifetime of
  the instance, which assumes a short-lived process; in Octane, a queue worker, or after
  running migrations in-process, a stale "not migrated" report was returned indefinitely.

### Changed
- **Breaking (interface):** `SchemaReadinessInterface` gains `flush()`. Implementors outside
  this package must add it. No change for callers of the shipped `SchemaReadiness`.

## [0.4.2] - 2026-07-24

### Fixed
- The availability probe opens exactly one connection and reuses it: no `DB::purge()` (which
  would close a live PDO and wipe a `:memory:` SQLite database) and no cloned connection.
  A healthy check now costs a single connection rather than two.

## [0.4.1] - 2026-07-24

### Fixed
- The availability probe reuses the real connection instead of registering a throwaway
  `__db_tools_probe__` connection in config.

## [0.4.0] - 2026-07-23

### Changed
- **Breaking:** package configuration is namespaced under `laranail.db-tools`. Read every
  value as `config('laranail.db-tools.*')` (was `config('db-tools.*')`), and the published
  file now lands at `config/laranail/db-tools.php`. Laravel loads nested config directories,
  so the published file resolves under the same key the provider merges to.

  **Upgrading from ≤ 0.3:** update your `config('db-tools.*')` calls to
  `config('laranail.db-tools.*')`, and move any published `config/db-tools.php` to
  `config/laranail/db-tools.php`. See [docs/configuration.md](docs/configuration.md).

## [0.3.0] - 2026-07-23

### Added
- **Boot-safety layer.** `suspend()` / `resume()` / `isSuspended()` on the availability
  guard for the window before a database exists (installers, first boot), a fast-fail
  connection probe bounded by `guard.probe_timeout` so an unreachable or blackholed host
  fails in seconds rather than the driver default (~30s), and `whenTable()`.
- **Schema readiness.** `SchemaReadiness` + `SchemaReadinessInterface`,
  `SchemaReadinessReport` and the `SchemaStatus` enum (`down` | `empty` | `pending` |
  `ready`), reporting whether a connection is reachable, migrated, and has the tables an app
  requires — without throwing when it is not.
- **`BootWithoutDatabase::degradeToFilesystem()`** — swap database-backed session/cache/queue
  drivers to filesystem/sync equivalents for the current process so an app can boot before
  its schema exists. Config-driven and non-destructive: nothing is written to `.env`, and
  each entry only fires when the live value matches.
- **Events + listener.** `DatabaseUnavailable` and `SchemaNotReady`, dispatched through
  `SafeEvent` (best-effort: no dispatcher, or a throwing listener, can break a probe), with
  an opt-out `LogDatabaseIssues` listener wired by `guard.log_events`.
- **`laranail::db-tools.health` command** — prints the readiness report for a connection,
  with `--connection`, `--tables` and `--strict` (non-zero exit unless fully ready).
- Config: `guard.probe_timeout`, `guard.emit_events`, `guard.log_events`,
  `readiness.required_tables`, `boot_without_database.drivers`.

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

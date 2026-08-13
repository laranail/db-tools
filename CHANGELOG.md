# Changelog

All notable changes to `laranail/db-tools` are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Five inheritance traits under `Concerns\`** — `HasMergedFillable`,
  `HasMergedHidden`, `HasMergedCasts`, `HasDefaultAttributes`, and the
  `HasExtendedModel` aggregate over all four. They let a subclass *extend* an
  inherited `$fillable` / `$hidden` / `$casts` / `$attributes` declaration by
  declaring only its additions (`additionalFillable()`, `additionalHidden()`,
  `additionalCasts()`, `additionalAttributes()`), instead of redeclaring the
  property and silently dropping whatever the parent put there. See
  [docs/tools/traits.md](docs/tools/traits.md#inheritance-traits).

  Two details are load-bearing and differ from the obvious implementation:

  - `HasMergedCasts` hooks `getCasts()`, not `casts()`. A trait method loses to
    a method declared in the class body with no error, so a base model
    declaring the idiomatic `protected function casts(): array` would shadow a
    trait-provided `casts()` and every subclass's `additionalCasts()` would
    never be consulted — the columns would just stop being cast.
  - `HasDefaultAttributes` applies defaults in the constructor-time trait
    initializer, not by overriding `getAttributes()`. `getAttributes()` backs
    `syncOriginal()`, `getAttributesForInsert()`, `replicate()` and
    `attributesToArray()`; injecting defaults there makes a stored `NULL` read
    back as the default *and* be written back to the database as the default on
    the next save, and invents values for columns a partial `select()` never
    loaded.

## [0.7.0] - 2026-07-28

A correctness sweep over the whole package, plus a structural fix for the bug
class behind it. Most entries here are cases where the code returned a
plausible wrong answer rather than failing, so an upgrade may surface work that
was silently not happening.

### Changed — breaking

- **`Schema\Concerns\HasSchemaOperations`: all five methods gain a trailing
  `?string $connection = null`.** Call sites are unaffected. A class
  **overriding** any of these protected methods fatals with "Declaration must
  be compatible" until it adopts the new signature.
- **`Concerns\HasArchiver::runArchive()` returns `int`** (rows matched) instead
  of `void`.
- **`Services\DatabaseService::setMorphClassNames()` no longer writes
  `config('app.aliases')`.** It registers a morph map. Anything relying on the
  old side effect must set the alias itself.
- **`Schema\Concerns\HasSchemaInspection::clearSchemaCache()` no longer
  cascades to subclasses.** That cascade was the bug. Use the new
  `clearAllSchemaCaches()` where the blanket behaviour is wanted.
- **`HasSlug` no longer declares `$slugSrcInputName` / `$slugDestColumnName`,
  and `HasArchiver` no longer declares `$archives`.** Code reading those
  properties off a model that does not declare them must use the resolvers —
  `getSlugSrcInputName()`, `getSlugDestColumnName()`, `usesArchiving()` — which
  return the documented defaults. See *Fixed* for why.
- **A destructive CLI action skipped for want of a terminal now exits non-zero.**
  An interactive "no" still exits `0`. Add `--force` to keep the previous
  behaviour in a non-interactive run.
- **`Models\BaseModel::reload()` throws `ModelNotFoundException`** when the row
  is gone, instead of silently keeping stale attributes.
- **`hasTable()` / `hasColumn()` / `hasColumns()` on
  `Schema\DatabaseSchemaInspector` rethrow** when the connection cannot be
  opened, instead of answering `false`.

### Fixed — silent data loss or wrong data

- **`HasSlug`'s documented configuration was a fatal error.** All three of
  `$slugSrcInputName`, `$slugDestColumnName` and `$archives` are documented as
  model-level properties, and `docs/tools/traits.md`'s primary example declared
  one. PHP forbids redeclaring a trait property with a different value, so
  copying the documented form produced *"define the same property … the
  definition differs and is considered incompatible"*. The traits no longer
  declare them.
- **`HasSlug::slugExists()` / `bySlug()` ignored the configured slug column**,
  defaulting to a literal `slug`. A model storing its slug elsewhere queried a
  column that does not exist, or — on a table that also carries a `slug` column
  — quietly answered about the wrong one.
- **`$uuidVersion` / `$uuidString` were read by nothing.** Every model got a
  random v4 no matter what it declared, so a model configured for v5 — whose
  point is that the same name yields the same id — got a fresh value each time
  and "idempotent" re-imports inserted duplicates instead of colliding on the
  unique index. Versions 1, 3, 4 and 5 are now honoured, with an overridable
  `uuidNamespace()`. A v3/v5 model with no `$uuidString` throws rather than
  falling back to random.
- **`setMorphClassNames()` had no effect on morph types.** It wrote the
  container's class-alias list, which the facade loader reads and polymorphic
  relations never consult; rows kept storing fully-qualified class names.
- **`$archives` was read by nothing**, so the documented archiving opt-out did
  not work and archived rows were hidden regardless.
- **`AuditObserver` stamped `deleted_by` through a scoped query.** Where the
  model's own global scope hid the row the update matched nothing, and the
  attribute was then marked clean regardless — so `deleted_by` stayed `NULL`
  permanently, with nothing reported.
- **`HasArchiver::archive()` reported success for a row that no longer
  existed**, firing the `archived` event and stamping an attribute for a row it
  never touched. `unArchive()` had none of `archive()`'s `exists` guard and set
  `exists = true` unconditionally, issuing an UPDATE for a row that is not
  there.
- **`HasThreadedParentChildrenRecords` leaked across the thread scope.** The
  scope applied to the root query but not to the eager-loaded descendants, and
  `children()` matched on the parent key alone, so a row belonging to another
  thread was pulled into the tree. Where the scope column stands in for a
  tenant, that is a cross-tenant read.
- **`HasSchemaInspection` answered for the wrong class and the wrong
  connection.** Its cache was a pair of trait statics written through `self::`,
  and a static declared in a trait is shared down the inheritance chain — so
  whichever class was asked first populated it for the whole hierarchy and a
  `Comment extends Post` reported `posts`' columns. The connection was never
  part of the key either.
- **`HasSchemaOperations` and the `dropColumnIfExists` / `dropForeignIfExists`
  macros read the default connection**, so a migration against a second
  database made its keep-or-drop decision from a different database than the
  one it modified. `dropForeignIfExists()` also guarded with
  `hasColumn($table, $index)`, so a conventional constraint name such as
  `posts_user_id_foreign` matched nothing and the macro silently dropped no key
  while reporting success.
- **`morphs()` / `nullableMorphs()` dropped `$after` on the UUID and ULID
  paths** — the id types this package exists to support — so morph columns
  configured for a position were appended instead.
- **`HasJsonColumnAccessors` decoded only in `getAttribute()`.** `toArray()`
  does not go through it, so anything serialising the model — API resources,
  queued payloads, `toJson()` — shipped a double-encoded field.
- **`HasSoftDeletesWithUndo`'s restore stamp flushed the whole model.** It is
  documented as writing one column but called `saveQuietly()`, so an unrelated
  in-memory edit was persisted on restore with no event firing. It also ran
  unguarded after the restore had committed, so a table without the column
  restored the row and then threw.
- **`DatabaseService::modifyTimestamps()` left `$model->timestamps` off**, so
  the instance stopped maintaining `updated_at` for the rest of the request —
  including when the save threw.
- **`generateRelationshipSyncData()` dropped every falsy value.** Pivot columns
  set to `0`, `false` or `''` vanished and fell back to the column default.
  Only `null` is dropped now.
- **`LoadsAggregatesIfMissing` raised a `TypeError`** on the constrained
  `['relation' => closure]` form that `loadCount()` / `loadAggregate()` accept
  and that these methods pass straight through.
- **`Models\DatabaseSession::user()` fatalled.** It fell back to
  `Model::class`, which is abstract, so the relation raised "Cannot instantiate
  abstract class" instead of naming what was missing. `usingUserModel()` also
  set a plain property that `newFromBuilder()` discarded, so the setting never
  reached hydrated rows.
- **`Files\DatabaseFileService::handleImport()` had no traversal protection**
  despite the docblock claiming it. `realpath()` canonicalises a path rather
  than rejecting it, so any readable file was importable. Imports are now
  confined to `db-tools.files.import_base` (default `storage_path('app')`;
  `null` restores the old behaviour).
- **`makeTempFile()` discarded `rename()`'s result**, so a failure returned a
  path that had never been exclusively created.

### Fixed — misleading diagnostics

- **A verification run against an unreachable database reported
  `connected: true` with every table missing**, sending the operator to run
  migrations when the connection was the problem. `hasTable()` / `hasColumn()`
  now distinguish an absent table from an unreachable database.
- **`--dry-run` printed nothing in CI.** It was checked *after* the destructive
  confirmation, so a non-interactive dry run hit the "re-run with `--force`"
  skip and exited `0` without saying what it would have done. A dry run
  destroys nothing and no longer asks.
- **A non-interactive skip exited `0`**, so
  `db-tools restore --path=dump.sql && ./deploy.sh` deployed against a database
  that was never restored.
- **`getTableCount()` counted views as tables** on MySQL/MariaDB and
  PostgreSQL, disagreeing with `getTables()`, and read a hardcoded
  `database.connections.pgsql.schema` — so counting a connection named anything
  else read the wrong schema, or silently fell back to `public` when no
  connection was literally named `pgsql`.
- **`BaseModel::reload()` read through global scopes**, so a row that had
  stopped matching one was not found and the method kept the stale in-memory
  values while reporting success. It also never called `syncOriginal()`, so
  `isModified()` reported unsaved changes on a model just read from the
  database.

### Added

- **`Support\ConnectionContext`** — the single place that answers "which
  connection is this, and how is it configured". It replaces eight duplicated
  null-connection normalisations in four spellings with three different
  sentinels, plus ten redundant resolution ternaries. Both recent
  wrong-connection bugs were instances of that duplication.
- **`Support\SchemaColumnCache`** — one process-wide memo for column lookups,
  deliberately a class rather than a trait static, because a static declared in
  a trait is copied into every using class and a "clear everything" on the
  trait would only clear one copy.
- `Console\Concerns\ReadsOptions` — normalises `--flag=` (empty string) to
  `null`, so a bare `--connection=` no longer forks caches keyed on the
  resolved connection.
- `HasSchemaInspection::schemaColumns()` / `hasSchemaColumn()` instance
  accessors, for when the connection is set per instance (tenancy, a read
  replica, `setConnection()`), and `clearAllSchemaCaches()`.
- `HasArchiver::usesArchiving()`, `HasSoftDeletesWithUndo::getRestoredAtColumn()`.
- `db-tools.files.import_base` config key.
- `?string $connection` on `DbTools::withoutForeignKeyChecks()` and
  `InteractsWithDatabaseFile` (restoring into the wrong database is
  destructive).

### Performance

- **`HasUuid` ran a schema introspection query on every insert**, so a bulk
  import of 10,000 rows issued 10,000 of them. The lookup is memoised per
  connection, table and column; call `HasUuid::flushColumnCache()` after
  changing the schema in-process.

### Internal

- `tests/Unit/Architecture/FacadeSeamTest` fails on any `DB::`/`Schema::`
  facade call or `database.default` / `database.connections` literal outside
  the seam. Both baselines are **empty**, so a reintroduction fails
  immediately.
- `tests/Unit/Architecture/DocumentedExamplesTest` loads every documented model
  example in a subprocess and fails if it does not compose. This is what found
  the `HasSlug` fatal above.
- Test suite 254 → 381.

### Not done

- `$devEnvironments` and `$enableUuidTesting` on `HasUuidOptions` have
  resolvers and a documented table row, but neither the code nor the docs ever
  stated what they should do. Implementing them would mean inventing a
  contract, so they remain unread and are now documented as such. Use the
  `generateUuidUsing()` hook for test-time UUIDs.
- The audit claim that `makeTempFile()` leaves a world-readable dump in `/tmp`
  **did not reproduce** — `rename()` preserves the `0600` mode. An explicit
  `chmod` was kept as defence in depth and the test is labelled a regression
  guard rather than a fix, since it passes against the old code too.

## [0.6.0] - 2026-07-28

### Added
- **`EnsureSchemaIsReady` HTTP middleware.** On an instance whose database is not fully migrated
  (a restored-but-unmigrated dump, a half-run migration), it lets the request through but stamps
  advisory `X-Schema-Status`/`X-Schema-Message` headers so a bare 500 doesn't hide the cause; when
  the schema is ready it is a single cheap, cached check. It delegates to `DbTools::schemaReport()`,
  so it honours `laranail.db-tools.readiness.required_tables`.
- **Auto-registration.** When `laranail.db-tools.readiness.middleware.enabled` (default `true`), the
  service provider pushes the middleware onto the HTTP kernel's **global** stack (not the web/api
  route groups) so it survives on both the slim and the traditional kernel — a traditional kernel
  rebuilds its route groups per request and would otherwise drop a boot-time group append. A
  `db-tools.schema-ready` route-middleware alias is always registered for manual use.
- New config block `laranail.db-tools.readiness.middleware` (`enabled`, `cache_store` [default `file`,
  deliberately DB-independent], `cache_key`, `cache_ttl`, `header_status`, `header_message`).

### Note for consumers
- On upgrade, the readiness middleware is added to every HTTP request by default. It never blocks a
  request and is cheap; disable with `laranail.db-tools.readiness.middleware.enabled = false` if you
  do not want it. Apps that registered their own copy should drop it and rely on the package's.

## [0.5.2] - 2026-07-28

### Fixed
- **`extra.branch-alias` was stale, and `^0.4` consumers may have received 0.5.x code.** The
  alias still read `dev-main => 0.4.x-dev` after 0.5.0 and 0.5.1 shipped, so `main`
  advertised itself to Composer as `0.4.x-dev`. Because `0.4.x-dev` sorts above the `v0.4.2`
  tag, any consumer whose constraint allows `^0.4` and whose root project permits dev
  stability could resolve `main` — and receive 0.5.0's breaking
  `SchemaReadinessInterface::flush()` addition with no major-version signal. The alias now
  reads `0.5.x-dev`.

  **If you require this package as `^0.4`, check your lock file for a `dev-main` entry.** If
  there is one you are already running 0.5.x: move the constraint to `^0.5` and re-resolve,
  or pin to `0.4.2` if you implement `SchemaReadinessInterface` yourself.
- **SQL restore is now atomic on a non-default connection.** `transactionOrFail()` opened
  its transaction on the default connection via the `DB` facade while `SqlFileRestorer`
  executed the statements on `DB::connection($connection)`. For any non-default connection
  the statements therefore ran outside a transaction entirely: a failing restore left the
  already-applied statements committed, and the rollback undid an empty transaction on a
  different connection. `ManagesTransactions` methods now take an optional `$connection`
  and resolve it, and the restorer passes its own through.
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

### Added
- `.scripts/check-branch-alias.sh` plus two CI gates that make the alias defect above
  unrepeatable. The release workflow asserts the alias minor equals the tag being released,
  before anything is built or published; static analysis asserts on `main` that the alias has
  not fallen behind the newest tag. The drift check deliberately permits the alias to run
  *ahead* of the last tag — that is the normal state while a new minor is in development.

### Changed
- CI: Rector runs as its own job, independent of Pint/PHPStan, so a toolchain fatal in one
  analyser no longer buries the others' verdicts. Resolved analyser versions are logged
  before each run.
- `php` constraint `^8.4 || ^8.5` → `^8.4.1 || ^8.5`, matching the other laranail packages
  and the floor `symfony/uid` already imposes transitively. No code change — the declared
  range now states what was already true.

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

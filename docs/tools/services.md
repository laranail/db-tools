# Services

Two container-bound services. Resolve them via their interfaces (constructor
injection or `app(...)`) — both are registered by `DbToolsServiceProvider`.

| Contract | Implementation |
|----------|----------------|
| `Services\Contracts\DatabaseServiceInterface` | `Services\DatabaseService` |
| `Services\Contracts\MaintenanceServiceInterface` | `Services\MaintenanceService` |

## `DatabaseService`

Query and model helpers. (Filesystem housekeeping lives in `MaintenanceService`,
not here.)

```php
use Simtabi\Laranail\DbTools\Services\Contracts\DatabaseServiceInterface;

$db = app(DatabaseServiceInterface::class);
```

- **`isJoined(mixed $query, string $table): bool`** — whether `$table` is already
  joined on the given query. Accepts an Eloquent or query builder; returns `false`
  for anything else or when there are no joins.
  ```php
  $query = User::query()->join('profiles', 'profiles.user_id', '=', 'users.id');
  $db->isJoined($query, 'profiles'); // true
  ```
- **`modifyTimestamps(array $dates, Model $model): bool`** — set timestamp columns
  without touching the auto-managed `updated_at` (`$model->timestamps = false`),
  then save. Returns the save result; logs on success/failure. An empty `$dates`
  array is a no-op (`false`).
  ```php
  $db->modifyTimestamps(['created_at' => now()->subYear()], $post);
  ```
- **`handleViewCount(Model $object, string $sessionName): bool`** — increment the
  model's `views` column once per session, keyed by `"{$sessionName}.{$id}"`.
  Returns `false` if already counted this session.
  ```php
  $db->handleViewCount($article, 'viewed_articles');
  ```
- **`setMorphClassNames(array $aliases): void`** — merge polymorphic type
  aliases into Eloquent's morph map via `Relation::enforceMorphMap()`, so morph
  columns store the alias instead of a fully-qualified class name. Merges rather
  than replaces, so several callers can each contribute their own types.
  ```php
  $db->setMorphClassNames(['post' => Post::class]);
  (new Post)->getMorphClass(); // 'post'
  ```
  > Before 0.6.0 this wrote to `config('app.aliases')` — the container's
  > class-alias list, which the facade loader reads and polymorphic relations
  > never consult — so calling it had no effect on morph types at all.
- **`generateRelationshipSyncData(string|array $ids, array $data = [], string $columnName = 'id'): array`**
  — build a `sync()`-ready map keyed by id, each row seeded with a fresh UUID under
  `$columnName` plus any shared `$data`. Only `null` values are dropped; `0`,
  `false` and `''` are kept (before 0.6.0 every falsy value was discarded, so
  pivot columns set to `0` or `false` silently fell back to the column default).
  ```php
  $pivot = $db->generateRelationshipSyncData([1, 2], ['active' => true]);
  $model->tags()->sync($pivot);
  ```

## `MaintenanceService`

Filesystem housekeeping over the application's storage — kept separate from the
database concerns. Constructed with the application base path, so resolve it from
the container.

```php
use Simtabi\Laranail\DbTools\Services\Contracts\MaintenanceServiceInterface;

$maintenance = app(MaintenanceServiceInterface::class);
```

- **`clearCache(): bool`** — flush the cache store and remove compiled framework
  caches (`storage/framework/cache/facade-*.php`, `bootstrap/cache/*.php`), firing
  `cache:clearing` / `cache:cleared` events.
- **`clearLogFiles(): bool`** — delete files under `storage/{clockwork,debugbar,logs}`
  (preserving `.gitignore`), firing `logs:clearing` / `logs:cleared`.
- **`deleteStorageSymlink(): bool`** — remove the `public/storage` symlink; `false`
  if it doesn't exist.

Each method logs and returns `false` on failure rather than throwing.

## `CleanDatabaseService`

Contract `Services\Contracts\CleanDatabaseServiceInterface`. Truncates tables
without leaving the database half-emptied. Backs the CLI's
[`clean` action](database-cli.md).

| Method | Returns | Notes |
|---|---|---|
| `truncate(array $tables, ?string $connection = null)` | `CleanDatabaseResult` | Exactly the named tables. Throws on an empty list, an unknown table, or a protected one. |
| `truncateAll(array $except = [], ?string $connection = null)` | `CleanDatabaseResult` | Everything on the connection except the protected list and `$except`. |
| `protectedTables()` | `list<string>` | From `laranail.db-tools.clean.protected_tables`. |
| `isProtected(string $table)` | `bool` | |

```php
use Simtabi\Laranail\DbTools\Services\Contracts\CleanDatabaseServiceInterface;

$result = app(CleanDatabaseServiceInterface::class)->truncateAll(['users']);

$result->truncated;   // ['posts', 'comments', ...]
$result->skipped;     // ['migrations', 'users']
$result->count();     // 2
```

### Foreign keys

A bare truncation loop breaks the moment two of the named tables reference each
other: `TRUNCATE users` with a `posts.user_id` foreign key fails on MySQL, and
on PostgreSQL without `CASCADE`. A loop that dies halfway has already emptied
everything before it, and there is no transaction to undo it.

Constraints are disabled for the whole run — via
[`ManagesForeignKeyChecks`](traits.md), which is nesting- and exception-safe and
keyed per connection — so table order stops mattering, including for circular
references, which have no valid order at all.

### The transaction, and its honest limit

The run is wrapped in a transaction, which makes it atomic on **PostgreSQL and
SQLite**. It does **not** on **MySQL/MariaDB**: `TRUNCATE` is DDL there and
forces an implicit commit, so each table is final as it completes. The wrapper
is worth having for the drivers where it works; this note exists so nobody
assumes a rollback the engine will not give them.

### Protected tables

`laranail.db-tools.clean.protected_tables` defaults to `['migrations']`.
Truncating the migration ledger strands the schema at an unknown version with
no record of how it got there.

The two entry points treat the list differently, deliberately:

- **`truncate()` refuses** a protected table. Silently skipping part of an
  explicit list would leave the caller believing it ran.
- **`truncateAll()` skips** them. A whole-database clean asks for "everything
  reasonable", not for a specific table.

Refusal happens **before** anything is written, so a rejected request leaves the
database untouched.

---
[← Docs index](../../README.md#documentation)

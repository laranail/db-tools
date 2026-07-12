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
- **`setMorphClassNames(array $aliases): void`** — merge a morph-alias map into
  `config('app.aliases')` at runtime.
- **`generateRelationshipSyncData(string|array $ids, array $data = [], string $columnName = 'id'): array`**
  — build a `sync()`-ready map keyed by id, each row seeded with a fresh UUID under
  `$columnName` plus any shared `$data` (empty values are filtered out).
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

---
[← Docs index](../../README.md#documentation)

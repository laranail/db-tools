# Model & behavior traits

Reusable Eloquent traits under `Simtabi\Laranail\DbTools\Concerns`. Each
is opt-in — apply only the ones you need. A full worked example using several
together lives at [`docs/examples/Order.php`](../examples/Order.php).

## Identifier traits

### `HasUuid`

Auto-sets a v4 (or time-ordered) UUID on a **secondary** column at creating
time — the model keeps its own primary key. The UUID is immutable: an attempt to
change it during an update restores the original value. The model's route key is
the UUID column. Pulls in [`HasUuidOptions`](#hasuuidoptions) for configuration.

```php
use Simtabi\Laranail\DbTools\Concerns\HasUuid;

class Order extends Model
{
    use HasUuid;
    // uuid column auto-filled on create; missing column throws
    // MissingUuidColumnException.
}

Order::findByUuid($uuid);              // first match or null
Order::query()->byUuid($uuid)->get(); // scope
Order::query()->findByUuid($uuid);    // scope; firstOrFail by default
```

#### `HasUuidOptions`

Configuration + behavior for `HasUuid` (mixed in automatically). All knobs are
optional properties/methods on the model:

| Knob | Resolver | Default |
|------|----------|---------|
| `$uuidColumnName` / `uuidColumn()` | `getUuidColumnName()` | `'uuid'` |
| `$uuidVersion` | `getUuidVersion()` | `4` |
| `$uuidString` | `getUuidString()` | `''` (for v3/v5) |
| `$devEnvironments` | `getDevEnvironments()` | `['local', 'testing']` |
| `$enableUuidTesting` | `isEnableUuidTesting()` | `false` |
| `$useTimeOrderedUuid` | `isUseTimeOrderedUuid()` | `false` |
| `$enforceUuid` | `isEnforceUuid()` | `true` |

When `$useTimeOrderedUuid` is true, `Str::orderedUuid()` is used (lexically
sortable); otherwise `Str::uuid()`. Helpers: `getUuid()`, `setUuid($value)`,
`getGeneratedUuid()`.

**Custom generator hook.** Register a closure to override generation
process-wide (e.g. a readable UUID in tests). It receives `($this, $model)` and
returns the UUID string. Pass `null` to clear.

```php
Order::generateUuidUsing(fn ($model, $ctx) => 'fixed-uuid-for-tests');
// ...
Order::generateUuidUsing(null); // restore default
```

### `HasUlid`

Auto-sets a 26-char Crockford-base32 ULID (lexicographically sortable by
creation time) on the `ulid` column at creating time. Override the column with a
`ULID_COLUMN` constant or `ulidColumn()`.

```php
use Simtabi\Laranail\DbTools\Concerns\HasUlid;

class Order extends Model
{
    use HasUlid;
    public const ULID_COLUMN = 'ulid';
}
```

### `HasNanoid`

Auto-sets a NanoID (default 21-char URL-safe alphabet) on the `nanoid` column at
creating time. No external dependency — uses `random_bytes()`. Override with the
`NANOID_COLUMN` / `NANOID_LENGTH` constants or `nanoidColumn()` /
`nanoidLength()`.

```php
use Simtabi\Laranail\DbTools\Concerns\HasNanoid;

class ShortLink extends Model
{
    use HasNanoid;
    public const NANOID_COLUMN = 'code';
    public const NANOID_LENGTH = 10;
}
```

### `HasUuidsOrIntegerIds`

Switches the **primary key** type globally between BIGINT, UUID, and ULID based
on config, so one model class works in apps that prefer either. At creating
time, a string id is generated unless integer ids are in use.

Driven by config (read via `getTypeOfId()`):

- `db-tools.using_uuids_for_id` (bool) → UUID
- `db-tools.using_ulids_for_id` (bool) → ULID
- `db-tools.id_type` (string, default `'BIGINT'`)

See [Configuration](../configuration.md#id_type) for these keys.

```php
use Simtabi\Laranail\DbTools\Concerns\HasUuidsOrIntegerIds;

class Order extends Model
{
    use HasUuidsOrIntegerIds;
}

Order::isUsingIntegerId();  // true when BIGINT
Order::isUsingStringId();   // true for UUID/ULID
Order::getTypeOfId();       // 'BIGINT' | 'UUID' | 'ULID'
```

`getKeyType()` and `getIncrementing()` adapt automatically for string ids. This
trait is also used by [`BaseModel`](base-model.md).

## Attribute traits

### `HasJsonColumnAccessors`

Auto-decodes (to array) on read and JSON-encodes on write for the columns listed
in `$jsonColumns` (or returned by `jsonColumns()`). Columns already handled by
Laravel's `$casts` (`array`/`json`/`object`/`collection`) are skipped to avoid
double-casting.

```php
use Simtabi\Laranail\DbTools\Concerns\HasJsonColumnAccessors;

class Order extends Model
{
    use HasJsonColumnAccessors;
    protected array $jsonColumns = ['metadata', 'snapshot'];
}

$order->metadata = ['via' => 'fedex']; // encoded on save
$order->metadata['via'];               // 'fedex' — decoded on read
```

## Behavior traits

### `HasQuietSaving`

Adds `saveQuietly(array $options = [])` — saves without firing model events.
Mirrors Laravel's built-in capability as an explicit, discoverable trait.

```php
$model->saveQuietly();
```

### `HasScopes`

Reusable query scopes.

- **`scopeWithWhereHas($query, string $relation, callable $constraint)`** —
  constrains a relation *and* eager-loads it with the same constraint.
- **`scopeSearch($query, string $term, array $searchable = [])`** — searches the
  given columns (falling back to a `$searchable` property). On MySQL/MariaDB it
  uses a native `MATCH ... AGAINST (... IN BOOLEAN MODE)` FULLTEXT query; on
  every other driver it degrades to portable chained `LIKE '%term%'` filters. An
  empty column list or blank term is a no-op.

```php
use Simtabi\Laranail\DbTools\Concerns\HasScopes;

class Article extends Model
{
    use HasScopes;
    protected array $searchable = ['title', 'body'];
}

Article::query()->search('laravel database')->get();
Article::query()->withWhereHas('comments', fn ($q) => $q->where('approved', true))->get();
```

### `HasImmutability`

Blocks updates and deletes on immutable models — throws
[`ImmutableDataException`](#immutabledataexception). Models are immutable by
default once the trait is applied; override `isImmutable()` to make it
conditional.

```php
use Simtabi\Laranail\DbTools\Concerns\HasImmutability;

class LedgerEntry extends Model
{
    use HasImmutability;

    // Optional: allow mutation while a draft.
    public function isImmutable(): bool
    {
        return $this->status !== 'draft';
    }
}
```

#### `ImmutableDataException`

`Simtabi\Laranail\DbTools\Exceptions\ImmutableDataException` (extends
`DbToolsException`, code `2001`). Built with `::forModel($model)`, carrying
`['model' => …, 'key' => …]` context.

### `HasThreadedParentChildrenRecords`

Adjacency-list parent/child threading for self-referential models. Column names
are configurable per model:

| Method | Property | Default |
|--------|----------|---------|
| `parentKeyColumn()` | `$parentKeyColumn` | `'parent_id'` |
| `threadScopeColumn()` | `$threadScopeColumn` | `null` (whole table) |
| `threadOrderColumn()` | `$threadOrderColumn` | `'created_at'` |

Relations: `parent()` (BelongsTo), `children()` (HasMany, ordered),
`descendants()` (children with their recursive descendant tree eager-loaded).

```php
use Simtabi\Laranail\DbTools\Concerns\HasThreadedParentChildrenRecords;

class Comment extends Model
{
    use HasThreadedParentChildrenRecords;
    protected string $threadScopeColumn = 'ticket_id';
}

$comment->isParent();    // true if no parent
$comment->hasChildren(); // exists() check

// Root records (optionally scoped) with their full threaded tree:
(new Comment)->getAsThreadedParentToChildren($ticketId);
```

### `HasSlug`

Opinionated wrapper around `spatie/laravel-sluggable` with configurable
source/destination columns and slug-lookup helpers.

| Resolver | Source | Default |
|----------|--------|---------|
| `getSlugSrcInputName()` | `$slugSrcInputName` or `setSlugSrcInputName()` | `'name'` |
| `getSlugDestColumnName()` | `$slugDestColumnName` or `setSlugDestColumnName()` | `'slug'` |

```php
use Simtabi\Laranail\DbTools\Concerns\HasSlug;

class Post extends Model
{
    use HasSlug;
    protected string $slugSrcInputName = 'title';
}

Post::slugExists('my-post');          // bool
Post::checkModelSlug('my-post');      // 'my-post' or 'my-post-<uniqid>'
Post::query()->bySlug('my-post')->first();
```

`getSlugOptions()` wires spatie from the configured columns automatically.

### `ManagesTransactions`

Transaction helpers (protected, for use inside a class).

```php
$this->transaction(fn () => /* ... */, attempts: 3); // DB::transaction wrapper
$this->transactionOrFail(fn () => /* ... */);        // manual begin/commit/rollBack
$this->inTransaction();        // DB::transactionLevel() > 0
$this->getTransactionLevel();  // current nesting level
```

### `ManagesForeignKeyChecks`

Nesting-aware version of "disable FK checks around a callback". Constraints are
disabled only on the outermost call and re-enabled once every nested call has
finished.

```php
$this->withoutForeignKeyChecks(function (): void {
    User::truncate();
    Post::truncate();
});

$this->getForeignKeyCheckNestingLevel(); // for debugging/testing
```

> For a one-shot facade call, use
> [`DbTools::withoutForeignKeyChecks()`](facade.md#foreign-keys).

### `ValidatesFilePaths`

Security-aware path helpers shared by the file/backup utilities (protected
methods):

- `normalizePath($path)` — strip null bytes, unify separators, resolve relatives
  against `base_path()`.
- `isAbsolutePath($path)` — Unix or Windows absolute path check.
- `isValidPhpFile($path)` / `isValidDirectory($path)` — readable + traversal-free
  checks.
- `hasDirectoryTraversal($path)` — detects `..` sequences.
- `getFileExtension($path)` / `getFileNameWithoutExtension($path)`.
- `isFileSizeWithinLimit($path, int $maxBytes)`.

---
[← Docs index](../../README.md#documentation)

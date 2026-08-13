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
| `$useTimeOrderedUuid` | `isUseTimeOrderedUuid()` | `false` |
| `$enforceUuid` | `isEnforceUuid()` | `true` |

`$devEnvironments` and `$enableUuidTesting` have resolvers
(`getDevEnvironments()`, `isEnableUuidTesting()`) but **nothing reads them** and
no behaviour is defined for them. They are listed here only so the resolvers are
not mistaken for working knobs. Use the custom generator hook below for
test-time UUIDs.

**Versions.** `$uuidVersion` accepts `1`, `3`, `4` (default) or `5`. Versions 3
and 5 are name-based and derive the UUID from `$uuidString`, so the same name
always yields the same id:

```php
class Invoice extends Model
{
    use HasUuid;

    protected $uuidVersion = 5;
    protected $uuidString  = 'invoice-2026-0001';
}
```

Override `uuidNamespace()` to namespace those ids per application; it defaults
to `Uuid::NAMESPACE_DNS`. A v3/v5 model with an empty `$uuidString` throws
`InvalidArgumentException` rather than falling back to a random value, since
that fallback would defeat the only reason to ask for a name-based version. An
unrecognised version throws too.

> Before 0.6.0 `getGeneratedUuid()` consulted neither knob, so every model got a
> random v4 no matter what it declared. A model configured for v5 silently got a
> fresh value each time, and "idempotent" re-imports inserted duplicates instead
> of colliding on the unique index.

When `$useTimeOrderedUuid` is true (and the version is 4), `Str::orderedUuid()`
is used (lexically sortable); otherwise `Str::uuid()`. Helpers: `getUuid()`,
`setUuid($value)`, `getGeneratedUuid()`.

The "does this table have the uuid column" check is memoised per connection,
table and column, so a bulk insert no longer issues one schema introspection
query per row. Call `HasUuid::flushColumnCache()` after changing the schema
within the same process.

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
$order->toArray()['metadata'];         // ['via' => 'fedex'] — decoded here too
```

> Before 0.6.0 decoding happened only in `getAttribute()`, which `toArray()`
> does not go through — so `$order->metadata` was an array while
> `$order->toArray()['metadata']` was still the raw JSON string, and anything
> serialising the model (API resources, queued payloads, `toJson()`) shipped a
> double-encoded field.

Malformed JSON is left as the raw string rather than becoming `null`, so a bad
row surfaces instead of silently emptying.

## Inheritance traits

Four traits for model hierarchies, where a subclass needs to *extend* a
declaration it inherits rather than replace it. Laravel's `$fillable`,
`$hidden`, `$casts` and `$attributes` are plain properties, so a subclass that
redeclares one drops everything the parent put there — silently. These traits
add a method seam alongside each property: the subclass declares only its own
additions and the inherited entries are merged in.

Apply them on the base model. Subclasses need nothing but the `additional*()`
override; re-applying a trait further down the chain is allowed and composes.

| Trait | Subclass hook | Extends |
|-------|---------------|---------|
| `HasMergedFillable` | `additionalFillable(): array` | `$fillable` |
| `HasMergedHidden` | `additionalHidden(): array` | `$hidden` |
| `HasMergedCasts` | `additionalCasts(): array` | `$casts` / `casts()` |
| `HasDefaultAttributes` | `additionalAttributes(): array` | `$attributes` |
| `HasExtendedModel` | — | all four at once |

### `HasExtendedModel`

The aggregate. Applies the other four; adds nothing of its own.

```php
use Simtabi\Laranail\DbTools\Concerns\HasExtendedModel;

class AccountModel extends Model
{
    use HasExtendedModel;

    protected $fillable = ['email', 'password'];

    protected $hidden = ['password'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }
}
```

A subclass then adds to each of those without restating any of them:

```php
final class Member extends AccountModel
{
    protected function additionalFillable(): array
    {
        return ['credit_limit'];
    }

    protected function additionalCasts(): array
    {
        return ['credit_limit' => 'integer'];
    }

    protected function additionalAttributes(): array
    {
        return ['credit_limit' => 2];
    }
}

(new Member)->getFillable();  // ['email', 'password', 'credit_limit']
(new Member)->credit_limit;   // 2 — before any save
```

It deliberately does **not** touch `$guarded`. Mass-assignment policy belongs to
the model; a composition trait that sets `$guarded = []` makes every attribute
mass-assignable on any model whose `$fillable` is empty, and fatals outright on
a model that declares its own `$guarded` with a different value.

### `HasMergedFillable`

`getFillable()` returns the inherited list plus `additionalFillable()`.
Duplicates are removed and the result re-indexed, so a subclass repeating an
inherited column is harmless. `additionalFillable()` is consulted on every
`fill()` — keep it pure.

### `HasMergedHidden`

`getHidden()` returns the inherited list plus `additionalHidden()`. This one
matters more than fillable does: a subclass that restates `$hidden` and forgets
one inherited entry starts leaking it through `toArray()` / `toJson()` with
nothing to notice. Merging makes the hidden set append-only down the hierarchy.

### `HasMergedCasts`

`getCasts()` returns the inherited cast map plus `additionalCasts()`. An
addition wins for a key that is already cast, so a subclass can also re-cast an
inherited column.

The trait hooks `getCasts()` rather than the more obvious `casts()`, because a
trait method loses to a method declared in the class body — silently, with no
error. A base model using the idiomatic Laravel 11+ form:

```php
protected function casts(): array
{
    return ['status' => 'string'];
}
```

would shadow a trait-provided `casts()` outright, and every subclass's
`additionalCasts()` would simply never be consulted — the columns would just
stop being cast, with nothing failing. `getCasts()` has no such problem:
Laravel folds `casts()` into the `$casts` property once, in
`initializeHasAttributes()`, and every casting decision (`hasCast()`,
`castAttribute()`, `attributesToArray()`) reads `getCasts()`. Declaring
`casts()` and using this trait therefore compose correctly.

### `HasDefaultAttributes`

`additionalAttributes()` tops up whatever `$attributes` already provides,
without redeclaring it:

```php
use Simtabi\Laranail\DbTools\Concerns\HasDefaultAttributes;

class InvoiceModel extends Model
{
    use HasDefaultAttributes;

    protected $attributes = ['status' => 'draft'];

    protected function additionalAttributes(): array
    {
        return ['currency' => 'KES'];
    }
}
```

The defaults are applied in `initializeHasDefaultAttributes()`, the per-trait
initializer Laravel runs from the model constructor. That timing is the point:

- **Before `syncOriginal()`** — a default is part of the model's original
  state, so it never shows up in `getDirty()`.
- **Before `fill()`** — constructor input still wins, *including an explicit
  `null`*. Only genuinely absent keys get a default; the test is
  `array_key_exists()`, not `isset()`.
- **Not during hydration** — `newFromBuilder()` replaces the attribute array
  wholesale via `setRawAttributes()`, so a row loaded from the database is never
  retro-fitted. What is stored is what you get.

> Applying defaults any later is a data-integrity hazard, not a style choice.
> Overriding `getAttributes()` — the low-level accessor behind `syncOriginal()`,
> `getAttributesForInsert()`, `replicate()` and `attributesToArray()` — makes a
> stored `NULL` read back as the default *and* written back to the database as
> the default on the next save, and invents values for columns a partial
> `select()` never loaded.

Like Laravel's own `$attributes`, this cannot cover a query-builder `insert()`,
which never instantiates a model.

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

When `threadScopeColumn()` is set, the scope applies at **every** level of the
tree, not just the roots.

> Before 0.6.0 `children()` matched on the parent key alone and
> `getAsThreadedParentToChildren()` scoped only its root query, so a row
> pointing at a record in another thread — reparented, imported, or written with
> a stale id — was pulled into that thread's tree. Where the scope column stands
> in for a tenant, that was a cross-tenant read.

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

`slugExists()` and `bySlug()` take an optional column name; omitting it uses the
model's configured destination column rather than a literal `slug`.

To store the slug somewhere other than a `slug` column, declare the property
(or override `setSlugDestColumnName()`):

```php
protected string $slugDestColumnName = 'permalink';
```

> Before 0.6.0 both properties were declared **in the trait** with defaults, and
> PHP forbids redeclaring a trait property with a different value — so the
> documented `protected string $slugSrcInputName = 'title';` above was a fatal
> error, not a configuration. The trait no longer declares them.
>
> Before 0.6.0 `slugExists()` and `bySlug()` also defaulted to a literal `'slug'`
> and ignored `getSlugDestColumnName()`, so a model storing its slug elsewhere
> queried a column that does not exist — or, on a table that also carries a
> `slug` column, quietly answered about the wrong one.

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

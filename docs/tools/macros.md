# Schema macros

Blueprint macros and an extended `Blueprint` subclass under
`Simtabi\Laranail\DbTools\Schema`. The `auditColumns()`,
`softDeletesWithUndo()`, `configuredMorphs()` / `configuredNullableMorphs()`,
and `softDeleteHistory()` macros are registered automatically in the service
provider's `boot()`. They all work on the standard `Schema::create()`
Blueprint — no custom builder required.

## `auditColumns()`

Adds nullable, indexed `created_by` / `updated_by` / `deleted_by` columns. Pairs
with [`AuditObserver`](observers.md) to stamp the authenticated user.

```php
Schema::create('orders', function (Blueprint $t): void {
    $t->id();
    $t->string('name');
    $t->auditColumns();        // created_by, updated_by, deleted_by
    $t->timestamps();
    $t->softDeletes();
});
```

Arguments (all optional, named):

| Argument | Default | Purpose |
|----------|---------|---------|
| `$foreignKey` | `'foreignId'` | FK column type. Use `'foreignUuid'` / `'foreignUlid'` for non-int PKs. |
| `$includeDeletedBy` | `true` | Set `false` to skip `deleted_by` on non-soft-deleting tables. |
| `$userTable` | `'users'` | Referenced table (only used when `$constrained`). |
| `$constrained` | `false` | When `true`, adds a real FK constraint with `nullOnDelete()`. |

```php
$t->auditColumns(foreignKey: 'foreignUuid', constrained: true);
```

Re-registration is a no-op (guarded by `Blueprint::hasMacro()`).

## `softDeletesWithUndo()`

Adds Laravel's standard `deleted_at` plus a companion `restored_at` timestamp
(nullable, indexed) to track the most recent restoration.

```php
Schema::create('orders', function (Blueprint $t): void {
    $t->id();
    $t->softDeletesWithUndo(); // deleted_at + restored_at
    $t->timestamps();
});
```

Arguments (optional): `$deletedColumn = 'deleted_at'`,
`$restoredColumn = 'restored_at'`, `$precision = 0`.

This macro ships only the columns. The runtime restore-history behavior — the
`HasSoftDeletesWithUndo` trait plus the `softDeleteHistory()` table below — is
documented on the [Soft-delete restore history](soft-deletes.md) page.

## `configuredMorphs()` / `configuredNullableMorphs()`

Id-type-aware polymorphic columns. Unlike Laravel's `morphs()` (always an
integer `*_id`), these pick `morphs()` / `uuidMorphs()` / `ulidMorphs()` to
match the package's configured key type (`db-tools.id_type`), so
polymorphic foreign keys line up with models using
[`HasUuidsOrIntegerIds`](traits.md).

```php
Schema::create('comments', function (Blueprint $t): void {
    $t->id();
    $t->configuredMorphs('commentable');          // *_id (int/uuid/ulid) + *_type
    $t->configuredNullableMorphs('subject');      // nullable variant
});
```

Both accept an optional `$indexName`. The key type is resolved from
`db-tools.using_uuids_for_id` / `using_ulids_for_id` (booleans take
precedence) then `db-tools.id_type` — see
[Configuration](../configuration.md#id_type).

## `softDeleteHistory()`

Builds the columns for the soft-delete history table written by the
[`HasSoftDeletesWithUndo`](soft-deletes.md) trait — used by the publishable
`create_soft_delete_history_table` migration (publish tag
`db-tools-migrations`).

```php
Schema::create('soft_delete_history', function (Blueprint $t): void {
    $t->softDeleteHistory();
});
```

It adds `id`, id-type-aware polymorphic `record_id` / `record_type`, `action`
(`deleted` | `restored`), a nullable indexed `actor_id`, a nullable `reason`,
an indexed `happened_at`, and `timestamps`. The polymorphic columns honour
`db-tools.id_type`, so the history table matches your configured key
type. Most apps publish the migration rather than calling this macro directly.

## `BlueprintMacros`

An extended `Blueprint` subclass (`extends Illuminate\…\Blueprint`) that makes
`id()`, `foreignId()`, `morphs()`, and `nullableMorphs()` resolve their column
type from a configurable resolver — so one set of migrations works whether your
app uses BIGINT, UUID, or ULID keys. Wire it into a custom schema builder, then
configure it statically.

```php
use Simtabi\Laranail\DbTools\Schema\BlueprintMacros;

// Resolve the id type once (e.g. from config). Returns 'UUID', 'ULID',
// or anything else (treated as BIGINT).
BlueprintMacros::setIdTypeResolver(fn (): string => 'UUID');

// Optional: per-driver setup callback, run on Blueprint construction.
BlueprintMacros::registerDriverSetup('pgsql', function ($connection): void {
    // e.g. ensure an extension exists
});
```

With the resolver returning `'UUID'`:

| Method | Resolves to |
|--------|-------------|
| `id()` | `uuid()->primary()` |
| `foreignId()` | `foreignUuid()` |
| `morphs()` | `uuidMorphs()` |
| `nullableMorphs()` | `nullableUuidMorphs()` |

`'ULID'` resolves to the `ulid*` equivalents; any other value falls back to the
parent BIGINT behavior. A per-driver setup callback that throws is swallowed so
migrations are not blocked.

## Field-group macros

`FieldGroupMacros` (registered automatically in the provider boot) adds
convenience `Blueprint` macros for the column patterns that recur across
migrations:

```php
Schema::create('posts', function (Blueprint $t) {
    $t->id();
    $t->addSlugField();          // unique `slug` (pass true for nullable)
    $t->addPublishingFields();   // is_published, published_at
    $t->addMetaFields();         // meta_title, meta_description, meta_keywords
    $t->addCommonFields();       // timestamps() + softDeletes()
});
```

| Macro | Columns |
|--------|---------|
| `addCommonFields()` | `timestamps()` + `softDeletes()` |
| `addUserFields()` | `created_by`, `updated_by`, `deleted_by` (nullable, **id-type-aware**) |
| `addAcceptanceFields(string $name)` | `is_{name}`, `{name}_at`, `{name}_by` (**id-type-aware**), `{name}_remarks` — approval workflow |
| `addPublishingFields()` | `is_published`, `published_at` |
| `addStatusField(string $default = 'active')` | `status` (indexed) |
| `addSortingField(int $default = 0)` | `sort_order` (indexed) |
| `addSlugField(bool $nullable = false)` | `slug` (unique) |
| `addMetaFields()` / `addSeoFields()` | `meta_title`, `meta_description`, `meta_keywords` |
| `addLocationFields()` | `latitude`, `longitude` (decimal) |
| `addImageFields(string $prefix = '')` | `{prefix}image`, `_alt`, `_title` |
| `addPriceFields()` | `price`, `sale_price`, `currency` |
| `addActivationFields()` | `is_active`, `activated_at`, `deactivated_at` |
| `addExpiryFields()` | `starts_at`, `expires_at` |
| `addUuidPrimaryKey(string $column = 'id')` | UUID primary key (explicit) |
| `addNullableMorphs(string $name, ?string $indexName = null)` | nullable `{name}_type` / `{name}_id` (**id-type-aware**) + index |
| `dropForeignIfExists(string $index)` | drop a foreign key only if it exists |
| `dropColumnIfExists(string\|array $columns)` | drop column(s) only if present |

Both conditional drops read the **blueprint's own connection**, so a migration
running against a second database decides from the database it is modifying.
Before 0.6.0 they asked the `Schema` facade, which always answers for the
default connection.

`dropForeignIfExists()` takes an index name and matches the table's actual
foreign keys against the reported constraint name, the column list, or
Laravel's generated `{table}_{columns}_foreign` form — the last because SQLite
reports foreign keys with no name, so without it the same migration would
behave differently there than on MySQL or Postgres.

> Before 0.6.0 it guarded with `hasColumn($table, $index)`. A conventional
> constraint name such as `posts_user_id_foreign` is not a column, so the guard
> answered false and the macro silently dropped nothing while reporting
> success.

**Id-type-aware** columns (`addUserFields`, `addAcceptanceFields`, `addNullableMorphs`,
plus `auditColumns()` by default) follow the configured key type
(`db-tools.using_uuids_for_id` / `using_ulids_for_id` / `id_type`) — so a
UUID/ULID-keyed app gets `uuid`/`ulid` foreign-key columns, not `BIGINT`.

### Coming from `cleaniquecoders/blueprint-macro`?

That third-party pack's single-column shorthands are native Laravel one-liners —
use the natives directly: `name()`/`label()` → `string()`; `code()`/`reference()`
→ `string()->unique()`; `description()`/`remarks()` → `text()->nullable()`;
`money()` → `decimal(_, 8, 2)`; `amount()`/`smallAmount()` → `(unsigned)bigInteger`/
`integer`; `slug()`/`hashslug()` → `string()->unique()`; `uuid()` → `uuid()`;
`ordering()` → `integer()->default(0)`; `percent()` → `decimal(_, 5, 2)`;
`addForeign()`/`belongsTo()` → `foreignId()->constrained()`. Its multi-column
patterns are covered above (`standardTime()` → `addCommonFields()`; `user()` →
`addUserFields()`; `addAcceptance()` → `addAcceptanceFields()`).

---
[← Docs index](../../README.md#documentation)

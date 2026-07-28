# Schema traits

Two opt-in traits for working with a table's shape from a model or a migration:
`HasSchemaInspection` (read) and `HasSchemaOperations` (modify). Both live under
`Schema\Concerns`.

## `HasSchemaInspection`

Memoised column lookups for a model's table, so repeated questions do not each
cost an introspection query.

```php
use Simtabi\Laranail\DbTools\Schema\Concerns\HasSchemaInspection;

class Post extends Model
{
    use HasSchemaInspection;
}

Post::getSchemaTableName();          // 'posts'
Post::getSchemaColumnNames();        // ['id', 'title', …]
Post::schemaHasColumn('title');      // true
```

| Member | Effect |
|---|---|
| `getSchemaTableName(): string` | The model's table name. |
| `getSchemaColumnNames(): list<string>` | Column listing, memoised. |
| `schemaHasColumn(string $name): bool` | Whether the column is present. |
| `schemaColumns(): list<string>` | Columns for **this instance's** connection. |
| `hasSchemaColumn(string $name): bool` | Instance-scoped column check. |
| `clearSchemaCache(): void` | Forget this class's memoised answers. |
| `clearAllSchemaCaches(): void` | Forget every class's memoised answers. |

The cache is keyed by model class, connection and table, and is stored in
`Support\SchemaColumnCache` — one process-wide store, so one flush clears it.

Use the instance accessors when the connection is set per instance (tenancy, a
read replica, an explicit `setConnection()`). The static accessors answer for a
fresh instance and cannot see that.

Call `clearSchemaCache()` after altering the table within the same process — for
example in a test that drops and recreates its tables between cases.

> Two things were wrong before 0.6.0, both returning a plausible wrong answer
> rather than failing. The cache was a pair of trait statics written through
> `self::`, and a static declared in a trait is shared down the inheritance
> chain — so whichever class was asked **first** populated the cache for the
> whole hierarchy, and a `Comment extends Post` reported `posts`' columns. The
> connection was never part of the key either, so the same model read on a
> second connection got the first one's answer.
>
> `clearSchemaCache()` also no longer cascades to subclasses. That cascade was
> the bug; use `clearAllSchemaCaches()` where the blanket behaviour is wanted.

## `HasSchemaOperations`

Existence-checked schema modifications, safe to run against an already-migrated
database. Every method is `protected` — use the trait from a migration or a
service, not as a public API.

```php
use Simtabi\Laranail\DbTools\Schema\Concerns\HasSchemaOperations;

return new class extends Migration
{
    use HasSchemaOperations;

    public function up(): void
    {
        $this->addColumnIfNotExists('posts', 'summary', fn ($table, $column) => $table->text($column)->nullable());
        $this->dropColumnsFromTable('posts', ['legacy_body']);
        $this->dropIndexIfExists('posts', 'posts_slug_index');
    }
};
```

```php
protected function dropColumnsFromTable(string $table, array|string $columns, ?string $connection = null): void;
protected function addColumnIfNotExists(string $table, string $column, Closure $definition, ?string $connection = null): void;
protected function renameColumnIfExists(string $table, string $from, string $to, ?string $connection = null): void;
protected function dropTablesIfExist(string|array $tables, ?string $connection = null): void;
protected function dropIndexIfExists(string $table, string|array $index, ?string $connection = null): void;
```

Passing `null` for `$connection` uses the default connection.

> **Breaking in 0.6.0.** Every method gained the trailing `?string $connection`
> parameter. Call sites are unaffected, but a class **overriding** any of these
> protected methods will fatal with "Declaration must be compatible" until it
> adopts the new signature.
>
> They previously went through the `Schema` facade, which always answers for the
> default connection, so the trait could not do what a migration against a second
> database needs. Where a table of the same name existed on both connections the
> mismatch was silent: the existence check read one database and the
> modification wrote to the other. `dropColumnsFromTable()` also resolved
> existence *inside* the `Schema::table()` callback, so the check and the drop
> could disagree about which connection they meant.

---
[← Docs index](../../README.md#documentation)

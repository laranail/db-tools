# Soft-archive (`HasArchiver`)

`Concerns\HasArchiver` adds a **soft-archive** to an Eloquent model, keyed off an
`archived_at` column. Laravel's native soft deletes occupy `deleted_at`, so a
model can be both *archivable* and *soft-deletable* at once — archiving is a
separate, reversible "put away" state distinct from deletion.

## Setup

Add the column in a migration and use the trait:

```php
use Illuminate\Database\Schema\Blueprint;

Schema::table('documents', function (Blueprint $t) {
    $t->timestamp('archived_at')->nullable();
});
```

```php
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Concerns\HasArchiver;

class Document extends Model
{
    use HasArchiver;

    // Optional — override the column name:
    // public const ARCHIVED_AT = 'archived_at';
}
```

Registering the trait adds a global scope that **hides archived rows** from every
query by default (mirroring Laravel's `SoftDeletingScope`).

## Usage

```php
$document->archive();        // stamps archived_at; fires archiving/archived
$document->isArchived();     // true
$document->unArchive();      // clears archived_at; fires unArchiving/unArchived

Document::query()->get();              // archived rows hidden
Document::query()->withArchived();     // include archived
Document::query()->onlyArchived();     // only archived
Document::query()->withoutArchived();  // explicitly exclude (default)
```

| Member | Effect |
|---|---|
| `archive(): ?bool` | Stamp `archived_at`. `null` if the model doesn't exist, `false` if the update matched no row. |
| `unArchive(): ?bool` | Clear `archived_at`. `null` if the model doesn't exist. |
| `runArchive(): int` | Perform the update; returns the number of rows matched. |
| `usesArchiving(): bool` | Whether the global scope hides archived rows. Override to opt out. |
| `isArchived(): bool` | Whether the row is archived. |
| `archiving` / `archived` / `unArchiving` / `unArchived` | Register model-event callbacks. |
| `getArchivedAtColumn()` / `getQualifiedArchivedAtColumn()` | Column accessors. |
| builder: `withArchived()` / `onlyArchived()` / `withoutArchived()` | Scope helpers. |

## Opting out

`ArchiveScope` consults `usesArchiving()`, which reads the `$archives`
property:

```php
public bool $archives = false;   // archived rows stay visible
```

Override `usesArchiving()` instead when the decision is dynamic.

> Two things were wrong before 0.6.0. Nothing read `$archives` at all, so the
> documented opt-out had no effect and archived rows were hidden regardless —
> and the trait declared `public bool $archives = true`, which made declaring it
> on a model a fatal error, since PHP forbids redeclaring a trait property with
> a different value. The trait no longer declares it.

## Behaviour changes in 0.6.0

- `runArchive()` returns `int` instead of `void`. `archive()` used to discard
  that count and report success for a row that no longer existed, firing the
  `archived` event and stamping the attribute for a row it never touched.
- `unArchive()` now returns `null` for a model that was never persisted,
  matching `archive()`. It previously set `exists = true` unconditionally and
  issued an UPDATE for a row that does not exist, reporting success.

For a delete-then-undo workflow (rather than archive), see
[`HasSoftDeletesWithUndo`](soft-deletes.md).

[← Docs index](../../README.md#documentation)

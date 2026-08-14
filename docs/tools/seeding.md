# Seeding

A base class and two concerns under `Seeding\`, covering the three things
application seeders otherwise re-implement or quietly get wrong: idempotence,
output, and refusing to run on production.

## `BaseSeeder`

```php
use Simtabi\Laranail\DbTools\Seeding\BaseSeeder;

final class RoleSeeder extends BaseSeeder
{
    public function run(): void
    {
        $result = $this->upsertAll(Role::class, 'slug', [
            ['slug' => 'owner', 'name' => 'Owner'],
            ['slug' => 'member', 'name' => 'Member'],
        ]);

        $this->info("Roles: {$result->summary()}");   // "2 created" — then "2 unchanged"
    }
}
```

| Member | Effect |
|---|---|
| `upsert(string $model, array $identity, array $attributes = []): Model` | Create or converge one row. |
| `upsertAll(string $model, string $key, array $rows): UpsertResult` | Converge a list keyed on one attribute. |
| `blockedInProduction(): bool` | Whether this seeder must not touch the database. |
| `refuseInProduction(string $what = 'This seeder'): bool` | The same, but says so on the console. |

Plus `info()`, `warn()`, `comment()`, `tell()` and `console()` from
[`InteractsWithSeederOutput`](#interactswithseederoutput).

### Idempotence

Seeders run on install and re-run on upgrade, so they must **converge rather
than duplicate**. `upsert()` states a row's identity explicitly rather than
leaving it implicit in a `firstOrCreate()` argument list, where the identity and
the payload are the same array and nobody can tell which columns decide "the
same row".

A row in `upsertAll()` that is missing its identity column throws, rather than
being inserted afresh on every run — which is the duplication the method exists
to prevent, and it would be silent.

### `UpsertResult`

`upsertAll()` returns what happened, not how many rows it was handed.

| Member | Effect |
|---|---|
| `created` / `updated` / `unchanged` | Counts, from Eloquent's own `wasRecentlyCreated` and `wasChanged()`. |
| `total(): int` | Their sum. |
| `changedAnything(): bool` | False is what a second run of the same seeder should report. |
| `plus(UpsertResult $other): self` | For a seeder that writes several tables. |
| `summary(): string` | `"3 created, 1 updated"`, or `"nothing to do"`. |

> The version this replaces documented "number of rows written" and returned
> `count($rows)` — the number of rows it was *given*, the same on every run
> whether it wrote anything or not. A seeder reported "40 roles" on its fortieth
> idempotent re-run.

### Production safety

`blockedInProduction()` is not a convenience. Demo seeders exist to write
fixtures that must never reach a production installation: published passwords,
and in at least one real case a published TOTP secret enrolled against staff
accounts able to suspend or delete any customer. The guard is the only thing
between that fixture and a production database, so it **fails closed**.

```php
public function run(): void
{
    if ($this->refuseInProduction('The demo seeder')) {
        return;
    }

    // …
}
```

Production is blocked unless `--force` was passed to a command that declares it.
Tests run under `APP_ENV=testing` and are unaffected. `refuseInProduction()` is
for seeders that would rather stop loudly than return early — a demo seeder that
silently does nothing looks exactly like a seeder that ran.

## <a name="interactswithseederoutput"></a>`Concerns\InteractsWithSeederOutput`

Progress output from a seeder that may or may not have a console. Included by
`BaseSeeder`; `use` it directly on a seeder with a different base.

```php
$this->info('Seeding roles…');
$this->tell('newLine');
$this->console();     // ?Illuminate\Console\Command
```

### The problem it exists for

`Seeder::$command` is a **typed, non-nullable property that Laravel assigns only
for console runs**. Under `$this->seed()` in a test it is never initialised — and
an uninitialised typed property is not null, so:

- `?->` does not guard it,
- `isset()` reads false while PHPStan insists the property cannot be null,
- reading it throws `Error: Typed property … must not be accessed before
  initialization`.

So a seeder that reports progress works under `artisan db:seed` and dies in the
test suite. `ReflectionProperty::isInitialized()` asks the question that is
actually being asked, and `console()` returns the instance callers should use.

`tell()` takes the method name as a string and checks it against an allow-list —
`info`, `line`, `comment`, `warn`, `error`, `newLine`. Dispatching a
caller-supplied string onto the command object otherwise reaches any method on
it, including `call()`, which runs another artisan command.

## `Concerns\InteractsWithSeedFiles`

Reading seed data out of files rather than embedding it in PHP, plus a
reproducible Faker. Relocated here from `laranail/package-tools` in 0.8.0 — it
is application seeder tooling that had landed in the package-authoring package.

```php
use Simtabi\Laranail\DbTools\Seeding\Concerns\InteractsWithSeedFiles;

final class CountrySeeder extends BaseSeeder
{
    use InteractsWithSeedFiles;

    public function run(): void
    {
        $this->upsertAll(Country::class, 'code', $this->seedFileJson('countries.json'));
    }
}
```

| Member | Effect |
|---|---|
| `seedFileBasePath(): string` | Where relative paths resolve from; `setSeedFileBasePath()` moves it. |
| `seedFilePath(string $relative): string` | The absolute path, without reading it. |
| `seedFileExists(string $relative): bool` | Presence check. |
| `seedFileContents(string $relative): string` | Raw contents. |
| `seedFileJson(string $relative): array` | Decoded JSON, asserted to be an array. |
| `seedFiles(string $relative = '', ?string $extension = null): array` | List a directory of seed files. |
| `fake(?string $locale = null): Generator` | A Faker, honouring `fakerLocale()`. |
| `seedFaker(int $seed): Generator` | A Faker seeded for a reproducible run. |

Failures raise `Exceptions\SeedFileException` — `fileMissing()`,
`notAJsonArray()`, `missingFaker()` — naming the path and the reason, rather
than returning an empty array that seeds nothing and reports success.

---
[← Docs index](../../README.md#documentation)

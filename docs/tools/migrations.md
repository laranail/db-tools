# Migrations

Three classes under `Migrations\`: a base class that derives `down()` from a
table list, the policy that decides whether a rollback is allowed at all, and
the listener that applies that policy to the two commands a `down()` guard
cannot see.

## `BaseMigration`

Declare the tables the migration owns, in creation order, and `down()` is
written for you — dropping in reverse, so dependent tables go before their
parents and the foreign keys resolve.

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Migrations\BaseMigration;

return new class extends BaseMigration
{
    protected function tables(): array
    {
        return ['organisations', 'teams', 'team_user'];
    }

    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $table): void { /* … */ });
        Schema::create('teams', function (Blueprint $table): void { /* … */ });
        Schema::create('team_user', function (Blueprint $table): void { /* … */ });
    }
};
```

| Member | Effect |
|---|---|
| `tables(): list<string>` | Abstract. The tables this migration owns, in creation order. |
| `down(): void` | Guards via `ReversalPolicy`, then drops `array_reverse(tables())`. |
| `schema(): Builder` | The schema builder for this migration's connection. |

Stating the creation order in one place is worth more than the `down()` it
saves: it is also the order the foreign keys depend on.

### No column helpers, deliberately

`$table->foreignId('user_id')->constrained()->cascadeOnDelete()` is the obvious
thing to extract into a helper on this base class, and it must not be.

**larastan derives model property types by parsing migration files for
`Schema::create` calls.** A column added from a helper defined elsewhere is
invisible to that parse, so every model gains an "access to an undefined
property" error for the column it cannot see. Repeating one readable line costs
less than the abstraction does.

### The connection is pinned, not inherited

`down()` resolves its schema builder from `Migration::$connection` — the same
property the framework reads to decide where the migration runs. So a rollback
drops from where `up()` created, rather than from whatever connection happened
to be default when the rollback ran.

That matters most in the multi-database case this base class came from: a tenant
migration rolled back while no tenant is initialised would otherwise drop the
central database's tables.

## `ReversalPolicy`

Decides whether this installation may have its schema dropped.

```php
use Simtabi\Laranail\DbTools\Migrations\ReversalPolicy;

ReversalPolicy::isPermitted();   // bool
ReversalPolicy::guard();         // throws RuntimeException when it is not
ReversalPolicy::environments();  // the configured allow-list
```

The policy is **environment-based rather than a flag per migration**, because
the question is never "is this migration reversible" (they all are) but "is this
a database where losing everything is acceptable".

| Key | Default | Meaning |
|---|---|---|
| `laranail.db-tools.migrations.reversible_environments` | `['local', 'development', 'dev', 'testing']` | Environments where dropping the schema is normal workflow. |
| `laranail.db-tools.migrations.allow_rollback` | `env('DB_TOOLS_ALLOW_ROLLBACK', false)` | The deliberate override, for the operator who genuinely means it. |

`testing` is in the default list because `RefreshDatabase` runs
`migrate:fresh`; a suite that could not rebuild its schema could not run.

> The override is read through `config()` and never `env()`. `config:cache` is
> routine on exactly the servers where this guard matters, and `env()` returns
> null once the configuration is cached — which would shut the escape hatch for
> the one operator who needs it, at the worst possible moment.

`guard()` takes an optional description of the operation, so the refusal names
what was refused:

```
Refusing to drop every table and re-migrate in the "production" environment: …
```

## `GuardsDestructiveCommands`

The coverage gap, and the reason a `down()` guard is not enough on its own.
Traced through the framework rather than assumed:

| Command | Path | Runs `down()`? |
|---|---|---|
| `migrate:rollback` | `Migrator::rollback()` → `runDown()` | yes |
| `migrate:reset` | `Migrator::reset()` → `resetMigrations()` → `runDown()` | yes |
| `migrate:fresh` | `db:wipe` → `dropAllTables()` | **no** |
| `db:wipe` | `dropAllTables()` | **no** |

The bottom two go straight to the schema builder. No migration's `down()` runs,
so `BaseMigration` cannot see them — and neither can any migration that does not
extend a base class at all, which in a real application is usually most of them.

This listener applies `ReversalPolicy` to those two on `CommandStarting`, which
fires before `handle()` with the resolved command name. A listener rather than a
command override, because replacing a framework command means inheriting its
implementation and tracking it across releases.

`--force` is honoured and passes through: it is the framework's own "yes, in
production" for these commands, so a deliberate deploy keeps working. The policy
still refuses without it, which is the case that matters — nobody types
`--force` by accident.

Registered automatically. Turn it off with
`laranail.db-tools.migrations.guard_destructive_commands => false`.

---
[← Docs index](../../README.md#documentation)

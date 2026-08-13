# Database CLI (`laranail::db-tools.db`)

A consolidated, security-hardened Artisan command over the package's backup,
restore, and verification services.

```bash
php artisan laranail::db-tools.db <action> [options]
```

| Action | What it does | Backed by |
|---|---|---|
| `export` | Dump the database to a file | [`BackupManager`](backup-restore.md) driver |
| `restore` | Restore the database from a dump | `BackupManager` driver |
| `import` | Run a `.sql` file against a connection | `SqlFileRestorer` |
| `clean` | TRUNCATE the named tables | query-builder grammar (never raw SQL) |

## Options

| Option | Purpose |
|---|---|
| `--path=` | SQL/backup file path (`import` / `export` / `restore`). |
| `--connection=` | Database connection (defaults to the app default). |
| `--tables=a,b,c` | Tables to truncate (`clean`). |
| `--force` | Skip the confirmation prompt. |
| `--dry-run` | Print what would happen without touching the database. Never prompts. |

## Examples

```bash
php artisan laranail::db-tools.db export --path=storage/backups/db.sql
php artisan laranail::db-tools.db import  --path=seed/data.sql --force
php artisan laranail::db-tools.db restore --path=storage/backups/db.sql
php artisan laranail::db-tools.db clean   --tables=cache,sessions --force
```

## Safety

- **Destructive actions** (`import`, `restore`, `clean`) confirm first. In a
  **non-interactive** run (pipe/CI) they proceed **only** with `--force`, so a
  script never silently destroys data.
- **`--dry-run` never prompts**, since it destroys nothing. Before 0.6.0 the
  confirmation ran first, so a dry run in CI hit the "re-run with `--force`"
  skip and exited 0 without printing what it would have done.
- **`clean` will not truncate a protected table.** The list lives at
  `laranail.db-tools.clean.protected_tables` and holds `migrations` by default;
  naming one is refused before the prompt. The confirmation is not a guard —
  `--force` removes it, and `--force` is what CI uses.
- **`clean` disables foreign key constraints for the run and wraps it in a
  transaction.** Table order stops mattering, including for circular
  references, which have no valid order at all. See
  [`CleanDatabaseService`](services.md#cleandatabaseservice) for the honest
  limit of that transaction on MySQL.

### Exit codes

| Situation | Code |
|---|---|
| Action completed | `0` |
| Dry run | `0` |
| Declined interactively | `0` — a deliberate choice |
| Skipped non-interactively (no `--force`) | `1` |
| Action failed / bad arguments | `1` |

A non-interactive skip reports failure because nobody was asked. Before 0.6.0
it exited 0, so

```bash
php artisan laranail::db-tools.db restore --path=dump.sql && ./deploy.sh
```

deployed against a database that was never restored.
- `clean` truncates through the connection's query grammar — table names are
  validated against the schema via [`DatabaseTableVerifier`](table-verification.md)
  first, and never interpolated into raw SQL.
- Dumps/restores delegate to the per-driver `BackupManager`, which passes the
  database password through an **environment variable** (`MYSQL_PWD` for MySQL/
  MariaDB, `PGPASSWORD` for PostgreSQL) rather than on the command line, so it
  never appears in the process listing.

The command uses the laranail `::` namespace separator via a local
`SupportsNamespacedNames` trait, so this package keeps its zero-dependency
invariant (no `laranail/console` needed).

[← Docs index](../../README.md#documentation)

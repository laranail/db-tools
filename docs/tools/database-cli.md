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
| `--dry-run` | Print what would happen without touching the database. |

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

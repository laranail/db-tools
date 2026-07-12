<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupManagerInterface;
use Simtabi\Laranail\DbTools\Backup\SqlFileRestorer;
use Simtabi\Laranail\DbTools\Console\Concerns\SupportsNamespacedNames;
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseTableVerifierInterface;

/**
 * Consolidated, security-hardened database CLI: `export` (driver-based dump),
 * `restore` (load a dump), `import` (run a `.sql` file), and `clean` (truncate
 * named tables). Destructive actions confirm first (and return the prompt
 * default in non-interactive/CI runs, so a pipe never silently destroys data).
 *
 * Dumps/restores delegate to the package's {@see BackupManagerInterface} driver
 * (which handles credentials securely); imports use {@see SqlFileRestorer}; the
 * `clean` truncate goes through the query builder grammar (never raw SQL).
 */
final class DbToolsCommand extends Command
{
    use SupportsNamespacedNames;

    /** @var string */
    protected $signature = 'laranail::db-tools.db
        {action : One of import|export|restore|clean}
        {--path= : SQL/backup file path (import|export|restore)}
        {--connection= : Database connection name (defaults to the app default)}
        {--tables= : Comma-separated tables to truncate (clean)}
        {--force : Skip the confirmation prompt}
        {--dry-run : Print what would happen without touching the database}';

    /** @var string */
    protected $description = 'Database utilities: import | export | restore | clean.';

    public function handle(
        BackupManagerInterface $backup,
        SqlFileRestorer $restorer,
        DatabaseTableVerifierInterface $verifier,
        ConnectionResolverInterface $connections,
    ): int {
        $action = $this->strArg('action');
        $connection = $this->strOption('connection');

        return match ($action) {
            'export' => $this->doExport($backup, $connection),
            'restore' => $this->doRestore($backup, $connection),
            'import' => $this->doImport($restorer, $connection),
            'clean' => $this->doClean($verifier, $connections, $connection),
            default => $this->failOut("Unknown action [{$action}]. Use import|export|restore|clean."),
        };
    }

    private function doExport(BackupManagerInterface $backup, ?string $connection): int
    {
        $path = $this->requirePath();
        if ($path === null) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line("[dry-run] would export {$this->connLabel($connection)} → {$path}");

            return self::SUCCESS;
        }

        return $backup->backup($path, $connection)
            ? $this->ok("Exported database to {$path}.")
            : $this->failOut('Export failed.');
    }

    private function doRestore(BackupManagerInterface $backup, ?string $connection): int
    {
        $path = $this->requirePath();
        if ($path === null) {
            return self::FAILURE;
        }

        if (! $this->confirmDestructive("restore {$this->connLabel($connection)} from {$path} (overwrites data)")) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("[dry-run] would restore {$path} → {$this->connLabel($connection)}");

            return self::SUCCESS;
        }

        return $backup->restore($path, $connection)
            ? $this->ok("Restored database from {$path}.")
            : $this->failOut('Restore failed.');
    }

    private function doImport(SqlFileRestorer $restorer, ?string $connection): int
    {
        $path = $this->requirePath();
        if ($path === null) {
            return self::FAILURE;
        }

        if (! $this->confirmDestructive("import {$path} into {$this->connLabel($connection)}")) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("[dry-run] would import {$path} → {$this->connLabel($connection)}");

            return self::SUCCESS;
        }

        return $restorer->restore($path, $connection)
            ? $this->ok("Imported {$path}.")
            : $this->failOut('Import failed.');
    }

    private function doClean(
        DatabaseTableVerifierInterface $verifier,
        ConnectionResolverInterface $connections,
        ?string $connection,
    ): int {
        $tables = array_values(array_filter(array_map(
            trim(...),
            explode(',', $this->strOption('tables') ?? ''),
        ), static fn (string $t): bool => $t !== ''));

        if ($tables === []) {
            return $this->failOut('Provide --tables=a,b,c to clean.');
        }

        $missing = $verifier->getMissingTables($tables, $connection);
        if ($missing !== []) {
            return $this->failOut('Unknown table(s): '.implode(', ', $missing).'.');
        }

        if (! $this->confirmDestructive('TRUNCATE '.implode(', ', $tables))) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line('[dry-run] would truncate '.implode(', ', $tables));

            return self::SUCCESS;
        }

        $db = $connections->connection($connection);
        foreach ($tables as $table) {
            $db->table($table)->truncate();
            $this->line("  truncated {$table}");
        }

        return $this->ok('Cleaned '.count($tables).' table(s).');
    }

    private function requirePath(): ?string
    {
        $path = $this->strOption('path');

        if ($path === null) {
            $this->failOut('Provide --path=/path/to/file.');

            return null;
        }

        return $path;
    }

    private function strArg(string $key): string
    {
        $value = $this->argument($key);

        return is_string($value) ? $value : '';
    }

    private function strOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function confirmDestructive(string $what): bool
    {
        if ($this->option('force')) {
            return true;
        }

        // Non-interactive (pipe / CI): never silently destroy data — skip with a
        // clear message instead, so the caller knows --force is required.
        if (! $this->input->isInteractive()) {
            $this->warn("Skipped: {$what} — re-run with --force to proceed in a non-interactive shell.");

            return false;
        }

        return $this->confirm("About to {$what}. Continue?", false);
    }

    private function connLabel(?string $connection): string
    {
        return $connection !== null ? "connection [{$connection}]" : 'the default connection';
    }

    private function ok(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function failOut(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}

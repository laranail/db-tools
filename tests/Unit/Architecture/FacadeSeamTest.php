<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Architecture;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;
use SplFileInfo;

/**
 * Guards the invariant that made a whole class of bug possible.
 *
 * This package's API is multi-connection aware, but the implementation kept
 * reaching for the `DB`/`Schema` facades — which silently resolve the DEFAULT
 * connection — and kept re-deriving "what is the default called?" in its own
 * way. That produced a transaction opened on the wrong connection, an
 * availability memo that forked, and eight copies of the same normalisation in
 * four spellings. {@see ConnectionContext} is
 * now the single place either question is answered; this test keeps it that way.
 *
 * Scanning is done over PHP tokens rather than with a regex, so docblock
 * examples (`Schema::table(...)` in a `@example`) are excluded structurally
 * instead of via an allowlist that would rot the moment someone rewords a
 * comment. `use` aliases are resolved, so `use ... Schema as S` is caught and a
 * class legitimately named `Schema` is not.
 */
final class FacadeSeamTest extends TestCase
{
    private const string SEAM = 'src/Support/ConnectionContext.php';

    /**
     * The facades that silently bind to the default connection.
     *
     * @var array<string, true>
     */
    private const array GUARDED_FACADES = [
        DB::class => true,
        Schema::class => true,
    ];

    /**
     * The only legitimate facade calls outside the seam.
     *
     * `DB::getConnections()` is manager-level introspection — "which connections
     * has this request already resolved?" — with no per-connection meaning, so
     * it has no seam equivalent.
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED_CALLS = [
        self::SEAM => ['*'],
        'src/Schema/DatabaseConnectionTester.php' => ['DB::getConnections'],
    ];

    /**
     * Config keys that identify a connection. Re-deriving these outside the seam
     * is how eight incompatible copies of the same logic came to exist — the
     * facade check alone would not have caught any of them.
     *
     * @var list<string>
     */
    private const array GUARDED_CONFIG_KEYS = [
        'database.default',
        'database.connections',
    ];

    /**
     * Sites not yet migrated to the seam.
     *
     * Asserted for EXACT equality, so removing an entry without fixing the file
     * fails, and adding a new violation anywhere fails too. Shrinks to empty as
     * adoption lands, at which point this constant and its assertion go away.
     *
     * @var list<string>
     */
    private const array KNOWN_VIOLATIONS = [
        'src/Backup/SqlFileRestorer.php:208 DB::connection',
        'src/Backup/SqlFileRestorer.php:208 DB::connection',
        'src/Concerns/HasSoftDeletesWithUndo.php:123 DB::connection',
        'src/Concerns/HasSoftDeletesWithUndo.php:124 DB::connection',
        'src/Concerns/HasSoftDeletesWithUndo.php:75 DB::connection',
        'src/Concerns/ManagesForeignKeyChecks.php:110 Schema::connection',
        'src/Concerns/ManagesForeignKeyChecks.php:111 DB::getDefaultConnection',
        'src/Concerns/ManagesForeignKeyChecks.php:111 Schema::connection',
        'src/Concerns/ManagesTransactions.php:108 DB::connection',
        'src/Concerns/ManagesTransactions.php:108 DB::connection',
        'src/DbTools.php:255 Schema::withoutForeignKeyConstraints',
        'src/Schema/Concerns/HasSchemaInspection.php:36 Schema::getColumnListing',
        'src/Schema/Concerns/HasSchemaOperations.php:29 Schema::table',
        'src/Schema/Concerns/HasSchemaOperations.php:31 Schema::hasColumn',
        'src/Schema/Concerns/HasSchemaOperations.php:46 Schema::hasColumn',
        'src/Schema/Concerns/HasSchemaOperations.php:47 Schema::table',
        'src/Schema/Concerns/HasSchemaOperations.php:61 Schema::hasColumn',
        'src/Schema/Concerns/HasSchemaOperations.php:62 Schema::table',
        'src/Schema/Concerns/HasSchemaOperations.php:73 Schema::dropIfExists',
        'src/Schema/Concerns/HasSchemaOperations.php:87 Schema::hasIndex',
        'src/Schema/Concerns/HasSchemaOperations.php:87 Schema::hasTable',
        'src/Schema/Concerns/HasSchemaOperations.php:91 Schema::table',
        'src/Schema/DatabaseConnectionTester.php:280 DB::connection',
        'src/Schema/DatabaseConnectionTester.php:280 DB::connection',
        'src/Schema/DatabaseSchemaInspector.php:129 Schema::connection',
        'src/Schema/DatabaseSchemaInspector.php:130 Schema::getColumnListing',
        'src/Schema/DatabaseSchemaInspector.php:153 Schema::connection',
        'src/Schema/DatabaseSchemaInspector.php:154 Schema::hasColumn',
        'src/Schema/DatabaseSchemaInspector.php:172 Schema::connection',
        'src/Schema/DatabaseSchemaInspector.php:173 Schema::hasColumns',
        'src/Schema/DatabaseSchemaInspector.php:30 Schema::connection',
        'src/Schema/DatabaseSchemaInspector.php:30 Schema::getFacadeRoot',
        'src/Schema/DatabaseSchemaInspector.php:51 Schema::connection',
        'src/Schema/DatabaseSchemaInspector.php:52 Schema::hasTable',
        'src/Schema/DatabaseSchemaInspector.php:67 DB::connection',
        'src/Schema/DatabaseSchemaInspector.php:67 DB::connection',
        'src/Schema/FieldGroupMacros.php:86 Schema::hasColumn',
        'src/Schema/FieldGroupMacros.php:97 Schema::hasColumn',
    ];

    /**
     * Connection-config reads not yet migrated to the seam. Same exact-equality
     * contract as {@see KNOWN_VIOLATIONS}.
     *
     * @var list<string>
     */
    private const array KNOWN_CONFIG_VIOLATIONS = [
        'src/Backup/BackupManager.php:139 database.default',
        'src/Backup/BackupManager.php:140 database.connections.',
        'src/Backup/BackupManager.php:64 database.default',
        'src/Concerns/ManagesForeignKeyChecks.php:101 database.default',
        'src/Console/HealthCommand.php:47 database.default',
        'src/Guard/DatabaseGuard.php:121 database.default',
        'src/Schema/DatabaseConnectionTester.php:161 database.default',
        'src/Schema/DatabaseConnectionTester.php:170 database.default',
        'src/Schema/DatabaseConnectionTester.php:176 database.default',
        'src/Schema/DatabaseConnectionTester.php:182 database.default',
        'src/Schema/DatabaseConnectionTester.php:44 database.default',
        'src/Schema/DatabaseConnectionTester.php:46 database.connections.',
        'src/Schema/DatabaseConnectionTester.php:78 database.connections.',
        'src/Schema/DatabaseSchemaInspector.php:78 database.connections.pgsql.schema',
        'src/Schema/DatabaseTableVerifier.php:116 database.default',
        'src/Schema/SchemaReadiness.php:91 database.default',
    ];

    public function test_no_default_binding_facade_calls_outside_the_seam(): void
    {
        $found = [];

        foreach ($this->sourceFiles() as $relative => $code) {
            foreach ($this->facadeCalls($code) as [$line, $call]) {
                if ($this->isAllowed($relative, $call)) {
                    continue;
                }

                $found[] = "{$relative}:{$line} {$call}";
            }
        }

        sort($found);

        self::assertSame(
            self::KNOWN_VIOLATIONS,
            $found,
            "DB::/Schema:: bind to the DEFAULT connection. Route through ConnectionContext instead:\n"
            .'  '.implode("\n  ", $found)."\n"
        );
    }

    public function test_connection_config_keys_are_only_read_by_the_seam(): void
    {
        $found = [];

        foreach ($this->sourceFiles() as $relative => $code) {
            if ($relative === self::SEAM) {
                continue;
            }

            foreach ($this->connectionConfigLiterals($code) as [$line, $literal]) {
                $found[] = "{$relative}:{$line} {$literal}";
            }
        }

        sort($found);

        self::assertSame(
            self::KNOWN_CONFIG_VIOLATIONS,
            $found,
            "Resolving the default connection outside ConnectionContext is how eight\n"
            ."incompatible copies of that logic came to exist. Use ConnectionContext::for():\n"
            .'  '.implode("\n  ", $found)."\n"
        );
    }

    /**
     * @return array<string, string> relative path => source
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $src = $root.'/src';

        /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src));

        $sources = [];

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = (string) $file->getRealPath();
            $relative = str_replace($root.DIRECTORY_SEPARATOR, '', $path);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            $sources[$relative] = (string) file_get_contents($path);
        }

        ksort($sources);

        return $sources;
    }

    /**
     * `Alias::method(` calls whose alias resolves to a guarded facade.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function facadeCalls(string $code): array
    {
        $tokens = token_get_all($code);
        $aliases = $this->facadeAliases($tokens);

        if ($aliases === []) {
            return [];
        }

        $calls = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            // Comments and strings are not code — this is what removes every
            // docblock example without an allowlist.
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] !== T_STRING) {
                continue;
            }

            $alias = $token[1];

            if (! isset($aliases[$alias])) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;
            $method = $tokens[$i + 2] ?? null;
            if (! is_array($next)) {
                continue;
            }
            if ($next[0] !== T_DOUBLE_COLON) {
                continue;
            }
            if (! is_array($method)) {
                continue;
            }
            if ($method[0] !== T_STRING) {
                continue;
            }

            $calls[] = [$token[2], $alias.'::'.$method[1]];
        }

        return $calls;
    }

    /**
     * Map local aliases to the guarded facades they import.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array<string, true>
     */
    private function facadeAliases(array $tokens): array
    {
        $aliases = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) {
                continue;
            }
            if ($token[0] !== T_USE) {
                continue;
            }

            $fqcn = '';
            $alias = null;

            for ($j = $i + 1; $j < $count; $j++) {
                $inner = $tokens[$j];

                if ($inner === ';' || $inner === '{') {
                    break;
                }

                if (! is_array($inner)) {
                    continue;
                }

                if ($inner[0] === T_AS) {
                    // Everything after `as` is the local alias.
                    $alias = '';

                    continue;
                }

                if (in_array($inner[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    if ($alias === null) {
                        $fqcn .= $inner[1];
                    } else {
                        $alias = $inner[1];
                    }
                }
            }

            $fqcn = ltrim($fqcn, '\\');
            if ($fqcn === '') {
                continue;
            }
            if (! isset(self::GUARDED_FACADES[$fqcn])) {
                continue;
            }

            $local = $alias !== null && $alias !== ''
                ? $alias
                : substr($fqcn, (int) strrpos($fqcn, '\\') + 1);

            $aliases[$local] = true;
        }

        return $aliases;
    }

    /**
     * String literals naming a connection-identifying config key.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function connectionConfigLiterals(string $code): array
    {
        $found = [];

        foreach (token_get_all($code) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $literal = trim($token[1], "'\"");

            foreach (self::GUARDED_CONFIG_KEYS as $key) {
                if (str_starts_with($literal, $key)) {
                    $found[] = [$token[2], $literal];

                    break;
                }
            }
        }

        return $found;
    }

    private function isAllowed(string $relative, string $call): bool
    {
        $allowed = self::ALLOWED_CALLS[$relative] ?? [];

        return in_array('*', $allowed, true) || in_array($call, $allowed, true);
    }
}

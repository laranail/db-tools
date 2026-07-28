<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Simtabi\Laranail\DbTools\Concerns\ManagesTransactions;
use Simtabi\Laranail\DbTools\Support\ConnectionContext;

/**
 * Class SqlFileRestorer
 *
 * Restores database from SQL dump files.
 * Handles SQL statement parsing and execution with proper error handling.
 */
class SqlFileRestorer
{
    use ManagesTransactions;

    /**
     * Restore database from an SQL file
     *
     * @param  string  $path  Path to SQL dump file
     * @param  string|null  $connection  Connection name (null for default)
     * @return bool True if restore successful
     *
     * @throws InvalidArgumentException If file doesn't exist or is empty
     * @throws RuntimeException If restore fails
     */
    public function restore(string $path, ?string $connection = null): bool
    {
        $this->validateFile($path);

        $sql = File::get($path);
        $statements = $this->parseStatements($sql);

        if ($statements === []) {
            throw new InvalidArgumentException("SQL file contains no executable statements: {$path}");
        }

        try {
            return $this->executeStatements($statements, $connection);
        } catch (QueryException $e) {
            throw new RuntimeException('Database restoration failed: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate that the SQL file exists and is readable
     *
     * @throws InvalidArgumentException
     */
    private function validateFile(string $path): void
    {
        if (! File::exists($path)) {
            throw new InvalidArgumentException("SQL file not found: {$path}");
        }

        if (! File::isReadable($path)) {
            throw new InvalidArgumentException("SQL file is not readable: {$path}");
        }

        if (File::size($path) === 0) {
            throw new InvalidArgumentException("SQL file is empty: {$path}");
        }
    }

    /**
     * Parse SQL file into individual statements
     *
     * Handles:
     * - SQL comments (single-line and block comments)
     * - String literals (respects single/double quotes and escaped quotes)
     * - PostgreSQL dollar-quoted blocks ($$ ... $$ and $tag$ ... $tag$), so a
     *   semicolon inside a function body does not split the statement
     * - Statement delimiters (semicolons)
     *
     * The single O(n) character scan is intentional and is fine for the dump
     * sizes this restorer targets (typical app dumps, not multi-GB exports);
     * a tokenising SQL parser would be heavier without practical benefit here.
     *
     * @param  string  $sql  Raw SQL content
     * @return array Array of SQL statements
     */
    private function parseStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = '';
        $dollarTag = null; // active dollar-quote tag, e.g. '$$' or '$body$'
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $nextChar = ($i + 1 < $length) ? $sql[$i + 1] : '';

            // Inside a dollar-quoted block: copy verbatim until the matching
            // closing tag is found. Nothing (quotes, semicolons) is special.
            if ($dollarTag !== null) {
                if ($char === '$' && substr($sql, $i, strlen($dollarTag)) === $dollarTag) {
                    $current .= $dollarTag;
                    $i += strlen($dollarTag) - 1;
                    $dollarTag = null;
                } else {
                    $current .= $char;
                }

                continue;
            }

            // Comments are only comments outside string literals (dollar-quoted
            // blocks already returned above). Handling them here rather than in a
            // regex pre-pass is what keeps "--" and "/* */" INSIDE a value as
            // data: stripping them beforehand also removed the closing quote and
            // delimiter, merging the row with the next one, or silently dropped
            // part of a value while leaving valid SQL behind.
            if (! $inString && $char === '-' && $nextChar === '-') {
                $newline = strpos($sql, "\n", $i);

                if ($newline === false) {
                    break;
                }

                // Resume on the newline itself so it survives as a separator,
                // matching how the comment-stripping pre-pass used to behave.
                $i = $newline - 1;

                continue;
            }

            if (! $inString && $char === '/' && $nextChar === '*') {
                $end = strpos($sql, '*/', $i + 2);

                if ($end === false) {
                    // Unterminated block comment: the rest of the file is comment.
                    break;
                }

                $i = $end + 1;

                continue;
            }

            // A dollar-quote can only open when we are not inside a normal
            // string literal. Match $$ or $tag$ where tag is an identifier.
            if (! $inString && $char === '$'
                && preg_match('/^\$[A-Za-z_]\w*\$|^\$\$/', substr($sql, $i), $m) === 1
            ) {
                $dollarTag = $m[0];
                $current .= $dollarTag;
                $i += strlen($dollarTag) - 1;

                continue;
            }

            if (! $inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
            } elseif ($inString && $char === $stringChar) {
                if ($nextChar === $stringChar) {
                    // Doubled quote is an escaped quote, not a string close.
                    $current .= $char.$nextChar;
                    $i++;
                } else {
                    $inString = false;
                    $current .= $char;
                }
            } elseif (! $inString && $char === ';') {
                $statement = trim($current);
                if ($statement !== '' && $statement !== '0') {
                    $statements[] = $statement;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        $statement = trim($current);
        if ($statement !== '' && $statement !== '0') {
            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * Execute SQL statements with transaction support
     *
     * @param  array  $statements  Array of SQL statements
     * @param  string|null  $connection  Connection name
     * @return bool True if all statements executed successfully
     *
     * @throws QueryException If any statement fails
     */
    private function executeStatements(array $statements, ?string $connection = null): bool
    {
        // The transaction must be opened on the SAME connection the statements
        // run on, or a non-default restore has no atomicity: the work commits as
        // it goes and the rollback undoes an empty transaction elsewhere.
        return $this->transactionOrFail(function () use ($statements, $connection): true {
            $conn = ConnectionContext::for($connection)->connection();

            foreach ($statements as $statement) {
                $conn->unprepared($statement);
            }

            return true;
        }, $connection);
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Migrations;

use RuntimeException;

/**
 * Decides whether this installation may have its schema dropped.
 *
 * `down()` drops tables. That is what you want while developing a schema and
 * what you never want on a live installation, where `migrate:rollback` — one
 * mistyped command, or an automated deploy step that runs it on failure —
 * destroys the customer's data with no confirmation and no backup.
 *
 * The policy is environment-based rather than a flag per migration, because the
 * question is never "is this migration reversible" (they all are) but "is this
 * a database where losing everything is acceptable".
 */
final class ReversalPolicy
{
    /**
     * Environments where dropping the schema is part of the normal workflow.
     *
     * `testing` is in the list because `RefreshDatabase` runs `migrate:fresh`,
     * and a suite that could not rebuild its schema could not run.
     *
     * @var list<string>
     */
    public const array DEFAULT_ENVIRONMENTS = ['local', 'development', 'dev', 'testing'];

    private const string OVERRIDE_KEY = 'laranail.db-tools.migrations.allow_rollback';

    private const string ENVIRONMENTS_KEY = 'laranail.db-tools.migrations.reversible_environments';

    public static function isPermitted(): bool
    {
        if (self::overridden()) {
            return true;
        }

        return in_array(strtolower((string) app()->environment()), self::environments(), true);
    }

    /**
     * Stop an operation that would destroy data, explaining both why and how to
     * proceed if it really was intended.
     *
     * @throws RuntimeException
     */
    public static function guard(string $operation = 'roll back migrations'): void
    {
        if (self::isPermitted()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to %s in the "%s" environment: this drops tables, which would delete every '
            . 'row in this database. Do it in %s, or set %s=true if it is genuinely intended — '
            . 'take a backup first.',
            $operation,
            app()->environment(),
            implode('/', self::environments()),
            'DB_TOOLS_ALLOW_ROLLBACK',
        ));
    }

    /**
     * @return list<string>
     */
    public static function environments(): array
    {
        $configured = config(self::ENVIRONMENTS_KEY);

        if (! is_array($configured) || $configured === []) {
            return self::DEFAULT_ENVIRONMENTS;
        }

        return array_values(array_map(
            static fn (mixed $value): string => strtolower((string) $value),
            array_filter($configured, is_string(...)),
        ));
    }

    /**
     * Whether the operator has explicitly opted in.
     *
     * `config()`, **not** `env()`. `config:cache` is routine on exactly the
     * servers where this guard matters, and `env()` returns null once the
     * configuration is cached — which would shut the escape hatch for the one
     * operator who needs it, at the worst possible moment.
     */
    private static function overridden(): bool
    {
        return filter_var(config(self::OVERRIDE_KEY, false), FILTER_VALIDATE_BOOLEAN);
    }
}

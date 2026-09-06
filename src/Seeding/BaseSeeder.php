<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Seeding;

use InvalidArgumentException;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Seeding\Concerns\InteractsWithSeederOutput;

/**
 * Shared behaviour for application seeders.
 *
 * Three concerns, each of which every seeder otherwise re-implements or gets
 * quietly wrong:
 *
 * 1. **Idempotence.** Seeders run on install and re-run on upgrade, so they must
 *    converge rather than duplicate. {@see upsert()} states a row's identity
 *    explicitly rather than leaving it implicit in a `firstOrCreate` argument
 *    list.
 *
 * 2. **Output.** See {@see InteractsWithSeederOutput} — `Seeder::$command` is an
 *    uninitialised typed property outside console runs, and `?->` does not
 *    guard one.
 *
 * 3. **Production safety.** {@see blockedInProduction()} is not a convenience.
 *    Demo seeders exist to write fixtures that must never reach a production
 *    installation — published passwords, and in at least one real case a
 *    published TOTP secret enrolled against staff accounts that can suspend or
 *    delete any customer. The guard is the only thing standing between that
 *    fixture and a production database, so it fails closed and its override is
 *    explicit.
 */
abstract class BaseSeeder extends Seeder
{
    use InteractsWithSeederOutput;

    /**
     * Create or converge one row, identified by `$identity`.
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $identity the columns that decide "the same row"
     * @param array<string, mixed> $attributes everything else
     */
    protected function upsert(string $model, array $identity, array $attributes = []): Model
    {
        return $model::query()->updateOrCreate($identity, $attributes);
    }

    /**
     * Converge a list of rows keyed on one attribute.
     *
     * Returns what happened, not how many rows it was handed. The version this
     * replaces documented "number of rows written" and returned `count($rows)`
     * — the same number on every run, whether it wrote anything or not, so a
     * seeder reported "40 roles" on its fortieth idempotent re-run.
     *
     * @param class-string<Model> $model
     * @param string $key the attribute identifying a row
     * @param list<array<string, mixed>> $rows
     */
    protected function upsertAll(string $model, string $key, array $rows): UpsertResult
    {
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach ($rows as $row) {
            if (! array_key_exists($key, $row)) {
                // A row with no identity cannot be converged — it would be
                // inserted afresh on every run, which is the duplication this
                // method exists to prevent. Skipping is wrong too, so say so.
                throw new InvalidArgumentException(sprintf(
                    'Row is missing its identity column [%s], so it cannot be upserted into %s.',
                    $key,
                    $model,
                ));
            }

            $record = $this->upsert($model, [$key => $row[$key]], $row);

            // wasRecentlyCreated and wasChanged are Eloquent's own record of
            // what the write did, which is why this counts rather than guesses.
            match (true) {
                $record->wasRecentlyCreated => $created++,
                $record->wasChanged()       => $updated++,
                default                     => $unchanged++,
            };
        }

        return new UpsertResult($created, $updated, $unchanged);
    }

    /**
     * True when this seeder must not touch the database.
     *
     * Fails closed: production is blocked unless the operator passed `--force`
     * to a command that declares it. Tests run under `APP_ENV=testing` and are
     * unaffected.
     */
    protected function blockedInProduction(): bool
    {
        return app()->environment('production')
            && ! app()->runningUnitTests()
            && ! $this->hasForceOption();
    }

    /**
     * Refuse to run, and say why.
     *
     * The companion to {@see blockedInProduction()} for seeders that would
     * rather stop loudly than return early — a demo seeder that silently does
     * nothing on production looks like a seeder that ran.
     */
    protected function refuseInProduction(string $what = 'This seeder'): bool
    {
        if (! $this->blockedInProduction()) {
            return false;
        }

        $this->tell('warn', sprintf(
            '%s writes fixtures that must not exist on a production installation, so it was skipped. '
            . 'Pass --force if this is genuinely what you want.',
            $what,
        ));

        return true;
    }

    private function hasForceOption(): bool
    {
        $command = $this->console();

        // Not every command that can drive a seeder declares --force, and
        // asking an InputDefinition for an undeclared option throws rather than
        // returning null.
        return $command instanceof Command
            && $command->getDefinition()->hasOption('force')
            && (bool) $command->option('force');
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Seeding;

use Faker\Generator;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Exceptions\SeedFileException;
use Simtabi\Laranail\DbTools\Seeding\Concerns\InteractsWithSeedFiles;

final class InteractsWithSeedFilesTest extends TestCase
{
    private string $sandbox;

    private object $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir() . '/laranail-seedfiles-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->sandbox . '/nested');

        File::put($this->sandbox . '/countries.json', json_encode(['KE', 'TZ', 'UG']));
        File::put($this->sandbox . '/broken.json', 'not json at all');
        File::put($this->sandbox . '/notes.txt', 'hello');
        File::put($this->sandbox . '/nested/b.json', '[]');
        File::put($this->sandbox . '/nested/a.json', '[]');
        File::put($this->sandbox . '/nested/c.csv', 'x');

        $this->seeder = new class
        {
            use InteractsWithSeedFiles {
                fake as public;
                seedFaker as public;
                seedFileBasePath as public;
                setSeedFileBasePath as public;
                seedFilePath as public;
                seedFileExists as public;
                seedFileContents as public;
                seedFileJson as public;
                seedFiles as public;
            }
        };
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // fake()
    // -----------------------------------------------------------------

    #[Test]
    public function the_generator_is_memoized(): void
    {
        // Not for speed — for reproducibility. A fresh Factory::create() per
        // call reseeds the RNG, so seeding once at the start of a run could
        // never make the run deterministic.
        self::assertSame($this->seeder->fake(), $this->seeder->fake());
    }

    #[Test]
    public function seeding_makes_a_run_reproducible(): void
    {
        $first = $this->seeder->seedFaker(1234)->numberBetween(1, 1_000_000);
        $second = $this->seeder->seedFaker(1234)->numberBetween(1, 1_000_000);

        self::assertSame($first, $second);
    }

    #[Test]
    public function a_locale_specific_generator_does_not_replace_the_default(): void
    {
        $default = $this->seeder->fake();
        $localized = $this->seeder->fake('fr_FR');

        self::assertNotSame($default, $localized);
        self::assertSame($default, $this->seeder->fake(), 'One localized call repointed the default.');
    }

    #[Test]
    public function the_configured_locale_is_used(): void
    {
        config()->set('laranail.db-tools.seeding.faker_locale', 'en_GB');

        self::assertInstanceOf(Generator::class, $this->seeder->fake());
    }

    // -----------------------------------------------------------------
    // Paths
    // -----------------------------------------------------------------

    #[Test]
    public function the_base_path_defaults_to_the_seeders_files_directory(): void
    {
        self::assertSame(database_path('seeders/files'), $this->seeder->seedFileBasePath());
    }

    #[Test]
    public function config_overrides_the_default_base_path(): void
    {
        config()->set('laranail.db-tools.seeding.files_path', '/srv/fixtures');

        self::assertSame('/srv/fixtures', $this->seeder->seedFileBasePath());
    }

    #[Test]
    public function a_per_instance_path_wins_over_config(): void
    {
        config()->set('laranail.db-tools.seeding.files_path', '/srv/fixtures');
        $this->seeder->setSeedFileBasePath('/tmp/mine/');

        self::assertSame('/tmp/mine', $this->seeder->seedFileBasePath());
        self::assertSame('/tmp/mine/x.json', $this->seeder->seedFilePath('x.json'));
    }

    #[Test]
    public function an_absolute_path_passes_through_unchanged(): void
    {
        $this->seeder->setSeedFileBasePath($this->sandbox);

        self::assertSame('/etc/hosts', $this->seeder->seedFilePath('/etc/hosts'));
    }

    // -----------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------

    #[Test]
    public function it_reads_a_fixture(): void
    {
        $this->seeder->setSeedFileBasePath($this->sandbox);

        self::assertTrue($this->seeder->seedFileExists('notes.txt'));
        self::assertSame('hello', $this->seeder->seedFileContents('notes.txt'));
    }

    #[Test]
    public function a_missing_fixture_throws_rather_than_returning_empty(): void
    {
        $this->seeder->setSeedFileBasePath($this->sandbox);

        // Asserted on the message rather than a numeric code: the codes were a
        // package-tools convention and db-tools' exceptions do not carry them,
        // so importing the numbering along with the trait would have left one
        // scheme with a single member.
        $this->expectException(SeedFileException::class);
        $this->expectExceptionMessage('nope.txt');

        $this->seeder->seedFileContents('nope.txt');
    }

    #[Test]
    public function it_decodes_json_fixtures(): void
    {
        $this->seeder->setSeedFileBasePath($this->sandbox);

        self::assertSame(['KE', 'TZ', 'UG'], $this->seeder->seedFileJson('countries.json'));
    }

    #[Test]
    public function malformed_json_throws(): void
    {
        $this->seeder->setSeedFileBasePath($this->sandbox);

        $this->expectException(SeedFileException::class);

        $this->seeder->seedFileJson('broken.json');
    }

    // -----------------------------------------------------------------
    // Listing
    // -----------------------------------------------------------------

    #[Test]
    public function it_lists_fixtures_in_sorted_order(): void
    {
        $this->seeder->setSeedFileBasePath($this->sandbox);

        self::assertSame(
            [$this->sandbox . '/nested/a.json', $this->sandbox . '/nested/b.json', $this->sandbox . '/nested/c.csv'],
            $this->seeder->seedFiles('nested'),
        );
    }

    #[Test]
    public function listing_can_filter_by_extension(): void
    {
        $this->seeder->setSeedFileBasePath($this->sandbox);

        self::assertSame(
            [$this->sandbox . '/nested/a.json', $this->sandbox . '/nested/b.json'],
            $this->seeder->seedFiles('nested', 'json'),
        );
        self::assertSame(
            [$this->sandbox . '/nested/c.csv'],
            $this->seeder->seedFiles('nested', '.csv'),
        );
    }

    #[Test]
    public function a_missing_directory_lists_nothing_rather_than_failing(): void
    {
        $this->seeder->setSeedFileBasePath($this->sandbox);

        self::assertSame([], $this->seeder->seedFiles('does-not-exist'));
    }
}

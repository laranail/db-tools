<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Concerns\HasArchiver;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class ArchivableWidget extends Model
{
    use HasArchiver;

    protected $table = 'archivable_widgets';

    protected $guarded = [];
}

/**
 * A model that opts out of archiving via the documented $archives flag.
 */
final class UnarchivableWidget extends Model
{
    use HasArchiver;

    protected $table = 'archivable_widgets';

    protected $guarded = [];

    public bool $archives = false;
}

final class HasArchiverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('archivable_widgets', function ($t): void {
            $t->id();
            $t->string('name');
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
        });
    }

    public function test_archived_rows_are_hidden_by_the_global_scope(): void
    {
        $a = ArchivableWidget::create(['name' => 'keep']);
        $b = ArchivableWidget::create(['name' => 'archive']);

        $b->archive();

        self::assertTrue($b->isArchived());
        self::assertSame(1, ArchivableWidget::query()->count());
        self::assertTrue(ArchivableWidget::query()->first()->is($a));
    }

    public function test_only_archived_and_with_archived(): void
    {
        ArchivableWidget::create(['name' => 'live']);
        $archived = ArchivableWidget::create(['name' => 'gone']);
        $archived->archive();

        self::assertSame(2, ArchivableWidget::query()->withArchived()->count());
        self::assertSame(1, ArchivableWidget::query()->onlyArchived()->count());
        self::assertTrue(ArchivableWidget::query()->onlyArchived()->first()->is($archived));
    }

    public function test_unarchive_restores_visibility(): void
    {
        $w = ArchivableWidget::create(['name' => 'x']);
        $w->archive();
        self::assertSame(0, ArchivableWidget::query()->count());

        $w->unArchive();

        self::assertFalse($w->fresh()->isArchived());
        self::assertSame(1, ArchivableWidget::query()->count());
    }

    public function test_archive_events_fire(): void
    {
        $fired = [];
        ArchivableWidget::archiving(function () use (&$fired): void {
            $fired[] = 'archiving';
        });
        ArchivableWidget::archived(function () use (&$fired): void {
            $fired[] = 'archived';
        });

        ArchivableWidget::create(['name' => 'e'])->archive();

        self::assertSame(['archiving', 'archived'], $fired);
    }

    public function test_archive_reports_failure_when_no_row_was_matched(): void
    {
        $widget = ArchivableWidget::create(['name' => 'vanishing']);

        // The row is gone, but the in-memory model still believes it exists.
        // runArchive() discarded the UPDATE's affected-row count, so archive()
        // returned true, fired the "archived" event and stamped the attribute
        // for a row that was never touched.
        ArchivableWidget::query()->withArchived()->whereKey($widget->getKey())->delete();

        self::assertFalse($widget->archive());
    }

    public function test_un_archive_refuses_a_model_that_was_never_persisted(): void
    {
        $widget = new ArchivableWidget(['name' => 'never saved']);

        // archive() guards on $this->exists; unArchive() did not, and set
        // exists = true unconditionally — so save() issued an UPDATE for a row
        // that does not exist and reported success.
        self::assertNull($widget->unArchive());
        self::assertFalse($widget->exists);
    }

    public function test_archives_flag_opts_a_model_out_of_the_global_scope(): void
    {
        // $archives is public API in the trait and documented as the switch,
        // but nothing ever read it: the scope applied regardless, so a model
        // that opted out still had its archived rows hidden.
        $widget = UnarchivableWidget::create(['name' => 'always visible']);
        $widget->archive();

        self::assertSame(1, UnarchivableWidget::query()->count());
    }

    public function test_archives_flag_left_on_still_hides_archived_rows(): void
    {
        $widget = ArchivableWidget::create(['name' => 'hidden']);
        $widget->archive();

        self::assertSame(0, ArchivableWidget::query()->count());
    }
}

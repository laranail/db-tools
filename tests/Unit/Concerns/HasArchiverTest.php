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
}

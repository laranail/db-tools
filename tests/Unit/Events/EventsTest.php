<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use Simtabi\Laranail\DbTools\Events\DatabaseEvents;

final class EventsTest extends TestCase
{
    public function test_database_event_factory_populates_action_and_metadata(): void
    {
        $event = DatabaseEvents::configured(['driver' => 'sqlite']);

        self::assertSame('configured', $event->action);
        self::assertSame('database', $event->type);
        self::assertSame(['driver' => 'sqlite'], $event->getDatabaseConfig());
        self::assertSame('Database Configured', $event->getDisplayName());
        self::assertSame('medium', $event->getPriorityLevel());
        self::assertTrue($event->isSuccessful());
    }

    public function test_database_event_unknown_action_falls_back_to_base(): void
    {
        $event = DatabaseEvents::migrationFailed('create_users', 'boom');

        self::assertSame('high', $event->getPriorityLevel());
        self::assertStringContainsString('boom', $event->getDescription());
        self::assertSame('create_users', $event->getMigrationName());
    }

    public function test_base_event_records_fired_at(): void
    {
        $event = DatabaseEvents::configuring(['driver' => 'sqlite']);

        self::assertGreaterThan(0.0, $event->firedAt);
    }
}

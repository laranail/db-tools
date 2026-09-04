<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Backup;

use RuntimeException;
use InvalidArgumentException;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Backup\BackupManager;
use Simtabi\Laranail\DbTools\Backup\Contracts\BackupDriverInterface;

/**
 * A fake backup driver that records the arguments it was called with and
 * supports an arbitrary set of driver names. Used to assert that the manager
 * resolves and delegates to the correct driver.
 */
final class RecordingBackupDriver implements BackupDriverInterface
{
    public bool $backupCalled = false;

    public bool $restoreCalled = false;

    public ?array $lastConfig = null;

    public ?string $lastPath = null;

    public ?string $lastConnection = null;

    /** @param array<int, string> $supported */
    public function __construct(public array $supported, public bool $returns = true) {}

    public function backup(array $config, string $path): bool
    {
        $this->backupCalled = true;
        $this->lastConfig = $config;
        $this->lastPath = $path;

        return $this->returns;
    }

    public function restore(array $config, string $path, ?string $connection = null): bool
    {
        $this->restoreCalled = true;
        $this->lastConfig = $config;
        $this->lastPath = $path;
        $this->lastConnection = $connection;

        return $this->returns;
    }

    public function supports(string $driver): bool
    {
        return in_array($driver, $this->supported, true);
    }
}

final class BackupManagerTest extends TestCase
{
    public function test_supports_driver_recognises_default_drivers(): void
    {
        $manager = new BackupManager;

        self::assertTrue($manager->supportsDriver('sqlite'));
        self::assertTrue($manager->supportsDriver('mysql'));
        self::assertTrue($manager->supportsDriver('mariadb'));
        self::assertTrue($manager->supportsDriver('pgsql'));
        self::assertFalse($manager->supportsDriver('oracle'));
    }

    public function test_register_driver_is_fluent_and_adds_support(): void
    {
        $manager = new BackupManager;
        $driver = new RecordingBackupDriver(['oracle']);

        $returned = $manager->registerDriver($driver);

        self::assertSame($manager, $returned);
        self::assertTrue($manager->supportsDriver('oracle'));
    }

    public function test_restore_delegates_to_resolved_driver_for_connection_driver(): void
    {
        config()->set('database.connections.fake_restore', [
            'driver'   => 'oracle',
            'database' => 'whatever',
        ]);

        $driver = new RecordingBackupDriver(['oracle']);
        $manager = (new BackupManager)->registerDriver($driver);

        $result = $manager->restore('/tmp/some-backup.sql', 'fake_restore');

        self::assertTrue($result);
        self::assertTrue($driver->restoreCalled);
        self::assertSame('/tmp/some-backup.sql', $driver->lastPath);
        self::assertSame('fake_restore', $driver->lastConnection);
        self::assertSame('oracle', $driver->lastConfig['driver']);
    }

    public function test_backup_delegates_to_resolved_driver(): void
    {
        config()->set('database.connections.fake_backup', [
            'driver'   => 'oracle',
            'database' => 'whatever',
        ]);

        $driver = new RecordingBackupDriver(['oracle']);
        $manager = (new BackupManager)->registerDriver($driver);

        $result = $manager->backup('/tmp/out.sql', 'fake_backup');

        self::assertTrue($result);
        self::assertTrue($driver->backupCalled);
        self::assertSame('/tmp/out.sql', $driver->lastPath);
        // The manager injects the connection name into the config it passes on.
        self::assertSame('fake_backup', $driver->lastConfig['connection']);
    }

    public function test_restore_throws_for_unsupported_driver(): void
    {
        config()->set('database.connections.unsupported', [
            'driver'   => 'definitely-not-a-real-driver',
            'database' => 'whatever',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No backup driver supports database driver: definitely-not-a-real-driver');

        (new BackupManager)->restore('/tmp/x.sql', 'unsupported');
    }

    public function test_restore_throws_for_unknown_connection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Database connection 'no_such_connection' not found");

        (new BackupManager)->restore('/tmp/x.sql', 'no_such_connection');
    }
}

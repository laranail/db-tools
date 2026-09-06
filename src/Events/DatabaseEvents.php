<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Events;

use Override;
use Illuminate\Http\Request;

/**
 * Database Events
 *
 * Handles database configuration and migration events. Seeding events
 * live in the laranail/package-tools package.
 */
class DatabaseEvents extends BaseEvent
{
    /**
     * Database configuration started event
     */
    public static function configuring(array $databaseConfig, ?Request $request = null, ?array $metadata = null): self
    {
        $event = new self;
        $event->createEvent('configuring', 'database', $request, $metadata);
        $event->metadata = array_merge($event->metadata, ['database_config' => $databaseConfig]);

        return $event;
    }

    /**
     * Database configuration completed event
     */
    public static function configured(array $databaseConfig, ?Request $request = null, ?array $metadata = null): self
    {
        $event = new self;
        $event->createEvent('configured', 'database', $request, $metadata);
        $event->metadata = array_merge($event->metadata, ['database_config' => $databaseConfig]);

        return $event;
    }

    /**
     * Database connection failed event
     */
    public static function connectionFailed(string $reason, array $databaseConfig = [], ?Request $request = null, ?array $metadata = null): self
    {
        $event = new self;
        $event->createEvent('connection_failed', 'database', $request, $metadata);
        $event->metadata = array_merge($event->metadata, [
            'reason'          => $reason,
            'database_config' => $databaseConfig,
        ]);

        return $event;
    }

    /**
     * Database migration started event
     */
    public static function migrationStarted(string $migrationName, ?Request $request = null, ?array $metadata = null): self
    {
        $event = new self;
        $event->createEvent('migration_started', 'database', $request, $metadata);
        $event->metadata = array_merge($event->metadata, ['migration_name' => $migrationName]);

        return $event;
    }

    /**
     * Database migration completed event
     */
    public static function migrationCompleted(string $migrationName, ?Request $request = null, ?array $metadata = null): self
    {
        $event = new self;
        $event->createEvent('migration_completed', 'database', $request, $metadata);
        $event->metadata = array_merge($event->metadata, ['migration_name' => $migrationName]);

        return $event;
    }

    /**
     * Database migration failed event
     */
    public static function migrationFailed(string $migrationName, string $reason, ?Request $request = null, ?array $metadata = null): self
    {
        $event = new self;
        $event->createEvent('migration_failed', 'database', $request, $metadata);
        $event->metadata = array_merge($event->metadata, [
            'migration_name' => $migrationName,
            'reason'         => $reason,
        ]);

        return $event;
    }

    /**
     * Get database-specific display name
     */
    #[Override]
    public function getDisplayName(): string
    {
        return match ($this->action) {
            'configuring'         => 'Database Configuration Started',
            'configured'          => 'Database Configured',
            'connection_failed'   => 'Database Connection Failed',
            'migration_started'   => 'Database Migration Started',
            'migration_completed' => 'Database Migration Completed',
            'migration_failed'    => 'Database Migration Failed',
            default               => parent::getDisplayName(),
        };
    }

    /**
     * Get database-specific description
     */
    #[Override]
    public function getDescription(): string
    {
        $migrationName = $this->metadata['migration_name'] ?? null;
        $reason = $this->metadata['reason'] ?? null;

        return match ($this->action) {
            'configuring'         => 'Database configuration process has started',
            'configured'          => 'Database has been successfully configured',
            'connection_failed'   => 'Database connection failed: ' . ($reason ?? 'Unknown error'),
            'migration_started'   => 'Database migration started' . ($migrationName ? " for {$migrationName}" : ''),
            'migration_completed' => 'Database migration completed' . ($migrationName ? " for {$migrationName}" : ''),
            'migration_failed'    => 'Database migration failed' . ($migrationName ? " for {$migrationName}" : '') . ($reason ? ": {$reason}" : ''),
            default               => parent::getDescription(),
        };
    }

    /**
     * Get database-specific priority level
     */
    #[Override]
    public function getPriorityLevel(): string
    {
        return match ($this->action) {
            'connection_failed', 'migration_failed' => 'high',
            'configured', 'migration_completed'     => 'medium',
            'configuring', 'migration_started'      => 'low',
            default                                 => parent::getPriorityLevel(),
        };
    }

    /**
     * Check if database operation was successful
     */
    public function isSuccessful(): bool
    {
        return in_array($this->action, ['configured', 'migration_completed']);
    }

    /**
     * Get database operation result
     */
    public function getResult(): string
    {
        return match ($this->action) {
            'configured', 'migration_completed'     => 'success',
            'connection_failed', 'migration_failed' => 'failure',
            'configuring', 'migration_started'      => 'in_progress',
            default                                 => 'unknown',
        };
    }

    /**
     * Get database configuration from metadata
     */
    public function getDatabaseConfig(): ?array
    {
        return $this->metadata['database_config'] ?? null;
    }

    /**
     * Get migration name from metadata
     */
    public function getMigrationName(): ?string
    {
        return $this->metadata['migration_name'] ?? null;
    }

    /**
     * Get failure reason from metadata
     */
    public function getFailureReason(): ?string
    {
        return $this->metadata['reason'] ?? null;
    }
}

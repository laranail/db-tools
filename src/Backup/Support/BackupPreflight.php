<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Backup\Support;

use Simtabi\Laranail\DbTools\Backup\Enums\PreflightStatus;

/**
 * Whether a backup of one connection could run now, found without dumping
 * anything: the client tool it needs, the version of that tool, and, where
 * the tool must be at least as new as the server, the server's version.
 *
 * A version that could not be read is null. An unknown server version never
 * makes the result Outdated, because the backup itself only refuses a client
 * it has proved older.
 */
final readonly class BackupPreflight
{
    public function __construct(
        public PreflightStatus $status,
        public string $tool,
        public string $binary,
        public ?int $clientVersion,
        public ?int $serverVersion,
        public string $message,
    ) {}

    public static function ready(string $tool, string $binary, ?int $clientVersion, ?int $serverVersion, string $message): self
    {
        return new self(PreflightStatus::Ready, $tool, $binary, $clientVersion, $serverVersion, $message);
    }

    public static function missing(string $tool, string $binary): self
    {
        return new self(
            PreflightStatus::Missing,
            $tool,
            $binary,
            null,
            null,
            "{$tool} could not be run ({$binary}). Install it, put it on PATH, or point "
            . "laranail.db-tools.backup.binaries.{$tool} at it.",
        );
    }

    public function isReady(): bool
    {
        return $this->status === PreflightStatus::Ready;
    }
}

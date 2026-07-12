<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Events;

use Illuminate\Http\Request;

/**
 * Minimal event base. Subclasses use static factory methods that call
 * {@see createEvent()} to populate the shared action/type/metadata fields,
 * then override the get*() helpers for human-facing presentation.
 */
abstract class BaseEvent
{
    public readonly float $firedAt;

    /** The action that occurred (e.g. "configured", "executed"). */
    public ?string $action = null;

    /** The event family (e.g. "database"). */
    public ?string $type = null;

    /** The HTTP request in scope when the event fired, if any. */
    public ?Request $request = null;

    /** @var array<string, mixed> */
    public array $metadata = [];

    public function __construct()
    {
        $this->firedAt = microtime(true);
    }

    /**
     * Populate the shared event fields. Called by subclass factory methods.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    protected function createEvent(
        string $action,
        string $type,
        ?Request $request = null,
        ?array $metadata = null,
    ): void {
        $this->action = $action;
        $this->type = $type;
        $this->request = $request;
        $this->metadata = $metadata ?? [];
    }

    /**
     * Human-readable name. Subclasses override per action and fall back here.
     */
    public function getDisplayName(): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $this->action ?? 'event'));
    }

    /**
     * Human-readable description. Subclasses override per action and fall back
     * here.
     */
    public function getDescription(): string
    {
        return $this->getDisplayName();
    }

    /**
     * Priority/severity hint for listeners. Subclasses override per action and
     * fall back here.
     */
    public function getPriorityLevel(): string
    {
        return 'medium';
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

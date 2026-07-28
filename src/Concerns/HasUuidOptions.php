<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Concerns;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Simtabi\Laranail\DbTools\Exceptions\UuidException;

trait HasUuidOptions
{
    /**
     * Optional host-supplied UUID generator. Receives ($model, $context) and
     * returns the UUID string. Lets a host plug a custom generator (e.g. a
     * readable-for-testing UUID) without this package taking the dependency.
     */
    private static ?Closure $uuidGenerator = null;

    /**
     * Register (or clear, with null) a custom UUID generator.
     */
    public static function generateUuidUsing(?Closure $resolver): void
    {
        self::$uuidGenerator = $resolver;
    }

    /**
     * Default Uuid column name (defaults to "uuid")
     *
     * @var string
     */
    // protected $uuidColumnName  = 'uuid';

    /**
     * Default Uuid version to be used
     * Available 1,3,4 or 5
     *
     * @var string
     */
    // protected $uuidVersion     = 4;

    /**
     * Default Uuid string
     * Needed when $uuidVersion is "3 or 5"
     *
     * @var string
     */
    // protected $uuidString      = '';

    /**
     * Default development/testing environments
     *
     * @var string[]
     */
    // protected $devEnvironments = ['local', 'testing'];

    /**
     * Enable testing Uuid type
     *
     * @var bool
     */
    // protected $enableUuidTesting   = false;

    /**
     * Enable testing Uuid type
     *
     * @var bool
     */
    // protected $useTimeOrderedUuid  = false;

    /**
     * Enforce UUID usage (defaults to true; set false to opt out per-model)
     *
     * @var bool
     */
    // protected $enforceUuid  = true;

    /**
     * Get the column name that stores the UUID.
     *
     * Resolution order: a `uuidColumn()` method, then a `$uuidColumnName`
     * property, then the default `uuid` column. The UUID is treated as a
     * secondary column — the model keeps its own primary key.
     */
    public function getUuidColumnName(): string
    {
        if (method_exists($this, 'uuidColumn')) {
            return $this->uuidColumn();
        }

        return property_exists($this, 'uuidColumnName') ? $this->uuidColumnName : 'uuid';
    }

    /**
     * Get "uuid" version or default to 4.
     *
     * @return int
     */
    public function getUuidVersion()
    {
        return property_exists($this, 'uuidVersion') ? $this->uuidVersion : 4;
    }

    /**
     * Get string to generate uuid version 3 and 5.
     *
     * @return string
     */
    public function getUuidString()
    {
        return property_exists($this, 'uuidString') ? $this->uuidString : '';
    }

    /**
     * @return string[]
     */
    public function getDevEnvironments()
    {
        return property_exists($this, 'devEnvironments') ? $this->devEnvironments : ['local', 'testing'];
    }

    /**
     * @return bool
     */
    public function isEnableUuidTesting()
    {
        return property_exists($this, 'enableUuidTesting') ? $this->enableUuidTesting : false;
    }

    /**
     * Checks to see if "Time Ordered" UUIDs have been specified
     */
    public function isUseTimeOrderedUuid(): bool
    {
        return property_exists($this, 'useTimeOrderedUuid') ? $this->useTimeOrderedUuid : false;
    }

    /**
     * Checks to see if we have to use UUID
     */
    public function isEnforceUuid(): bool
    {
        return property_exists($this, 'enforceUuid') ? $this->enforceUuid : true;
    }

    /**
     * Set the uuid value.
     *
     * @param  string  $value
     * @return static
     */
    public function setUuid($value)
    {
        if (! empty($this->getUuidColumnName())) {
            $this->{$this->getUuidColumnName()} = $value;
        }

        return $this;
    }

    /**
     * Get the uuid value.
     *
     *
     * @throws Exception
     */
    public function getUuid(): string
    {
        if (! empty($this->getUuidColumnName())) {
            return (string) $this->{$this->getUuidColumnName()};
        }

        throw UuidException::missingValue($this->getUuidColumnName());
    }

    /**
     * Gets a generated UUID.
     *
     * Time-ordered UUIDs (lexically sortable) are produced when
     * {@see isUseTimeOrderedUuid()} is enabled; otherwise a standard
     * random (v4) UUID is returned.
     */
    public function getGeneratedUuid($model = null): string
    {
        if (self::$uuidGenerator instanceof Closure) {
            return (string) (self::$uuidGenerator)($this, $model);
        }

        // $uuidVersion and $uuidString are published knobs with public
        // resolvers, but nothing consulted them — every model got a random v4.
        // A model configured for v5, whose whole point is that the same input
        // yields the same id, silently got a fresh value each time, so
        // "idempotent" re-imports inserted duplicates.
        $version = (int) $this->getUuidVersion();

        return match ($version) {
            1 => Uuid::uuid1()->toString(),
            3 => Uuid::uuid3($this->uuidNamespace(), $this->requireUuidString(3))->toString(),
            5 => Uuid::uuid5($this->uuidNamespace(), $this->requireUuidString(5))->toString(),
            4 => $this->isUseTimeOrderedUuid()
                ? (string) Str::orderedUuid()
                : (string) Str::uuid(),
            default => throw new InvalidArgumentException(
                "Unsupported \$uuidVersion [{$version}] on ".static::class.'. Supported versions are 1, 3, 4 and 5.'
            ),
        };
    }

    /**
     * Namespace for the name-based (v3/v5) versions.
     *
     * Override to namespace ids per application; the default matches what
     * ramsey/uuid documents for name-based generation.
     */
    protected function uuidNamespace(): string
    {
        return Uuid::NAMESPACE_DNS;
    }

    /**
     * The name a v3/v5 UUID is derived from.
     *
     * Falling back to a random value would defeat the only reason to ask for a
     * name-based version, so an absent name is an error rather than a silent
     * downgrade.
     */
    private function requireUuidString(int $version): string
    {
        $name = (string) $this->getUuidString();

        if (trim($name) === '') {
            throw new InvalidArgumentException(
                'UUID version '.$version.' is name-based and needs a $uuidString on '.static::class.'.'
            );
        }

        return $name;
    }

    /**
     *  Scoping method to search for a record via the UUID
     *
     *
     * @return mixed
     */
    public function scopeByUuid($query, $uuid)
    {
        return $query->where($this->getUuidColumnName(), $uuid);
    }

    /**
     * Static call to search for a record via the UUID
     *
     *
     * @return mixed
     */
    public static function findByUuid($uuid)
    {
        return static::byUuid($uuid)->first();
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Override;

/**
 * Read model over Laravel's database session table (`SESSION_DRIVER=database`).
 *
 * The package ships no migration for this — it reads the table the framework's
 * own session driver creates. Use it to inspect/relate session rows (e.g. "who
 * is online"); writes still go through the session driver, not this model.
 *
 * @property string $id
 * @property int|string|null $user_id
 * @property string|null $payload
 * @property int $last_activity
 */
class DatabaseSession extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    /**
     * Sessions use a string primary key and do not auto-increment.
     *
     * @var string
     */
    protected $keyType = 'string';

    protected $table = 'sessions';

    /**
     * Read model — no input is mass-assigned from requests, so guarding adds no
     * safety here.
     *
     * @var array<string>
     */
    protected $guarded = [];

    /**
     * Fully-qualified class name of the related user model.
     *
     * @var class-string<Model>|null
     */
    protected ?string $userModelClass = null;

    /**
     * Override the table name (defaults to `sessions`).
     */
    public function usingTable(string $table): static
    {
        $this->setTable($table);

        return $this;
    }

    /**
     * Set the related user model class for {@see user()}.
     *
     * @param  class-string<Model>  $userModelClass
     */
    public function usingUserModel(string $userModelClass): static
    {
        $this->userModelClass = $userModelClass;

        return $this;
    }

    /**
     * The session's decoded payload (Laravel stores `base64_encode(serialize($data))`).
     *
     * @return array<array-key, mixed>
     */
    public function getUnserializedPayloadAttribute(): array
    {
        if ($this->payload === null || $this->payload === '') {
            return [];
        }

        $decoded = base64_decode($this->payload, true);

        if ($decoded === false) {
            return [];
        }

        $data = @unserialize($decoded, ['allowed_classes' => false]);

        return is_array($data) ? $data : [];
    }

    /**
     * The session's last-activity timestamp as a Carbon instance.
     */
    public function getLastActivityAtAttribute(): Carbon
    {
        return Carbon::createFromTimestamp($this->last_activity);
    }

    /**
     * The user that owns the session.
     *
     * Falls back to the application's configured auth user model when
     * usingUserModel() has not been called. The old fallback was Model::class,
     * which is abstract, so any access fatalled with "Cannot instantiate
     * abstract class" rather than saying what was missing.
     *
     * @return BelongsTo<Model, $this>
     *
     * @throws LogicException when no user model is configured
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo($this->resolveUserModelClass());
    }

    /**
     * Carry usingUserModel() across hydration.
     *
     * newFromBuilder() builds a fresh instance, so the configuration set on the
     * model the query was started from never reached the rows it returned —
     * every hydrated session fell back to the default.
     *
     * @param  array<string, mixed>  $attributes
     */
    #[Override]
    public function newInstance($attributes = [], $exists = false): static
    {
        $instance = parent::newInstance($attributes, $exists);
        $instance->userModelClass = $this->userModelClass;

        return $instance;
    }

    /**
     * @return class-string<Model>
     *
     * @throws LogicException
     */
    protected function resolveUserModelClass(): string
    {
        if ($this->userModelClass !== null) {
            return $this->userModelClass;
        }

        $configured = config('auth.providers.users.model');

        if (is_string($configured) && is_subclass_of($configured, Model::class)) {
            return $configured;
        }

        throw new LogicException(
            'DatabaseSession has no user model to relate to. Call usingUserModel(YourUser::class), '
            .'or configure auth.providers.users.model.',
        );
    }
}

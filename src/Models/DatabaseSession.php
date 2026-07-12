<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
    /**
     * Sessions use a string primary key and do not auto-increment.
     *
     * @var string
     */
    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

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
     * The user that owns the session, when a user model has been configured.
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo($this->userModelClass ?? Model::class);
    }
}

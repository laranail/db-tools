# Database session read-model (`DatabaseSession`)

`Models\DatabaseSession` is a **read model** over Laravel's own `sessions` table
(`SESSION_DRIVER=database`). The package ships **no migration** for it — it reads
the table the framework's session driver already creates. Use it to inspect or
relate session rows ("who is online", per-user session lists); **writes still go
through the session driver, not this model**.

```php
use Simtabi\Laranail\DbTools\Models\DatabaseSession;
use App\Models\User;

$online = DatabaseSession::query()
    ->usingUserModel(User::class)               // wire up the user() relation
    ->where('last_activity', '>=', now()->subMinutes(5)->timestamp)
    ->get();

$session->unserialized_payload;   // safely decoded payload (allowed_classes: false)
$session->last_activity_at;       // last_activity as a Carbon instance
$session->user;                   // BelongsTo the configured user model
```

| Member | Effect |
|---|---|
| `usingTable(string $table)` | Override the table name (defaults to `sessions`). |
| `usingUserModel(class-string $model)` | Set the related model for `user()`. |
| `getUnserializedPayloadAttribute(): array` | Decode Laravel's `base64(serialize(...))` payload **with `allowed_classes: false`** (no object injection). |
| `getLastActivityAtAttribute(): Carbon` | `last_activity` (Unix ts) as Carbon. |
| `user(): BelongsTo` | The owning user, when a user model is configured. |

It is a string-keyed, non-incrementing, timestamp-less read model
(`$table = 'sessions'`).

[← Docs index](../../README.md#documentation)

<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Example: an Eloquent model using every laranail/db-tools trait.
|------------------------------------------------------------------------------
| Adjust the namespace + table name for your app. The model auto-stamps:
|
|   - a UUID on `uuid`
|   - a ULID on `ulid` (lexicographically sortable by creation time)
|   - JSON encode/decode on `metadata` and `audit`
|   - created_by/updated_by/deleted_by via AuditObserver
|
| Pair with a migration that uses the schema macros — see
| OrderMigration.php in this directory.
*/

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Simtabi\Laranail\DbTools\Concerns\HasJsonColumnAccessors;
use Simtabi\Laranail\DbTools\Concerns\HasUlid;
use Simtabi\Laranail\DbTools\Concerns\HasUuid;
use Simtabi\Laranail\DbTools\Observers\AuditObserver;

class Order extends Model
{
    use HasJsonColumnAccessors;
    use HasUlid;
    use HasUuid;
    use SoftDeletes;

    /** @var list<string> Auto-encode/decode these columns as JSON. */
    protected array $jsonColumns = ['metadata', 'audit'];

    protected $guarded = [];

    protected static function booted(): void
    {
        static::observe(AuditObserver::class);
    }
}

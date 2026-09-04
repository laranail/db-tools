<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Casts;

use Illuminate\Database\Eloquent\Model;
use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Casts\CastDatetime;

final class CastDatetimeTest extends TestCase
{
    public function test_get_presents_stored_utc_in_display_timezone(): void
    {
        $result = (new CastDatetime('Europe/Paris'))->get($this->model(), 'at', '2024-01-01 12:00:00', []);

        self::assertNotNull($result);
        self::assertSame('Europe/Paris', $result->getTimezone()->getName());
        self::assertSame('2024-01-01 13:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_set_normalizes_input_to_utc(): void
    {
        $stored = (new CastDatetime('Europe/Paris'))->set($this->model(), 'at', '2024-01-01 13:00:00', []);

        self::assertSame('2024-01-01 12:00:00', $stored);
    }

    public function test_null_round_trips(): void
    {
        $cast = new CastDatetime('UTC');

        self::assertNull($cast->get($this->model(), 'at', null, []));
        self::assertNull($cast->set($this->model(), 'at', null, []));
    }

    private function model(): Model
    {
        return new class extends Model {};
    }
}

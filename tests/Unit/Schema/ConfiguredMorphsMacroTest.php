<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class ConfiguredMorphsMacroTest extends TestCase
{
    public function test_configured_morphs_creates_id_and_type_columns_by_default(): void
    {
        config()->set('laranail.db-tools.id_type', 'BIGINT');
        config()->set('laranail.db-tools.using_uuids_for_id', false);
        config()->set('laranail.db-tools.using_ulids_for_id', false);

        Schema::create('cm_default', function ($t): void {
            $t->id();
            $t->configuredMorphs('owner');
        });

        self::assertTrue(Schema::hasColumn('cm_default', 'owner_id'));
        self::assertTrue(Schema::hasColumn('cm_default', 'owner_type'));
        self::assertSame('integer', Schema::getColumnType('cm_default', 'owner_id'));
    }

    public function test_configured_morphs_creates_uuid_columns_when_uuid_configured(): void
    {
        config()->set('laranail.db-tools.using_uuids_for_id', true);

        Schema::create('cm_uuid', function ($t): void {
            $t->id();
            $t->configuredMorphs('owner');
        });

        self::assertTrue(Schema::hasColumn('cm_uuid', 'owner_id'));
        self::assertTrue(Schema::hasColumn('cm_uuid', 'owner_type'));
        // uuidMorphs builds a (var)char-backed *_id column, never an integer one.
        self::assertNotSame('integer', Schema::getColumnType('cm_uuid', 'owner_id'));
    }

    public function test_configured_morphs_creates_ulid_columns_when_ulid_configured(): void
    {
        config()->set('laranail.db-tools.using_ulids_for_id', true);

        Schema::create('cm_ulid', function ($t): void {
            $t->id();
            $t->configuredMorphs('owner');
        });

        self::assertTrue(Schema::hasColumn('cm_ulid', 'owner_id'));
        self::assertTrue(Schema::hasColumn('cm_ulid', 'owner_type'));
        self::assertNotSame('integer', Schema::getColumnType('cm_ulid', 'owner_id'));
    }

    public function test_id_type_string_drives_uuid_columns(): void
    {
        config()->set('laranail.db-tools.using_uuids_for_id', false);
        config()->set('laranail.db-tools.using_ulids_for_id', false);
        config()->set('laranail.db-tools.id_type', 'uuid');

        Schema::create('cm_idtype_uuid', function ($t): void {
            $t->id();
            $t->configuredMorphs('thing');
        });

        self::assertTrue(Schema::hasColumn('cm_idtype_uuid', 'thing_id'));
        self::assertNotSame('integer', Schema::getColumnType('cm_idtype_uuid', 'thing_id'));
    }

    public function test_configured_nullable_morphs_creates_columns(): void
    {
        config()->set('laranail.db-tools.id_type', 'BIGINT');
        config()->set('laranail.db-tools.using_uuids_for_id', false);
        config()->set('laranail.db-tools.using_ulids_for_id', false);

        Schema::create('cm_nullable', function ($t): void {
            $t->id();
            $t->configuredNullableMorphs('target');
        });

        self::assertTrue(Schema::hasColumn('cm_nullable', 'target_id'));
        self::assertTrue(Schema::hasColumn('cm_nullable', 'target_type'));
    }
}

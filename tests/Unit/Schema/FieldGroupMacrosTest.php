<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Simtabi\Laranail\DbTools\Tests\TestCase;

final class FieldGroupMacrosTest extends TestCase
{
    public function test_field_group_macros_create_their_columns(): void
    {
        Schema::create('fg_widgets', function (Blueprint $t): void {
            $t->addUuidPrimaryKey();
            $t->addSlugField();
            $t->addStatusField();
            $t->addSortingField();
            $t->addUserFields();
            $t->addPublishingFields();
            $t->addMetaFields();
            $t->addLocationFields();
            $t->addImageFields('hero');
            $t->addPriceFields();
            $t->addActivationFields();
            $t->addExpiryFields();
            $t->addNullableMorphs('taggable');
            $t->addCommonFields();
        });

        foreach ([
            'id', 'slug', 'status', 'sort_order',
            'created_by', 'updated_by', 'deleted_by',
            'is_published', 'published_at',
            'meta_title', 'meta_description', 'meta_keywords',
            'latitude', 'longitude',
            'hero_image', 'hero_image_alt', 'hero_image_title',
            'price', 'sale_price', 'currency',
            'is_active', 'activated_at', 'deactivated_at',
            'starts_at', 'expires_at',
            'taggable_type', 'taggable_id',
            'created_at', 'updated_at', 'deleted_at',
        ] as $column) {
            self::assertTrue(Schema::hasColumn('fg_widgets', $column), "missing column {$column}");
        }
    }

    public function test_seo_aliases_meta_and_conditional_drops(): void
    {
        Schema::create('fg_seo', function (Blueprint $t): void {
            $t->id();
            $t->addSeoFields();
            $t->string('temp')->nullable();
        });

        self::assertTrue(Schema::hasColumn('fg_seo', 'meta_title'));

        Schema::table('fg_seo', function (Blueprint $t): void {
            $t->dropColumnIfExists('temp');
            $t->dropColumnIfExists('does_not_exist');
        });

        self::assertFalse(Schema::hasColumn('fg_seo', 'temp'));
    }

    public function test_acceptance_fields_create_their_columns(): void
    {
        Schema::create('fg_accept', function (Blueprint $t): void {
            $t->id();
            $t->addAcceptanceFields('approval');
        });

        foreach (['is_approval', 'approval_at', 'approval_by', 'approval_remarks'] as $column) {
            self::assertTrue(Schema::hasColumn('fg_accept', $column), "missing column {$column}");
        }

        $columns = collect(Schema::getConnection()->getSchemaBuilder()->getColumns('fg_accept'))->keyBy('name');
        self::assertTrue($columns['approval_at']['nullable']);
        self::assertTrue($columns['approval_by']['nullable']);
        self::assertTrue($columns['approval_remarks']['nullable']);
    }

    public function test_slug_can_be_nullable_and_defaults_honoured(): void
    {
        Schema::create('fg_opts', function (Blueprint $t): void {
            $t->id();
            $t->addSlugField(true);       // nullable
            $t->addStatusField('draft');  // custom default
            $t->addSortingField(5);       // custom default
        });

        $columns = collect(Schema::getConnection()->getSchemaBuilder()->getColumns('fg_opts'))->keyBy('name');
        self::assertTrue($columns['slug']['nullable']);
        self::assertStringContainsString('draft', (string) $columns['status']['default']);
        self::assertStringContainsString('5', (string) $columns['sort_order']['default']);
    }

    /**
     * The user-FK and morph id columns must follow the configured key type — a
     * UUID/ULID app cannot store a string identifier in a BIGINT column. These
     * assert the column TYPE (not just existence), since SQLite would otherwise
     * accept any value and hide the bug that real drivers reject.
     */
    public function test_user_and_morph_columns_are_bigint_by_default(): void
    {
        config()->set('db-tools.using_uuids_for_id', false);
        config()->set('db-tools.using_ulids_for_id', false);
        config()->set('db-tools.id_type', 'BIGINT');

        Schema::create('fg_int', function (Blueprint $t): void {
            $t->id();
            $t->addUserFields();
            $t->addAcceptanceFields('review');
            $t->addNullableMorphs('owner');
        });

        self::assertSame('integer', Schema::getColumnType('fg_int', 'created_by'));
        self::assertSame('integer', Schema::getColumnType('fg_int', 'review_by'));
        self::assertSame('integer', Schema::getColumnType('fg_int', 'owner_id'));
    }

    public function test_user_and_morph_columns_track_uuid_config(): void
    {
        config()->set('db-tools.using_uuids_for_id', true);

        Schema::create('fg_uuid', function (Blueprint $t): void {
            $t->id();
            $t->addUserFields();
            $t->addAcceptanceFields('review');
            $t->addNullableMorphs('owner');
        });

        self::assertNotSame('integer', Schema::getColumnType('fg_uuid', 'created_by'));
        self::assertNotSame('integer', Schema::getColumnType('fg_uuid', 'review_by'));
        self::assertNotSame('integer', Schema::getColumnType('fg_uuid', 'owner_id'));
    }

    public function test_user_and_morph_columns_track_ulid_config(): void
    {
        config()->set('db-tools.using_uuids_for_id', false);
        config()->set('db-tools.using_ulids_for_id', true);

        Schema::create('fg_ulid', function (Blueprint $t): void {
            $t->id();
            $t->addUserFields();
            $t->addNullableMorphs('owner');
        });

        self::assertNotSame('integer', Schema::getColumnType('fg_ulid', 'created_by'));
        self::assertNotSame('integer', Schema::getColumnType('fg_ulid', 'owner_id'));
    }
}

<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Schema;

use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Simtabi\Laranail\DbTools\Schema\BlueprintMacros;
use Simtabi\Laranail\DbTools\Tests\TestCase;

/**
 * morphs() and nullableMorphs() take a third $after argument and pass it to
 * the parent on the BIGINT path, but dropped it on the UUID and ULID paths —
 * even though uuidMorphs() and nullableUuidMorphs() accept it. A migration
 * adding morph columns at a chosen position silently got them appended
 * instead, on exactly the id types this package exists to support.
 */
final class BlueprintMacrosAfterTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function idTypes(): array
    {
        return ['UUID' => ['UUID'], 'ULID' => ['ULID'], 'BIGINT' => ['BIGINT']];
    }

    #[DataProvider('idTypes')]
    public function test_morphs_passes_after_through_for_every_id_type(string $idType): void
    {
        $blueprint = $this->blueprint($idType);
        $blueprint->morphs('taggable', null, 'name');

        $after = $this->afterValues($blueprint);

        self::assertNotSame([], $after, 'morphs() must generate columns.');
        self::assertContains('name', $after, "morphs() dropped \$after on the {$idType} path.");
    }

    #[DataProvider('idTypes')]
    public function test_nullable_morphs_passes_after_through_for_every_id_type(string $idType): void
    {
        $blueprint = $this->blueprint($idType);
        $blueprint->nullableMorphs('taggable', null, 'name');

        $after = $this->afterValues($blueprint);

        self::assertNotSame([], $after, 'nullableMorphs() must generate columns.');
        self::assertContains('name', $after, "nullableMorphs() dropped \$after on the {$idType} path.");
    }

    public function test_omitting_after_still_records_nothing(): void
    {
        $blueprint = $this->blueprint('UUID');
        $blueprint->morphs('taggable');

        self::assertSame([null, null], $this->afterValues($blueprint));
    }

    private function blueprint(string $idType): BlueprintMacros
    {
        BlueprintMacros::setIdTypeResolver(static fn (): string => $idType);

        $connection = DB::connection();

        // The schema grammar is only wired up once the schema builder has been
        // resolved; without it Blueprint's constructor rejects a null grammar.
        $connection->getSchemaBuilder();

        return new BlueprintMacros($connection, 'after_fixtures');
    }

    /**
     * The `after` value recorded on the generated column definitions.
     *
     * @return list<string|null>
     */
    private function afterValues(BlueprintMacros $blueprint): array
    {
        return array_values(array_map(
            static fn (ColumnDefinition $column) => $column->after,
            $blueprint->getColumns(),
        ));
    }
}

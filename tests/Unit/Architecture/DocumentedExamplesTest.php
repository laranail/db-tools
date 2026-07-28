<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every model class in a documented PHP example must actually compose.
 *
 * This exists because traits.md's primary HasSlug example was a fatal error:
 *
 *     class Post extends Model
 *     {
 *         use HasSlug;
 *         protected string $slugSrcInputName = 'title';
 *     }
 *
 * The trait declared that property with a default, and PHP forbids redeclaring
 * a trait property with a different value — so anyone copying the documented
 * form got "define the same property ... the definition differs and is
 * considered incompatible". Nothing caught it, because trait composition fails
 * at class-load time and no test ever loaded a class shaped like the docs.
 *
 * Composition errors are fatal and cannot be caught, so each example is loaded
 * in a subprocess and judged by its exit code.
 */
final class DocumentedExamplesTest extends TestCase
{
    private const string PACKAGE_ROOT = __DIR__.'/../../..';

    /**
     * Documented examples, as [label => php source].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function documentedModels(): array
    {
        $cases = [];

        foreach (self::markdownFiles() as $file) {
            $markdown = (string) file_get_contents($file);
            $relative = str_replace(realpath(self::PACKAGE_ROOT).'/', '', (string) realpath($file));

            preg_match_all('/```php\n(.*?)```/s', $markdown, $blocks);

            foreach ($blocks[1] as $index => $code) {
                if (! preg_match('/class\s+(\w+)\s+extends\s+\w*Model\b/', $code, $name)) {
                    continue;
                }

                if (! preg_match('/^\s*use\s+(Simtabi|Spatie)\\\\/m', $code)) {
                    continue;
                }

                $cases["{$relative} · {$name[1]}"] = [$relative, self::runnableSource($code, $index)];
            }
        }

        return $cases;
    }

    #[DataProvider('documentedModels')]
    public function test_a_documented_model_example_composes(string $file, string $source): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dbt-doc-').'.php';
        file_put_contents($path, $source);

        try {
            $output = [];
            $status = 0;
            exec(escapeshellarg(PHP_BINARY).' -d error_reporting=E_ALL '.escapeshellarg($path).' 2>&1', $output, $status);

            self::assertSame(
                0,
                $status,
                "A documented model example in {$file} does not compose:\n".implode("\n", $output),
            );
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return list<string>
     */
    private static function markdownFiles(): array
    {
        $found = [];
        $docs = self::PACKAGE_ROOT.'/docs';

        if (! is_dir($docs)) {
            return $found;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docs));

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'md') {
                $found[] = $entry->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Wrap a documented snippet so it can be loaded on its own: keep the class
     * and the package imports it declares, drop the surrounding usage lines.
     */
    private static function runnableSource(string $code, int $index): string
    {
        preg_match('/(class\s+\w+\s+extends\s+\w+\s*\{.*?\n\})/s', $code, $class);

        $imports = array_filter(
            array_map(trim(...), explode("\n", $code)),
            static fn (string $line): bool => str_starts_with($line, 'use Simtabi\\')
                || str_starts_with($line, 'use Spatie\\'),
        );

        $autoload = realpath(self::PACKAGE_ROOT.'/vendor/autoload.php');

        return implode("\n", [
            '<?php',
            "namespace { require '{$autoload}'; }",
            "namespace DocExample{$index} {",
            '  use Illuminate\Database\Eloquent\Model;',
            '  use Illuminate\Database\Eloquent\SoftDeletes;',
            ...array_map(static fn (string $line): string => '  '.$line, $imports),
            '  '.str_replace("\n", "\n  ", $class[1] ?? ''),
            '}',
        ]);
    }
}

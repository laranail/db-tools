<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Architecture;

use ReflectionMethod;
use ReflectionNamedType;
use Simtabi\Laranail\DbTools\DbTools;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Simtabi\Laranail\DbTools\Tests\TestCase;

/**
 * Every name docs/getting-started.md tells a reader to use must exist.
 *
 * The page once documented `Facades\DbTools`, `DbTools::connection()->test()`,
 * `DbTools::schema()`, `DbTools::verify()` and `DbTools::backup()->run()` --
 * none of which ever existed -- so the first walkthrough a new user followed
 * failed on its first line. This reads the page itself and checks each class,
 * static call, chained call, publish tag, Artisan command and config key
 * against the booted package, so the page cannot drift from the code again.
 */
final class GettingStartedDocumentationTest extends TestCase
{
    private const string PAGE = __DIR__ . '/../../../docs/getting-started.md';

    /** Balanced argument list, up to two levels of nested parentheses. */
    private const string ARGS = '\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)';

    public function test_every_imported_class_exists(): void
    {
        $imports = $this->captures('/^\s*use\s+([A-Za-z\\\\]+);/m', $this->phpCode());

        self::assertNotEmpty($imports, 'Inspected no `use` imports; the page or this pattern changed.');

        foreach ($imports as $class) {
            self::assertTrue(
                class_exists($class) || interface_exists($class) || trait_exists($class),
                "getting-started.md imports [{$class}], which does not exist.",
            );
        }
    }

    public function test_every_static_call_is_a_public_static_method_on_db_tools(): void
    {
        $methods = $this->captures('/\bDbTools::(\w+)\(/', $this->phpCode());

        self::assertGreaterThanOrEqual(5, count($methods), 'Inspected too few DbTools:: calls.');

        foreach ($methods as $method) {
            self::assertTrue(method_exists(DbTools::class, $method), "DbTools::{$method}() does not exist.");

            $reflection = new ReflectionMethod(DbTools::class, $method);
            self::assertTrue(
                $reflection->isPublic() && $reflection->isStatic(),
                "DbTools::{$method}() is not public static.",
            );
        }
    }

    public function test_every_chained_call_exists_on_the_type_the_first_call_returns(): void
    {
        $code = $this->phpCode();

        preg_match_all('/\bDbTools::(\w+)' . self::ARGS . '->(\w+)\(/', $code, $chains, PREG_SET_ORDER);
        preg_match_all('/->\w+\(/', $code, $arrows);

        self::assertNotEmpty($chains, 'Inspected no chained calls; the page or this pattern changed.');

        // A `->call(` the chain pattern did not account for is a call this test
        // cannot verify. Fail rather than let it through unchecked.
        self::assertCount(
            count($chains),
            $arrows[0],
            'getting-started.md has a `->` call that is not a DbTools::x()->y() chain; extend this test to verify it.',
        );

        foreach ($chains as [, $accessor, $method]) {
            self::assertTrue(method_exists(DbTools::class, $accessor), "DbTools::{$accessor}() does not exist.");

            $type = new ReflectionMethod(DbTools::class, $accessor)->getReturnType();
            self::assertInstanceOf(ReflectionNamedType::class, $type, "DbTools::{$accessor}() has no single return type.");

            self::assertTrue(
                method_exists($type->getName(), $method),
                "DbTools::{$accessor}() returns {$type->getName()}, which has no {$method}().",
            );
        }
    }

    public function test_every_publish_tag_is_registered(): void
    {
        $tags = $this->captures('/--tag=([\w:.-]+)/', $this->bashCode());

        self::assertGreaterThanOrEqual(2, count($tags), 'Inspected too few publish tags.');

        foreach ($tags as $tag) {
            self::assertContains($tag, ServiceProvider::publishableGroups(), "Publish tag [{$tag}] is not registered.");
        }
    }

    public function test_every_package_command_is_registered(): void
    {
        $commands = $this->captures('/php artisan (laranail::[\w.:-]+)/', $this->bashCode());

        self::assertGreaterThanOrEqual(2, count($commands), 'Inspected too few package commands.');

        foreach ($commands as $command) {
            self::assertArrayHasKey($command, Artisan::all(), "Command [{$command}] is not registered.");
        }
    }

    public function test_every_config_key_resolves(): void
    {
        $keys = $this->captures("/config\('([\w.-]+)'\)/", $this->markdown());

        self::assertGreaterThanOrEqual(2, count($keys), 'Inspected too few config keys.');

        foreach ($keys as $key) {
            self::assertTrue(config()->has($key), "Config key [{$key}] does not exist.");
        }
    }

    private function markdown(): string
    {
        $markdown = file_get_contents(self::PAGE);
        self::assertIsString($markdown, 'Could not read docs/getting-started.md.');

        return $markdown;
    }

    private function phpCode(): string
    {
        return $this->fenced('php');
    }

    private function bashCode(): string
    {
        return $this->fenced('bash');
    }

    private function fenced(string $language): string
    {
        preg_match_all('/```' . $language . '\n(.*?)```/s', $this->markdown(), $blocks);

        self::assertNotEmpty($blocks[1], "getting-started.md has no {$language} code fences.");

        return implode("\n", $blocks[1]);
    }

    /**
     * @return list<string>
     */
    private function captures(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $found);

        return array_values(array_unique($found[1]));
    }
}

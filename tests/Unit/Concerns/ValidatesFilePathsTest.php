<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Concerns;

use Simtabi\Laranail\DbTools\Tests\TestCase;
use Simtabi\Laranail\DbTools\Concerns\ValidatesFilePaths;

final class ValidatesFilePathsTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class
        {
            use ValidatesFilePaths {
                hasDirectoryTraversal as public;
                isAbsolutePath as public;
                isValidPhpFile as public;
                getFileExtension as public;
                getFileNameWithoutExtension as public;
                isFileSizeWithinLimit as public;
            }
        };
    }

    public function test_detects_directory_traversal(): void
    {
        self::assertTrue($this->subject->hasDirectoryTraversal('../etc/passwd'));
        self::assertFalse($this->subject->hasDirectoryTraversal('database/seeders'));
    }

    public function test_recognises_absolute_paths(): void
    {
        self::assertTrue($this->subject->isAbsolutePath('/var/www'));
        self::assertTrue($this->subject->isAbsolutePath('C:\\xampp'));
        self::assertFalse($this->subject->isAbsolutePath('relative/path'));
    }

    public function test_validates_a_real_php_file(): void
    {
        self::assertTrue($this->subject->isValidPhpFile(__FILE__));
        self::assertFalse($this->subject->isValidPhpFile(__DIR__ . '/does-not-exist.php'));
    }

    public function test_extracts_extension_and_name(): void
    {
        self::assertSame('php', $this->subject->getFileExtension('Model.PHP'));
        self::assertNull($this->subject->getFileExtension('Makefile'));
        self::assertSame('Model', $this->subject->getFileNameWithoutExtension('Model.php'));
    }

    public function test_enforces_file_size_limit(): void
    {
        self::assertTrue($this->subject->isFileSizeWithinLimit(__FILE__, 1_000_000));
        self::assertFalse($this->subject->isFileSizeWithinLimit(__FILE__, 1));
    }
}

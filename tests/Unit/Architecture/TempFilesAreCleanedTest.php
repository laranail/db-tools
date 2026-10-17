<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Architecture;

use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;

/**
 * tempnam() creates the file it names. Appending a suffix to its result and
 * cleaning up only the suffixed path left the bare file behind: 47 empty
 * files in the system temp directory on every run of this suite. Tests take
 * a path from TestCase::uniqueTempPath() instead, which removes it.
 */
final class TempFilesAreCleanedTest extends TestCase
{
    public function test_no_test_appends_a_suffix_to_a_tempnam_result(): void
    {
        $files = 0;
        $offenders = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2)));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files++;
            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/tempnam\(\s*sys_get_temp_dir\(\)\s*,[^)]*\)\s*\./', $source) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        // A floor, so a scan that found no files cannot pass.
        self::assertGreaterThan(50, $files);
        self::assertSame([], $offenders);
    }
}

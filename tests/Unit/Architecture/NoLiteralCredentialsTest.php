<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use Simtabi\Laranail\Package\Tools\Testing\AssertsNoLiteralCredentials;

/**
 * Secret scanning cannot tell a test password from a leaked one, and reported both. Tests take
 * their credentials from TestCredentials at run time; this fails on any literal left behind.
 */
final class NoLiteralCredentialsTest extends TestCase
{
    use AssertsNoLiteralCredentials;

    public function test_the_tests_hold_no_literal_credentials(): void
    {
        $this->assertNoLiteralCredentialsIn([dirname(__DIR__, 2)], minFiles: 78);
    }

    public function test_every_generated_credential_is_named_with_a_single_word(): void
    {
        $this->assertCredentialPurposesAreWordsIn([dirname(__DIR__, 2)], minFiles: 78);
    }
}

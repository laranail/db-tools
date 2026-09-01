<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

date_default_timezone_set('UTC');
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (! defined('TESTING')) {
    define('TESTING', true);
}

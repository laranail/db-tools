# Connection testing

`Schema\DatabaseConnectionTester` (bound to
`Schema\Contracts\DatabaseConnectionTesterInterface`) tests connections and
reports driver, version, and database name. All methods are failure-safe — they
swallow exceptions and return a benign fallback rather than throwing.

Resolve it via the container or `DbTools::connectionTester()`.

```php
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseConnectionTesterInterface;

$tester = app(DatabaseConnectionTesterInterface::class);
```

## `test()`

```php
$tester->test();          // default connection
$tester->test('pgsql');   // named connection
```

Returns `true` if a PDO handle can be opened, `false` otherwise.

## `testDetailed()`

```php
$report = $tester->testDetailed();
```

On success the array contains:

```php
[
    'success'    => true,
    'message'    => 'Connection successful',
    'connection' => 'mysql',         // the resolved connection name
    'driver'     => 'mysql',
    'version'    => '8.0.36',
    'database'   => 'forge',
]
```

On failure only the first three keys are present, and `message` is prefixed
with the error class category (`Database error:`, `Configuration error:`, or
`Connection failed:`):

```php
[
    'success'    => false,
    'message'    => 'Database error: SQLSTATE[HY000] [2002] Connection refused',
    'connection' => 'mysql',
]
```

## `getDriver()`

```php
$tester->getDriver(); // 'mysql' | 'pgsql' | 'sqlite' | 'sqlsrv' | 'unknown'
```

Returns `'unknown'` if the connection cannot be opened.

## `getVersion()`

```php
$tester->getVersion(); // e.g. '8.0.36', '15.4', '3.45.1' — or null
```

Driver-aware version query (`VERSION()` for MySQL/MariaDB, `version()` for
Postgres, `sqlite_version()` for SQLite, `@@VERSION` for SQL Server). Returns
`null` for unrecognized drivers or on error.

## `getDatabaseName()`

```php
$tester->getDatabaseName(); // 'forge' — or null on failure
```

## Reference

```php
public function test(?string $connection = null): bool;
public function testDetailed(?string $connection = null): array;
public function getDriver(?string $connection = null): string;
public function getVersion(?string $connection = null): ?string;
public function getDatabaseName(?string $connection = null): ?string;
```

---
[← Docs index](../../README.md#documentation)

# Table verification

`Schema\DatabaseTableVerifier` (bound to
`Schema\Contracts\DatabaseTableVerifierInterface`) composes the connection
tester and schema inspector to answer "do these tables exist?" — with a simple
boolean API and a rich detailed report.

```php
use Simtabi\Laranail\DbTools\Schema\Contracts\DatabaseTableVerifierInterface;

$verifier = app(DatabaseTableVerifierInterface::class);
```

## `verify()`

```php
$verifier->verify(['users', 'orders']);                    // any exist
$verifier->verify(['users', 'orders'], requireAll: true);  // all exist
```

An empty `$tables` array returns `true`. With `requireAll = false` it passes if
at least one table exists; with `true` every table must exist.

## `verifyDetailed()`

```php
$report = $verifier->verifyDetailed(['users', 'orders'], requireAll: true);
```

By default it tests the connection first (`$testConnection = true`). If the
connection fails:

```php
[
    'success'   => false,
    'connected' => false,
    'message'   => 'Database error: …',
    'driver'    => 'mysql',
]
```

On a successful connection with tables to check:

```php
[
    'success'   => true,
    'connected' => true,
    'tables' => [
        'checked'  => ['users', 'orders'],
        'existing' => ['users', 'orders'],
        'missing'  => [],
        'stats'    => [
            'total'      => 2,
            'found'      => 2,
            'missing'    => 0,
            'percentage' => 100.0,
        ],
    ],
    'message'     => 'All checks passed',
    'requirement' => 'all',            // 'all' or 'any'
    'connection'  => [                 // present only when $testConnection
        'name'    => 'mysql',
        'driver'  => 'mysql',
        'version' => '8.0.36',
    ],
]
```

When there is nothing to check, you get a short
`'message' => 'No tables to verify'` shape with `'tables' => []`.

> The facade wrapper `DbTools::verifyTablesDetailed()` always calls this
> with `$testConnection = true`.

## `getExistingTables()` / `getMissingTables()`

```php
$verifier->getExistingTables(['users', 'invoices']); // ['users']
$verifier->getMissingTables(['users', 'invoices']);  // ['invoices']
```

## `hasLaravelTables()`

```php
$verifier->hasLaravelTables();              // any default table present
$verifier->hasLaravelTables(strict: true);  // all default tables present
```

Checks `migrations`, `users`, `password_reset_tokens`, `failed_jobs`.

## Reference

```php
public function verify(array $tables, bool $requireAll = false, ?string $connection = null): bool;
public function verifyDetailed(array $tables, bool $requireAll = false, bool $testConnection = true, ?string $connection = null): array;
public function getExistingTables(array $tables, ?string $connection = null): array;
public function getMissingTables(array $tables, ?string $connection = null): array;
public function hasLaravelTables(bool $strict = false, ?string $connection = null): bool;
```

---
[← Docs index](../../README.md#documentation)

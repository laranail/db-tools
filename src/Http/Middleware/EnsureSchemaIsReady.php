<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Simtabi\Laranail\DbTools\DbTools;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * On an instance whose database is not fully migrated (a restored-but-unmigrated
 * dump, a half-run migration), let the request through but flag it: the boot-safe
 * guard means the app degrades instead of throwing, so a bare 500 would otherwise
 * hide the real cause. The advisory headers tell an operator (or the SPA) to run
 * `php artisan migrate --seed`.
 *
 * Nothing happens when the schema is ready, so the healthy path is a single cheap
 * check (the guard reuses the request's live connection). All behaviour is
 * configurable under `laranail.db-tools.readiness.middleware`.
 */
final class EnsureSchemaIsReady
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! (bool) $this->config('enabled', true)) {
            return $response;
        }

        // Once confirmed ready, skip the per-request database check for a short
        // window. A cache store that does NOT depend on the database is used
        // deliberately (default: file), so the check stays safe when the DB is down.
        if ($this->cachedReady()) {
            return $response;
        }

        // Required tables come from laranail.db-tools.readiness.required_tables.
        // Dispatches SchemaNotReady (logged by db-tools) when not ready.
        $report = DbTools::schemaReport();

        if ($report->isReady()) {
            $this->rememberReady();

            return $response;
        }

        $response->headers->set((string) $this->config('header_status', 'X-Schema-Status'), $report->status->value);
        $response->headers->set((string) $this->config('header_message', 'X-Schema-Message'), $report->message());

        return $response;
    }

    private function cachedReady(): bool
    {
        try {
            return Cache::store($this->cacheStore())->get($this->cacheKey()) === true;
        } catch (Throwable) {
            return false;
        }
    }

    private function rememberReady(): void
    {
        try {
            Cache::store($this->cacheStore())->put($this->cacheKey(), true, (int) $this->config('cache_ttl', 60));
        } catch (Throwable) {
            // A cache miss just means we re-check next request — never fatal.
        }
    }

    private function cacheStore(): ?string
    {
        $store = $this->config('cache_store', 'file');

        return is_string($store) && $store !== '' ? $store : null;
    }

    private function cacheKey(): string
    {
        return (string) $this->config('cache_key', 'db-tools.schema_ready');
    }

    private function config(string $key, mixed $default): mixed
    {
        return config('laranail.db-tools.readiness.middleware.'.$key, $default);
    }
}

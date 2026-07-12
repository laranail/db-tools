<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\DbTools\Services\Contracts\MaintenanceServiceInterface;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Maintenance Service Implementation
 *
 * Filesystem housekeeping (caches, logs, the storage symlink). Lives apart from
 * DatabaseService because these operate on the application's storage, not the
 * database.
 */
final readonly class MaintenanceService implements MaintenanceServiceInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private string $basePath
    ) {}

    /**
     * {@inheritDoc}
     */
    public function clearCache(): bool
    {
        try {
            Event::dispatch('cache:clearing');
            Cache::flush();

            $path = $this->basePath.'/storage/framework/cache';
            if (File::exists($path)) {
                foreach (File::files($path) as $file) {
                    /** @var SplFileInfo $file */
                    $realPath = $file->getRealPath();
                    if (preg_match('/facade-.*\.php$/', $realPath)) {
                        File::delete($realPath);
                    }
                }
            }

            $path = $this->basePath.'/bootstrap/cache';
            if (File::exists($path)) {
                foreach (File::allFiles($path) as $file) {
                    /** @var SplFileInfo $file */
                    if ($file->isFile()) {
                        $file = $file->getRealPath();
                        if (preg_match('/.*\.php$/', $file)) {
                            File::delete($file);
                        }
                    }
                }
            }

            Event::dispatch('cache:cleared');

            $this->logger->info('Cache cleared successfully');

            return true;
        } catch (Exception $exception) {
            $this->logger->error('Failed to clear cache', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clearLogFiles(): bool
    {
        try {
            Event::dispatch('logs:clearing');

            $directories = ['clockwork', 'debugbar', 'logs'];

            foreach ($directories as $directory) {
                $path = $this->basePath.'/storage/'.$directory;

                if (File::exists($path)) {
                    foreach (File::allFiles($path) as $file) {
                        /** @var SplFileInfo $file */
                        if ($file->isFile()) {
                            $file = $file->getRealPath();
                            if (! preg_match('/.*\.gitignore$/', $file)) {
                                File::delete($file);
                            }
                        }
                    }
                }
            }

            Event::dispatch('logs:cleared');

            $this->logger->info('Log files cleared successfully');

            return true;
        } catch (Exception $exception) {
            $this->logger->error('Failed to clear log files', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteStorageSymlink(): bool
    {
        try {
            $publicStorage = $this->basePath.'/public/storage';

            if (File::exists($publicStorage)) {
                File::delete($publicStorage);

                $this->logger->info('Storage symlink deleted');

                return true;
            }

            return false;
        } catch (Exception $exception) {
            $this->logger->error('Failed to delete storage symlink', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}

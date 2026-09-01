<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DbTools\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Psr\Log\LoggerInterface;
use Simtabi\Laranail\DbTools\Concerns\ValidatesFilePaths;
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
    use ValidatesFilePaths;

    public function __construct(
        private LoggerInterface $logger,
        private string $basePath,
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
                    if (preg_match('/^facade-.*\.php$/', $file->getFilename())) {
                        $this->deleteWithin($file, $path);
                    }
                }
            }

            $path = $this->basePath.'/bootstrap/cache';
            if (File::exists($path)) {
                foreach (File::allFiles($path) as $file) {
                    /** @var SplFileInfo $file */
                    if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                        $this->deleteWithin($file, $path);
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
                        if ($file->isFile() && ! str_ends_with($file->getFilename(), '.gitignore')) {
                            $this->deleteWithin($file, $path);
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

            // is_link() first: file_exists() follows the link and answers false
            // for a dangling one — the case most worth cleaning up.
            $isLink = is_link($publicStorage);

            if (! $isLink && ! File::exists($publicStorage)) {
                return false;
            }

            // A real directory here means someone copied files instead of
            // symlinking. Removing it would destroy them, and unlink() cannot
            // remove a directory anyway — the return used to be discarded and
            // success logged regardless.
            if (! $isLink && is_dir($publicStorage)) {
                $this->logger->warning('public/storage is a real directory, not a symlink; refusing to delete it', [
                    'path' => $publicStorage,
                ]);

                return false;
            }

            if (! @unlink($publicStorage)) {
                $this->logger->warning('Storage symlink could not be deleted', [
                    'path' => $publicStorage,
                ]);

                return false;
            }

            $this->logger->info('Storage symlink deleted');

            return true;
        } catch (Exception $exception) {
            $this->logger->error('Failed to delete storage symlink', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Delete a directory entry, never the thing it points at.
     *
     * The sweeps used to resolve each entry with getRealPath() and delete the
     * RESULT, so a symlink inside storage took its target with it — anywhere the
     * process could unlink. getRealPath() is exactly the call that defeats
     * containment.
     *
     * A symlink is unlinked in place. A real file is confined to $root with the
     * package's own realpath-based check before it is removed.
     */
    private function deleteWithin(SplFileInfo $file, string $root): void
    {
        $pathname = $file->getPathname();

        if (is_link($pathname)) {
            @unlink($pathname);

            return;
        }

        if ($this->isContainedWithin($pathname, $root)) {
            File::delete($pathname);
        }
    }
}

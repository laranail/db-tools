<?php

declare(strict_types=1);

/**
 * Every path composer.json references must survive `git archive`.
 *
 * ## The bug this exists for
 *
 * `laranail/enumerator` declared `extra.phpstan.includes: ["extension.neon"]`
 * and, in the same repository, `.gitattributes` carried
 * `/extension.neon export-ignore`. Both lines are individually reasonable —
 * one tells `phpstan/extension-installer` to load the file, the other reads
 * like ordinary dev-file housekeeping. Together they ship a package whose
 * generated PHPStan config points at a file the archive does not contain, and
 * every consumer with `phpstan/extension-installer` gets:
 *
 *     Config file .../vendor/laranail/enumerator/extension.neon
 *     does not exist or isn't readable
 *
 * naming a path inside `vendor/`, with nothing pointing back at the package
 * that caused it. It survived for months because it needs two conditions —
 * the installer *and* a dist install — and no package in the org had both
 * until one did.
 *
 * `autoload.files` is the worse version of the same shape: Composer `require`s
 * those on every autoload, so a stripped one is a fatal rather than a
 * degraded check.
 *
 * ## Why it reads everything from one commit
 *
 * The first version of this audit compared the working-tree `composer.json`
 * against the `HEAD` archive and reported a package mid-refactor as broken —
 * its manifest already named the new path, its archive still held the old one.
 * In a checkout several people work in, an audit that flags in-flight work is
 * worse than no audit, because people learn to ignore it. Manifest and archive
 * both come from the same revision.
 *
 * Usage:
 *   php scripts/verify-dist-integrity.php [revision]      # default HEAD
 */

$revision = $argv[1] ?? 'HEAD';
$root = dirname(__DIR__);

/**
 * @return array{0: int, 1: string}
 */
function run(string $command, string $cwd): array
{
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
    );

    if (! is_resource($process)) {
        return [1, ''];
    }

    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out];
}

[$status, $manifest] = run(sprintf('git show %s:composer.json', escapeshellarg($revision)), $root);

if ($status !== 0 || $manifest === '') {
    fwrite(STDERR, "Could not read composer.json at {$revision}.\n");
    exit(1);
}

$composer = json_decode($manifest, true, 512, JSON_THROW_ON_ERROR);

[$status, $listing] = run(
    sprintf('git archive --format=tar %s | tar -t', escapeshellarg($revision)),
    $root,
);

if ($status !== 0) {
    fwrite(STDERR, "Could not build the archive listing for {$revision}.\n");
    exit(1);
}

$shipped = array_filter(array_map(
    static fn (string $line): string => rtrim(trim($line), '/'),
    explode("\n", $listing),
));

[, $trackedList] = run(sprintf('git ls-tree -r --name-only %s', escapeshellarg($revision)), $root);
$tracked = array_filter(explode("\n", trim($trackedList)));

/**
 * The paths the manifest promises a consumer will find.
 *
 * Deliberately not every key — only those where a missing file is a failure in
 * the *consumer's* install rather than a nuisance in this repository.
 *
 * @return list<array{0: string, 1: string}>
 */
function referencedPaths(array $composer): array
{
    $paths = [];

    foreach (($composer['extra']['phpstan']['includes'] ?? []) as $include) {
        $paths[] = ['extra.phpstan.includes', $include];
    }

    foreach (($composer['bin'] ?? []) as $binary) {
        $paths[] = ['bin', $binary];
    }

    foreach (['psr-4', 'psr-0'] as $standard) {
        foreach (($composer['autoload'][$standard] ?? []) as $directories) {
            foreach ((array) $directories as $directory) {
                $paths[] = ["autoload.{$standard}", rtrim($directory, '/')];
            }
        }
    }

    foreach (($composer['autoload']['files'] ?? []) as $file) {
        $paths[] = ['autoload.files', $file];
    }

    return $paths;
}

/**
 * @param list<string> $haystack
 */
function contains(array $haystack, string $path): bool
{
    foreach ($haystack as $candidate) {
        if ($candidate === $path || str_starts_with($candidate, $path . '/')) {
            return true;
        }
    }

    return false;
}

$failures = [];

printf("  Dist integrity for %s at %s\n\n", $composer['name'] ?? '?', $revision);

foreach (referencedPaths($composer) as [$key, $path]) {
    if (! contains($tracked, $path)) {
        // Declared but never committed. Composer tolerates a missing psr-4
        // directory, so this is reported and not failed — but it is still a
        // manifest describing something that does not exist.
        printf("  \033[33m?\033[0m  %-24s %-30s declared but not committed\n", $key, $path);

        continue;
    }

    if (contains($shipped, $path)) {
        printf("  \033[32mok\033[0m %-24s %-30s in the archive\n", $key, $path);

        continue;
    }

    $failures[] = sprintf(
        '%s references %s, which .gitattributes strips from the dist archive. '
        . 'Consumers installing from dist will not have it.',
        $key,
        $path,
    );
}

if ($failures === []) {
    printf("\n\033[32m  ok Every referenced path survives the archive.\033[0m\n");

    exit(0);
}

echo "\n";

foreach ($failures as $failure) {
    printf("\033[31m  x %s\033[0m\n", $failure);
}

printf(
    "\n  Fix by removing the export-ignore rule, not by dropping the reference — the\n"
    . "  file is referenced because a consumer needs it.\n",
);

exit(1);

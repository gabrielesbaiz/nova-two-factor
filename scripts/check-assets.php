<?php

declare(strict_types=1);

/**
 * Fails if the committed bundle has drifted from a fresh build.
 *
 * This replaces the CI job that used to guard it. 1.x published compiled assets
 * that were months out of step with their source, which is why its UI looked
 * nothing like its code — so this is the one release step worth failing loudly.
 */
$root = dirname(__DIR__);

exec('git -C '.escapeshellarg($root).' status --porcelain -- dist 2>&1', $output, $status);

if ($status !== 0) {
    fwrite(STDERR, "Could not inspect dist/ with git.\n");

    exit(1);
}

$changed = array_values(array_filter(array_map('trim', $output)));

if ($changed === []) {
    fwrite(STDOUT, "dist/ matches a fresh build.\n");

    exit(0);
}

fwrite(STDERR, "dist/ differs from a fresh build — commit it before tagging:\n");

foreach ($changed as $line) {
    fwrite(STDERR, "  {$line}\n");
}

exit(1);

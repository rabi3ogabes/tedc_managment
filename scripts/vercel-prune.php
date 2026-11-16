<?php

/*
 * Vercel build step (run by the root composer.json "vercel" script): trims backend/vendor so the PHP
 * function stays under Vercel's 250 MB limit. Removes package test suites, docs and VCS metadata, and the
 * mPDF fonts the certificates never use (they need DejaVu for Latin text and XB Riyaz for Arabic).
 */

$vendor = $argv[1] ?? __DIR__.'/../vendor';
if (! is_dir($vendor)) {
    fwrite(STDERR, "vendor directory not found: {$vendor}\n");
    exit(1);
}

$removeTree = function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $removeTree("{$path}/{$entry}");
        }
    }
    @rmdir($path);
};

$before = dirSize($vendor);

// Package-level folders that are never loaded at runtime (vendor/<vendor>/<package>/<folder>).
foreach (glob("{$vendor}/*/*", GLOB_ONLYDIR) ?: [] as $package) {
    foreach (['tests', 'Tests', 'test', 'docs', 'doc', 'examples', '.git', '.github'] as $folder) {
        if (is_dir("{$package}/{$folder}")) {
            $removeTree("{$package}/{$folder}");
        }
    }
}

$fonts = "{$vendor}/mpdf/mpdf/ttfonts";
foreach (glob("{$fonts}/*") ?: [] as $font) {
    $name = basename($font);
    if (! preg_match('/^(DejaVu|XB Riyaz)/', $name) && ! str_ends_with($name, '.txt')) {
        @unlink($font);
    }
}

printf("vendor trimmed: %.1f MB -> %.1f MB\n", $before / 1e6, dirSize($vendor) / 1e6);

function dirSize(string $dir): int
{
    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->isFile() ? $file->getSize() : 0;
    }

    return $size;
}

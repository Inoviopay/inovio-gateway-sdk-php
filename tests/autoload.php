<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader so the suite runs without Composer.
 *
 * Several files declare more than one class (Refs, Errors, RequestParts,
 * Result), so a strict one-class-per-file mapping is not enough: after trying
 * the PSR-4 path we fall back to loading every file in the namespace directory.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Inovio\\Gateway\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $base = __DIR__ . '/../src/';

    $path = $base . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;

        return;
    }

    // multi-class file: load every php file in that namespace directory
    $dir = $base . str_replace('\\', '/', dirname(str_replace('\\', '/', $relative)));
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            require_once $file;
            if (class_exists($class, false) || interface_exists($class, false)) {
                return;
            }
        }
    }
});

<?php

declare(strict_types=1);

/**
 * TaxId runtime environment.
 *
 * Composer autoload is preferred when available. For development or a
 * standalone copy under htdocs/taxId, a small PSR-4 fallback keeps the
 * library usable before `composer install` has been executed.
 */

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'TaxId\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relativeClass = substr($class, strlen($prefix));
        $file = dirname(__DIR__)
            . '/src/TaxId/'
            . str_replace('\\', '/', $relativeClass)
            . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });
}

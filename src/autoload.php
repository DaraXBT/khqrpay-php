<?php

/**
 * Standalone PSR-4 autoloader for the KHQRPay PHP SDK.
 *
 * Use it when you can't use Composer, for example inside a WordPress or
 * WooCommerce plugin:
 *
 *     require __DIR__ . '/khqrpay-php/src/autoload.php';
 *
 * With Composer, require vendor/autoload.php instead and ignore this file.
 */

spl_autoload_register(static function ($class) {
    $prefix = 'KHQRPay\\';
    $prefixLength = strlen($prefix);

    if (strncmp($class, $prefix, $prefixLength) !== 0) {
        return;
    }

    $relative = substr($class, $prefixLength);
    $file = __DIR__ . DIRECTORY_SEPARATOR
        . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

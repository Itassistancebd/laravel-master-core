<?php

require dirname(__DIR__, 4).'/vendor/autoload.php';

// Keep generator autoload available in local test runs before Composer's optimized map is refreshed.
spl_autoload_register(static function (string $class): void {
    $prefix = 'MasterCore\\ProjectGenerator\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = dirname(__DIR__).'/src/'.str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))).'.php';
    if (is_file($file)) {
        require $file;
    }
});

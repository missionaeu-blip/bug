<?php
/**
 * Minimal PSR-4 autoloader for MaxMind DB Reader.
 * Source: maxmind/MaxMind-DB-Reader-php (Apache-2.0).
 */
spl_autoload_register(function ($class) {
    $prefix = 'MaxMind\\Db\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) { return; }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/MaxMind/Db/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) { require_once $path; }
});

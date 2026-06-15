<?php
namespace Translink;

class Autoloader
{
    private static array $prefixes = [];

    public static function register(): void
    {
        spl_autoload_register(function (string $class): void {
            $prefix = 'Translink\\';
            if (strncmp($prefix, $class, strlen($prefix)) !== 0) return;

            $relative = substr($class, strlen($prefix));
            $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

            if (file_exists($file)) require_once $file;
        });
    }

    public static function addNamespace(string $prefix, string $baseDir): void
    {
        self::$prefixes[$prefix] = rtrim($baseDir, '/') . '/';
    }
}

<?php

declare(strict_types=1);

namespace Sabri\CF03;

final class Autoloader
{
    public static function register(string $sourceDirectory): void
    {
        $base = rtrim($sourceDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        spl_autoload_register(static function (string $class) use ($base): void {
            $prefix = __NAMESPACE__ . '\\';
            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = $base . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($path)) {
                require_once $path;
            }
        });
    }
}

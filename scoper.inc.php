<?php

declare(strict_types=1);

return [
    'prefix' => 'Atshift\Deft\\Vendor',
    'php-version' => '8.2',
    // The second pass isolates Composer's regenerated loader without double-prefixing.
    'exclude-namespaces' => ['Atshift\Deft\\Vendor'],
    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
    'patchers' => [
        static function (string $filePath, string $prefix, string $contents): string {
            if (basename($filePath) !== 'autoload_real.php') {
                return $contents;
            }
            // PHP-Scoper does not infer Composer's string comparison/unregister callback.
            $contents = str_replace(
                "'Composer\\Autoload\\ClassLoader'",
                var_export($prefix . '\\Composer\\Autoload\\ClassLoader', true),
                $contents
            );
            return preg_replace_callback(
                "/'ComposerAutoloaderInit([a-f0-9]+)'/",
                static fn (array $match): string => var_export($prefix . '\\ComposerAutoloaderInit' . $match[1], true),
                $contents
            );
        },
    ],
];

<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/db-bridge.php';
require_once __DIR__ . '/options.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/microsoft.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/security-headers.php';
define('WORDPRESS_PATH', '/home/brscouts/irvalscouts.org.uk');
define('WORDPRESS_SITE_URL', 'https://irvalscouts.org.uk');

if (!function_exists('app_config')) {
    function app_config(string $key, ?string $default = null): ?string
    {
        if (defined($key)) {
            return (string) constant($key);
        }

        return $default;
    }
}

if (!function_exists('app_url')) {
    function app_url(string $path = ''): string
    {
        $base = rtrim((string) app_config('APP_URL', ''), '/');
        $path = '/' . ltrim($path, '/');

        return $base . $path;
    }
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $path): void
    {
        // Prevent open redirects: only allow relative paths starting with /
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            $path = '/';
        }

        header('Location: ' . $path);
        exit;
    }
}

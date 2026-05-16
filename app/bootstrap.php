<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| Existing app config
|--------------------------------------------------------------------------
|
| This should be your current config file that already contains the database
| connection and any existing app constants.
|
*/

require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/db-bridge.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/microsoft.php';

function app_config(string $key, ?string $default = null): ?string
{
    if (defined($key)) {
        return (string) constant($key);
    }

    return $default;
}

function app_url(string $path = ''): string
{
    $base = rtrim((string) app_config('APP_URL', ''), '/');
    $path = '/' . ltrim($path, '/');

    return $base . $path;
}

function redirect(string $path): never
{
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        header('Location: ' . $path);
        exit;
    }

    header('Location: ' . app_url($path));
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
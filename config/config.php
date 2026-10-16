<?php
declare(strict_types=1);

// Environment values take precedence. A small parser avoids an extra dependency.
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (getenv($key) === false) putenv($key . '=' . trim(trim($value), "\"'"));
    }
}
$env = static fn(string $key, string $default): string => getenv($key) !== false ? (string)getenv($key) : $default;
return [
    'environment' => $env('APP_ENV', 'development'),
    'timezone' => $env('APP_TIMEZONE', 'America/Guatemala'),
    // Only honoured when APP_ENV=testing: freezes "now" (e.g. 2026-10-16T09:00:00) for automated tests.
    'fixed_now' => $env('APP_FIXED_NOW', ''),
    'database' => [
        'driver' => $env('DB_DRIVER', 'mysql'), 'host' => $env('DB_HOST', 'db'),
        'port' => (int)$env('DB_PORT', '3306'), 'dbname' => $env('DB_NAME', 'futkids'),
        'username' => $env('DB_USER', 'futkids'), 'password' => $env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],
    'age_min' => (int)$env('AGE_MIN', '5'), 'age_max' => (int)$env('AGE_MAX', '14'),
    'age_reference_date' => $env('AGE_REFERENCE_DATE', '2026-10-03'),
];

<?php
declare(strict_types=1);
namespace Tests\Support;

use Phalcon\Di\Di;
use Phalcon\Di\DiInterface;

/** Builds an application container bound to a given SQLite file, with the clock frozen. */
final class TestApplication
{
    public const TIMEZONE = 'America/Guatemala';
    public const FIXED_NOW = '2026-10-16T09:00:00';

    public static function boot(string $databaseFile): DiInterface {
        $settings = [
            'environment' => 'testing',
            'timezone' => self::TIMEZONE,
            'fixed_now' => self::FIXED_NOW,
            'database' => ['driver' => 'sqlite', 'dbname' => $databaseFile],
            'age_min' => 5,
            'age_max' => 14,
            'age_reference_date' => '2026-10-03',
        ];
        $di = (require BASE_PATH . '/config/services.php')($settings);
        Di::setDefault($di);
        return $di;
    }
}

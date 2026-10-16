<?php
declare(strict_types=1);
namespace Tests\Support;

use App\Database\Seeder;

/**
 * Migrates and seeds one SQLite template per run; every test works on its own copy,
 * so tests are isolated and fast.
 */
final class TestDatabase
{
    public const ADMIN_PASSWORD = 'FutKids2026!';
    public const REFEREE_PASSWORD = 'Arbitro2026!';

    private static ?string $template = null;
    /** @var list<string> */
    private static array $files = [];

    public static function freshCopy(): string {
        $copy = self::newFile();
        if (!copy(self::template(), $copy)) throw new \RuntimeException('No se pudo copiar la base de pruebas.');
        return $copy;
    }

    /** An empty file (no schema), for migration tests. */
    public static function emptyFile(): string {
        return self::newFile();
    }

    public static function cleanup(): void {
        foreach (self::$files as $file) if (is_file($file)) unlink($file);
        self::$files = [];
        self::$template = null;
    }

    private static function template(): string {
        if (self::$template !== null) return self::$template;
        $file = self::newFile();
        $di = TestApplication::boot($file);
        $di->getShared('migrator')->migrate();
        (new Seeder($di->getShared('db'), $di->getShared('security'), BASE_PATH . '/database/seed.json'))
            ->run(['admin' => self::ADMIN_PASSWORD, 'referee' => self::REFEREE_PASSWORD]);
        $di->getShared('db')->close();
        return self::$template = $file;
    }

    private static function newFile(): string {
        $file = sys_get_temp_dir() . '/futkids-test-' . bin2hex(random_bytes(8)) . '.sqlite';
        self::$files[] = $file;
        return $file;
    }
}

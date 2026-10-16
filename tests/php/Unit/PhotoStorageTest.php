<?php
declare(strict_types=1);
namespace Tests\Unit;

use App\Services\PhotoStorage;
use PHPUnit\Framework\TestCase;

final class PhotoStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void {
        $this->directory = sys_get_temp_dir() . '/futkids-photo-permissions-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0555);
    }

    protected function tearDown(): void {
        chmod($this->directory, 0755);
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function testReadOnlyDirectoryRecoversWritePermission(): void {
        PhotoStorage::ensureWritableDirectory($this->directory);
        $this->assertSame(2, file_put_contents($this->directory . '/write-check', 'ok'));
    }
}

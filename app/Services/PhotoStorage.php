<?php
namespace App\Services;
use App\Exceptions\HttpException;
class PhotoStorage
{
    public static function ensureWritableDirectory(string $directory): void {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new HttpException('No se pudo preparar el directorio para guardar fotografías.', 500);
        }
        if (!is_writable($directory) && (!chmod($directory, 0755) || !is_writable($directory))) {
            throw new HttpException('El directorio de fotografías no permite escritura. Revise los permisos del volumen uploads.', 500);
        }
    }
    public static function store(\Phalcon\Http\Request $request): ?string {
        foreach ($request->getUploadedFiles(false) as $file) {
            if ($file->getKey() !== 'photo' || $file->getError() === UPLOAD_ERR_NO_FILE) continue;
            if ($file->getError() !== UPLOAD_ERR_OK || $file->getSize() > 2 * 1024 * 1024) throw new HttpException('La fotografía debe pesar como máximo 2 MB.');
            $tmp = $file->getTempName();
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $size = @getimagesize($tmp);
            if (!in_array($mime, ['image/jpeg', 'image/png'], true) || !$size || $size[0] * $size[1] > 25000000) throw new HttpException('Seleccione una fotografía JPG o PNG válida, de hasta 25 megapíxeles.');
            $contents = file_get_contents($tmp);
            $source = $contents === false ? false : @imagecreatefromstring($contents);
            if (!$source) throw new HttpException('No se pudo leer la imagen.');
            $ratio = min(1, 1000 / max($size[0], $size[1]));
            $target = imagecreatetruecolor((int)max(1, $size[0] * $ratio), (int)max(1, $size[1] * $ratio));
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
            imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $size[0], $size[1]);
            $path = 'uploads/players/' . bin2hex(random_bytes(16)) . '.jpg';
            $destination = BASE_PATH . '/public/' . $path;
            try {
                self::ensureWritableDirectory(dirname($destination));
                if (!imagejpeg($target, $destination, 88)) throw new HttpException('No se pudo guardar la fotografía.', 500);
            } catch (\Throwable $e) {
                if (is_file($destination) && !unlink($destination)) throw new HttpException('No se pudo guardar ni limpiar la fotografía incompleta.', 500);
                throw $e;
            } finally {
                imagedestroy($source);
                imagedestroy($target);
            }
            return $path;
        }
        return null;
    }
    public static function remove(?string $path): void {
        if ($path && preg_match('~^uploads/players/[a-f0-9]{32}\.jpg$~', $path)) {
            $file = BASE_PATH . '/public/' . $path;
            if (is_file($file) && !unlink($file)) throw new HttpException('No se pudo eliminar la fotografía almacenada.', 500);
        }
    }
}

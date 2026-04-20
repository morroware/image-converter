<?php
declare(strict_types=1);

namespace Castle;

/**
 * Secure upload validation + filename safety.
 */
final class Uploader
{
    /**
     * Validate a single entry from $_FILES and return a normalized descriptor.
     * Throws RuntimeException on invalid input.
     *
     * @return array{tmp:string, name:string, size:int, mime:string, ext:string}
     */
    public static function validate(array $file, FormatHandler $fmt): array
    {
        if (!is_array($file) || !isset($file['tmp_name'], $file['error'])) {
            throw new \RuntimeException('Malformed upload.');
        }
        if ((int)$file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::uploadErrorMessage((int)$file['error']));
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('Not an uploaded file.');
        }
        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            throw new \RuntimeException('Empty file.');
        }
        if ($size > CASTLE_MAX_FILE_SIZE) {
            throw new \RuntimeException(sprintf('File exceeds %s limit.',
                self::formatBytes(CASTLE_MAX_FILE_SIZE)));
        }

        $origName = (string)($file['name'] ?? 'upload');
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // Block obvious double-extensions like "foo.php.jpg".
        if (preg_match('/\.(php|phtml|phar|pl|cgi|sh|exe|bat|html?|js)\./i', $origName)) {
            throw new \RuntimeException('Suspicious filename.');
        }

        // MIME sniffing via fileinfo when available.
        $mime = 'application/octet-stream';
        if (extension_loaded('fileinfo')) {
            $f = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $f->file($file['tmp_name']);
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            }
        } elseif (!empty($file['type'])) {
            $mime = (string)$file['type'];
        }

        $allowedInputs = $fmt->getSupportedInputFormats();
        $extCanon = self::canonExt($ext);
        if (!in_array($extCanon, $allowedInputs, true)) {
            throw new \RuntimeException('Unsupported input format: .' . $ext);
        }

        // Confirm it is a plausible image / pdf.
        if ($extCanon === 'pdf') {
            if (strpos($mime, 'pdf') === false && strpos($mime, 'application/') !== 0) {
                throw new \RuntimeException('Not a valid PDF.');
            }
        } elseif ($extCanon === 'svg') {
            if (strpos($mime, 'svg') === false && strpos($mime, 'xml') === false && strpos($mime, 'text/') !== 0) {
                throw new \RuntimeException('Not a valid SVG.');
            }
        } else {
            $info = @getimagesize($file['tmp_name']);
            if ($info === false) {
                // Imagick-supported formats (HEIC, TIFF, etc.) may not be readable by getimagesize.
                if (!in_array($extCanon, ['heic', 'heif', 'tif', 'tiff', 'psd', 'ico'], true)) {
                    throw new \RuntimeException('File is not a valid image.');
                }
            } elseif (isset($info[0], $info[1])) {
                if ($info[0] > CASTLE_MAX_DIMENSION || $info[1] > CASTLE_MAX_DIMENSION) {
                    throw new \RuntimeException(sprintf('Image exceeds %dpx dimension limit.',
                        CASTLE_MAX_DIMENSION));
                }
            }
        }

        return [
            'tmp'  => (string)$file['tmp_name'],
            'name' => self::safeName($origName),
            'size' => $size,
            'mime' => $mime,
            'ext'  => $extCanon,
        ];
    }

    public static function safeName(string $original): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? '';
        $base = trim($base, '.-_');
        if ($base === '') $base = 'file';
        return substr($base, 0, 80);
    }

    public static function canonExt(string $ext): string
    {
        $ext = strtolower($ext);
        return match ($ext) {
            'jpg'  => 'jpeg',
            'tif'  => 'tiff',
            'heif' => 'heic',
            default => $ext,
        };
    }

    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow   = min((int)floor(($bytes ? log($bytes) : 0) / log(1024)), count($units) - 1);
        return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File too large.',
            UPLOAD_ERR_PARTIAL   => 'Upload interrupted.',
            UPLOAD_ERR_NO_FILE   => 'No file uploaded.',
            UPLOAD_ERR_NO_TMP_DIR=> 'Server temp directory missing.',
            UPLOAD_ERR_CANT_WRITE=> 'Cannot write uploaded file.',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by PHP extension.',
            default              => 'Upload failed.',
        };
    }
}

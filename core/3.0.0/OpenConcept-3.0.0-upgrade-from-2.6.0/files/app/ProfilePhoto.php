<?php

declare(strict_types=1);

/** Bounded profile images; attachment uploads keep their original bytes. */
final class ProfilePhoto
{
    public const MAX_EDGE = 256;
    public const MAX_BYTES = 131072;
    public const CACHE_DIRECTORY = '.profile-thumbnails-v1';

    /** @return array{extension: string, mime: string, width: int, height: int}|null */
    public static function inspect(string $path, string $name): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$extension] ?? '';
        if ($mime === '' || (class_exists('finfo') && (new finfo(FILEINFO_MIME_TYPE))->file($path) !== $mime)) {
            return null;
        }
        $size = @getimagesize($path);
        $width = (int) ($size[0] ?? 0);
        $height = (int) ($size[1] ?? 0);
        if (($size['mime'] ?? '') !== $mime || $width < 1 || $height < 1
            || $width > 6000 || $height > 6000 || $width * $height > 20000000) {
            return null;
        }
        return ['extension' => $extension, 'mime' => $mime, 'width' => $width, 'height' => $height];
    }

    /** @return array{bytes: string, extension: string, mime: string, width: int, height: int} */
    public static function optimize(string $path, string $name): array
    {
        $info = self::inspect($path, $name);
        if ($info === null || (int) filesize($path) > 5 * 1024 * 1024) {
            throw new RuntimeException('profile.photoInvalid', 422);
        }
        $decoder = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$info['extension']];
        $encoder = ['jpg' => 'imagejpeg', 'png' => 'imagepng', 'webp' => 'imagewebp'][$info['extension']];
        if (!function_exists($decoder) || !function_exists($encoder) || !function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('profile.photoCompressionUnavailable', 503);
        }
        // Check before decoding: compressed image size does not bound decoded memory.
        $memoryLimit = trim((string) ini_get('memory_limit'));
        $limitBytes = (int) $memoryLimit;
        $limitBytes *= match (strtolower(substr($memoryLimit, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
        $requiredBytes = $info['width'] * $info['height'] * 5 + 16 * 1024 * 1024;
        if ($limitBytes > 0 && memory_get_usage(true) + $requiredBytes > $limitBytes) {
            throw new RuntimeException('profile.photoDimensionsError', 422);
        }
        $decodeWarning = false;
        set_error_handler(static function () use (&$decodeWarning): bool { $decodeWarning = true; return true; });
        try {
            $source = $decoder($path);
        } finally {
            restore_error_handler();
        }
        if ($source === false || $decodeWarning) {
            throw new RuntimeException('profile.photoInvalid', 422);
        }
        $orientation = $info['extension'] === 'jpg' ? self::jpegOrientation($path) : 1;
        $scale = min(1, self::MAX_EDGE / max($info['width'], $info['height']));
        $width = max(1, (int) round($info['width'] * $scale));
        $height = max(1, (int) round($info['height'] * $scale));
        do {
            $image = imagecreatetruecolor($width, $height);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $info['width'], $info['height']);
            // Rotate the thumbnail, not a second full-size camera image.
            if (in_array($orientation, [2, 5, 7], true)) {
                imageflip($image, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 4) {
                imageflip($image, IMG_FLIP_VERTICAL);
            }
            $angle = match ($orientation) { 3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0 };
            if ($angle !== 0) {
                $image = imagerotate($image, $angle, 0);
            }
            ob_start();
            try {
                $encoded = $encoder($image, null, $info['extension'] === 'png' ? 9 : 82);
                $bytes = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $outputWidth = imagesx($image);
            $outputHeight = imagesy($image);
            unset($image);
            if (!$encoded || $bytes === '') {
                throw new RuntimeException('profile.photoCompressionFailed', 500);
            }
            $width = max(1, (int) floor($width / 2));
            $height = max(1, (int) floor($height / 2));
        } while (strlen($bytes) > self::MAX_BYTES && max($outputWidth, $outputHeight) > 1);
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('profile.photoCompressionFailed', 500);
        }
        return ['bytes' => $bytes, 'extension' => $info['extension'], 'mime' => $info['mime'], 'width' => $outputWidth, 'height' => $outputHeight];
    }

    /** Lazily cache old oversized photos without changing their DB reference or original. */
    public static function deliveryPath(string $directory, string $storedName): string
    {
        $source = $directory . DIRECTORY_SEPARATOR . $storedName;
        $info = self::inspect($source, $storedName);
        if ($info === null || (max($info['width'], $info['height']) <= self::MAX_EDGE && filesize($source) <= self::MAX_BYTES)) {
            return $source;
        }
        $cacheDirectory = $directory . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY;
        $cache = $cacheDirectory . DIRECTORY_SEPARATOR . $storedName;
        if (self::validCache($cache, $storedName, $source)) {
            return $cache;
        }
        $temporary = null;
        try {
            $optimized = self::optimize($source, $storedName);
            if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0770, true) && !is_dir($cacheDirectory)) {
                return $source;
            }
            $temporary = $cacheDirectory . DIRECTORY_SEPARATOR . '.writing-' . bin2hex(random_bytes(16));
            if (@file_put_contents($temporary, $optimized['bytes'], LOCK_EX) !== strlen($optimized['bytes'])) {
                return $source;
            }
            if (!@rename($temporary, $cache) && !self::validCache($cache, $storedName, $source)) {
                return $source;
            }
            return $cache;
        } catch (Throwable $exception) {
            // Existing installations remain readable if GD is not enabled yet.
            return $source;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function validCache(string $cache, string $name, string $source): bool
    {
        $info = self::inspect($cache, $name);
        return $info !== null && max($info['width'], $info['height']) <= self::MAX_EDGE
            && filesize($cache) <= self::MAX_BYTES && filemtime($cache) >= filemtime($source);
    }

    /** Read only the bounded EXIF IFD0 orientation; no EXIF extension is required. */
    private static function jpegOrientation(string $path): int
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            return 1;
        }
        try {
            if (fread($stream, 2) !== "\xff\xd8") {
                return 1;
            }
            while (!feof($stream)) {
                if (fread($stream, 1) !== "\xff") { break; }
                do { $marker = fread($stream, 1); } while ($marker === "\xff");
                if ($marker === '' || in_array(ord($marker), [0xda, 0xd9], true)) { break; }
                $lengthBytes = fread($stream, 2);
                if (strlen($lengthBytes) !== 2) { break; }
                $length = unpack('n', $lengthBytes)[1] - 2;
                if ($length < 0) { break; }
                $segment = $length > 0 ? fread($stream, $length) : '';
                if (ord($marker) !== 0xe1 || !str_starts_with($segment, "Exif\0\0")) { continue; }
                $tiff = substr($segment, 6);
                if (strlen($tiff) < 8 || !in_array(substr($tiff, 0, 4), ["II\x2a\0", "MM\0\x2a"], true)) { continue; }
                $little = substr($tiff, 0, 2) === 'II';
                $short = static fn (int $offset): int => unpack($little ? 'v' : 'n', substr($tiff, $offset, 2))[1];
                $long = static fn (int $offset): int => unpack($little ? 'V' : 'N', substr($tiff, $offset, 4))[1];
                $offset = $long(4);
                if ($offset < 8 || $offset + 2 > strlen($tiff)) { continue; }
                $count = $short($offset);
                for ($entry = $offset + 2, $index = 0; $index < $count && $entry + 12 <= strlen($tiff); $index++, $entry += 12) {
                    if ($short($entry) === 0x112 && $short($entry + 2) === 3 && $long($entry + 4) === 1) {
                        $orientation = $short($entry + 8);
                        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
                    }
                }
            }
        } finally {
            fclose($stream);
        }
        return 1;
    }
}

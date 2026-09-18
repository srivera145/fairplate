<?php

namespace Keel\App\Services;

use Keel\Core\Env;

/**
 * Photos that come off a phone, stored as something a menu can serve.
 *
 * A picture taken on the counter tablet is four thousand pixels wide and four
 * megabytes; the menu shows it at a few hundred pixels. Every upload is resized
 * to fit an 800px box and re-encoded as WebP, which is roughly a quarter of the
 * JPEG for the same picture and is the difference between a menu that loads on
 * the pavement outside and one that does not.
 *
 * Nothing is trusted about the upload. The extension is ignored entirely; the
 * type comes from reading the file's own header through getimagesize(), and a
 * file that does not decode as an image never reaches disk. That matters more
 * here than for a normal upload, because these land in the public web root,
 * where anything executable would be served.
 *
 * Re-encoding is itself a defence: a polyglot file that is both a valid GIF and
 * a valid PHP script stops being either once it has been through GD.
 */
class ImageService
{
    /** Longest edge, in pixels, after the resize. */
    public const MAX_EDGE = 800;

    /** 0-100. 82 is where WebP stops looking different and keeps getting smaller. */
    public const QUALITY = 82;

    private const SUPPORTED = [
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_GIF => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];

    /**
     * Stores one uploaded photo and hands back the public path to it.
     *
     * @param array $file one entry from $_FILES
     * @param string $directory below public_html/uploads, e.g. "menu/12"
     * @return string the path to put in the database, e.g. "/uploads/menu/12/ab.webp"
     */
    public static function storeUpload(array $file, string $directory): string
    {
        self::assertAvailable();

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new \RuntimeException('That photo is too large.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('The photo did not upload. Try again.');
        }

        $source = (string) ($file['tmp_name'] ?? '');

        // is_uploaded_file is the check that stops a crafted tmp_name pointing
        // this at a file on the server that was never uploaded at all.
        if ($source === '' || !is_uploaded_file($source)) {
            throw new \RuntimeException('That upload could not be read.');
        }

        $maxBytes = max(1, (int) Env::get('FILESYSTEM_MAX_UPLOAD_MB', 10)) * 1024 * 1024;

        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new \RuntimeException('That photo is too large.');
        }

        return self::storeFile($source, $directory);
    }

    /**
     * The same work on a file already on disk. Seeders and tests use this; the
     * upload path is the same code with the upload checks in front of it.
     */
    public static function storeFile(string $sourcePath, string $directory): string
    {
        self::assertAvailable();

        $info = @getimagesize($sourcePath);

        if ($info === false || !isset(self::SUPPORTED[$info[2]])) {
            throw new \RuntimeException('That file is not a JPEG, PNG, GIF or WebP image.');
        }

        $image = @(self::SUPPORTED[$info[2]])($sourcePath);

        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('That image could not be read.');
        }

        try {
            $resized = self::fitWithin($image, self::MAX_EDGE);
            $relativeDirectory = self::safeDirectory($directory);
            $filename = bin2hex(random_bytes(16)) . '.webp';
            $absoluteDirectory = self::uploadRoot() . '/' . $relativeDirectory;

            if (!is_dir($absoluteDirectory)
                && !mkdir($absoluteDirectory, 0775, true)
                && !is_dir($absoluteDirectory)) {
                throw new \RuntimeException('The photo could not be saved.');
            }

            if (!imagewebp($resized, $absoluteDirectory . '/' . $filename, self::QUALITY)) {
                throw new \RuntimeException('The photo could not be saved.');
            }
        } finally {
            imagedestroy($image);

            if (isset($resized) && $resized instanceof \GdImage && $resized !== $image) {
                imagedestroy($resized);
            }
        }

        return '/uploads/' . $relativeDirectory . '/' . $filename;
    }

    /**
     * Removes a photo this service stored. Anything outside the uploads root is
     * left alone, so a tampered database value cannot delete application files.
     */
    public static function delete(?string $publicPath): void
    {
        $publicPath = trim((string) $publicPath);

        if ($publicPath === '' || !str_starts_with($publicPath, '/uploads/')) {
            return;
        }

        $relative = self::safeDirectory(substr($publicPath, strlen('/uploads/')));
        $absolute = self::uploadRoot() . '/' . $relative;

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagewebp');
    }

    private static function assertAvailable(): void
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('Photo uploads need PHP\'s GD extension with WebP support enabled.');
        }
    }

    /**
     * Scales an image down to fit a square of $edge, keeping its proportions.
     * An image already inside the box is handed back untouched — re-encoding a
     * small photo only loses detail.
     */
    private static function fitWithin(\GdImage $image, int $edge): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= $edge && $height <= $edge) {
            return $image;
        }

        $scale = min($edge / $width, $edge / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);

        // A PNG or WebP logo is usually transparent, and a menu on a dark theme
        // makes that obvious the moment it is flattened onto black.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $resized;
    }

    private static function uploadRoot(): string
    {
        return dirname(__DIR__, 3) . '/public_html/uploads';
    }

    /**
     * Drops empty, "." and ".." segments, so a directory built from a record id
     * can never climb out of the uploads root.
     */
    private static function safeDirectory(string $path): string
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..'
        );

        return implode('/', $segments);
    }
}

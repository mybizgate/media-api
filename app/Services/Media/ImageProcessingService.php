<?php

namespace App\Services\Media;

use App\DTO\Media\ProcessedImage;
use App\Exceptions\MediaException;
use GdImage;

/**
 * GD based decode -> resize (never upscale) -> WebP encode pipeline.
 * ImageMagick is intentionally not used; GD is available locally and on
 * the shared production host.
 */
class ImageProcessingService
{
    public function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public function supportsWebp(): bool
    {
        return $this->available()
            && function_exists('imagewebp')
            && ! empty(gd_info()['WebP Support']);
    }

    public function supportsAvif(): bool
    {
        return $this->available()
            && function_exists('imagecreatefromavif')
            && ! empty(gd_info()['AVIF Support']);
    }

    /**
     * Image dimensions and MIME type as detected from the file contents.
     *
     * @return array{width: int, height: int, mime: string}|null
     */
    public function inspect(string $path): ?array
    {
        $info = @getimagesize($path);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        return ['width' => (int) $info[0], 'height' => (int) $info[1], 'mime' => (string) $info['mime']];
    }

    public function decode(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/avif' => $this->supportsAvif() ? @imagecreatefromavif($path) : false,
            default => false,
        };

        if (! $image instanceof GdImage) {
            throw MediaException::validation('The image could not be decoded.', ['file' => ['The uploaded image is corrupt or unsupported.']]);
        }

        if ($mime === 'image/jpeg') {
            $image = $this->applyExifOrientation($image, $path);
        }

        return $image;
    }

    /**
     * Fit the image into the bounding box (keeping aspect ratio, never
     * upscaling) and encode it as WebP.
     */
    public function toWebp(GdImage $source, int $maxWidth, int $maxHeight, int $quality): ProcessedImage
    {
        if (! $this->supportsWebp()) {
            throw MediaException::server('WebP encoding is not available on this server.');
        }

        $width = imagesx($source);
        $height = imagesy($source);

        $scale = min($maxWidth / $width, $maxHeight / $height, 1);

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $destination = imagecreatetruecolor($newWidth, $newHeight);

        imagealphablending($destination, false);
        imagesavealpha($destination, true);

        $transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);
        imagefilledrectangle($destination, 0, 0, $newWidth, $newHeight, $transparent);

        imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        $ok = imagewebp($destination, null, max(0, min(100, $quality)));
        $binary = (string) ob_get_clean();

        imagedestroy($destination);

        if (! $ok || $binary === '') {
            throw MediaException::server('Image conversion failed.');
        }

        return new ProcessedImage($binary, $newWidth, $newHeight);
    }

    public function free(GdImage $image): void
    {
        imagedestroy($image);
    }

    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated instanceof GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }
}

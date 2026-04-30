<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * Image service — resize, thumbnail, watermark operations.
 */
final class ImageService
{
    /**
     * Resize image (maintain aspect ratio).
     *
     * @return string Output path
     */
    public function resize(string $inputPath, int $maxWidth, int $maxHeight, ?string $outputPath = null): string
    {
        $outputPath ??= $inputPath;
        $info = @getimagesize($inputPath);
        if ($info === false) {
            throw new \RuntimeException('Not a valid image');
        }

        [$origW, $origH] = $info;
        $ratio = min($maxWidth / $origW, $maxHeight / $origH);

        if ($ratio >= 1.0) {
            // Already smaller, just copy
            if ($outputPath !== $inputPath) {
                copy($inputPath, $outputPath);
            }
            return $outputPath;
        }

        $newW = (int) ($origW * $ratio);
        $newH = (int) ($origH * $ratio);

        $src = $this->createFromFile($inputPath, $info[2]);
        $dst = imagecreatetruecolor($newW, $newH);

        // Preserve transparency for PNG
        if ($info[2] === IMAGETYPE_PNG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        $this->saveImage($dst, $outputPath, $info[2]);

        imagedestroy($src);
        imagedestroy($dst);

        return $outputPath;
    }

    /**
     * Create thumbnail.
     */
    public function thumbnail(string $inputPath, int $size, string $outputPath): string
    {
        return $this->resize($inputPath, $size, $size, $outputPath);
    }

    private function createFromFile(string $path, int $type): \GdImage
    {
        return match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => imagecreatefrompng($path),
            IMAGETYPE_GIF  => imagecreatefromgif($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default => throw new \RuntimeException('Unsupported image type'),
        };
    }

    private function saveImage(\GdImage $image, string $path, int $type): void
    {
        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($image, $path, 85),
            IMAGETYPE_PNG  => imagepng($image, $path, 6),
            IMAGETYPE_GIF  => imagegif($image, $path),
            IMAGETYPE_WEBP => imagewebp($image, $path, 85),
            default => throw new \RuntimeException('Unsupported output type'),
        };
    }
}

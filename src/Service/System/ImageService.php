<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

/**
 * Service orchestrating image mutations such as resizing and thumbnail generation.
 *
 * Employs the PHP GD extension to perform operations while preserving metadata,
 * color profiles, and alpha channel transparency.
 */
final class ImageService
{
    /**
     * Resizes an image file while preserving its original aspect ratio.
     *
     * If the target dimensions are larger than or equal to the source image,
     * the file is copied to the destination without resampling to prevent quality degradation.
     *
     * @param string $inputPath Absolute path to the source image.
     * @param int $maxWidth Max width constraint in pixels.
     * @param int $maxHeight Max height constraint in pixels.
     * @param string|null $outputPath Optional destination path. If omitted, overwrites the source file.
     * @return string The output file path of the processed image.
     * @throws \RuntimeException If the image is invalid, unsupported, or GD operations fail.
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
            // Target is larger or equal; clone source image directly
            if ($outputPath !== $inputPath) {
                copy($inputPath, $outputPath);
            }
            return $outputPath;
        }

        $newW = max(1, (int) ($origW * $ratio));
        $newH = max(1, (int) ($origH * $ratio));

        $src = $this->createFromFile($inputPath, $info[2]);
        $dst = imagecreatetruecolor($newW, $newH);

        // Preserve alpha transparency layers for PNG files
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
     * Creates a square/proportional thumbnail of the input image.
     *
     * @param string $inputPath Absolute path to the source image.
     * @param int $size Maximum boundary size in pixels.
     * @param string $outputPath Destination path for the generated thumbnail.
     * @return string The output file path of the processed thumbnail.
     * @throws \RuntimeException If the source image is invalid, unsupported, or GD operations fail.
     */
    public function thumbnail(string $inputPath, int $size, string $outputPath): string
    {
        return $this->resize($inputPath, $size, $size, $outputPath);
    }

    /**
     * Initialises a GD image resource from a physical file matching its image type.
     *
     * @param string $path Target file path.
     * @param int $type IMAGETYPE_* constant identifier.
     * @return \GdImage Active GD image resource structure.
     * @throws \RuntimeException If the image type format is not supported.
     */
    private function createFromFile(string $path, int $type): \GdImage
    {
        $im = match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => imagecreatefrompng($path),
            IMAGETYPE_GIF  => imagecreatefromgif($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default => throw new \RuntimeException('Unsupported image type'),
        };

        if ($im === false) {
            throw new \RuntimeException('Failed to create image resource from file: ' . $path);
        }

        return $im;
    }

    /**
     * Saves a GD image resource to disk using the appropriate file format.
     *
     * @param \GdImage $image Active GD image resource structure.
     * @param string $path Destination file path.
     * @param int $type IMAGETYPE_* constant identifier.
     * @return void
     * @throws \RuntimeException If the export format type is unsupported.
     */
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

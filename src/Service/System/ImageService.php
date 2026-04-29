<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

class ImageService
{
    private static string $storageDir = __DIR__ . '/../../app/../media/storage/';

    public static function getStorageDir(): string
    {
        $dir = realpath(__DIR__ . '/../../') . '/media/storage/';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public static function generateRandomFilename(string $extension): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $randomString = '';
        for ($i = 0; $i < 30; $i++) {
            $randomString .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $randomString . "." . $extension;
    }

    public static function upload(array $file, int $max_file_size): string
    {
        $upload_directory = self::getStorageDir();

        // ─────────── VALIDATION ───────────
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return json_encode(['status' => false, 'message' => 'No file uploaded or upload failed.']);
        }

        if ($file['size'] > $max_file_size) {
            return json_encode(['status' => false, 'message' => 'File size exceeds maximum allowed.']);
        }

        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $file_info = pathinfo($file['name']);
        $file_extension = strtolower($file_info['extension'] ?? '');

        if (!in_array($file_extension, $allowed_extensions)) {
            return json_encode(['status' => false, 'message' => 'Only JPG, PNG, GIF, and WEBP files are allowed.']);
        }

        // ─────────── FILE NAME ───────────
        $random_filename = self::generateRandomFilename($file_extension);
        $full_path = $upload_directory . $random_filename;

        // ─────────── TRY IMAGICK ───────────
        try {
            if (!extension_loaded('imagick')) {
                throw new \Exception('Imagick extension not installed.');
            }

            $img = new \Imagick($file['tmp_name']);

            $hasAlpha = $img->getImageAlphaChannel();

            if ($hasAlpha && \Imagick::queryFormats('WEBP')) {
                $img->setImageFormat('webp');
                $img->setOption('webp:lossless', 'true');
                $img->setImageCompressionQuality(85);
                $random_filename = self::generateRandomFilename('webp');
            } elseif (!$hasAlpha && \Imagick::queryFormats('JPEG')) {
                $img->setImageFormat('jpeg');
                $img->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $img->setImageCompressionQuality(75);
                $random_filename = self::generateRandomFilename('jpg');
            } else {
                throw new \Exception('Required format not supported by Imagick.');
            }

            $full_path = $upload_directory . $random_filename;

            $img->stripImage();
            $img->writeImage($full_path);
            $img->clear();
            $img->destroy();

            return json_encode(['status' => true, 'file' => $random_filename]);

        } catch (\Exception $e) {
            // ───── FALLBACK: MOVE FILE DIRECTLY ─────
            if (move_uploaded_file($file['tmp_name'], $full_path)) {
                return json_encode([
                    'status' => true,
                    'file' => $random_filename,
                    'note' => 'Imagick not used. File uploaded without processing.'
                ]);
            } else {
                return json_encode(['status' => false, 'message' => 'File upload failed without Imagick: ' . $e->getMessage()]);
            }
        }
    }

    public static function delete(string $file): string
    {
        $upload_directory = self::getStorageDir();

        // Sanitize the filename to prevent directory traversal attacks
        $filename = basename($file);
        $full_path = $upload_directory . $filename;

        $realPath = realpath($full_path);
        $realBase = realpath($upload_directory);
        if ($realPath === false || $realBase === false || strpos($realPath, $realBase . DIRECTORY_SEPARATOR) !== 0) {
            return json_encode(["status" => false, "message" => "File not found."]);
        }

        if (unlink($realPath)) {
            return json_encode(["status" => true, "message" => "File deleted successfully!"]);
        } else {
            return json_encode(["status" => false, "message" => "Error deleting file."]);
        }
    }
}

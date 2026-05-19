<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Support\DateHelper;

/**
 * Filesystem service — safe file operations with path validation.
 *
 * Per OWASP: path traversal prevention, extension whitelist.
 */
final class FilesystemService
{
    private string $baseDir;

    /** Allowed upload extensions */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'zip'];

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 3) . '/storage';
    }

    /**
     * Store uploaded file safely.
     *
     * @return string Relative path from storage dir
     */
    public function storeUpload(array $file, string $subDir = 'uploads'): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . $file['error']);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException("File type not allowed: .{$ext}");
        }

        // Verify MIME type matches extension
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!$this->isMimeAllowed($mimeType, $ext)) {
            throw new \RuntimeException('File MIME type mismatch');
        }

        $targetDir = $this->baseDir . '/' . $subDir . '/' . DateHelper::yearMonthPath();
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $targetPath = $targetDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new \RuntimeException('Failed to move uploaded file');
        }

        return $subDir . '/' . DateHelper::yearMonthPath() . '/' . $filename;
    }

    /**
     * Read file safely (prevents path traversal).
     */
    public function read(string $relativePath): string
    {
        $fullPath = $this->resolveSafe($relativePath);
        if (!file_exists($fullPath)) {
            throw new \RuntimeException('File not found');
        }
        return file_get_contents($fullPath) ?: '';
    }

    /**
     * Delete file safely.
     */
    public function delete(string $relativePath): bool
    {
        $fullPath = $this->resolveSafe($relativePath);
        if (file_exists($fullPath)) {
            return @unlink($fullPath);
        }
        return false;
    }

    /**
     * Check if file exists.
     */
    public function exists(string $relativePath): bool
    {
        try {
            return file_exists($this->resolveSafe($relativePath));
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Resolve path safely — prevent directory traversal.
     * @throws \RuntimeException
     */
    private function resolveSafe(string $relativePath): string
    {
        $fullPath = realpath($this->baseDir . '/' . $relativePath);
        if ($fullPath === false) {
            // File doesn't exist yet — validate parent
            $fullPath = $this->baseDir . '/' . $relativePath;
            $normalized = str_replace(['../', '..\\'], '', $fullPath);
            if ($normalized !== $fullPath) {
                throw new \RuntimeException('Path traversal detected');
            }
            return $normalized;
        }

        $baseReal = realpath($this->baseDir);
        if ($baseReal === false || !str_starts_with($fullPath, $baseReal)) {
            throw new \RuntimeException('Path traversal detected');
        }

        return $fullPath;
    }

    private function isMimeAllowed(string $mime, string $ext): bool
    {
        $allowed = [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'svg'  => ['image/svg+xml'],
            'pdf'  => ['application/pdf'],
            'zip'  => ['application/zip', 'application/x-zip-compressed'],
        ];

        return in_array($mime, $allowed[$ext] ?? [], true);
    }
}

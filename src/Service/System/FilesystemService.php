<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Support\DateHelper;

/**
 * Service facilitating safe filesystem access with validation filters.
 *
 * Implements security checks to prevent path traversal exploits and validates
 * uploaded file extensions against a strict whitelist aligned with OWASP recommendations.
 */
final class FilesystemService
{
    /**
     * Directory path serving as the base root for all file operations.
     *
     * @var string
     */
    private string $baseDir;

    /**
     * Whitelist of permitted upload file extensions.
     *
     * @var string[]
     */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'pdf', 'zip']; // Keep it minimum as possible for security.

    /**
     * Initialises the filesystem service.
     *
     * @param string|null $baseDir Base folder absolute path. Defaults to the system storage directory.
     */
    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 3) . '/storage';
    }

    /**
     * Stores an uploaded file securely within the storage hierarchy.
     *
     * Validates file upload errors, cross-checks file extensions against the whitelist,
     * matches the file's binary signature (MIME type), and moves it to a structured target path.
     *
     * @param array{error: int, name: string, tmp_name: string} $file Associative array structure containing PHP upload metadata.
     * @param string $subDir Sub-directory path where the file should be deposited.
     * @return string Relative path referencing the newly stored file from the storage root.
     * @throws \RuntimeException If the upload contains errors, validation fails, or file transfer fails.
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

        // Verify MIME type matches extension to detect spoofing attempts
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!is_string($mimeType)) {
            throw new \RuntimeException('Failed to determine file MIME type');
        }
        if (!$this->isMimeAllowed($mimeType, $ext)) {
            throw new \RuntimeException('File MIME type mismatch');
        }

        // Add SVG safety check to mitigate Stored XSS and XML attacks
        if ($ext === 'svg') {
            $svgContent = file_get_contents($file['tmp_name']);
            if ($svgContent !== false) {
                if ($this->isSvgMalicious($svgContent)) {
                    throw new \RuntimeException('Malicious SVG content detected');
                }
            }
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
     * Reads the complete contents of a file safely.
     *
     * Prevents path traversal vulnerabilities by enforcing canonical path checks.
     *
     * @param string $relativePath Path to target file relative to the storage directory.
     * @return string The raw contents of the target file.
     * @throws \RuntimeException If the file is missing or validation fails.
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
     * Deletes a file safely from the filesystem.
     *
     * @param string $relativePath Path to target file relative to the storage directory.
     * @return bool True if deletion succeeded, false if target was missing or deletion failed.
     * @throws \RuntimeException If directory traversal attempts are detected.
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
     * Validates whether a file exists at the specified path relative to the storage base.
     *
     * @param string $relativePath Path to target file relative to the storage directory.
     * @return bool True if file exists and traversal checks pass, false otherwise.
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
     * Resolves a relative path to its absolute location while blocking traversal attempts.
     *
     * Asserts that the target absolute path resides strictly within the storage base directory.
     *
     * @param string $relativePath Relative path candidate.
     * @return string The validated absolute filesystem path.
     * @throws \RuntimeException If path validation fails or traversal is detected.
     */
    private function resolveSafe(string $relativePath): string
    {
        $fullPath = realpath($this->baseDir . '/' . $relativePath);
        if ($fullPath === false) {
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

    /**
     * Cross-checks a resolved MIME type signature against an extension.
     *
     * @param string $mime Resolved MIME type of the file content.
     * @param string $ext Extension string to verify.
     * @return bool True if MIME matches the extension structure, false otherwise.
     */
    private function isMimeAllowed(string $mime, string $ext): bool
    {
        $allowed = [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'svg'  => ['image/svg+xml'],
            'ico'  => ['image/x-icon', 'image/vnd.microsoft.icon', 'image/x-ico', 'image/icon'],
            'pdf'  => ['application/pdf'],
            'zip'  => ['application/zip', 'application/x-zip-compressed'],
        ];

        return in_array($mime, $allowed[$ext] ?? [], true);
    }

    /**
     * Inspects SVG XML payload for XSS scripts, handlers, javascript URIs, or XXE entities.
     *
     * @param string $content Raw SVG content.
     * @return bool True if content is deemed malicious.
     */
    private function isSvgMalicious(string $content): bool
    {
        $lower = strtolower($content);

        // Block script tag
        if (str_contains($lower, '<script')) {
            return true;
        }

        // Block XML entity definitions/DTDs (XXE mitigation)
        if (str_contains($lower, '<!entity') || str_contains($lower, '<!doctype')) {
            return true;
        }

        // Block javascript: URIs
        if (str_contains($lower, 'javascript:')) {
            return true;
        }

        // Block inline event handlers (e.g. onload, onclick)
        if (preg_match('/on[a-z]+\s*=/i', $content)) {
            return true;
        }

        return false;
    } // Add more if there any.
}

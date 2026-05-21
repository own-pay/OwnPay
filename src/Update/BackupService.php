<?php
declare(strict_types=1);

namespace OwnPay\Update;

use OwnPay\Service\System\Logger;
use OwnPay\Support\DateHelper;

/**
 * Backup service — DB dump + code ZIP for update rollback.
 *
 * Per security skill: backups include DB schema + data, code files.
 */
final class BackupService
{
    private string $backupDir;
    private ?Logger $logger;
    private \OwnPay\Core\Database $db;

    public function __construct(?string $backupDir = null, ?Logger $logger = null, ?\OwnPay\Core\Database $db = null)
    {
        $this->backupDir = $backupDir ?? dirname(__DIR__, 2) . '/storage/backups';
        $this->logger = $logger;
        $this->db = $db ?? \OwnPay\Core\Database::getInstance();
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Create full backup (DB + code).
     * @return string Path to backup directory
     */
    public function createFullBackup(): string
    {
        $timestamp = DateHelper::backupTimestamp();
        $backupPath = $this->backupDir . '/backup_' . $timestamp;
        @mkdir($backupPath, 0755, true);

        // 1. Database dump
        $this->dumpDatabase($backupPath . '/database.sql');

        // 2. Code backup (ZIP key directories)
        $this->backupCode($backupPath . '/code.zip');

        // 3. Write manifest
        file_put_contents($backupPath . '/manifest.json', json_encode([
            'timestamp'  => $timestamp,
            'version'    => getenv('APP_VERSION') ?: '0.1.0',
            'php'        => PHP_VERSION,
            'db_file'    => 'database.sql',
            'code_file'  => 'code.zip',
        ]));

        return $backupPath;
    }

    /**
     * Restore from backup directory.
     */
    public function restore(string $backupPath): void
    {
        $manifest = json_decode(file_get_contents($backupPath . '/manifest.json') ?: '{}', true);

        // 1. Restore code
        $codeZip = $backupPath . '/code.zip';
        if (file_exists($codeZip)) {
            $zip = new \ZipArchive();
            if ($zip->open($codeZip) === true) {
                $zip->extractTo(dirname(__DIR__, 2));
                $zip->close();
            }
        }

        // 2. Restore database
        $dbFile = $backupPath . '/database.sql';
        if (file_exists($dbFile)) {
            $this->restoreDatabase($dbFile);
        }
    }

    /**
     * Cleanup old backups (keep last N).
     */
    public function cleanup(int $keepLast = 5): void
    {
        $backups = glob($this->backupDir . '/backup_*');
        if ($backups === false) {
            return;
        }

        rsort($backups); // Newest first
        $toDelete = array_slice($backups, $keepLast);

        foreach ($toDelete as $dir) {
            $this->removeDir($dir);
        }
    }

    private function dumpDatabase(string $outputPath): void
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $name = getenv('DB_NAME') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';
        $port = getenv('DB_PORT') ?: '3306';

        // Use --defaults-extra-file to keep password out of process list.
        $tmpCnf = tempnam(sys_get_temp_dir(), 'op_dump_');
        file_put_contents($tmpCnf, "[client]\nuser={$user}\npassword={$pass}\nhost={$host}\nport={$port}\n", LOCK_EX);
        @chmod($tmpCnf, 0600);

        $cmd = sprintf(
            'mysqldump --defaults-extra-file=%s --single-transaction --routines %s > %s 2>&1',
            escapeshellarg($tmpCnf),
            escapeshellarg($name),
            escapeshellarg($outputPath)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        @unlink($tmpCnf); // Always clean up

        if ($exitCode !== 0) {
            $this->pdoDump($outputPath);
        }
    }

    private function pdoDump(string $outputPath): void
    {
        $db = $this->db;
        $pdo = $db->pdo();
        $tables = $db->fetchAll("SHOW TABLES");
        $sql = "-- OwnPay Database Backup\n-- Generated: " . DateHelper::now() . "\n\n";

        foreach ($tables as $row) {
            $tableName = array_values($row)[0];
            $createRow = $db->fetchOne("SHOW CREATE TABLE `{$tableName}`");
            $sql .= ($createRow['Create Table'] ?? '') . ";\n\n";

            $rows = $db->fetchAll("SELECT * FROM `{$tableName}`");
            foreach ($rows as $dataRow) {
                $values = array_map(function ($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote((string) $v);
                }, array_values($dataRow));
                $sql .= "INSERT INTO `{$tableName}` VALUES (" . implode(',', $values) . ");\n";
            }
            $sql .= "\n";
        }

        file_put_contents($outputPath, $sql);
    }

    private function restoreDatabase(string $sqlFile): void
    {
        $sql = file_get_contents($sqlFile);
        if ($sql === false || trim($sql) === '') {
            return;
        }

        $db = $this->db;
        $statements = $this->splitSqlStatements($sql);

        foreach ($statements as $stmt) {
            try {
                $db->execute($stmt);
            } catch (\Throwable $e) {
                $this->logger?->warning('SQL restore failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Split SQL into individual statements, respecting quoted strings.
     * Semicolons inside single-quoted or double-quoted strings are not treated as delimiters.
     *
     * @return string[]
     */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $prev = $i > 0 ? $sql[$i - 1] : '';

            // Handle escaped quotes
            if ($prev === '\\') {
                $current .= $char;
                continue;
            }

            if ($char === "'" && !$inDoubleQuote) {
                $inSingleQuote = !$inSingleQuote;
            } elseif ($char === '"' && !$inSingleQuote) {
                $inDoubleQuote = !$inDoubleQuote;
            }

            if ($char === ';' && !$inSingleQuote && !$inDoubleQuote) {
                $stmt = trim($current);
                if ($stmt !== '' && !str_starts_with($stmt, '--')) {
                    $statements[] = $stmt;
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        // Handle last statement (no trailing semicolon)
        $stmt = trim($current);
        if ($stmt !== '' && !str_starts_with($stmt, '--')) {
            $statements[] = $stmt;
        }

        return $statements;
    }

    private function backupCode(string $outputPath): void
    {
        $appRoot = dirname(__DIR__, 2);
        $zip = new \ZipArchive();
        if ($zip->open($outputPath, \ZipArchive::CREATE) !== true) {
            return;
        }

        $dirs = ['src', 'config', 'templates', 'public'];
        foreach ($dirs as $dir) {
            $fullDir = $appRoot . '/' . $dir;
            if (!is_dir($fullDir)) continue;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($fullDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isFile()) {
                    $relativePath = $dir . '/' . $iterator->getSubPathname();
                    $zip->addFile($item->getPathname(), $relativePath);
                }
            }
        }

        $zip->close();
    }

    private function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}

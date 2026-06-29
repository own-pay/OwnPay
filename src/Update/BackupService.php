<?php
declare(strict_types=1);

namespace OwnPay\Update;

use OwnPay\Service\System\Logger;
use OwnPay\Support\DateHelper;

/**
 * OwnPay backup and rollback recovery service (Enterprise Grade).
 *
 * Facilitates the generation of database dumps and zip archives of core
 * application directories before update execution, allowing reliable
 * point-in-time recovery. Uses secure CLI commands and falls back to PDO-driven
 * schema serialization when CLI utilities are unavailable.
 *
 * @category Update
 * @package  OwnPay\Update
 */
class BackupService
{
    /**
     * Directory path where backup files are saved.
     *
     * @var string
     */
    private string $backupDir;

    /**
     * Application logging service.
     *
     * @var \OwnPay\Service\System\Logger|null
     */
    private ?Logger $logger;

    /**
     * Database connection wrapper instance.
     *
     * @var \OwnPay\Core\Database
     */
    private \OwnPay\Core\Database $db;

    /**
     * BackupService constructor.
     *
     * @param string|null                $backupDir Custom directory to store backups.
     * @param \OwnPay\Service\System\Logger|null $logger    Optional logger interface.
     * @param \OwnPay\Core\Database|null        $db        Optional database instance.
     */
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
     * Generates a full system backup containing database schema/data and source code.
     *
     * Creates a timestamped directory containing database.sql, code.zip, and
     * a manifest describing the backup metadata.
     *
     * @return string Path to the newly created backup directory.
     */
    public function createFullBackup(): string
    {
        $timestamp = DateHelper::backupTimestamp();
        $backupPath = $this->backupDir . '/backup_' . $timestamp;
        @mkdir($backupPath, 0755, true);

        $this->dumpDatabase($backupPath . '/database.sql');

        $this->backupCode($backupPath . '/code.zip');

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
     * Restores the application to a previous state from a specified backup path.
     *
     * Extracts source code archives and runs sequential restoration of the database dump.
     *
     * @param string $backupPath Path to the backup folder.
     * @return void
     */
    public function restore(string $backupPath): void
    {
        $manifest = json_decode(file_get_contents($backupPath . '/manifest.json') ?: '{}', true);

        $codeZip = $backupPath . '/code.zip';
        if (file_exists($codeZip)) {
            $zip = new \ZipArchive();
            if ($zip->open($codeZip) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name === false || str_contains($name, '..') || str_starts_with($name, '/') || str_starts_with($name, '\\')) {
                        $zip->close();
                        throw new \RuntimeException('Backup archive contains unsafe paths');
                    }
                }
                $zip->extractTo(dirname(__DIR__, 2));
                $zip->close();
            }
        }

        $dbFile = $backupPath . '/database.sql';
        if (file_exists($dbFile)) {
            $this->restoreDatabase($dbFile);
        }
    }

    /**
     * Automatically prunes older backups keeping only a defined number of newest archives.
     *
     * @param int $keepLast Maximum number of recent backups to preserve.
     * @return void
     */
    public function cleanup(int $keepLast = 5): void
    {
        $backups = glob($this->backupDir . '/backup_*');
        if ($backups === false) {
            return;
        }

        rsort($backups);
        $toDelete = array_slice($backups, $keepLast);

        foreach ($toDelete as $dir) {
            $this->removeDir($dir);
        }
    }

    /**
     * Dumps the database using mysqldump if available, otherwise falls back to PDO-based extraction.
     *
     * Secures credentials by writing a temporary config file (defaults-extra-file) to prevent
     * credentials from showing up in system process tables.
     *
     * @param string $outputPath Path where the SQL file should be saved.
     * @return void
     */
    private function dumpDatabase(string $outputPath): void
    {
        $host = getenv('DB_HOST') ?: 'localhost';
        $name = getenv('DB_NAME') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS') ?: '';
        $port = getenv('DB_PORT') ?: '3306';

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
        @unlink($tmpCnf);

        if ($exitCode !== 0) {
            $this->pdoDump($outputPath);
        }
    }

    /**
     * Fallback database exporter using PHP PDO connection and dynamic table definition discovery.
     *
     * @param string $outputPath Path where the SQL file should be saved.
     * @return void
     */
    private function pdoDump(string $outputPath): void
    {
        $db = $this->db;
        $pdo = $db->pdo();
        $tables = $db->fetchAll("SHOW TABLES");
        $sql = "-- OwnPay Database Backup\n-- Generated: " . DateHelper::now() . "\n\n";

        foreach ($tables as $row) {
            $tableNameRaw = array_values($row)[0] ?? '';
            $tableName = is_string($tableNameRaw) ? $tableNameRaw : '';
            if ($tableName === '') {
                continue;
            }
            $createRow = $db->fetchOne("SHOW CREATE TABLE `{$tableName}`");
            $createTableSql = is_array($createRow) && isset($createRow['Create Table']) && is_string($createRow['Create Table']) ? $createRow['Create Table'] : '';
            $sql .= $createTableSql . ";\n\n";

            $rows = $db->fetchAll("SELECT * FROM `{$tableName}`");
            foreach ($rows as $dataRow) {
                $values = array_map(function ($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote(is_scalar($v) ? (string) $v : '');
                }, array_values($dataRow));
                $sql .= "INSERT INTO `{$tableName}` VALUES (" . implode(',', $values) . ");\n";
            }
            $sql .= "\n";
        }

        file_put_contents($outputPath, $sql);
    }

    /**
     * Restores database schema and records from an SQL file.
     *
     * Parses the dump file into single queries and executes them sequentially.
     *
     * @param string $sqlFile Absolute path to the SQL dump file.
     * @return void
     */
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
     * Splitting logic for SQL dump processing that respects single and double quotes.
     *
     * Prevents semicolons contained within text fields from incorrectly segmenting queries.
     *
     * @param string $sql Raw SQL content.
     * @return array<int, string> Ordered list of valid SQL queries.
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

        $stmt = trim($current);
        if ($stmt !== '' && !str_starts_with($stmt, '--')) {
            $statements[] = $stmt;
        }

        return $statements;
    }

    /**
     * Backup application directory files into a single ZIP archive.
     *
     * Backs up source (src), templates, config, and web root (public) directories.
     *
     * @param string $outputPath Path where the ZIP file should be generated.
     * @return void
     */
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
                if ($item instanceof \SplFileInfo && $item->isFile()) {
                    $relativePath = $dir . '/' . $iterator->getSubPathname();
                    $zip->addFile($item->getPathname(), $relativePath);
                }
            }
        }

        $zip->close();
    }

    /**
     * Recursively purges a directory and its nested file contents.
     *
     * @param string $dir Path to the target directory.
     * @return void
     */
    private function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}

<?php
declare(strict_types=1);

namespace OwnPay\Service;

use OwnPay\Core\Database;

/**
 * Filesystem Service
 *
 * Handles file system operations: folder deletion, copying, ZIP creation,
 * SQL execution, database backup, and update extraction.
 */
class FilesystemService
{
    public static function deleteFolder($dir)
{
    if (!is_dir($dir))
        return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = "$dir/$file";
        is_dir($path) ? deleteFolder($path) : unlink($path);
    }
    rmdir($dir);
}

    public static function copyFolder($src, $dst)
{
    $dir = opendir($src);
    @mkdir($dst, 0755, true);

    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;

            if (is_dir($srcPath)) {
                copyFolder($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }
    closedir($dir);
}

    public static function zipFolder($source, $zipFile)
{
    $zip = new ZipArchive;
    $zip->open($zipFile, ZipArchive::CREATE);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source)
    );

    foreach ($files as $file) {
        if (!$file->isDir()) {
            $zip->addFile($file, substr($file, strlen($source) + 1));
        }
    }
    $zip->close();
}

    public static function runSql($file)
{
    $db = Database::getInstance();

    if (!file_exists($file)) {
        throw new \Exception("SQL file not found");
    }

    $sql = file_get_contents($file);

    try {
        // Use Database transactional wrapper; getPdo() needed for DDL exec()
        return $db->transactional(function () use ($db, $sql) {
            $pdo = $db->getPdo();

            // Split SQL safely
            $queries = array_filter(array_map('trim', explode(";\n", $sql)));

            foreach ($queries as $query) {
                if ($query !== '') {
                    $pdo->exec($query);
                }
            }

            return true;
        });
    } catch (\Throwable $e) {
        error_log('Update SQL failed: ' . $e->getMessage());
        throw new \Exception('Database update failed');
    }
}

    public static function backupDatabasePDO($backupPath)
{
    $db = Database::getInstance();
    // Backup needs raw PDO for exec(), quote(), and streaming FETCH_ASSOC queries
    $pdo = $db->getPdo();
    $pdo->exec("SET NAMES utf8mb4");

    $fh = fopen($backupPath, 'w');

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $db->fetchAll("SHOW TABLES");
    $tables = array_map(fn($row) => array_values($row)[0], $tables);

    foreach ($tables as $table) {

        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(\PDO::FETCH_NUM)[1];
        fwrite($fh, "DROP TABLE IF EXISTS `$table`;\n$create;\n\n");

        $stmt = $pdo->query("SELECT * FROM `$table`", \PDO::FETCH_ASSOC);
        foreach ($stmt as $row) {
            $vals = [];
            foreach ($row as $val) {
                $vals[] = ($val === null) ? "NULL" : $pdo->quote($val);
            }
            fwrite($fh, "INSERT INTO `$table` VALUES (" . implode(',', $vals) . ");\n");
        }

        fwrite($fh, "\n");
    }

    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
}

    public static function extractUpdate($zipFile, $destination)
{
    $zip = new ZipArchive;
    if ($zip->open($zipFile) !== true) {
        throw new Exception("Cannot open ZIP file");
    }

    // Detect top-level folder in zip
    $topFolder = '';
    if ($zip->numFiles > 0) {
        $firstFile = $zip->getNameIndex(0);
        $parts = explode('/', $firstFile);
        if (count($parts) > 1)
            $topFolder = $parts[0] . '/';
    }

    // Extract each file manually to remove top-level folder
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);

        // Remove top folder prefix
        if ($topFolder && str_starts_with($entry, $topFolder)) {
            $entryNew = substr($entry, strlen($topFolder));
        } else {
            $entryNew = $entry;
        }

        if ($entryNew === '')
            continue; // skip folder itself

        // SEC-05 fix: Zip slip traversal guard
        $entryNew = str_replace('\\', '/', $entryNew);
        if (str_contains($entryNew, '../') || str_starts_with($entryNew, '/')) {
            error_log("[OwnPay] Zip slip detected in update archive: {$entryNew}");
            $zip->close();
            throw new Exception('Security violation: zip entry contains path traversal');
        }

        $targetPath = $destination . '/' . $entryNew;

        // Verify resolved path stays inside destination
        $realDest = realpath($destination);
        if ($realDest !== false) {
            $resolvedDir = realpath(dirname($targetPath));
            if ($resolvedDir !== false && !str_starts_with($resolvedDir, $realDest)) {
                error_log("[OwnPay] Zip entry escapes target dir: {$targetPath}");
                $zip->close();
                throw new Exception('Security violation: zip entry escapes extraction directory');
            }
        }

        if (substr($entry, -1) === '/') { // folder
            @mkdir($targetPath, 0755, true);
        } else { // file
            @mkdir(dirname($targetPath), 0755, true);
            copy("zip://$zipFile#$entry", $targetPath);
        }
    }

    $zip->close();
}
}

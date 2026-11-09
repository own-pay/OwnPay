<?php
declare(strict_types=1);

namespace OwnPay\Update;

/**
 * Thrown by BackupService::restoreDatabase() when an individual SQL statement
 * fails mid-restore. The transaction has already been rolled back by the time
 * this exception is thrown, so callers can rely on the database being in the
 * same state it was in before the restore attempt began (for DML at least;
 * MySQL DDL auto-commits and cannot be rolled back - see the method docblock).
 *
 * @category Update
 * @package  OwnPay\Update
 */
class RestoreDatabaseException extends \RuntimeException
{
    // Inherits constructor and message/previous semantics from RuntimeException.
}

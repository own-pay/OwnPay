<?php
// Fix LedgerService createTransaction call (6 args → 3 args)
$root = dirname(__DIR__);
$file = $root . '/src/Service/Payment/LedgerService.php';
$c = file_get_contents($file);

// The createTransaction call currently has 6 args, needs 3
// Repo sig: createTransaction($referenceType, int $referenceId, ?string $description)
// We need to convert the 6-arg call to 3-arg

$old = <<<'PHP'
$txnId = $this->ledger->createTransaction(
                $eventType,
                $referenceType,
                $referenceId,
                $amount,
                $currency,
                $description
            );
PHP;

$new = <<<'PHP'
$txnId = $this->ledger->createTransaction(
                $referenceType,
                (int) $referenceId,
                $description ?? $eventType
            );
PHP;

// Normalize line endings for comparison
$oldNorm = str_replace("\n", "\r\n", $old);
$newNorm = str_replace("\n", "\r\n", $new);

if (strpos($c, $oldNorm) !== false) {
    $c = str_replace($oldNorm, $newNorm, $c);
    echo "Fixed (CRLF)\n";
} elseif (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    echo "Fixed (LF)\n";
} else {
    echo "Target not found — dumping context around createTransaction:\n";
    $pos = strpos($c, 'createTransaction');
    if ($pos !== false) {
        echo substr($c, $pos - 20, 200) . "\n";
    }
}

file_put_contents($file, $c);

// Also fix RequestValidator
$file2 = $root . '/src/Security/RequestValidator.php';
if (file_exists($file2)) {
    $c2 = file_get_contents($file2);
    $old2 = 'OwnPay\\Security\\InputSanitizer';
    $new2 = 'OwnPay\\Service\\System\\InputSanitizer';
    if (strpos($c2, $old2) !== false) {
        $c2 = str_replace($old2, $new2, $c2);
        file_put_contents($file2, $c2);
        echo "Fixed: RequestValidator namespace\n";
    } else {
        echo "RequestValidator already fixed or not found\n";
    }
}

// Syntax check
exec("php -l " . $root . "/src/Service/Payment/LedgerService.php 2>&1", $o, $code);
echo "LedgerService: " . ($code === 0 ? "OK" : implode(', ', $o)) . "\n";

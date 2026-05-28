<?php
declare(strict_types=1);

$dir = __DIR__ . '/../modules/gateways';

foreach (glob($dir . '/*', GLOB_ONLYDIR) as $folder) {
    $slug = basename($folder);
    $phpFiles = glob($folder . '/*.php');
    if (empty($phpFiles)) continue;
    $file = $phpFiles[0];
    $content = file_get_contents($file);
    
    $tokens = token_get_all($content);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === '$params') {
            // Check if it accesses ['amount']
            if ($i + 3 < $count) {
                $t1 = $tokens[$i+1];
                $t2 = $tokens[$i+2];
                $t3 = $tokens[$i+3];
                if ($t1 === '[' && is_array($t2) && ($t2[0] === T_CONSTANT_ENCAPSED_STRING || $t2[0] === T_STRING) && $t3 === ']') {
                    $key = trim($t2[1], "\"'");
                    if ($key === 'amount') {
                        // Look ahead for math operator
                        for ($j = $i + 4; $j < min($i + 15, $count); $j++) {
                            $nextToken = $tokens[$j];
                            if (is_string($nextToken) && ($nextToken === '*' || $nextToken === '/')) {
                                // Check if it's followed by a number
                                for ($k = $j + 1; $k < min($j + 5, $count); $k++) {
                                    $numToken = $tokens[$k];
                                    if (is_array($numToken) && ($numToken[0] === T_DNUMBER || $numToken[0] === T_LNUMBER)) {
                                        $line = $token[2];
                                        echo "[{$slug}] Match on line {$line}: \$params['amount'] {$nextToken} {$numToken[1]}\n";
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

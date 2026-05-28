<?php
declare(strict_types=1);

$json = json_decode(file_get_contents(__DIR__ . '/phpstan_errors_utf8.json'), true);
$groups = [];
foreach ($json['files'] as $file => $fileData) {
    foreach ($fileData['messages'] as $msg) {
        $text = $msg['message'];
        // Simplify message for grouping (replace specific variable/key names)
        $groupText = preg_replace([
            '/offset \'[^\']+\'/',
            '/Parameter #\d+ \$[a-zA-Z0-9_]+/',
            '/expects [^,]+, [^ ]+ given/'
        ], [
            'offset \'KEY\'',
            'Parameter #N $VAR',
            'expects TYPE, GIVEN'
        ], $text);
        $groups[$groupText] = ($groups[$groupText] ?? 0) + 1;
    }
}

arsort($groups);
foreach ($groups as $msg => $count) {
    echo "- $count: $msg\n";
}

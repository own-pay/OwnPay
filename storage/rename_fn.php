<?php
$f = file_get_contents(dirname(__DIR__) . '/storage/fix_final.php');
$f = str_replace('readFile(', 'rf2(', $f);
$f = str_replace('writeFile(', 'wf2(', $f);
file_put_contents(dirname(__DIR__) . '/storage/fix_final.php', $f);
echo "Renamed all calls\n";

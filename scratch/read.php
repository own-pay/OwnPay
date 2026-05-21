<?php
$file = 'C:/Users/iamna/.gemini/antigravity/brain/99da75f9-0d13-4c3c-9cd5-d881e7af6715/.system_generated/logs/transcript.jsonl';
$handle = fopen($file, 'r');
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        $d = json_decode($line, true);
        if (isset($d['step_index']) && $d['step_index'] == 12941) {
            echo $d['content'];
            break;
        }
    }
    fclose($handle);
}

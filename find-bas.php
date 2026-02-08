<?php
$file = 'c:/Users/DouglasMashonganyika/Documents/GitHub/bytemash-sync/responses/stock/response.json';
$handle = fopen($file, 'r');
if ($handle) {
    $line_num = 0;
    $found = false;
    while (($line = fgets($handle)) !== false) {
        $line_num++;
        if (strpos($line, 'BAS-4770') !== false) {
            echo "Found BAS-4770 at line $line_num: " . trim($line) . "\n";
            $found = true;
            // Optionally break after finding a few
            if ($line_num > 502200) break;
        }
    }
    fclose($handle);
    if (!$found) echo "BAS-4770 not found in file.\n";
} else {
    echo "Could not open file.\n";
}

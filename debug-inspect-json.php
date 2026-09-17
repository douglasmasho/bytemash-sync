<?php
// debug-inspect-json.php
$file = __DIR__ . '/responses/stock/response.json';
$sku = 'BAS-4770-BL-S';

if (!file_exists($file)) {
    die("File not found: $file\n");
}

echo "Reading $file...\n";

// Use a stream parser if memory is tight, but for 15MB file_get_contents is fine usually (limit is often 128MB+)
// But simply decoding 15MB JSON might hit memory limits if limit is 32MB.
// Let's try simple decode first.

$content = file_get_contents($file);
$data = json_decode($content, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("JSON Decode Error: " . json_last_error_msg() . "\n");
}

echo "Searching for SKU: $sku\n";
$found = false;

foreach ($data as $item) {
    if (
        (isset($item['fullCode']) && $item['fullCode'] === $sku) ||
        (isset($item['simpleCode']) && $item['simpleCode'] === $sku)
    ) {
        print_r($item);
        $found = true;
    }
}

if (!$found) {
    echo "SKU not found in JSON.\n";
}

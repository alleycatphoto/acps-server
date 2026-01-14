<?php
// Script to generate historical transactions CSV from receipt files
// Usage: php generate_historical_csv.php "Location Name" "/path/to/base/dir"
// Scans recursively for receipts/*.txt and assigns all to specified location

$location = $argv[1] ?? 'Historical';
$baseDir = $argv[2] ?? __DIR__ . '/../photos';
$outputCsv = __DIR__ . '/historical_transactions.csv';

echo "Scanning from: $baseDir\n";
echo "Location: $location\n";
echo "Output: $outputCsv\n\n";

// Function to recursively find all receipt files
function findReceiptFiles($dir) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'txt' && strpos($file->getPath(), 'receipts') !== false) {
            $files[] = $file->getPathname();
        }
    }
    return $files;
}

// Function to parse a receipt file
function parseReceipt($filePath, $location) {
    $content = file_get_contents($filePath);
    if (!$content) return null;

    $lines = explode("\n", $content);
    $data = [
        'payment_type' => null,
        'amount' => 0,
        'order_number' => null,
        'order_date' => null,
        'time' => date("Y-m-d H:i:s", filemtime($filePath)),
        'location' => $location
    ];

    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'CASH ORDER:') !== false) {
            $data['payment_type'] = 'cash';
            if (preg_match('/\$([0-9]+\.[0-9]{2})/', $line, $m)) {
                $data['amount'] = (float)$m[1];
            }
        } elseif (strpos($line, 'SQUARE ORDER:') !== false) {
            $data['payment_type'] = 'square';
            if (preg_match('/\$([0-9]+\.[0-9]{2})/', $line, $m)) {
                $data['amount'] = (float)$m[1];
            }
        }
        if (preg_match('/Order #:\s*(\d+)/', $line, $m)) {
            $data['order_number'] = $m[1];
        }
        if (preg_match('/Order Date:\s*(.+)/', $line, $m)) {
            $data['order_date'] = trim($m[1]);
        }
    }

    if (!$data['payment_type'] || !$data['order_number']) return null;

    return $data;
}

// Main execution
$receiptFiles = findReceiptFiles($baseDir);
$transactions = [];

foreach ($receiptFiles as $file) {
    $data = parseReceipt($file, $location);
    if ($data) {
        $transactions[] = $data;
    }
}

// Sort by order number
usort($transactions, function($a, $b) {
    return $a['order_number'] <=> $b['order_number'];
});

// Write to CSV
$fp = fopen($outputCsv, 'w');
fputcsv($fp, ['Location', 'Time', 'Order Date', 'Payment Type', 'Amount', 'Order Number']);

foreach ($transactions as $t) {
    fputcsv($fp, [
        $t['location'],
        $t['time'],
        $t['order_date'],
        $t['payment_type'],
        $t['amount'],
        $t['order_number']
    ]);
}

fclose($fp);

echo "Historical transactions CSV generated: $outputCsv\n";
echo "Processed " . count($transactions) . " transactions from " . count($receiptFiles) . " files.\n";
?>
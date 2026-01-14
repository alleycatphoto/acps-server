<?php
// Set PHP limits for processing large amounts of historical data
ini_set('memory_limit', '-1'); // Unlimited memory
ini_set('max_execution_time', 0); // Unlimited execution time
set_time_limit(0); // Remove time limit

// Script to generate historical transactions CSV from receipt files
// Usage: php generate_historical_csv.php "Location Name" "/path/to/base/dir"
// Scans date folders and processes receipts with CASH/CREDIT detection

$location = $argv[1] ?? 'Historical';
$baseDir = $argv[2] ?? __DIR__ . '/../photos';
$outputCsv = __DIR__ . '/historical_transactions.csv';

echo "Scanning from: $baseDir\n";
echo "Location: $location\n";
echo "Output: $outputCsv\n\n";

// Function to find all date folders (YYYY/MM/DD format)
function findDateFolders($baseDir) {
    $dateFolders = [];
    if (!is_dir($baseDir)) return $dateFolders;

    $yearDirs = scandir($baseDir);
    foreach ($yearDirs as $year) {
        if ($year === '.' || $year === '..' || !is_numeric($year)) continue;

        $yearPath = $baseDir . '/' . $year;
        if (!is_dir($yearPath)) continue;

        $monthDirs = scandir($yearPath);
        foreach ($monthDirs as $month) {
            if ($month === '.' || $month === '..' || !is_numeric($month)) continue;

            $monthPath = $yearPath . '/' . $month;
            if (!is_dir($monthPath)) continue;

            $dayDirs = scandir($monthPath);
            foreach ($dayDirs as $day) {
                if ($day === '.' || $day === '..' || !is_numeric($day)) continue;

                $datePath = $monthPath . '/' . $day;
                if (is_dir($datePath)) {
                    $dateFolders[] = $datePath;
                }
            }
        }
    }

    // Sort by date descending (newest first)
    usort($dateFolders, function($a, $b) {
        return strcmp($b, $a);
    });

    return $dateFolders;
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

    $hasCashOrder = false;

    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, 'CASH ORDER:') !== false) {
            $data['payment_type'] = 'cash';
            $hasCashOrder = true;
            if (preg_match('/\$([0-9]+\.[0-9]{2})/', $line, $m)) {
                $data['amount'] = (float)$m[1];
            }
        } elseif (strpos($line, 'SQUARE ORDER:') !== false) {
            $data['payment_type'] = 'square';
            if (preg_match('/\$([0-9]+\.[0-9]{2})/', $line, $m)) {
                $data['amount'] = (float)$m[1];
            }
        }
        // Extract order total if no payment type found yet
        elseif (strpos($line, 'Order Total:') !== false && !$data['payment_type']) {
            // If we haven't found CASH ORDER, assume it's credit/Square
            $data['payment_type'] = $hasCashOrder ? 'cash' : 'credit';
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

    // If we still don't have payment type but have order total, determine based on CASH presence
    if (!$data['payment_type'] && $data['amount'] > 0 && $data['order_number']) {
        $data['payment_type'] = strpos($content, 'CASH ORDER:') !== false ? 'cash' : 'square';
    }

    if (!$data['payment_type'] || !$data['order_number'] || $data['amount'] == 0) return null;

    return $data;
}

// Main execution
$dateFolders = findDateFolders($baseDir);
$allTransactions = [];
$totalProcessed = 0;
$totalCash = 0;
$totalCredit = 0;

echo "Found " . count($dateFolders) . " date folders to process\n\n";

foreach ($dateFolders as $dateFolder) {
    $receiptsDir = $dateFolder . '/receipts';
    if (!is_dir($receiptsDir)) {
        echo "No receipts folder in: $dateFolder\n";
        continue;
    }

    // Find receipt files in this date folder
    $receiptFiles = glob($receiptsDir . '/*.txt');
    if (empty($receiptFiles)) {
        echo "No receipt files in: $receiptsDir\n";
        continue;
    }

    $dateTransactions = [];
    $dateCash = 0;
    $dateCredit = 0;

    foreach ($receiptFiles as $file) {
        $data = parseReceipt($file, $location);
        if ($data) {
            $dateTransactions[] = $data;
            if ($data['payment_type'] === 'cash') {
                $dateCash++;
                $totalCash++;
            } else {
                $dateCredit++;
                $totalCredit++;
            }
        }
    }

    $dateName = basename($dateFolder);
    $dateProcessed = count($dateTransactions);

    echo "$dateName: added $dateProcessed transactions ($dateCash cash, $dateCredit credit)\n";

    $allTransactions = array_merge($allTransactions, $dateTransactions);
    $totalProcessed += $dateProcessed;
}

// Sort all transactions by order number
usort($allTransactions, function($a, $b) {
    return $a['order_number'] <=> $b['order_number'];
});

// Write to CSV
$fp = fopen($outputCsv, 'w');
fputcsv($fp, ['Location', 'Time', 'Order Date', 'Payment Type', 'Amount', 'Order Number']);

foreach ($allTransactions as $t) {
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

echo "\nHistorical transactions CSV generated: $outputCsv\n";
echo "Total processed: $totalProcessed transactions ($totalCash cash, $totalCredit credit) from " . count($dateFolders) . " date folders\n";
?>
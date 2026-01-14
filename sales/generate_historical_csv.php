<?php
// Set PHP limits for processing large amounts of historical data
ini_set('memory_limit', '-1'); // Unlimited memory
ini_set('max_execution_time', 0); // Unlimited execution time
set_time_limit(0); // Remove time limit

// Script to generate historical CASH transactions CSV from receipt files
// Usage: php generate_historical_csv.php "Location Name" "/path/to/base/dir"
// Scans date folders and processes receipts for "CASH ORDER: $XX.XX PAID" lines
// Outputs daily cash totals in same format as CREDIT_ALL_ALLEYCAT.csv

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

// Function to parse a receipt file for CASH ORDER lines
function parseReceipt($filePath) {
    $content = file_get_contents($filePath);
    if (!$content) return [];

    $lines = explode("\n", $content);
    $cashAmounts = [];

    foreach ($lines as $line) {
        $line = trim($line);
        // Only match exact format: "CASH ORDER: ($24.00) PAID"
        if (preg_match('/^CASH ORDER:\s*\(\$([0-9]+\.[0-9]{2})\)\s*PAID$/', $line, $matches)) {
            $cashAmounts[] = (float)$matches[1];
        }
    }

    return $cashAmounts;
}

// Main execution
$dateFolders = findDateFolders($baseDir);
$cashByDate = [];
$totalProcessed = 0;
$totalCashAmount = 0;

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

    $dateCash = 0;
    $orderCount = 0;

    foreach ($receiptFiles as $file) {
        $amounts = parseReceipt($file);
        foreach ($amounts as $amount) {
            $dateCash += $amount;
            $orderCount++;
            $totalProcessed++;
            $totalCashAmount += $amount;
        }
    }

    if ($orderCount > 0) {
        // Extract date from folder path (YYYY/MM/DD)
        $parts = explode('/', $dateFolder);
        $year = $parts[count($parts)-3];
        $month = $parts[count($parts)-2];
        $day = $parts[count($parts)-1];
        $dateKey = "$month/$day/$year";

        $cashByDate[$dateKey] = [
            'location' => $location,
            'orders' => $orderCount,
            'amount' => $dateCash
        ];

        echo "$dateKey: $orderCount cash orders, $" . number_format($dateCash, 2) . "\n";
    }
}

// Sort by date descending
krsort($cashByDate);

// Write cash CSV in same format as credit CSV
$fp = fopen($outputCsv, 'w');
fputcsv($fp, ['Location', 'Order Date', 'Orders', 'Payment Type', 'Amount']);

foreach ($cashByDate as $date => $data) {
    fputcsv($fp, [
        $data['location'],
        $date,
        $data['orders'],
        'Cash',
        '$' . number_format($data['amount'], 2)
    ]);
}

fclose($fp);

echo "\nCash transactions CSV generated: $outputCsv\n";
echo "Total processed: $totalProcessed cash payments, $" . number_format($totalCashAmount, 2) . " from " . count($cashByDate) . " dates\n";
?>
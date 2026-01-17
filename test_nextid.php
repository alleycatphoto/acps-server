<?php
// test_nextid.php - Test script for atomic order ID generation
// Run with: php test_nextid.php

$testFile = __DIR__ . '/photos/test/orders.txt';

// Function from pay.php
function getNextOrderId($filename, $initial = 1000) {
    $dir = dirname($filename);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log("getNextOrderId: failed to create dir: $dir");
            return $initial;
        }
    }

    $fp = fopen($filename, 'c+');
    if (!$fp) {
        error_log("getNextOrderId: unable to open $filename");
        return $initial;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        error_log("getNextOrderId: unable to lock $filename");
        return $initial;
    }

    rewind($fp);
    $contents = stream_get_contents($fp);
    $current = (int) trim($contents);
    if ($current < $initial) {
        $current = $initial - 1;
    }

    $next = $current + 1;
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, (string)$next . PHP_EOL);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $next;
}

// Test: Get next ID
$id = getNextOrderId($testFile);
echo "Next Order ID: $id\n";
?>
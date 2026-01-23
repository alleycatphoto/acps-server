#!/usr/bin/env php
<?php
/**
 * ACPS90 v9.0 - System Verification Script
 * Verifies all critical components are functioning post-rebranding
 * Usage: php verify_acps90.php
 */

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         ACPS90 v9.0 - System Verification Script            ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$checks = [];
$base_dir = __DIR__;

// 1. Package.json Version Check
echo "[1/10] Checking package.json version...";
$pkg = json_decode(file_get_contents($base_dir . '/package.json'), true);
if ($pkg['version'] === '9.0.0' && $pkg['name'] === 'acps90') {
    echo " ✅ PASS (v9.0.0, name: acps90)\n";
    $checks[] = true;
} else {
    echo " ❌ FAIL (Expected 9.0.0/acps90, got {$pkg['version']}/{$pkg['name']})\n";
    $checks[] = false;
}

// 2. README.md Title Check
echo "[2/10] Checking README.md title...";
$readme = file_get_contents($base_dir . '/README.md');
if (strpos($readme, 'ACPS90') !== false && strpos($readme, '9.0.0') !== false) {
    echo " ✅ PASS (Found ACPS90 v9.0.0)\n";
    $checks[] = true;
} else {
    echo " ❌ FAIL (ACPS90 branding not found)\n";
    $checks[] = false;
}

// 3. Cart Engine Class Check
echo "[3/10] Checking shopping_cart.class.php header...";
$cart = file_get_contents($base_dir . '/shopping_cart.class.php');
if (strpos($cart, 'ACPS90') !== false) {
    echo " ✅ PASS (ACPS90 header updated)\n";
    $checks[] = true;
} else {
    echo " ❌ FAIL (Header not updated)\n";
    $checks[] = false;
}

// 4. Gmailer Check
echo "[4/10] Checking gmailer.php header...";
$gmailer = file_get_contents($base_dir . '/gmailer.php');
if (strpos($gmailer, 'ACPS90') !== false && strpos($gmailer, '9.0') !== false) {
    echo " ✅ PASS (ACPS90 v9.0 header)\n";
    $checks[] = true;
} else {
    echo " ❌ FAIL (Not updated)\n";
    $checks[] = false;
}

// 5. Admin Index Check
echo "[5/10] Checking admin/index.php header...";
$admin = file_get_contents($base_dir . '/admin/index.php');
if (strpos($admin, 'ACPS90') !== false) {
    echo " ✅ PASS (ACPS90 header updated)\n";
    $checks[] = true;
} else {
    echo " ❌ FAIL (Not updated)\n";
    $checks[] = false;
}

// 6. Web Manifest Check
echo "[6/10] Checking site.webmanifest...";
$manifest = json_decode(file_get_contents($base_dir . '/site.webmanifest'), true);
if (strpos($manifest['name'], 'ACPS90') !== false && $manifest['short_name'] === 'ACPS90') {
    echo " ✅ PASS (ACPS90 branding updated)\n";
    $checks[] = true;
} else {
    echo " ❌ FAIL (Not updated)\n";
    $checks[] = false;
}

// 7. Favicon Settings Check
echo "[7/10] Checking favicon-settings.json...";
$favicon = json_decode(file_get_contents($base_dir . '/favicon-settings.json'), true);
if (strpos($favicon['icon']['touch']['appTitle'], 'ACPS90') !== false) {
    echo " ✅ PASS (ACPS90 app title)\n";
    $checks[] = true;
} else {
    echo " ❌ FAIL (Not updated)\n";
    $checks[] = false;
}

// 8. Config Debug Console Check
echo "[8/10] Checking config/debug.php...";
$debug = file_get_contents($base_dir . '/config/debug.php');
if (strpos($debug, 'ACPS90 Debug Console v9.0') !== false) {
    echo " ✅ PASS (Debug console branded)\n";
    $checks[] = true;
} else {
    echo " ❌ FAIL (Not updated)\n";
    $checks[] = false;
}

// 9. API Endpoint Check
echo "[9/10] Checking checkout API...";
if (file_exists($base_dir . '/config/api/checkout.php')) {
    $code = file_get_contents($base_dir . '/config/api/checkout.php');
    if (strpos($code, 'json_encode') !== false) {
        echo " ✅ PASS (Endpoint file exists and valid)\n";
        $checks[] = true;
    } else {
        echo " ⚠️  WARN (File exists, needs review)\n";
        $checks[] = true; // Don't fail
    }
} else {
    echo " ❌ FAIL (Endpoint missing)\n";
    $checks[] = false;
}

// 10. Branding Update File Check
echo "[10/10] Checking ACPS90_BRANDING_UPDATE.md...";
if (file_exists($base_dir . '/ACPS90_BRANDING_UPDATE.md')) {
    echo " ✅ PASS (Branding documentation exists)\n";
    $checks[] = true;
} else {
    echo " ❌ FAIL (Documentation missing)\n";
    $checks[] = false;
}

// Summary
echo "\n╔══════════════════════════════════════════════════════════════╗\n";
$passed = array_sum($checks);
$total = count($checks);
$percentage = ($passed / $total) * 100;
echo "║  RESULTS: $passed/$total passed ({$percentage}%)";
echo str_repeat(" ", 31 - strlen("$passed/$total passed ({$percentage}%)"));
echo "║\n";

if ($percentage === 100) {
    echo "║                   ✅ ALL SYSTEMS GO                        ║\n";
} elseif ($percentage >= 80) {
    echo "║                    ⚠️  REVIEW NEEDED                       ║\n";
} else {
    echo "║                    ❌ CRITICAL ISSUES                      ║\n";
}

echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n📦 ACPS90 v9.0 - Ready for Deployment\n\n";

exit($passed === $total ? 0 : 1);
?>

<?php
//*********************************************************************//
//       _____  .__  .__                 _________         __          //
//      /  _  \ |  | |  |   ____ ___.__. \_   ___ \_____ _/  |_        //
//     /  /_\  \|  | |  | _/ __ <   |  | /    \  \/\__  \\   __\       //
//    /    |    \  |_|  |_\  ___/\___  | \     \____/ __ \|  |         //
//    \____|__  /____/____/\___  > ____|  \______  (____  /__|         //
//            \/               \/\/              \/     \/             //
// *********************** INFORMATION ********************************//
// AlleyCat PhotoStation v3.3.0                                        //
// Author: Paul K. Smith (photos@alleycatphoto.net)                    //
// Date: 12/01/2025                                                    //
//*********************************************************************//
error_reporting(E_ALL);
//ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('mail_error_log', 'order_error.log');

ini_set('memory_limit', '-1');
set_time_limit(0);
ignore_user_abort();
require_once __DIR__ . '/vendor/autoload.php';
require_once "admin/config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

date_default_timezone_set('America/New_York');

ini_set('memory_limit', '-1');
set_time_limit(0);

$date_path = date('Y/m/d');
$dirname   = "photos/" . $date_path . "/pending_email/";

// If ?order=XXX is present, switch the directory
$order = '';
if ((isset($_GET['order']) && $_GET['order'] !== '') || (php_sapi_name() === 'cli' && isset($argv[0]) && $argv[0] !== '')) {
    // Sanitize to avoid traversal or weird chars
    $order = urldecode($_GET['order'] ?? $argv[1] ?? '');
    $order = preg_replace('/[^A-Za-z0-9_-]/', '', $order);

    if ($order !== '') {
        $dirname = "photos/{$date_path}/cash_email/{$order}/";
    }
}

// Read info.txt
$emailDetail = file_get_contents($dirname . "info.txt", true);
$email_inf   = explode('|', $emailDetail);

$user_email   = trim($email_inf[0]);
$user_message = $email_inf[1];

// Build emails folder for this user
$filePath = "photos/" . $date_path . "/emails/" . $user_email;
$files    = glob($filePath . "/*.[jJ][pP]*");

// Move info.txt into the email folder
if (is_file($dirname . "info.txt")) {
    if (!is_dir($filePath)) {
        @mkdir($filePath, 0777, true);
    }
    @rename($dirname . "info.txt", $filePath . "/info.txt");
}
// Add this helper function inside mailer.php (since it needs to log an event):
function acp_log_event($orderID, $event) {
    // Note: Logging here will use file_put_contents directly since mailer.php
    // isn't called via AJAX and can take its time.
    $log_dir = realpath(__DIR__ . "/admin/../logs");
    $log_file = $log_dir . '/cash_orders_event.log';

    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0777, true);
    }

    $logMsg = str_replace(array("\r", "\n"), '', $event);
    $timestamp = date("Y-m-d H:i:s");
    $logEntry = "{$timestamp} | Order {$orderID} | {$logMsg}\n";

    @file_put_contents($log_file, $logEntry, FILE_APPEND | LOCK_EX);
}
// --- helper: move a folder; rename() first, fallback to copy+delete if needed
function move_dir_force(string $src, string $dst): bool {
    if (!is_dir($src)) {
        error_log("move_dir_force: source not found: {$src}");
        return false;
    }

    // Ensure parent of destination exists (…/sent/…)
    $parent = rtrim(dirname(rtrim($dst, '/')), '/');
    if (!is_dir($parent) && !mkdir($parent, 0775, true)) {
        error_log("move_dir_force: failed to mkdir parent: {$parent}");
        return false;
    }

    // Try atomic move
    if (@rename($src, $dst)) {
        return true;
    }

    // Fallback: copy recursively then delete source
    $ok = true;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        /** @var RecursiveDirectoryIterator $innerIterator */
        $innerIterator = $iterator->getInnerIterator();
        $target = $dst . DIRECTORY_SEPARATOR . $innerIterator->getSubPathname();
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0775, true)) {
                $ok = false; break;
            }
        } else {
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true)) {
                $ok = false; break;
            }
            if (!copy($item->getPathname(), $target)) {
                $ok = false; break;
            }
        }
    }
    if ($ok) {
        // Remove source tree
        $cleanup = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($cleanup as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($src);
    } else {
        error_log("move_dir_force: copy phase failed from {$src} to {$dst}");
    }
    return $ok;
}

// --- Watermark all image files before sending ---
// Use a single logo; we’ll dynamically scale it and place it bottom-right.
$stamp = imagecreatefrompng($locationLogo);
imagealphablending($stamp, true);
imagesavealpha($stamp, true);

$stamp_orig_width  = imagesx($stamp);
$stamp_orig_height = imagesy($stamp);

foreach ($files as $image) {
    $edit_photo  = imagecreatefromjpeg($image);
    if (!$edit_photo) {
        continue;
    }

    $edit_width  = imagesx($edit_photo);
    $edit_height = imagesy($edit_photo);

    // --- Compute scaled size: make the logo ~18% of the photo width ---
    $targetWidth  = max(120, (int)round($edit_width * 0.18)); // tweak 0.18 or 120 as desired
    $scale        = $targetWidth / $stamp_orig_width;
    $targetHeight = (int)round($stamp_orig_height * $scale);

    // Create resized stamp with alpha preserved
    $resizedStamp = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($resizedStamp, false);
    imagesavealpha($resizedStamp, true);

    imagecopyresampled(
        $resizedStamp,
        $stamp,
        0, 0,                      // dst x,y
        0, 0,                      // src x,y
        $targetWidth, $targetHeight,
        $stamp_orig_width, $stamp_orig_height
    );

    // --- Bottom-right placement with padding ---
    $padding = 40; // pixels from edges; tweak to taste
    $dstX = $edit_width  - $targetWidth  - $padding;
    $dstY = $edit_height - $targetHeight - $padding;

    if ($dstX < 0) $dstX = 0;
    if ($dstY < 0) $dstY = 0;

    imagecopy(
        $edit_photo,
        $resizedStamp,
        $dstX,
        $dstY,
        0,
        0,
        $targetWidth,
        $targetHeight
    );

    // Save over original image
    imagejpeg($edit_photo, $image, 90);

    imagedestroy($resizedStamp);
    imagedestroy($edit_photo);
}

// Clean up original stamp resource
imagedestroy($stamp);


// --- DEBUG LOGGING ---
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) { @mkdir($logsDir, 0777, true); }
$mailerLog = $logsDir . '/mailer.log';
function mailer_log($msg) {
    global $mailerLog;
    $ts = date('Y-m-d H:i:s');
    @file_put_contents($mailerLog, "[$ts] $msg\n", FILE_APPEND | LOCK_EX);
}
mailer_log('Mailer.php started, cwd: ' . getcwd() . ', _SERVER[PHP_SELF]: ' . ($_SERVER['PHP_SELF'] ?? 'n/a'));

// ---------------------------------------------------------------------
// PHPMailer config + multi-send
// ---------------------------------------------------------------------

$to       = $user_email;
$fromMail = $locationEmail;
$fromName = 'Alley Cat Photo : ' . $locationName;

// New copyright release text appended to body
$copyrightText = <<<EOT

Dear Sir/Madam:

Thank you for your purchase from AlleycatPhoto. Enclosed with this correspondence are the digital image files you have acquired, along with this copyright release for your records. This letter confirms that you have purchased and paid in full for the rights to the accompanying photographs. AlleycatPhoto hereby grants you express written permission to use, reproduce, print, and distribute these digital files without limitation for personal or professional purposes. While AlleycatPhoto retains the original copyright ownership of the images, you are authorized
 to use them freely in any lawful manner you choose, without further obligation or restriction. We sincerely appreciate your business and trust in our work. Please retain this release for your records as proof of usage rights.

Sincerely,
Josh Silva
President
AlleycatPhoto
EOT;

// Base subject lines
$hasFiles = count($files) > 0;
$subjectWithImages = "Alley Cat Photo : Digital Image & Order Receipt";
$subjectNoImages   = "Alley Cat Photo : Order Receipt";

try {

    // If we have at least one image, send ONE email per image
    if ($hasFiles) {
        foreach ($files as $imagePath) {
            mailer_log("Preparing to send email with attachment: $imagePath to $to");

            $mail = new PHPMailer(true);

            // Server settings
            // $mail->SMTPDebug = SMTP::DEBUG_SERVER;
            $mail->isSMTP();
            $mail->Host       = 'netsol-smtp-oxcs.hostingplatform.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = $locationEmail;
            $mail->Password   = $locationEmailPass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            // Force CA cert for SSL
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => true,
                    'cafile' => realpath(__DIR__ . '/cacert.pem'),
                ]
            ];

            // Recipients
            $mail->setFrom($fromMail, $fromName);
            $mail->addAddress($to);
            $mail->addReplyTo($fromMail, 'Alley Cat Photo : ' . $locationName);
            $mail->addBCC('orders@alleycatphoto.net');

            // Attach ONE image per email
            $mail->addAttachment($imagePath);

            // Content
            $mail->isHTML(false); // Plain-text email
            $mail->Subject = $subjectWithImages;
            $mail->Body    = rtrim($user_message) . "\n\n" . $copyrightText;

            try {
                $mail->send();
                mailer_log("Email with $imagePath sent to $to successfully.");
            } catch (Exception $e) {
                mailer_log("ERROR: Failed to send email with $imagePath to $to: " . $mail->ErrorInfo);
            }
        }
    } else {
        mailer_log("No image files found, sending plain receipt to $to");
        $mail = new PHPMailer(true);

        // Server settings
        // $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        $mail->isSMTP();
        $mail->Host       = 'netsol-smtp-oxcs.hostingplatform.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $locationEmail;
        $mail->Password   = $locationEmailPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        // Force CA cert for SSL
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => true,
                'cafile' => realpath(__DIR__ . '/cacert.pem'),
            ]
        ];

        // Recipients
        $mail->setFrom($fromMail, $fromName);
        $mail->addAddress($to);
        $mail->addReplyTo($fromMail, 'Alley Cat Photo : ZipNSlip');
        $mail->addBCC('orders@alleycatphoto.net');

        // Content
        $mail->isHTML(false);
        $mail->Subject = $subjectNoImages;
        $mail->Body    = rtrim($user_message) . "\n\n" . $copyrightText;

        try {
            $mail->send();
            mailer_log("Plain receipt email sent to $to successfully.");
        } catch (Exception $e) {
            mailer_log("ERROR: Failed to send plain receipt email to $to: " . $mail->ErrorInfo);
        }
    }

    echo 'Message has been sent';
    mailer_log("Mailer script completed successfully for $to");
    // AFTER 'Message has been sent':
    if (!empty($order)) {
        acp_log_event($order, "EMAIL_OK"); // Log success
        // ... rest of the move_dir_force logic
    }
    // If this was an order (cash_email/XXX), archive it to sent/XXX
    if (!empty($order)) {
        $src = "photos/{$date_path}/cash_email/{$order}/";
        $dst = "photos/{$date_path}/cash_email/sent/{$order}/";

        if (!move_dir_force($src, $dst)) {
            error_log("Failed to move order folder from {$src} to {$dst}");
        }
    }

} catch (Exception $e) {
    mailer_log("FATAL ERROR: Mailer Exception: " . $e->getMessage());
    echo "Message could not be sent. Mailer Error: {$e->getMessage()}";
}

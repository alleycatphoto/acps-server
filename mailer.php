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

if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    mailer_log("FATAL ERROR: PHPMailer class not found. Check vendor/autoload.php");
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

date_default_timezone_set('America/New_York');

ini_set('memory_limit', '-1');
set_time_limit(0);

$date_path = date('Y/m/d');
$base_dir  = __DIR__;
$dirname   = $base_dir . "/photos/" . $date_path . "/pending_email/";

// If ?order=XXX is present, switch the directory
$order = '';
if ((isset($_GET['order']) && $_GET['order'] !== '') || (php_sapi_name() === 'cli' && isset($argv[0]) && $argv[0] !== '')) {
    // Sanitize to avoid traversal or weird chars 
    $order = urldecode($_GET['order'] ?? $argv[1] ?? '');
    $order = preg_replace('/[^A-Za-z0-9_-]/', '', $order);

    if ($order !== '') {
        $dirname = $base_dir . "/photos/{$date_path}/cash_email/{$order}/";
        mailer_log("Order mode: $order, directory: $dirname");
    }
}

// Read info.txt
if (!is_file($dirname . "info.txt")) {
    mailer_log("ERROR: info.txt not found in $dirname - stopping.");
    exit;
}

$emailDetail = file_get_contents($dirname . "info.txt");
$email_inf   = explode('|', $emailDetail);

if (count($email_inf) < 1 || empty(trim($email_inf[0]))) {
    mailer_log("ERROR: Invalid info.txt content in $dirname - stopping.");
    exit;
}

$user_email   = trim(array_shift($email_inf));
// Re-join remaining parts as message/metadata
$user_message = implode(' | ', $email_inf);

mailer_log("Target email: $user_email");

// Build emails folder for this user
$filePath = $base_dir . "/photos/" . $date_path . "/emails/" . $user_email;
$files    = glob($filePath . "/*.[jJ][pP]*");

mailer_log("Found " . count($files) . " files for attachment in $filePath");

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


// ---------------------------------------------------------------------
// HTML Email Template Builder
// ---------------------------------------------------------------------
function acp_generate_html_email($orderInfo, $copyrightText) {
    // Extract data from the raw message using regex
    $orderId = 'N/A';
    $station = 'N/A';
    $date = 'N/A';
    $total = '0.00';
    $paymentStatus = 'Paid';
    $delivery = 'N/A';

    if (preg_match('/Order #:\s*(\d+)\s*-\s*([A-Z]+)/i', $orderInfo, $m)) {
        $orderId = $m[1];
        $station = $m[2];
    }
    if (preg_match('/Order Date:\s*(.+)$/mi', $orderInfo, $m)) {
        $date = trim($m[1]);
    }
    if (preg_match('/Order Total:\s*\$([0-9.]+)/i', $orderInfo, $m)) {
        $total = $m[1];
    }
    if (preg_match('/Delivery:\s*(.+)$/mi', $orderInfo, $m)) {
        $delivery = trim($m[1]);
    }
    
    // Determine status from the "label" part (e.g. CASH ORDER: $43.00 DUE)
    if (stripos($orderInfo, 'DUE') !== false) {
        $paymentStatus = 'Payment Due at Counter';
    } else {
        $paymentStatus = 'Paid in Full';
    }

    // Define accent color
    $accentColor = '#28a745'; // Green
    
    // Parse items
    $itemsHtml = '';
    if (preg_match('/ITEMS ORDERED:\s*[\-]+(.*?)[\-]+/s', $orderInfo, $m)) {
        $itemsRaw = trim($m[1]);
        $lines = explode("\n", $itemsRaw);
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            // Format: [Qty] Name (ID)
            if (preg_match('/\[(\d+)\]\s*(.*?)\s*\((.*?)\)/', $line, $im)) {
                $itemsHtml .= "<tr>
                    <td style='padding: 12px 10px; border-bottom: 1px solid #222; color: #eee; font-size: 14px;'>
                        <strong style='color: $accentColor;'>{$im[1]}x</strong> {$im[2]}
                    </td>
                    <td style='padding: 12px 10px; border-bottom: 1px solid #222; color: #666; font-size: 13px; text-align: right; font-family: monospace;'>
                        {$im[3]}
                    </td>
                </tr>";
            } else {
                $itemsHtml .= "<tr><td colspan='2' style='padding: 10px; border-bottom: 1px solid #222; color: #ccc; font-size: 13px;'>".htmlspecialchars($line)."</td></tr>";
            }
        }
    }

    $logoUrl = 'cid:logo_img';
    $accentColor = '#28a745'; // Green
    $paymentStatus = 'Payment Received';

    $html = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <link href='https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap' rel='stylesheet'>
        <style>
            body { font-family: 'Poppins', sans-serif !important; }
            .badge { display: inline-block; padding: 6px 14px; border-radius: 4px; font-weight: 600; font-size: 13px; text-transform: uppercase; }
            .badge-paid { background-color: #28a745; color: #fff; }
            .badge-due { background-color: #ffc107; color: #000; }
            .main-text { font-size: 18px; line-height: 1.6; color: #bbb; }
        </style>
    </head>
    <body style='background-color: #0a0a0a; color: #e0e0e0; font-family: \"Poppins\", sans-serif; margin: 0; padding: 0;'>
        <table width='100%' cellpadding='0' cellspacing='0' style='background-color: #0a0a0a;'>
            <tr>
                <td align='center' style='padding: 20px;'>
                    <table width='600' cellpadding='0' cellspacing='0' style='background-color: #141414; border: 1px solid #e70017; border-radius: 12px; overflow: hidden;'>
                        <tr>
                            <td style='padding: 40px; text-align: center; border-bottom: 1px solid #333;'>
                                <img src='$logoUrl' alt='Alley Cat Photo' style='display: block; margin: 0 auto; max-width: 100%;'>
                            </td>
                        </tr>
                        <tr>
                            <td style='padding: 40px;'>
                                <table width='100%' cellpadding='0' cellspacing='0'>
                                    <tr>
                                        <td valign='top' style='border-bottom: 2px solid $accentColor; padding-bottom: 20px;'>
                                            <h1 style='margin: 0; font-size: 32px; color: #fff; font-weight: 800;'>Order #$orderId</h1>
                                            <p style='margin: 5px 0; color: #888; font-size: 16px;'>$date</p>
                                        </td>
                                        <td valign='top' align='right' style='border-bottom: 2px solid $accentColor; padding-bottom: 20px;'>
                                            <div style='margin-bottom: 10px;'>
                                                <span class='badge badge-paid'>$paymentStatus</span>
                                            </div>
                                            <h2 style='margin: 0; color: $accentColor; font-size: 28px; font-weight: 800;'>\$$total</h2>
                                        </td>
                                    </tr>
                                </table>

                                <p class='main-text' style='margin: 30px 0; font-size: 18px;'>
                                  Thank you for choosing <strong>Alley Cat Photo</strong>! Your photos are being printed now, and you can also pick up a copy of your receipt at the counter. Your order details and digital rights release are provided below.
                                </p>

                                <div style='text-align: center; margin: 40px 0; padding: 10px; border: 2px solid #e70017; border-radius: 8px; background-color: #000000;'>
                                    <p style='color: #e4e4e4; font-size: 24px; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px;'>See your photos later at</p>
                                    <a href='https://alleycatphoto.net' style='color: #e70017; text-decoration: none; font-size: 32px; font-weight: 800; text-transform: uppercase;'>alleycatphoto.net</a>
                                </div>

                                <table width='100%' cellpadding='0' cellspacing='0' style='margin-bottom: 30px;'>
                                    <thead>
                                        <tr>
                                            <th align='left' style='color: #e70017; font-size: 14px; text-transform: uppercase; padding: 10px; border-bottom: 1px solid #333;'>Item</th>
                                            <th align='right' style='color: #e70017; font-size: 14px; text-transform: uppercase; padding: 10px; border-bottom: 1px solid #333;'>Reference</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        $itemsHtml
                                    </tbody>
                                </table>

                                <div style='background-color: #1a1a1a; padding: 25px; border-radius: 8px; border-left: 4px solid #e70017;'>
                                    <span style='color: #fff; font-weight: bold; font-size: 15px; text-transform: uppercase; letter-spacing: 1px; display: block; margin-bottom: 15px;'>Legal Copyright Release</span>
                                    <div style='color: #999; font-size: 15px; line-height: 1.7;'>
                                        " . nl2br(trim($copyrightText)) . "
                                    </div>
                                </div>

                                <table width='100%' style='margin-top: 30px; color: #555; font-size: 14px;'>
                                    <tr>
                                        <td>Delivery: <strong style='color: #e70017;'>$delivery</strong></td>
                                        <td align='right'>Station: <strong style='color: #e70017;'>$station</strong></td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                        <tr>
                            <td style='padding: 30px; background-color: #0f0f0f; border-top: 1px solid #333; text-align: center;'>
                                <p style='margin: 0; color: #555; font-size: 14px;'>&copy; " . date('Y') . " Alley Cat Photo Station | <a href='https://www.alleycatphoto.net' style='color: #e70017; text-decoration: none; font-weight: 600;'>alleycatphoto.net</a></p>
                                <p style='margin: 10px 0 0 0; color: #444; font-size: 12px;'>This is an official transaction record. Please keep this email for your records.</p>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>
    </html>";

    return $html;
}

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

            // Embed Logo
            $logoPath = realpath(__DIR__ . '/public/assets/images/alley_logo.png');
            $mail->addEmbeddedImage($logoPath, 'logo_img');

            // Attach ONE image per email
            $mail->addAttachment($imagePath);

            // Content
            $mail->isHTML(true); 
            $mail->Subject = $subjectWithImages;
            $mail->Body    = acp_generate_html_email($user_message, $copyrightText);
            $mail->AltBody = rtrim($user_message) . "\n\n" . $copyrightText;

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

        // Embed Logo
        $logoPath = realpath(__DIR__ . '/public/assets/images/alley_logo.png');
        $mail->addEmbeddedImage($logoPath, 'logo_img');

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subjectNoImages;
        $mail->Body    = acp_generate_html_email($user_message, $copyrightText);
        $mail->AltBody = rtrim($user_message) . "\n\n" . $copyrightText;

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
        $src = __DIR__ . "/photos/{$date_path}/cash_email/{$order}/";
        $dst = __DIR__ . "/photos/{$date_path}/cash_email/sent/{$order}/";

        if (!move_dir_force($src, $dst)) {
            mailer_log("ERROR: Failed to move order folder from {$src} to {$dst}");
        } else {
            mailer_log("Successfully archived order folder to $dst");
        }
    }

} catch (Exception $e) {
    mailer_log("FATAL ERROR: Mailer Exception: " . $e->getMessage());
    echo "Message could not be sent. Mailer Error: {$e->getMessage()}";
}

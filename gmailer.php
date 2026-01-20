<?php
/**
 * GMailer - Google Workspace API Driver (Gmail + Drive)
 * Identity: hawksnest@alleycatphoto.com
 * Logic: Create Daily Root -> Create Order Subfolder -> Upload Binaries -> Share -> Email Link
 */

define('SENDER_EMAIL', 'hawksnest@alleycatphoto.com');
$daily_folder_name = 'ACPS_Photos_' . date("Y-m-d");

$order_id = $argv[1] ?? null;
if (!$order_id) die("No Order ID provided.");

// Local Paths
$date_path = date("Y/m/d");
$spool_path = __DIR__ . "/photos/$date_path/spool/mailer/$order_id/";
$archive_path = __DIR__ . "/photos/$date_path/emails/$order_id/";
$info_file = $spool_path . "info.txt";

if (!is_dir($spool_path) || !file_exists($info_file)) die("Spool folder for Order $order_id missing.");

$meta = json_decode(file_get_contents($info_file), true);
$customer_email = $meta['email'] ?? null;

function get_access_token() {
    $token = trim(shell_exec('gcloud auth application-default print-access-token 2>NUL'));
    return $token ?: null;
}

/**
 * Helper for Google API Calls
 */
function google_api_call($url, $method, $token, $payload = null, $content_type = "application/json") {
    $ch = curl_init($url);
    $headers = [
        "Authorization: Bearer $token",
        "Content-Type: $content_type"
    ];
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    
    if ($payload) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($payload) ? json_encode($payload) : $payload);
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['code' => $code, 'body' => json_decode($resp, true), 'raw' => $resp];
}

/**
 * Manages the Drive hierarchy and binary uploads
 */
function process_drive_upload($order_id, $folder_path, $daily_name, $token) {
    // 1. Find or Create Daily Root Folder
    $q = urlencode("name='$daily_name' and mimeType='application/vnd.google-apps.folder' and trashed=false");
    $search = google_api_call("https://www.googleapis.com/drive/v3/files?q=$q", "GET", $token);
    $daily_id = $search['body']['files'][0]['id'] ?? null;

    if (!$daily_id) {
        $create = google_api_call("https://www.googleapis.com/drive/v3/files", "POST", $token, [
            'name' => $daily_name, 
            'mimeType' => 'application/vnd.google-apps.folder'
        ]);
        $daily_id = $create['body']['id'];
    }

    // 2. Create Order-Specific Subfolder
    $order_folder_name = "Order_$order_id";
    $create_order = google_api_call("https://www.googleapis.com/drive/v3/files", "POST", $token, [
        'name' => $order_folder_name,
        'mimeType' => 'application/vnd.google-apps.folder',
        'parents' => [$daily_id]
    ]);
    $order_folder_id = $create_order['body']['id'];

    // 3. Binary Upload for each JPG
    foreach (glob($folder_path . "*.jpg") as $file) {
        $name = basename($file);
        $file_content = file_get_contents($file);
        
        // Step A: Create File Metadata in the folder
        $meta_res = google_api_call("https://www.googleapis.com/drive/v3/files", "POST", $token, [
            'name' => $name,
            'parents' => [$order_folder_id]
        ]);
        $file_id = $meta_res['body']['id'];

        // Step B: Upload the actual binary data
        $upload_url = "https://www.googleapis.com/upload/drive/v3/files/$file_id?uploadType=media";
        $ch = curl_init($upload_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PATCH");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file_content);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token",
            "Content-Type: image/jpeg"
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    // 4. Set Order Folder permissions to "Anyone with link"
    google_api_call("https://www.googleapis.com/drive/v3/files/$order_folder_id/permissions", "POST", $token, [
        'role' => 'reader',
        'type' => 'anyone'
    ]);

    return "https://drive.google.com/drive/folders/$order_folder_id";
}

function send_formatted_email($to, $order_id, $folder_link, $token) {
    $subject = "Your Alley Cat Photos - Order #$order_id";
    $body = "
        <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
            <h2 style='color: #d9534f;'>Thank You!</h2>
            <p>Your photos from order <strong>#$order_id</strong> are ready.</p>
            <p>We've created a private gallery for you on Google Drive:</p>
            <div style='margin: 30px 0;'>
                <a href='$folder_link' style='background: #d9534f; color: white; padding: 15px 25px; text-decoration: none; border-radius: 5px; font-weight: bold;'>VIEW MY GALLERY</a>
            </div>
            <p style='font-size: 12px; color: #777;'>Links are valid for 30 days. Please download your photos to keep them permanently.</p>
        </div>
    ";
    
    $raw = "MIME-Version: 1.0\r\n" .
           "To: $to\r\n" .
           "From: Alley Cat Photo <" . SENDER_EMAIL . ">\r\n" .
           "Subject: $subject\r\n" .
           "Content-Type: text/html; charset=utf-8\r\n\r\n" .
           $body;

    $encodedMail = strtr(base64_encode($raw), array('+' => '-', '/' => '_'));
    $res = google_api_call("https://gmail.googleapis.com/gmail/v1/users/me/messages/send", "POST", $token, ['raw' => $encodedMail]);
    
    return ($res['code'] == 200);
}

// Execution flow
$token = get_access_token();
if (!$token) {
    die("Error: GCloud not authorized. Run the login command from SPOOLER.md first.");
}

echo "Processing Order $order_id...\n";
$folder_link = process_drive_upload($order_id, $spool_path, $daily_folder_name, $token);
echo "Drive folder created: $folder_link\n";

$success = send_formatted_email($customer_email, $order_id, $folder_link, $token);

if ($success) {
    if (!is_dir(dirname($archive_path))) mkdir(dirname($archive_path), 0777, true);
    rename($spool_path, $archive_path);
    echo "SUCCESS: Order $order_id processed and email sent to $customer_email.";
} else {
    echo "ERROR: Email delivery failed.";
}
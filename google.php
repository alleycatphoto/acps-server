<?php

// ================= CONFIGURATION =================
// 1. Get these from Google Cloud Console (OAuth Client ID)
$clientId     = '88436572512-6ter2jtvirulndghtrk9s36mlc3v569r.apps.googleusercontent.com';
$clientSecret = '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';

// 2. Get this from OAuth Playground (One time only)
// IMPORTANT: You MUST authorize with these scopes to avoid 403 errors:
// - https://www.googleapis.com/auth/photoslibrary
// - https://www.googleapis.com/auth/photoslibrary.sharing
// - https://www.googleapis.com/auth/gmail.send
$refreshToken = '1//04SQghgf16NP7CgYIARAAGAQSNwF-L9IrVpNRf5x_u4NiiCcjtaLJheYetYpA104radIU0dTlF-Z3vYq82QaFT15-yACIio3Tzto'; 

// 3. Sender settings
$senderEmail  = 'admin@acps.dev';
// =================================================

/**
 * Main function to handle the entire workflow
 */
function processCustomerPhoto($filePath, $customerEmail, $fromEmail = null) {
    global $clientId, $clientSecret, $refreshToken, $senderEmail;

    // 1. Get a fresh Access Token (valid for 1 hour)
    $accessToken = getAccessToken($clientId, $clientSecret, $refreshToken);
    
    if (!$accessToken) {
        return "Error: Could not authenticate with Google.";
    }

    $from = $fromEmail ?? $senderEmail;

    // 2. Upload and Share
    $result = uploadAndShare($filePath, $customerEmail, $accessToken, $from);
    
    return $result;
}

/**
 * Generates a fresh Access Token using the Refresh Token
 */
function getAccessToken($clientId, $clientSecret, $refreshToken) {
    $url = 'https://oauth2.googleapis.com/token';
    $params = [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type'    => 'refresh_token'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // SSL Fix for local environments
    $cacert = realpath(__DIR__ . '/cacert.pem');
    if ($cacert && file_exists($cacert)) {
        curl_setopt($ch, CURLOPT_CAINFO, $cacert);
    }
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $response['access_token'] ?? null;
}

/**
 * Uploads photo, creates album, shares it, and emails link
 */
function uploadAndShare($filePath, $customerEmail, $token, $senderEmail) {
    
    if (!file_exists($filePath)) {
        return "Error: File not found at $filePath";
    }

    $cacert = realpath(__DIR__ . '/cacert.pem');
    
    // A. Upload Raw Bytes
    $uploadUrl = 'https://photoslibrary.googleapis.com/v1/uploads';
    $fileData = file_get_contents($filePath);
    if ($fileData === false) return "Error reading file.";
    
    $filename = basename($filePath);

    $ch = curl_init($uploadUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $token,
        "Content-type: application/octet-stream",
        "X-Goog-Upload-Content-Type: image/jpeg",
        "X-Goog-Upload-Protocol: raw"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($cacert && file_exists($cacert)) curl_setopt($ch, CURLOPT_CAINFO, $cacert);

    $uploadToken = curl_exec($ch);
    $uploadStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($uploadStatus != 200 || !$uploadToken) {
        return "Error uploading photo (Status $uploadStatus): " . $uploadToken;
    }

    // B. Create Album
    $albumUrl = 'https://photoslibrary.googleapis.com/v1/albums';
    $albumJson = json_encode(['album' => ['title' => 'Photo for ' . $customerEmail]]);
    
    $ch = curl_init($albumUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $albumJson);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($cacert && file_exists($cacert)) curl_setopt($ch, CURLOPT_CAINFO, $cacert);

    $response = curl_exec($ch);
    $albumStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $albumData = json_decode($response, true);
    curl_close($ch);
    
    $albumId = $albumData['id'] ?? null;
    if (!$albumId) {
        return "Error creating album (Status $albumStatus): " . ($albumData['error']['message'] ?? $response);
    }

    // C. Add Photo to Album
    $batchUrl = 'https://photoslibrary.googleapis.com/v1/mediaItems:batchCreate';
    $batchJson = json_encode([
        'albumId' => $albumId,
        'newMediaItems' => [[
            'description' => 'Shared Photo',
            'simpleMediaItem' => ['uploadToken' => $uploadToken, 'fileName' => $filename]
        ]]
    ]);

    $ch = curl_init($batchUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $batchJson);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($cacert && file_exists($cacert)) curl_setopt($ch, CURLOPT_CAINFO, $cacert);

    curl_exec($ch);
    curl_close($ch);

    // D. Share Album
    $shareUrl = "https://photoslibrary.googleapis.com/v1/albums/$albumId:share";
    $shareJson = json_encode(['sharedAlbumOptions' => ['isCollaborative' => false, 'isCommentable' => false]]);

    $ch = curl_init($shareUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $shareJson);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($cacert && file_exists($cacert)) curl_setopt($ch, CURLOPT_CAINFO, $cacert);

    $shareData = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $link = $shareData['shareInfo']['shareableUrl'] ?? null;
    if (!$link) return "Error getting share link.";

    // E. Email Customer
    $subject = "Your Photo is Ready!";
    $message = "Here is the photo you requested: " . $link;
    $headers = "From: $senderEmail";
    
    mail($customerEmail, $subject, $message, $headers);

    return "Success! Link sent: " . $link;
}

// --- USAGE ---
 echo processCustomerPhoto('photos/2026/01/11/raw/10011.jpg', 'photos@alleycatphoto.net');

?>
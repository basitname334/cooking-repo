<?php
/**
 * Stream a dish image from the database.
 * Keeps list pages fast by avoiding huge base64 blobs in HTML.
 */
require_once __DIR__ . '/../config/database.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit;
}

$conn = null;
try {
    // Fast path: skip schema/seed on image requests
    $conn = db_connect_once();
} catch (Throwable $e) {
    http_response_code(503);
    exit;
}
if (!$conn instanceof PDO) {
    http_response_code(503);
    exit;
}

$row = db_fetch($conn, 'SELECT image FROM dishes WHERE id = ?', [$id]);
$image = $row['image'] ?? '';
if ($image === '') {
    http_response_code(404);
    exit;
}

// data:image/jpeg;base64,....
if (str_starts_with($image, 'data:')) {
    if (!preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#s', $image, $m)) {
        http_response_code(500);
        exit;
    }
    $mime = $m[1];
    $binary = base64_decode($m[2], true);
    if ($binary === false) {
        http_response_code(500);
        exit;
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: public, max-age=86400');
    echo $binary;
    exit;
}

// Legacy local path
$relative = ltrim($image, '/');
$full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
if (!is_file($full)) {
    http_response_code(404);
    exit;
}
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
];
header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($full));
header('Cache-Control: public, max-age=86400');
readfile($full);
exit;

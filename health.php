<?php
/**
 * Health Check Endpoint for Render
 * This endpoint is used by Render to verify the application is running.
 * It doesn't require database connection, so it can return 200 even if DB is temporarily unavailable.
 */
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'message' => 'Application is running',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => PHP_VERSION
]);
exit;

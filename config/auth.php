<?php
/**
 * Authentication Helper Functions
 * Manages user sessions and authentication
 */

// Check payment status before allowing access
// Only block if payment_status.php exists and payment is required
$payment_status_file = __DIR__ . '/payment_status.php';
if (file_exists($payment_status_file)) {
    try {
        require_once $payment_status_file;
    } catch (Exception $e) {
        // If payment status file has an error, allow site to load
        error_log('Payment status check failed: ' . $e->getMessage());
    }
}

// Only block if PAYMENT_REQUIRED is explicitly set to true
if (defined('PAYMENT_REQUIRED') && PAYMENT_REQUIRED === true) {
    // Prevent any output before this
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Ensure no headers are sent yet
    if (!headers_sent()) {
        http_response_code(503); // Service Unavailable
    }
    
    // Display payment required message
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Site Temporarily Unavailable</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
        <style>
            body {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            }
            .maintenance-container {
                background: white;
                border-radius: 20px;
                padding: 3rem;
                max-width: 600px;
                width: 90%;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                text-align: center;
            }
            .maintenance-icon {
                font-size: 5rem;
                color: #dc3545;
                margin-bottom: 1.5rem;
            }
            .maintenance-title {
                color: #1e293b;
                font-weight: bold;
                margin-bottom: 1rem;
            }
            .maintenance-message {
                color: #64748b;
                font-size: 1.1rem;
                line-height: 1.6;
                margin-bottom: 2rem;
            }
        </style>
    </head>
    <body>
        <div class="maintenance-container">
            <i class="bi bi-exclamation-triangle-fill maintenance-icon"></i>
            <h1 class="maintenance-title">Site Temporarily Unavailable</h1>
            <p class="maintenance-message">
                <?php echo defined('PAYMENT_MESSAGE') ? htmlspecialchars(PAYMENT_MESSAGE) : 'This site has been stopped because payment is not made. Please contact the administrator for assistance.'; ?>
            </p>
            <div class="alert alert-warning" role="alert">
                <i class="bi bi-info-circle me-2"></i>
                <strong>Notice:</strong> The site will be restored once payment is completed.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 * @return bool True if user is logged in, false otherwise
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if user is admin
 * @return bool True if user is admin, false otherwise
 */
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Require user to be logged in
 * Redirects to login page if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../auth/login.php');
        exit();
    }
}

/**
 * Require user to be admin
 * Redirects to dashboard if not admin
 */
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ../user/dashboard.php');
        exit();
    }
}

/**
 * Get current user ID
 * @return int|null User ID or null if not logged in
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user role
 * @return string|null User role or null if not logged in
 */
function getCurrentUserRole() {
    return $_SESSION['user_role'] ?? null;
}

/**
 * Logout user
 * Destroys session and redirects to login page
 */
function logout() {
    session_destroy();
    header('Location: login.php');
    exit();
}
?>

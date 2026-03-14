<?php
/**
 * Database Configuration
 * Production-safe: connection retry, automatic migrations, env-based config.
 * PHP 8+ / MySQL cloud (e.g. Aiven) / Linux container.
 */

// -----------------------------------------------------------------------------
// Configuration (environment variables with safe defaults)
// -----------------------------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'food_management_system');
define('DB_PORT', (int) (getenv('DB_PORT') ?: '3306'));

/** Number of connection retry attempts (env: DB_CONNECT_RETRIES, default 3) */
define('DB_CONNECT_RETRIES', max(1, (int) (getenv('DB_CONNECT_RETRIES') ?: 3)));

/** Delay in milliseconds between retries (env: DB_CONNECT_RETRY_DELAY_MS, default 500) */
define('DB_CONNECT_RETRY_DELAY_MS', max(0, (int) (getenv('DB_CONNECT_RETRY_DELAY_MS') ?: 500)));

/** Whether SSL is required (Aiven, PlanetScale, etc.) */
function db_ssl_required(): bool {
    return getenv('DB_SSL_REQUIRED') === 'true'
        || str_contains(DB_HOST, 'aivencloud.com')
        || str_contains(DB_HOST, 'planetscale.com');
}

/** Whether we are in a production-style environment (remote DB) */
function db_is_production(): bool {
    $h = DB_HOST;
    return $h !== 'localhost' && $h !== '127.0.0.1';
}

// -----------------------------------------------------------------------------
// Connection (with retry and proper error handling)
// -----------------------------------------------------------------------------

/**
 * Create database connection with optional retry.
 * Uses SSL when DB_SSL_REQUIRED or host suggests cloud provider.
 * Ensures schema (tables) exists on first use in both local and production.
 *
 * @return mysqli|false Returns connection or false on failure
 */
function getDBConnection() {
    static $connection = null;
    if ($connection !== null) {
        return $connection;
    }

    $lastError = null;
    $attempts = DB_CONNECT_RETRIES;

    while ($attempts-- > 0) {
        try {
            $conn = db_connect_once();
            if ($conn instanceof mysqli && !$conn->connect_error) {
                $conn->set_charset('utf8mb4');
                $connection = $conn;
                db_ensure_schema_once($conn);
                return $conn;
            }
            $lastError = $conn instanceof mysqli ? $conn->connect_error : 'Connection failed';
        } catch (mysqli_sql_exception $e) {
            $lastError = $e->getMessage();
            error_log('DB connection attempt failed: ' . $lastError);
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            error_log('DB connection error: ' . $lastError);
        }

        if ($attempts > 0 && DB_CONNECT_RETRY_DELAY_MS > 0) {
            usleep(DB_CONNECT_RETRY_DELAY_MS * 1000);
        }
    }

    error_log('DB connection failed after retries: ' . ($lastError ?? 'Unknown'));
    return false;
}

/**
 * Single connection attempt (no retry).
 * @return mysqli|false
 */
function db_connect_once() {
    if (db_ssl_required()) {
        $conn = mysqli_init();
        if (!$conn) {
            return false;
        }
        $conn->ssl_set(null, null, null, null, null);
        $ok = @$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT, null, MYSQLI_CLIENT_SSL);
        return $ok ? $conn : false;
    }

    $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
    return ($conn && !$conn->connect_error) ? $conn : false;
}

/**
 * Ensure schema (tables) exists. Runs once per request. Safe for production.
 * Uses existing connection when provided; otherwise opens one (e.g. local without DB).
 */
function db_ensure_schema_once(?mysqli $existingConn = null): void {
    static $done = false;
    if ($done) {
        return;
    }

    $conn = $existingConn;
    $weOpened = false;

    if ($conn === null || $conn->connect_error) {
        if (db_is_production()) {
            $conn = db_connect_once();
        } else {
            $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
            if ($conn && !$conn->connect_error) {
                $conn->query("CREATE DATABASE IF NOT EXISTS `" . $conn->real_escape_string(DB_NAME) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $conn->select_db(DB_NAME);
            }
        }
        if (!$conn || $conn->connect_error) {
            return;
        }
        $conn->set_charset('utf8mb4');
        $weOpened = true;
    }

    try {
        ensureDatabaseSetup($conn);
    } catch (Throwable $e) {
        error_log('ensureDatabaseSetup failed: ' . $e->getMessage());
    } finally {
        if ($weOpened && $conn) {
            $conn->close();
        }
    }

    $done = true;
}

/**
 * Ensure database and tables exist. Creates missing tables only.
 * Safe to call on every first request (local and production).
 *
 * @param mysqli $conn Active connection (must already select DB for production)
 */
function ensureDatabaseSetup(mysqli $conn): void {
    static $setup_done = false;
    if ($setup_done) {
        return;
    }

    $db = $conn->real_escape_string(DB_NAME);
    $required_tables = ['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients', 'orders'];

    // Ensure we're on the right database (local may have connected without DB)
    if (db_is_production() === false) {
        @$conn->select_db(DB_NAME);
    }

    $existing_tables = [];
    $res = @$conn->query("SHOW TABLES WHERE Tables_in_{$db} IN ('" . implode("','", array_map([$conn, 'real_escape_string'], $required_tables)) . "')");
    if ($res) {
        while ($row = $res->fetch_array()) {
            $existing_tables[] = $row[0];
        }
    }

    if (count($existing_tables) === count($required_tables)) {
        $setup_done = true;
        return;
    }

    // Create tables in dependency order with proper error handling
    $sqls = [
        'users' => "CREATE TABLE IF NOT EXISTS `users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `email` VARCHAR(100) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `role` ENUM('admin', 'user') DEFAULT 'user',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'categories' => "CREATE TABLE IF NOT EXISTS `categories` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL UNIQUE,
            `description` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'ingredients' => "CREATE TABLE IF NOT EXISTS `ingredients` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `category_id` INT NOT NULL,
            `unit` VARCHAR(50) NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'dishes' => "CREATE TABLE IF NOT EXISTS `dishes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `description` TEXT,
            `category_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'dish_ingredients' => "CREATE TABLE IF NOT EXISTS `dish_ingredients` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `dish_id` INT NOT NULL,
            `ingredient_id` INT NOT NULL,
            `quantity` DECIMAL(10, 2) NOT NULL,
            `unit` VARCHAR(50) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`dish_id`) REFERENCES `dishes`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `unique_dish_ingredient` (`dish_id`, `ingredient_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'orders' => "CREATE TABLE IF NOT EXISTS `orders` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `customer_id` INT NOT NULL,
            `dish_id` INT NOT NULL,
            `quantity` DECIMAL(10, 2) NOT NULL,
            `total_amount` DECIMAL(10, 2) NOT NULL,
            `order_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `status` ENUM('pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled') DEFAULT 'pending',
            `notes` TEXT,
            FOREIGN KEY (`customer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`dish_id`) REFERENCES `dishes`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($sqls as $name => $sql) {
        if (in_array($name, $existing_tables, true)) {
            continue;
        }
        if (!@$conn->query($sql)) {
            error_log("Database: create table `{$name}` failed: " . $conn->error);
        }
    }

    // Optional: add unit column to dish_ingredients if missing (migration)
    $res = @$conn->query("SHOW COLUMNS FROM `dish_ingredients` LIKE 'unit'");
    if ($res && $res->num_rows === 0) {
        @$conn->query("ALTER TABLE `dish_ingredients` ADD COLUMN `unit` VARCHAR(50) DEFAULT NULL AFTER `quantity`");
    }

    ensureAdminUser($conn);
    $setup_done = true;
}

/**
 * Ensure admin user exists with correct password.
 * 1) If users table does not exist, creates it then inserts default admin.
 * 2) If table exists, checks/updates admin as before.
 * Secure: uses prepared statements and password_hash(PASSWORD_DEFAULT).
 *
 * @param mysqli $conn Active connection
 * @return bool True on success
 */
function ensureAdminUser($conn): bool {
    if (!$conn instanceof mysqli || $conn->connect_error) {
        return false;
    }

    $email = 'admin@example.com';
    $password = 'admin123';

    // 1) Check if users table exists
    $res = @$conn->query("SHOW TABLES LIKE 'users'");
    if (!$res || $res->num_rows === 0) {
        if (!db_create_users_table($conn)) {
            error_log('ensureAdminUser: failed to create users table');
            return false;
        }
    }

    // 2) Check if admin exists
    $stmt = $conn->prepare("SELECT id, password FROM `users` WHERE email = ? AND role = 'admin'");
    if (!$stmt) {
        error_log('ensureAdminUser prepare failed: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result->num_rows === 0) {
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $name = 'Admin';
        $role = 'admin';
        $insert = $conn->prepare("INSERT INTO `users` (name, email, password, role) VALUES (?, ?, ?, ?)");
        if (!$insert) {
            error_log('ensureAdminUser insert prepare failed: ' . $conn->error);
            return false;
        }
        $insert->bind_param('ssss', $name, $email, $password_hash, $role);
        $ok = $insert->execute();
        $insert->close();
        return $ok;
    }

    $admin = $result->fetch_assoc();
    if (!password_verify($password, $admin['password'])) {
        $new_hash = password_hash($password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE `users` SET password = ? WHERE email = ? AND role = 'admin'");
        if ($update) {
            $update->bind_param('ss', $new_hash, $email);
            $update->execute();
            $update->close();
        }
    }

    return true;
}

/**
 * Create only the users table. Used when table is missing before any other schema run.
 *
 * @param mysqli $conn
 * @return bool
 */
function db_create_users_table(mysqli $conn): bool {
    $sql = "CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` ENUM('admin', 'user') DEFAULT 'user',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    return (bool) @$conn->query($sql);
}

/**
 * Show a user-friendly error page when DB connection fails (e.g. local MySQL not running).
 */
function db_die_connection_error(string $message): void {
    if (db_is_production()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Service Unavailable</title></head><body>';
        echo '<h1>Service Unavailable</h1><p>Database is temporarily unavailable. Please try again later.</p>';
        echo '</body></html>';
        exit;
    }
    die("
    <!DOCTYPE html>
    <html>
    <head>
        <title>MySQL Not Running</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
            .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h1 { color: #dc3545; border-bottom: 2px solid #dc3545; padding-bottom: 10px; }
            .error { color: #dc3545; background: #f8d7da; padding: 15px; border-radius: 4px; margin: 20px 0; }
            .info { color: #0c5460; background: #d1ecf1; padding: 15px; border-radius: 4px; margin: 20px 0; }
            ol { line-height: 2; }
            code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; }
        </style>
    </head>
    <body>
    <div class='container'>
        <h1>MySQL Not Running</h1>
        <div class='error'><strong>Error:</strong> " . htmlspecialchars($message) . "</div>
        <div class='info'>
            <strong>To fix:</strong>
            <ol>
                <li>Open XAMPP Control Panel</li>
                <li>Start MySQL</li>
                <li>Refresh this page</li>
            </ol>
        </div>
    </div>
    </body>
    </html>
    ");
}

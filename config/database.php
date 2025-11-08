<?php
/**
 * Database Configuration
 * Contains database connection settings
 */

// Database configuration
// Use environment variables if available (for Render/production), otherwise use defaults (for local development)
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'food_management_system');
define('DB_PORT', getenv('DB_PORT') ?: '3306');

/**
 * Create database connection
 * @return mysqli|false Returns mysqli connection object or false on failure
 */
function getDBConnection() {
    // Only check setup once per request
    static $setup_checked = false;
    
    if (!$setup_checked) {
        ensureDatabaseSetup();
        $setup_checked = true;
    }
    
    // Suppress warnings and handle errors manually
    // Check if SSL is required (for Aiven and other cloud providers)
    $ssl_required = getenv('DB_SSL_REQUIRED') === 'true' || strpos(DB_HOST, 'aivencloud.com') !== false || strpos(DB_HOST, 'planetscale.com') !== false;
    
    if ($ssl_required) {
        // For cloud MySQL providers that require SSL (Aiven, PlanetScale, etc.)
        $conn = mysqli_init();
        if ($conn) {
            $conn->ssl_set(null, null, null, null, null);
            $connected = @$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT, null, MYSQLI_CLIENT_SSL);
            if (!$connected) {
                $conn = false;
            } else {
                // Set UTF-8 charset for proper Unicode support (Urdu, Arabic, etc.)
                $conn->set_charset("utf8mb4");
            }
        }
    } else {
        // Standard connection for local development
        $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
        // Set UTF-8 charset for proper Unicode support (Urdu, Arabic, etc.)
        if ($conn && !$conn->connect_error) {
            $conn->set_charset("utf8mb4");
        }
    }
    
    // Check connection
    if ($conn->connect_error) {
        // If connection fails, try to create database (only in local development)
        if (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1') {
            $temp_conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', (int)DB_PORT);
            if (!$temp_conn->connect_error) {
                $temp_conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $temp_conn->close();
                // Try connecting again
                $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
                // Set UTF-8 charset after reconnection
                if ($conn && !$conn->connect_error) {
                    $conn->set_charset("utf8mb4");
                }
            }
        }
        
        if ($conn && $conn->connect_error) {
            // Check if MySQL is running
            $error_code = $conn->connect_errno;
            if ($error_code == 2002 || $error_code == 2003) {
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
                    <h1>⚠️ MySQL Not Running</h1>
                    <div class='error'>
                        <strong>Error:</strong> Cannot connect to MySQL database. The MySQL service is not running.
                    </div>
                    <div class='info'>
                        <strong>To fix this:</strong>
                        <ol>
                            <li>Open <strong>XAMPP Control Panel</strong></li>
                            <li>Find <strong>MySQL</strong> in the list</li>
                            <li>Click the <strong>Start</strong> button next to MySQL</li>
                            <li>Wait until MySQL status shows as <strong>Running</strong> (green)</li>
                            <li>Refresh this page</li>
                        </ol>
                        <p><strong>Note:</strong> If MySQL won't start, check if port 3306 is already in use by another application.</p>
                    </div>
                </div>
                </body>
                </html>
                ");
            } else {
                die("Connection failed: " . $conn->connect_error);
            }
        }
    }
    
    return $conn;
}

/**
 * Ensure database and tables exist
 * Creates database and tables if they don't exist
 * Only runs once per request to avoid performance issues
 */
function ensureDatabaseSetup() {
    static $setup_done = false;
    
    // If already done in this request, skip
    if ($setup_done) {
        return true;
    }
    
    try {
        // Connect without database first (only in local development)
        // Suppress warnings and handle errors manually
        if (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1') {
            $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', (int)DB_PORT);
            
            if ($conn->connect_error) {
                // MySQL is not running
                return false;
            }
            
            // Create database if not exists (only in local development)
            $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $conn->select_db(DB_NAME);
        } else {
            // For production (Render), connect directly to the database
            // Check if SSL is required (for Aiven and other cloud providers)
            $ssl_required = getenv('DB_SSL_REQUIRED') === 'true' || strpos(DB_HOST, 'aivencloud.com') !== false || strpos(DB_HOST, 'planetscale.com') !== false;
            
            if ($ssl_required) {
                // For cloud MySQL providers that require SSL (Aiven, PlanetScale, etc.)
                $conn = mysqli_init();
                if ($conn) {
                    $conn->ssl_set(null, null, null, null, null);
                    $connected = @$conn->real_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT, null, MYSQLI_CLIENT_SSL);
                    if (!$connected || $conn->connect_error) {
                        return false;
                    } else {
                        // Set UTF-8 charset for proper Unicode support (Urdu, Arabic, etc.)
                        $conn->set_charset("utf8mb4");
                    }
                } else {
                    return false;
                }
            } else {
                // Standard connection for local development
                $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
                
                if ($conn->connect_error) {
                    return false;
                } else {
                    // Set UTF-8 charset for proper Unicode support (Urdu, Arabic, etc.)
                    $conn->set_charset("utf8mb4");
                }
            }
        }
        
                // Check which tables exist
                $required_tables = ['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients', 'orders'];
                $existing_tables = [];
        
        foreach ($required_tables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            if ($result && $result->num_rows > 0) {
                $existing_tables[] = $table;
            }
        }
        
        // If all tables exist, skip creation
        if (count($existing_tables) === count($required_tables)) {
            $conn->close();
            $setup_done = true;
            return true;
        }
        
        // Create users table if not exists
        $users_table = "CREATE TABLE IF NOT EXISTS `users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `email` VARCHAR(100) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `role` ENUM('admin', 'user') DEFAULT 'user',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->query($users_table);
        
        // Create other tables
        $categories_table = "CREATE TABLE IF NOT EXISTS `categories` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL UNIQUE,
            `description` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->query($categories_table);
        
        // Check if ingredients table exists, create if not
        $ingredients_check = $conn->query("SHOW TABLES LIKE 'ingredients'");
        if (!$ingredients_check || $ingredients_check->num_rows == 0) {
            $ingredients_table = "CREATE TABLE IF NOT EXISTS `ingredients` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `category_id` INT NOT NULL,
                `unit` VARCHAR(50) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $result = $conn->query($ingredients_table);
            if (!$result && $conn->error) {
                // If foreign key fails, try without it first, then add it later
                error_log("Ingredients table creation error: " . $conn->error);
            }
        }
        
        $dishes_table = "CREATE TABLE IF NOT EXISTS `dishes` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(100) NOT NULL,
            `description` TEXT,
            `category_id` INT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->query($dishes_table);
        
        $dish_ingredients_table = "CREATE TABLE IF NOT EXISTS `dish_ingredients` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `dish_id` INT NOT NULL,
            `ingredient_id` INT NOT NULL,
            `quantity` DECIMAL(10, 2) NOT NULL,
            `unit` VARCHAR(50) DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`dish_id`) REFERENCES `dishes`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `unique_dish_ingredient` (`dish_id`, `ingredient_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->query($dish_ingredients_table);
        
        // Add unit column if it doesn't exist (for existing tables)
        $result = $conn->query("SHOW COLUMNS FROM `dish_ingredients` LIKE 'unit'");
        if (!$result || $result->num_rows == 0) {
            $conn->query("ALTER TABLE `dish_ingredients` ADD COLUMN `unit` VARCHAR(50) DEFAULT NULL AFTER `quantity`");
        }
        
        // Create customers table (using users table with role='user' as customers)
        // Customers are already stored in users table, but we can add a customers view or additional fields if needed
        
        // Create orders table
        $orders_table = "CREATE TABLE IF NOT EXISTS `orders` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $conn->query($orders_table);
        
        // Insert admin user if not exists
        $result = $conn->query("SELECT COUNT(*) as count FROM `users` WHERE email = 'admin@example.com'");
        if ($result) {
            $row = $result->fetch_assoc();
            if ($row['count'] == 0) {
                // Generate password hash for 'admin123'
                $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO `users` (name, email, password, role) VALUES (?, ?, ?, 'admin')");
                if ($stmt) {
                    $stmt->bind_param("sss", $admin_name, $admin_email, $password_hash);
                    $admin_name = 'Admin';
                    $admin_email = 'admin@example.com';
                    $stmt->execute();
                    $stmt->close();
                } else {
                    // Fallback to direct query if prepared statement fails
                    $conn->query("INSERT INTO `users` (name, email, password, role) 
                                 VALUES ('Admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin')");
                }
            } else {
                // If admin exists, verify password is correct
                $check_stmt = $conn->prepare("SELECT password FROM `users` WHERE email = 'admin@example.com' AND role = 'admin'");
                if ($check_stmt) {
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    if ($check_result->num_rows > 0) {
                        $check_user = $check_result->fetch_assoc();
                        // Only update if password doesn't verify correctly
                        if (!password_verify('admin123', $check_user['password'])) {
                            $test_hash = password_hash('admin123', PASSWORD_DEFAULT);
                            $update_stmt = $conn->prepare("UPDATE `users` SET password = ? WHERE email = 'admin@example.com' AND role = 'admin'");
                            if ($update_stmt) {
                                $update_stmt->bind_param("s", $test_hash);
                                $update_stmt->execute();
                                $update_stmt->close();
                            }
                        }
                    }
                    $check_stmt->close();
                }
            }
        }
        
        $conn->close();
        $setup_done = true;
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Ensure admin user exists with correct password
 * This function is called during login to verify/fix admin user
 */
function ensureAdminUser($conn) {
    $email = 'admin@example.com';
    $password = 'admin123';
    
    // Check if admin user exists
    $stmt = $conn->prepare("SELECT id, password FROM users WHERE email = ? AND role = 'admin'");
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Admin user doesn't exist, create it
        $stmt->close();
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $insert_stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')");
        if ($insert_stmt) {
            $name = 'Admin';
            $insert_stmt->bind_param("sss", $name, $email, $password_hash);
            $insert_stmt->execute();
            $insert_stmt->close();
        }
        return true;
    } else {
        // Admin user exists, verify password is correct
        $admin = $result->fetch_assoc();
        $stmt->close();
        
        if (!password_verify($password, $admin['password'])) {
            // Password is incorrect, update it
            $new_hash = password_hash($password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = ? AND role = 'admin'");
            if ($update_stmt) {
                $update_stmt->bind_param("ss", $new_hash, $email);
                $update_stmt->execute();
                $update_stmt->close();
            }
        }
        return true;
    }
}
?>

<?php
/**
 * Quick Table Creation Script
 * This will create all required tables directly
 */
require_once __DIR__ . '/config/database.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Tables</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .success { color: #28a745; background: #d4edda; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #28a745; }
        .error { color: #dc3545; background: #f8d7da; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #dc3545; }
        .info { color: #0c5460; background: #d1ecf1; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #0c5460; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; border: 1px solid #dee2e6; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Create Database Tables</h1>
    
<?php

try {
    // Connect without database first
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    echo "<div class='success'>✅ Connected to MySQL server</div>";
    
    // Create database
    $sql = "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    if ($conn->query($sql)) {
        echo "<div class='success'>✅ Database '" . DB_NAME . "' created or already exists</div>";
    } else {
        throw new Exception("Error creating database: " . $conn->error);
    }
    
    // Select database
    $conn->select_db(DB_NAME);
    echo "<div class='success'>✅ Selected database '" . DB_NAME . "'</div>";
    
    // Create tables one by one
    $tables = [
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
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`dish_id`) REFERENCES `dishes`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients`(`id`) ON DELETE CASCADE,
            UNIQUE KEY `unique_dish_ingredient` (`dish_id`, `ingredient_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    
    echo "<h2>Creating Tables...</h2>";
    
    foreach ($tables as $table_name => $sql) {
        if ($conn->query($sql)) {
            echo "<div class='success'>✅ Table '<strong>$table_name</strong>' created successfully</div>";
        } else {
            // Check if table already exists
            if (strpos($conn->error, 'already exists') !== false || strpos($conn->error, 'Duplicate') !== false) {
                echo "<div class='info'>ℹ️ Table '<strong>$table_name</strong>' already exists</div>";
            } else {
                echo "<div class='error'>❌ Error creating table '$table_name': " . $conn->error . "</div>";
                echo "<pre>" . htmlspecialchars(substr($sql, 0, 200)) . "...</pre>";
            }
        }
    }
    
    // Insert admin user if not exists
    echo "<h2>Checking Admin User...</h2>";
    $result = $conn->query("SELECT COUNT(*) as count FROM `users` WHERE email = 'admin@example.com'");
    if ($result) {
        $row = $result->fetch_assoc();
        if ($row['count'] == 0) {
            $admin_sql = "INSERT INTO `users` (name, email, password, role) 
                         VALUES ('Admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin')";
            if ($conn->query($admin_sql)) {
                echo "<div class='success'>✅ Admin user created successfully</div>";
            } else {
                echo "<div class='error'>❌ Error inserting admin user: " . $conn->error . "</div>";
            }
        } else {
            echo "<div class='info'>ℹ️ Admin user already exists</div>";
        }
    }
    
    // Verify all tables exist
    echo "<h2>Verification</h2>";
    $all_tables_exist = true;
    foreach (array_keys($tables) as $table_name) {
        $result = $conn->query("SHOW TABLES LIKE '$table_name'");
        if ($result && $result->num_rows > 0) {
            echo "<div class='success'>✅ Table '<strong>$table_name</strong>' exists</div>";
        } else {
            echo "<div class='error'>❌ Table '<strong>$table_name</strong>' is missing</div>";
            $all_tables_exist = false;
        }
    }
    
    $conn->close();
    
    if ($all_tables_exist) {
        echo "<div class='success' style='font-size: 18px; font-weight: bold; margin-top: 20px; padding: 20px;'>";
        echo "🎉 <strong>All tables created successfully!</strong><br><br>";
        echo "<strong>Default Admin Credentials:</strong><br>";
        echo "Email: <strong>admin@example.com</strong><br>";
        echo "Password: <strong>admin123</strong><br><br>";
        echo "<a href='auth/login.php' style='display: inline-block; padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 4px;'>Go to Login Page</a>";
        echo "</div>";
    } else {
        echo "<div class='error' style='margin-top: 20px;'>";
        echo "⚠️ Some tables could not be created. Please check the errors above.";
        echo "</div>";
    }
    
} catch (Exception $e) {
    echo "<div class='error'>❌ <strong>Fatal Error:</strong> " . $e->getMessage() . "</div>";
    echo "<div class='info'>";
    echo "<strong>Make sure:</strong><br>";
    echo "1. MySQL is running in XAMPP<br>";
    echo "2. Check your database credentials in <code>config/database.php</code><br>";
    echo "3. MySQL user has permission to create databases and tables<br>";
    echo "</div>";
}

?>

</div>
</body>
</html>


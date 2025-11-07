<?php
/**
 * Database Setup Script
 * This script will automatically create the database and all required tables
 * Run this once to set up your database
 */
require_once __DIR__ . '/config/database.php';

// Disable error display for cleaner output
error_reporting(E_ALL);
ini_set('display_errors', 1);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Setup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .success { color: #28a745; background: #d4edda; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #28a745; }
        .error { color: #dc3545; background: #f8d7da; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #dc3545; }
        .info { color: #0c5460; background: #d1ecf1; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #0c5460; }
        .warning { color: #856404; background: #fff3cd; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #ffc107; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; border: 1px solid #dee2e6; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; margin: 10px 5px 10px 0; }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-success:hover { background: #218838; }
    </style>
</head>
<body>
<div class="container">
    <h1>🚀 Database Setup</h1>
    
<?php

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
    
    $success = true;
    $messages = [];
    
    try {
        // Connect without selecting database first
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
        
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        
        echo "<div class='success'>✅ Connected to MySQL server</div>";
        
        // Create database if not exists
        $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        if ($conn->query($sql)) {
            echo "<div class='success'>✅ Database '" . DB_NAME . "' created or already exists</div>";
        } else {
            throw new Exception("Error creating database: " . $conn->error);
        }
        
        // Select the database
        $conn->select_db(DB_NAME);
        
        // Read schema file
        $schema_file = __DIR__ . '/database/schema.sql';
        if (!file_exists($schema_file)) {
            throw new Exception("Schema file not found: " . $schema_file);
        }
        
        $schema = file_get_contents($schema_file);
        
        // Remove CREATE DATABASE and USE statements (already handled)
        $schema = preg_replace('/CREATE DATABASE.*?;/is', '', $schema);
        $schema = preg_replace('/USE\s+\w+;/is', '', $schema);
        
        // Split by semicolons and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $schema)));
        
        foreach ($statements as $statement) {
            if (empty($statement) || strpos($statement, '--') === 0) {
                continue; // Skip comments and empty lines
            }
            
            // Execute statement
            if ($conn->query($statement)) {
                // Check what was executed
                if (stripos($statement, 'CREATE TABLE') !== false) {
                    preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $statement, $matches);
                    $table_name = $matches[1] ?? 'unknown';
                    echo "<div class='success'>✅ Table '<strong>$table_name</strong>' created successfully</div>";
                } elseif (stripos($statement, 'INSERT INTO') !== false) {
                    preg_match('/INSERT INTO.*?`?(\w+)`?/i', $statement, $matches);
                    $table_name = $matches[1] ?? 'unknown';
                    echo "<div class='success'>✅ Data inserted into '<strong>$table_name</strong>'</div>";
                }
            } else {
                // Check if error is just "table already exists" - that's okay
                if (stripos($conn->error, 'already exists') !== false) {
                    preg_match('/CREATE TABLE.*?`?(\w+)`?/i', $statement, $matches);
                    $table_name = $matches[1] ?? 'unknown';
                    echo "<div class='info'>ℹ️ Table '<strong>$table_name</strong>' already exists (skipped)</div>";
                } elseif (stripos($conn->error, 'Duplicate entry') !== false) {
                    echo "<div class='info'>ℹ️ Data already exists (skipped)</div>";
                } else {
                    echo "<div class='error'>❌ Error: " . $conn->error . "</div>";
                    echo "<div class='warning'><pre>" . htmlspecialchars(substr($statement, 0, 200)) . "...</pre></div>";
                    $success = false;
                }
            }
        }
        
        // Verify tables were created
        echo "<h2>📋 Verification</h2>";
        $required_tables = ['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients'];
        $all_exist = true;
        
        foreach ($required_tables as $table) {
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            if ($result->num_rows > 0) {
                echo "<div class='success'>✅ Table '<strong>$table</strong>' exists</div>";
            } else {
                echo "<div class='error'>❌ Table '<strong>$table</strong>' is missing</div>";
                $all_exist = false;
            }
        }
        
        // Check admin user
        $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE email = 'admin@example.com'");
        $row = $result->fetch_assoc();
        if ($row['count'] > 0) {
            echo "<div class='success'>✅ Admin user exists</div>";
        } else {
            echo "<div class='warning'>⚠️ Admin user not found. Inserting now...</div>";
            $admin_insert = "INSERT INTO users (name, email, password, role) 
                            VALUES ('Admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin')";
            if ($conn->query($admin_insert)) {
                echo "<div class='success'>✅ Admin user inserted</div>";
            } else {
                echo "<div class='error'>❌ Error inserting admin user: " . $conn->error . "</div>";
            }
        }
        
        $conn->close();
        
        if ($all_exist && $success) {
            echo "<div class='success' style='font-size: 18px; font-weight: bold; margin-top: 20px;'>";
            echo "🎉 <strong>Database setup completed successfully!</strong>";
            echo "</div>";
            echo "<div class='info' style='margin-top: 20px;'>";
            echo "<strong>Default Admin Credentials:</strong><br>";
            echo "Email: <strong>admin@example.com</strong><br>";
            echo "Password: <strong>admin123</strong><br><br>";
            echo "<a href='auth/login.php' class='btn btn-success'>Go to Login Page</a>";
            echo "</div>";
        } else {
            echo "<div class='warning' style='margin-top: 20px;'>";
            echo "⚠️ Setup completed with some warnings. Please review the messages above.";
            echo "</div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ <strong>Fatal Error:</strong> " . $e->getMessage() . "</div>";
        echo "<div class='info'>";
        echo "<strong>Troubleshooting:</strong><br>";
        echo "1. Make sure MySQL is running in XAMPP<br>";
        echo "2. Check your database credentials in <code>config/database.php</code><br>";
        echo "3. Verify MySQL user has permission to create databases<br>";
        echo "</div>";
    }
    
} else {
    // Show setup form
    echo "<div class='info'>";
    echo "<strong>This script will:</strong><br>";
    echo "1. Create the database '" . DB_NAME . "' (if it doesn't exist)<br>";
    echo "2. Create all required tables (users, categories, ingredients, dishes, dish_ingredients)<br>";
    echo "3. Insert the default admin user<br>";
    echo "</div>";
    
    echo "<div class='warning'>";
    echo "<strong>⚠️ Important:</strong> This will only create missing tables. Existing data will not be deleted.";
    echo "</div>";
    
    echo "<form method='POST' style='margin-top: 20px;'>";
    echo "<input type='hidden' name='setup' value='1'>";
    echo "<button type='submit' class='btn' style='cursor: pointer; border: none; font-size: 16px;'>🚀 Run Database Setup</button>";
    echo "</form>";
    
    echo "<div style='margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6;'>";
    echo "<h3>Manual Setup Alternative</h3>";
    echo "<p>If automatic setup doesn't work, you can manually import the SQL file:</p>";
    echo "<ol>";
    echo "<li>Open phpMyAdmin: <a href='http://localhost/phpmyadmin' target='_blank'>http://localhost/phpmyadmin</a></li>";
    echo "<li>Click on 'New' to create a database named '<strong>" . DB_NAME . "</strong>'</li>";
    echo "<li>Select the database and click the 'Import' tab</li>";
    echo "<li>Choose the file: <code>database/schema.sql</code></li>";
    echo "<li>Click 'Go' to import</li>";
    echo "</ol>";
    echo "</div>";
}

?>

</div>
</body>
</html>


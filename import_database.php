<?php
/**
 * Database Import Script
 * 
 * IMPORTANT: Delete this file after importing your database!
 * This script imports the database schema from database/database.sql
 * 
 * Usage: Access this file via browser or CLI after setting up environment variables
 */

// Load database configuration
require_once __DIR__ . '/config/database.php';

// Check if we're in a safe environment (not production, or with admin authentication)
$is_local = (DB_HOST === 'localhost' || DB_HOST === '127.0.0.1');

// For production, you might want to add authentication here
// if (!$is_local) {
//     die('This script should only be run in development environment');
// }

echo "<!DOCTYPE html>
<html>
<head>
    <title>Database Import</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .success { color: #28a745; background: #d4edda; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .error { color: #dc3545; background: #f8d7da; padding: 15px; border-radius: 4px; margin: 20px 0; }
        .info { color: #0c5460; background: #d1ecf1; padding: 15px; border-radius: 4px; margin: 20px 0; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
<div class='container'>
    <h1>Database Import Tool</h1>";

try {
    // Connect to database
    $conn = getDBConnection();
    
    if (!$conn) {
        throw new Exception("Failed to connect to database. Please check your database configuration.");
    }
    
    echo "<div class='info'><strong>Connected to database:</strong> " . DB_NAME . "@" . DB_HOST . "</div>";
    
    // Read SQL file
    $sql_file = __DIR__ . '/database/database.sql';
    
    if (!file_exists($sql_file)) {
        throw new Exception("SQL file not found: $sql_file");
    }
    
    $sql_content = file_get_contents($sql_file);
    
    if (empty($sql_content)) {
        throw new Exception("SQL file is empty");
    }
    
    echo "<div class='info'><strong>Reading SQL file:</strong> $sql_file</div>";
    
    // Extract only the food_management_system database section
    // The SQL file contains multiple databases, we need only the relevant one
    $pattern = '/CREATE DATABASE IF NOT EXISTS `food_management_system`[\s\S]*?(?=CREATE DATABASE|COMMIT|$)/i';
    preg_match($pattern, $sql_content, $matches);
    
    if (!empty($matches[0])) {
        $sql_content = $matches[0];
        // Remove the CREATE DATABASE statement as we're already connected to the DB
        $sql_content = preg_replace('/CREATE DATABASE IF NOT EXISTS `food_management_system`[^;]*;/i', '', $sql_content);
        $sql_content = preg_replace('/USE `food_management_system`[^;]*;/i', '', $sql_content);
    }
    
    // Remove comments and split by semicolon
    $sql_content = preg_replace('/--.*$/m', '', $sql_content);
    $sql_content = preg_replace('/\/\*[\s\S]*?\*\//', '', $sql_content);
    
    // Split into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql_content)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^(SET|START TRANSACTION|COMMIT)/i', $stmt);
        }
    );
    
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    
    echo "<div class='info'><strong>Executing SQL statements...</strong></div>";
    echo "<pre>";
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) {
            continue;
        }
        
        // Skip if it's a database creation statement
        if (preg_match('/CREATE DATABASE/i', $statement)) {
            continue;
        }
        
        // Execute statement
        if ($conn->query($statement)) {
            $success_count++;
            echo "✓ Executed successfully\n";
        } else {
            $error_count++;
            $error_msg = $conn->error;
            $errors[] = $error_msg;
            
            // Don't show error if table already exists
            if (strpos($error_msg, 'already exists') === false && 
                strpos($error_msg, 'Duplicate key') === false) {
                echo "✗ Error: $error_msg\n";
                echo "Statement: " . substr($statement, 0, 100) . "...\n\n";
            } else {
                echo "⚠ Table/Key already exists (skipped)\n";
                $error_count--; // Don't count "already exists" as errors
            }
        }
    }
    
    echo "</pre>";
    
    // Insert default admin user if not exists
    $check_admin = $conn->query("SELECT COUNT(*) as count FROM users WHERE email = 'admin@example.com'");
    if ($check_admin) {
        $row = $check_admin->fetch_assoc();
        if ($row['count'] == 0) {
            $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')");
            if ($stmt) {
                $name = 'Admin';
                $email = 'admin@example.com';
                $stmt->bind_param("sss", $name, $email, $password_hash);
                $stmt->execute();
                $stmt->close();
                echo "<div class='success'>✓ Default admin user created (admin@example.com / admin123)</div>";
            }
        } else {
            echo "<div class='info'>ℹ Admin user already exists</div>";
        }
    }
    
    echo "<div class='success'><strong>Import completed!</strong><br>";
    echo "Successful statements: $success_count<br>";
    if ($error_count > 0) {
        echo "Errors: $error_count (some may be expected, like 'table already exists')";
    }
    echo "</div>";
    
    if (!empty($errors)) {
        echo "<div class='error'><strong>Some errors occurred:</strong><ul>";
        foreach (array_unique($errors) as $error) {
            if (strpos($error, 'already exists') === false) {
                echo "<li>$error</li>";
            }
        }
        echo "</ul></div>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "<div class='error'><strong>Error:</strong> " . $e->getMessage() . "</div>";
}

echo "<div class='info'><strong>⚠ Security Note:</strong> Please delete this file (import_database.php) after importing your database!</div>";
echo "</div></body></html>";
?>


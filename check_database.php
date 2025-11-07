<?php
/**
 * Database Diagnostic Script
 * Use this to check if your database is properly set up
 */
require_once __DIR__ . '/config/database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Database Check</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .success { color: #28a745; background: #d4edda; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .error { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .warning { color: #856404; background: #fff3cd; padding: 10px; border-radius: 4px; margin: 10px 0; }
        .info { color: #0c5460; background: #d1ecf1; padding: 10px; border-radius: 4px; margin: 10px 0; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; }
    </style>
</head>
<body>
<div class='container'>
    <h1>🔍 Database Diagnostic Check</h1>";

$issues = [];
$success = [];

// Check 1: Database Connection
echo "<h2>1. Database Connection</h2>";
try {
    $conn = getDBConnection();
    if ($conn === false) {
        echo "<div class='error'>❌ <strong>Failed:</strong> Cannot connect to database</div>";
        $issues[] = "Database connection failed";
    } else {
        echo "<div class='success'>✅ <strong>Success:</strong> Connected to database</div>";
        $success[] = "Database connection successful";
        
        // Check 2: Database exists
        echo "<h2>2. Database Selection</h2>";
        $db_selected = mysqli_select_db($conn, DB_NAME);
        if (!$db_selected) {
            echo "<div class='error'>❌ <strong>Failed:</strong> Database '" . DB_NAME . "' does not exist</div>";
            echo "<div class='info'><strong>Solution:</strong> Create the database first. Run this SQL: <pre>CREATE DATABASE " . DB_NAME . ";</pre></div>";
            $issues[] = "Database '" . DB_NAME . "' does not exist";
        } else {
            echo "<div class='success'>✅ <strong>Success:</strong> Database '" . DB_NAME . "' exists</div>";
            $success[] = "Database exists";
            
            // Check 3: Required tables
            echo "<h2>3. Required Tables</h2>";
            $required_tables = ['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients'];
            $missing_tables = [];
            
            foreach ($required_tables as $table) {
                $result = $conn->query("SHOW TABLES LIKE '$table'");
                if ($result->num_rows > 0) {
                    echo "<div class='success'>✅ Table '<strong>$table</strong>' exists</div>";
                    $success[] = "Table '$table' exists";
                } else {
                    echo "<div class='error'>❌ Table '<strong>$table</strong>' does not exist</div>";
                    $missing_tables[] = $table;
                    $issues[] = "Table '$table' is missing";
                }
            }
            
            // Check 4: Users table structure
            if (in_array('users', $missing_tables)) {
                echo "<div class='warning'><strong>⚠ Important:</strong> The 'users' table is missing. This is why login is failing!</div>";
            } else {
                echo "<h2>4. Users Table Structure</h2>";
                $result = $conn->query("DESCRIBE users");
                if ($result) {
                    echo "<div class='success'>✅ Users table structure is valid</div>";
                    echo "<table border='1' cellpadding='10' style='width:100%; border-collapse: collapse; margin-top: 10px;'>";
                    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
                    while ($row = $result->fetch_assoc()) {
                        echo "<tr>";
                        echo "<td>" . $row['Field'] . "</td>";
                        echo "<td>" . $row['Type'] . "</td>";
                        echo "<td>" . $row['Null'] . "</td>";
                        echo "<td>" . $row['Key'] . "</td>";
                        echo "<td>" . ($row['Default'] ?? 'NULL') . "</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                    
                    // Check for admin user
                    $result = $conn->query("SELECT COUNT(*) as count FROM users WHERE email = 'admin@example.com'");
                    $row = $result->fetch_assoc();
                    if ($row['count'] > 0) {
                        echo "<div class='success'>✅ Admin user exists</div>";
                    } else {
                        echo "<div class='warning'>⚠ Admin user does not exist. You may need to insert it.</div>";
                    }
                }
            }
            
            // Check 5: Test query
            echo "<h2>5. Test Query</h2>";
            $stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
            if ($stmt === false) {
                echo "<div class='error'>❌ <strong>Failed:</strong> Cannot prepare query: " . $conn->error . "</div>";
                $issues[] = "Query preparation failed: " . $conn->error;
            } else {
                echo "<div class='success'>✅ <strong>Success:</strong> Query preparation works</div>";
                $success[] = "Query preparation successful";
                $stmt->close();
            }
        }
        
        $conn->close();
    }
} catch (Exception $e) {
    echo "<div class='error'>❌ <strong>Exception:</strong> " . $e->getMessage() . "</div>";
    $issues[] = "Exception: " . $e->getMessage();
}

// Summary
echo "<h2>📋 Summary</h2>";
if (empty($issues)) {
    echo "<div class='success'><strong>✅ All checks passed!</strong> Your database is properly configured.</div>";
} else {
    echo "<div class='error'><strong>❌ Issues found:</strong></div>";
    echo "<ul>";
    foreach ($issues as $issue) {
        echo "<li>" . htmlspecialchars($issue) . "</li>";
    }
    echo "</ul>";
    echo "<div class='info'><strong>💡 Solution:</strong> Please import the SQL schema file:<br>";
    echo "<strong>Path:</strong> <code>database/schema.sql</code><br>";
    echo "<strong>How to import:</strong><ol>";
    echo "<li>Open phpMyAdmin (http://localhost/phpmyadmin)</li>";
    echo "<li>Select or create the database '" . DB_NAME . "'</li>";
    echo "<li>Go to the 'Import' tab</li>";
    echo "<li>Choose the file: <code>database/schema.sql</code></li>";
    echo "<li>Click 'Go' to import</li>";
    echo "</ol></div>";
}

echo "</div></body></html>";
?>


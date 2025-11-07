<?php
/**
 * Fix Admin User Script
 * This script will create or update the admin user with correct password
 */
require_once __DIR__ . '/config/database.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix Admin User</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .success { color: #28a745; background: #d4edda; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #28a745; }
        .error { color: #dc3545; background: #f8d7da; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #dc3545; }
        .info { color: #0c5460; background: #d1ecf1; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #0c5460; }
        .btn { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px; margin: 10px 5px 10px 0; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; border: 1px solid #dee2e6; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Fix Admin User</h1>
    
<?php

try {
    $conn = getDBConnection();
    
    if (!$conn) {
        throw new Exception("Could not connect to database");
    }
    
    echo "<div class='success'>✅ Connected to database</div>";
    
    // Check if admin user exists
    $stmt = $conn->prepare("SELECT id, name, email, password FROM users WHERE email = 'admin@example.com'");
    $stmt->execute();
    $result = $stmt->get_result();
    $admin_exists = $result->num_rows > 0;
    
    if ($admin_exists) {
        $admin = $result->fetch_assoc();
        echo "<div class='info'>ℹ️ Admin user found (ID: {$admin['id']})</div>";
        
        // Test current password
        $test_password = 'admin123';
        if (password_verify($test_password, $admin['password'])) {
            echo "<div class='success'>✅ Current password hash is valid for 'admin123'</div>";
        } else {
            echo "<div class='error'>❌ Current password hash is NOT valid. Updating password...</div>";
            
            // Update password
            $new_hash = password_hash('admin123', PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = 'admin@example.com'");
            $update_stmt->bind_param("s", $new_hash);
            
            if ($update_stmt->execute()) {
                echo "<div class='success'>✅ Password updated successfully!</div>";
                
                // Verify the new password
                $verify_stmt = $conn->prepare("SELECT password FROM users WHERE email = 'admin@example.com'");
                $verify_stmt->execute();
                $verify_result = $verify_stmt->get_result();
                $verify_admin = $verify_result->fetch_assoc();
                
                if (password_verify('admin123', $verify_admin['password'])) {
                    echo "<div class='success'>✅ Password verification successful!</div>";
                } else {
                    echo "<div class='error'>❌ Password verification failed after update</div>";
                }
                $verify_stmt->close();
            } else {
                echo "<div class='error'>❌ Failed to update password: " . $update_stmt->error . "</div>";
            }
            $update_stmt->close();
        }
    } else {
        echo "<div class='info'>ℹ️ Admin user does not exist. Creating...</div>";
        
        // Create admin user
        $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
        $insert_stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')");
        $name = 'Admin';
        $email = 'admin@example.com';
        $insert_stmt->bind_param("sss", $name, $email, $password_hash);
        
        if ($insert_stmt->execute()) {
            echo "<div class='success'>✅ Admin user created successfully!</div>";
        } else {
            echo "<div class='error'>❌ Failed to create admin user: " . $insert_stmt->error . "</div>";
        }
        $insert_stmt->close();
    }
    
    // Final verification
    echo "<h2>📋 Final Verification</h2>";
    $final_stmt = $conn->prepare("SELECT id, name, email, role FROM users WHERE email = 'admin@example.com'");
    $final_stmt->execute();
    $final_result = $final_stmt->get_result();
    
    if ($final_result->num_rows > 0) {
        $final_admin = $final_result->fetch_assoc();
        echo "<div class='success'>✅ Admin user exists:</div>";
        echo "<pre>";
        echo "ID: " . $final_admin['id'] . "\n";
        echo "Name: " . $final_admin['name'] . "\n";
        echo "Email: " . $final_admin['email'] . "\n";
        echo "Role: " . $final_admin['role'] . "\n";
        echo "</pre>";
        
        // Test password
        $test_stmt = $conn->prepare("SELECT password FROM users WHERE email = 'admin@example.com'");
        $test_stmt->execute();
        $test_result = $test_stmt->get_result();
        $test_admin = $test_result->fetch_assoc();
        
        if (password_verify('admin123', $test_admin['password'])) {
            echo "<div class='success' style='font-size: 18px; font-weight: bold; margin-top: 20px; padding: 20px;'>";
            echo "🎉 <strong>Admin user is ready!</strong><br><br>";
            echo "<strong>Login Credentials:</strong><br>";
            echo "Email: <strong>admin@example.com</strong><br>";
            echo "Password: <strong>admin123</strong><br><br>";
            echo "<a href='auth/login.php' class='btn'>Go to Login Page</a>";
            echo "</div>";
        } else {
            echo "<div class='error'>❌ Password verification failed</div>";
        }
        $test_stmt->close();
    } else {
        echo "<div class='error'>❌ Admin user not found after creation attempt</div>";
    }
    
    $final_stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    echo "<div class='error'>❌ <strong>Error:</strong> " . $e->getMessage() . "</div>";
}

?>

</div>
</body>
</html>


<?php
/**
 * Debug Admin User Script
 * Check admin user status and test login
 */
require_once __DIR__ . '/config/database.php';

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug Admin User</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .success { color: #28a745; background: #d4edda; padding: 15px; border-radius: 4px; margin: 10px 0; }
        .error { color: #dc3545; background: #f8d7da; padding: 15px; border-radius: 4px; margin: 10px 0; }
        .info { color: #0c5460; background: #d1ecf1; padding: 15px; border-radius: 4px; margin: 10px 0; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; border: 1px solid #dee2e6; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; text-align: left; border: 1px solid #dee2e6; }
        th { background: #f8f9fa; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Debug Admin User</h1>
    
<?php

try {
    $conn = getDBConnection();
    
    if (!$conn) {
        throw new Exception("Could not connect to database");
    }
    
    echo "<div class='success'>✅ Connected to database</div>";
    
    // Check if users table exists
    $result = $conn->query("SHOW TABLES LIKE 'users'");
    if ($result->num_rows == 0) {
        echo "<div class='error'>❌ Users table does not exist!</div>";
        exit;
    }
    
    echo "<div class='success'>✅ Users table exists</div>";
    
    // Check all users
    $result = $conn->query("SELECT id, name, email, role, created_at FROM users");
    echo "<h2>All Users in Database:</h2>";
    if ($result->num_rows > 0) {
        echo "<table>";
        echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Created</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . htmlspecialchars($row['name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['email']) . "</td>";
            echo "<td>" . htmlspecialchars($row['role']) . "</td>";
            echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='error'>❌ No users found in database!</div>";
    }
    
    // Check admin user specifically
    echo "<h2>Admin User Check:</h2>";
    $stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
    $email = 'admin@example.com';
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        echo "<div class='error'>❌ Admin user (admin@example.com) NOT FOUND!</div>";
        echo "<div class='info'>Creating admin user now...</div>";
        
        // Create admin user
        $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
        $insert_stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'admin')");
        $name = 'Admin';
        $insert_email = 'admin@example.com';
        $insert_stmt->bind_param("sss", $name, $insert_email, $password_hash);
        
        if ($insert_stmt->execute()) {
            echo "<div class='success'>✅ Admin user created successfully!</div>";
        } else {
            echo "<div class='error'>❌ Failed to create admin user: " . $insert_stmt->error . "</div>";
        }
        $insert_stmt->close();
        
        // Check again
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    if ($result->num_rows > 0) {
        $admin = $result->fetch_assoc();
        echo "<div class='success'>✅ Admin user found!</div>";
        echo "<pre>";
        echo "ID: " . $admin['id'] . "\n";
        echo "Name: " . htmlspecialchars($admin['name']) . "\n";
        echo "Email: " . htmlspecialchars($admin['email']) . "\n";
        echo "Role: " . htmlspecialchars($admin['role']) . "\n";
        echo "Password Hash: " . substr($admin['password'], 0, 30) . "...\n";
        echo "</pre>";
        
        // Test password verification
        echo "<h2>Password Verification Test:</h2>";
        $test_password = 'admin123';
        
        if (password_verify($test_password, $admin['password'])) {
            echo "<div class='success'>✅ Password 'admin123' VERIFIES CORRECTLY!</div>";
        } else {
            echo "<div class='error'>❌ Password 'admin123' DOES NOT VERIFY!</div>";
            echo "<div class='info'>Updating password hash...</div>";
            
            // Update password
            $new_hash = password_hash('admin123', PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE email = 'admin@example.com'");
            $update_stmt->bind_param("s", $new_hash);
            
            if ($update_stmt->execute()) {
                echo "<div class='success'>✅ Password updated!</div>";
                
                // Verify again
                $verify_stmt = $conn->prepare("SELECT password FROM users WHERE email = 'admin@example.com'");
                $verify_stmt->execute();
                $verify_result = $verify_stmt->get_result();
                $verify_admin = $verify_result->fetch_assoc();
                
                if (password_verify('admin123', $verify_admin['password'])) {
                    echo "<div class='success'>✅ Password verification now works!</div>";
                } else {
                    echo "<div class='error'>❌ Password verification still fails!</div>";
                }
                $verify_stmt->close();
            } else {
                echo "<div class='error'>❌ Failed to update password: " . $update_stmt->error . "</div>";
            }
            $update_stmt->close();
        }
        
        // Simulate login process
        echo "<h2>Login Simulation:</h2>";
        $login_email = 'admin@example.com';
        $login_password = 'admin123';
        
        $login_stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
        $login_stmt->bind_param("s", $login_email);
        $login_stmt->execute();
        $login_result = $login_stmt->get_result();
        
        if ($login_result->num_rows === 1) {
            $login_user = $login_result->fetch_assoc();
            echo "<div class='success'>✅ User found with email: " . htmlspecialchars($login_email) . "</div>";
            
            if (password_verify($login_password, $login_user['password'])) {
                echo "<div class='success' style='font-size: 18px; font-weight: bold; margin-top: 20px; padding: 20px;'>";
                echo "🎉 <strong>LOGIN SIMULATION SUCCESSFUL!</strong><br><br>";
                echo "Email: <strong>" . htmlspecialchars($login_email) . "</strong><br>";
                echo "Password: <strong>" . htmlspecialchars($login_password) . "</strong><br><br>";
                echo "<a href='auth/login.php' style='display: inline-block; padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 4px;'>Go to Login Page</a>";
                echo "</div>";
            } else {
                echo "<div class='error'>❌ Password verification failed in login simulation!</div>";
            }
        } else {
            echo "<div class='error'>❌ User not found in login simulation!</div>";
        }
        $login_stmt->close();
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    echo "<div class='error'>❌ <strong>Error:</strong> " . $e->getMessage() . "</div>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

?>

</div>
</body>
</html>


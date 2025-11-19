<?php
/**
 * Diagnostic Script for Render 503 Errors
 * This script helps identify what's causing the 503 error
 * 
 * IMPORTANT: DELETE THIS FILE AFTER DIAGNOSIS FOR SECURITY!
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Render 503 Diagnostic Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #dc3545; border-bottom: 2px solid #dc3545; padding-bottom: 10px; }
        h2 { color: #495057; margin-top: 30px; }
        .success { color: #28a745; background: #d4edda; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #28a745; }
        .error { color: #dc3545; background: #f8d7da; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #dc3545; }
        .warning { color: #856404; background: #fff3cd; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #ffc107; }
        .info { color: #0c5460; background: #d1ecf1; padding: 15px; border-radius: 4px; margin: 10px 0; border-left: 4px solid #17a2b8; }
        code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #dee2e6; }
        th { background: #f8f9fa; font-weight: bold; }
        .status-ok { color: #28a745; font-weight: bold; }
        .status-fail { color: #dc3545; font-weight: bold; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Render 503 Error Diagnostic Tool</h1>
    
    <?php
    $issues = [];
    $warnings = [];
    
    // 1. Check PHP Version
    echo "<h2>1. PHP Environment</h2>";
    echo "<div class='success'>";
    echo "✅ PHP Version: " . PHP_VERSION;
    echo "</div>";
    
    // 2. Check Environment Variables
    echo "<h2>2. Environment Variables</h2>";
    $required_vars = ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_PORT'];
    $optional_vars = ['DB_SSL_REQUIRED'];
    
    echo "<table>";
    echo "<tr><th>Variable</th><th>Status</th><th>Value</th></tr>";
    
    foreach ($required_vars as $var) {
        $value = getenv($var);
        if (empty($value)) {
            echo "<tr><td><code>$var</code></td><td class='status-fail'>❌ MISSING</td><td><em>Not set</em></td></tr>";
            $issues[] = "Missing required environment variable: $var";
        } else {
            $display_value = ($var === 'DB_PASS') ? str_repeat('*', strlen($value)) : $value;
            echo "<tr><td><code>$var</code></td><td class='status-ok'>✅ Set</td><td><code>$display_value</code></td></tr>";
        }
    }
    
    foreach ($optional_vars as $var) {
        $value = getenv($var);
        if (empty($value)) {
            echo "<tr><td><code>$var</code></td><td>⚠️ Not Set</td><td><em>Optional (recommended for Aiven/PlanetScale)</em></td></tr>";
            $warnings[] = "Optional variable $var not set (recommended for cloud MySQL)";
        } else {
            echo "<tr><td><code>$var</code></td><td class='status-ok'>✅ Set</td><td><code>$value</code></td></tr>";
        }
    }
    
    echo "</table>";
    
    // 3. Test Database Connection
    echo "<h2>3. Database Connection Test</h2>";
    
    if (empty(getenv('DB_HOST'))) {
        echo "<div class='error'>❌ Cannot test database connection: DB_HOST is not set</div>";
    } else {
        $host = getenv('DB_HOST');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');
        $name = getenv('DB_NAME');
        $port = getenv('DB_PORT') ?: '3306';
        $ssl_required = getenv('DB_SSL_REQUIRED') === 'true' || strpos($host, 'aivencloud.com') !== false || strpos($host, 'planetscale.com') !== false;
        
        echo "<div class='info'>";
        echo "Attempting to connect to: <code>$host:$port</code><br>";
        echo "Database: <code>$name</code><br>";
        echo "SSL Required: " . ($ssl_required ? 'Yes' : 'No') . "<br>";
        echo "</div>";
        
        if ($ssl_required) {
            $conn = mysqli_init();
            if ($conn) {
                $conn->ssl_set(null, null, null, null, null);
                $connected = @$conn->real_connect($host, $user, $pass, $name, (int)$port, null, MYSQLI_CLIENT_SSL);
                if (!$connected) {
                    $error = $conn->connect_error ?: 'Connection failed';
                    echo "<div class='error'>❌ Database connection failed: $error</div>";
                    $issues[] = "Database connection failed: $error";
                } else {
                    echo "<div class='success'>✅ Database connection successful!</div>";
                    $conn->set_charset("utf8mb4");
                    
                    // Check if tables exist
                    $tables = ['users', 'categories', 'ingredients', 'dishes', 'orders'];
                    $missing_tables = [];
                    foreach ($tables as $table) {
                        $result = $conn->query("SHOW TABLES LIKE '$table'");
                        if (!$result || $result->num_rows == 0) {
                            $missing_tables[] = $table;
                        }
                    }
                    
                    if (!empty($missing_tables)) {
                        echo "<div class='warning'>⚠️ Missing tables: " . implode(', ', $missing_tables) . "</div>";
                        echo "<div class='info'>💡 You may need to import the database schema. Visit: <code>/import_database.php</code></div>";
                        $warnings[] = "Database tables missing - import schema needed";
                    } else {
                        echo "<div class='success'>✅ All required tables exist</div>";
                    }
                    
                    $conn->close();
                }
            } else {
                echo "<div class='error'>❌ Failed to initialize MySQL connection</div>";
                $issues[] = "Failed to initialize MySQL connection";
            }
        } else {
            $conn = @new mysqli($host, $user, $pass, $name, (int)$port);
            if ($conn->connect_error) {
                echo "<div class='error'>❌ Database connection failed: " . $conn->connect_error . "</div>";
                $issues[] = "Database connection failed: " . $conn->connect_error;
            } else {
                echo "<div class='success'>✅ Database connection successful!</div>";
                $conn->set_charset("utf8mb4");
                
                // Check if tables exist
                $tables = ['users', 'categories', 'ingredients', 'dishes', 'orders'];
                $missing_tables = [];
                foreach ($tables as $table) {
                    $result = $conn->query("SHOW TABLES LIKE '$table'");
                    if (!$result || $result->num_rows == 0) {
                        $missing_tables[] = $table;
                    }
                }
                
                if (!empty($missing_tables)) {
                    echo "<div class='warning'>⚠️ Missing tables: " . implode(', ', $missing_tables) . "</div>";
                    echo "<div class='info'>💡 You may need to import the database schema. Visit: <code>/import_database.php</code></div>";
                    $warnings[] = "Database tables missing - import schema needed";
                } else {
                    echo "<div class='success'>✅ All required tables exist</div>";
                }
                
                $conn->close();
            }
        }
    }
    
    // 4. Check File Permissions
    echo "<h2>4. File System</h2>";
    $uploads_dir = __DIR__ . '/uploads';
    if (is_dir($uploads_dir)) {
        if (is_writable($uploads_dir)) {
            echo "<div class='success'>✅ Uploads directory is writable</div>";
        } else {
            echo "<div class='warning'>⚠️ Uploads directory is not writable</div>";
            $warnings[] = "Uploads directory not writable";
        }
    } else {
        echo "<div class='warning'>⚠️ Uploads directory does not exist</div>";
    }
    
    // 5. Check Required Files
    echo "<h2>5. Required Files</h2>";
    $required_files = [
        'config/database.php',
        'config/auth.php',
        'index.php',
        'health.php'
    ];
    
    $missing_files = [];
    foreach ($required_files as $file) {
        if (file_exists(__DIR__ . '/' . $file)) {
            echo "<div class='success'>✅ $file exists</div>";
        } else {
            echo "<div class='error'>❌ $file is missing</div>";
            $missing_files[] = $file;
            $issues[] = "Missing required file: $file";
        }
    }
    
    // 6. Check Apache/PHP Extensions
    echo "<h2>6. PHP Extensions</h2>";
    $required_extensions = ['mysqli', 'pdo', 'pdo_mysql', 'gd', 'zip'];
    $missing_extensions = [];
    
    foreach ($required_extensions as $ext) {
        if (extension_loaded($ext)) {
            echo "<div class='success'>✅ $ext extension loaded</div>";
        } else {
            echo "<div class='error'>❌ $ext extension not loaded</div>";
            $missing_extensions[] = $ext;
            $issues[] = "Missing PHP extension: $ext";
        }
    }
    
    // Summary
    echo "<h2>📋 Summary</h2>";
    
    if (empty($issues)) {
        echo "<div class='success'>";
        echo "<strong>✅ No critical issues found!</strong><br>";
        echo "Your application should be working. If you're still seeing 503 errors:<br>";
        echo "<ul>";
        echo "<li>Wait 30-60 seconds (free tier spin-up time)</li>";
        echo "<li>Check Render logs for runtime errors</li>";
        echo "<li>Verify health check endpoint: <code>/health.php</code></li>";
        echo "</ul>";
        echo "</div>";
    } else {
        echo "<div class='error'>";
        echo "<strong>❌ Found " . count($issues) . " critical issue(s):</strong><br>";
        echo "<ul>";
        foreach ($issues as $issue) {
            echo "<li>$issue</li>";
        }
        echo "</ul>";
        echo "</div>";
    }
    
    if (!empty($warnings)) {
        echo "<div class='warning'>";
        echo "<strong>⚠️ " . count($warnings) . " warning(s):</strong><br>";
        echo "<ul>";
        foreach ($warnings as $warning) {
            echo "<li>$warning</li>";
        }
        echo "</ul>";
        echo "</div>";
    }
    
    // Recommendations
    echo "<h2>💡 Recommendations</h2>";
    echo "<div class='info'>";
    echo "<ol>";
    echo "<li><strong>Check Render Logs:</strong> Go to Render Dashboard → Your Service → Logs tab</li>";
    echo "<li><strong>Verify Environment Variables:</strong> Render Dashboard → Your Service → Environment tab</li>";
    echo "<li><strong>Test Health Endpoint:</strong> Visit <code>https://your-app.onrender.com/health.php</code></li>";
    echo "<li><strong>If database connection fails:</strong> Double-check all DB_* environment variables match your Aiven/PlanetScale credentials exactly</li>";
    echo "<li><strong>If tables are missing:</strong> Visit <code>/import_database.php</code> to import schema</li>";
    echo "<li><strong>Free Tier Note:</strong> Services spin down after 15 min inactivity. First request may take 30-60 seconds.</li>";
    echo "</ol>";
    echo "</div>";
    
    echo "<div class='warning' style='margin-top: 30px;'>";
    echo "<strong>⚠️ SECURITY WARNING:</strong><br>";
    echo "This diagnostic script exposes sensitive information. <strong>DELETE THIS FILE</strong> after diagnosis!";
    echo "</div>";
    ?>
</div>
</body>
</html>


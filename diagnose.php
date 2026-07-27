<?php
/**
 * Diagnose — PDO PostgreSQL environment check
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Diagnose</title>
<style>body{font-family:Arial,sans-serif;margin:40px;background:#f5f5f5}
.container{max-width:800px;margin:0 auto;background:#fff;padding:30px;border-radius:8px}
.ok{color:#155724;background:#d4edda;padding:8px;margin:6px 0;border-radius:4px}
.err{color:#721c24;background:#f8d7da;padding:8px;margin:6px 0;border-radius:4px}
.info{color:#0c5460;background:#d1ecf1;padding:8px;margin:6px 0;border-radius:4px}
</style></head><body><div class='container'><h1>System Diagnose</h1>";

echo "<h2>PHP</h2>";
echo "<div class='info'>PHP " . PHP_VERSION . "</div>";

$required_extensions = ['pdo', 'pdo_pgsql', 'gd', 'zip'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "<div class='ok'>extension {$ext}: loaded</div>";
    } else {
        echo "<div class='err'>extension {$ext}: MISSING</div>";
    }
}

echo "<h2>Database config</h2>";
echo "<div class='info'>DB_HOST=" . htmlspecialchars(DB_HOST) . "</div>";
echo "<div class='info'>DB_NAME=" . htmlspecialchars(DB_NAME) . "</div>";
echo "<div class='info'>DB_PORT=" . DB_PORT . "</div>";
echo "<div class='info'>DB_SSLMODE=" . htmlspecialchars(DB_SSLMODE) . "</div>";
echo "<div class='info'>DATABASE_URL set: " . (getenv('DATABASE_URL') ? 'yes' : 'no') . "</div>";

echo "<h2>Connection</h2>";
$conn = getDBConnection();
if ($conn === false) {
    echo "<div class='err'>getDBConnection() failed</div>";
} else {
    echo "<div class='ok'>Connected via PDO PostgreSQL</div>";
    try {
        ensureDatabaseSetup($conn);
        echo "<div class='ok'>ensureDatabaseSetup() completed</div>";
    } catch (Throwable $e) {
        echo "<div class='err'>Setup: " . htmlspecialchars($e->getMessage()) . "</div>";
    }

    $tables = ['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients', 'orders'];
    foreach ($tables as $table) {
        if (db_table_exists($conn, $table)) {
            echo "<div class='ok'>table {$table}: exists</div>";
        } else {
            echo "<div class='err'>table {$table}: missing</div>";
        }
    }
}

echo "</div></body></html>";

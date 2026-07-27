<?php
/**
 * Database Setup / Diagnostics
 * Uses PDO PostgreSQL helpers from config/database.php
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Database Setup</title>
<style>body{font-family:Arial,sans-serif;margin:40px;background:#f5f5f5}
.container{max-width:700px;margin:0 auto;background:#fff;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,.1)}
.ok{color:#155724;background:#d4edda;padding:10px;border-radius:4px;margin:8px 0}
.err{color:#721c24;background:#f8d7da;padding:10px;border-radius:4px;margin:8px 0}
.info{color:#0c5460;background:#d1ecf1;padding:10px;border-radius:4px;margin:8px 0}
</style></head><body><div class='container'><h1>Database Setup</h1>";

$conn = getDBConnection();
if ($conn === false) {
    echo "<div class='err'>Connection failed. Check DATABASE_URL or DB_* environment variables.</div>";
    echo "</div></body></html>";
    exit;
}

echo "<div class='ok'>Connected to PostgreSQL (" . htmlspecialchars(DB_HOST) . " / " . htmlspecialchars(DB_NAME) . ")</div>";

try {
    ensureDatabaseSetup($conn);
    echo "<div class='ok'>Schema ensured via ensureDatabaseSetup()</div>";
} catch (Throwable $e) {
    echo "<div class='err'>Setup error: " . htmlspecialchars($e->getMessage()) . "</div>";
}

$tables = ['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients', 'orders'];
echo "<h2>Tables</h2>";
foreach ($tables as $table) {
    $exists = db_table_exists($conn, $table);
    $cls = $exists ? 'ok' : 'err';
    $msg = $exists ? "exists" : "missing";
    echo "<div class='{$cls}'>{$table}: {$msg}</div>";
}

$admin = db_fetch($conn, "SELECT id, name, email FROM users WHERE email = 'admin@example.com'");
if ($admin) {
    echo "<div class='ok'>Admin user present: " . htmlspecialchars($admin['email']) . "</div>";
} else {
    echo "<div class='err'>Admin user missing</div>";
}

echo "<div class='info'>Done. You can delete or restrict this script in production.</div>";
echo "</div></body></html>";

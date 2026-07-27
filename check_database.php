<?php
/**
 * Check Database — PDO PostgreSQL diagnostics
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Check Database</title>
<style>body{font-family:Arial,sans-serif;margin:40px;background:#f5f5f5}
.container{max-width:700px;margin:0 auto;background:#fff;padding:30px;border-radius:8px}
.ok{color:#155724;background:#d4edda;padding:8px;margin:6px 0;border-radius:4px}
.err{color:#721c24;background:#f8d7da;padding:8px;margin:6px 0;border-radius:4px}
.info{color:#0c5460;background:#d1ecf1;padding:8px;margin:6px 0;border-radius:4px}
</style></head><body><div class='container'><h1>Database Check</h1>";

echo "<div class='info'>Host: " . htmlspecialchars(DB_HOST) . " | DB: " . htmlspecialchars(DB_NAME) . " | Port: " . DB_PORT . "</div>";

$conn = getDBConnection();
if ($conn === false) {
    echo "<div class='err'>Could not connect.</div></div></body></html>";
    exit;
}

echo "<div class='ok'>Connection successful (PDO PostgreSQL)</div>";

$tables = ['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients', 'orders'];
echo "<h2>Tables</h2>";
foreach ($tables as $table) {
    if (db_table_exists($conn, $table)) {
        $row = db_fetch($conn, "SELECT COUNT(*) AS c FROM {$table}");
        echo "<div class='ok'>{$table}: exists (" . (int)($row['c'] ?? 0) . " rows)</div>";
    } else {
        echo "<div class='err'>{$table}: missing</div>";
    }
}

if (db_table_exists($conn, 'users')) {
    echo "<h2>Users columns</h2>";
    $cols = db_fetch_all(
        $conn,
        "SELECT column_name, data_type FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'users' ORDER BY ordinal_position"
    );
    foreach ($cols as $col) {
        echo "<div class='info'>" . htmlspecialchars($col['column_name']) . " (" . htmlspecialchars($col['data_type']) . ")</div>";
    }

    $admin = db_fetch($conn, "SELECT id, name, email, role FROM users WHERE email = 'admin@example.com'");
    if ($admin) {
        echo "<div class='ok'>Admin found: " . htmlspecialchars($admin['email']) . " (" . htmlspecialchars($admin['role']) . ")</div>";
    } else {
        echo "<div class='err'>Admin user not found</div>";
        ensureAdminUser($conn);
        echo "<div class='info'>Attempted ensureAdminUser()</div>";
    }
}

echo "</div></body></html>";

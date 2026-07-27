<?php
/**
 * Optimize Database — PostgreSQL ANALYZE / VACUUM
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Optimize Database</title>
<style>body{font-family:Arial,sans-serif;margin:40px}.ok{color:green}.err{color:red}.info{color:#333}</style>
</head><body><h1>Optimize Database</h1>";

$conn = getDBConnection();
if ($conn === false) {
    echo "<p class='err'>Database connection failed.</p></body></html>";
    exit;
}

$tables = ['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients', 'orders'];

foreach ($tables as $table) {
    if (!db_table_exists($conn, $table)) {
        echo "<p class='err'>{$table}: missing (skipping)</p>";
        continue;
    }
    try {
        // ANALYZE updates planner statistics (safe on managed Postgres)
        $conn->exec("ANALYZE {$table}");
        echo "<p class='ok'>ANALYZE {$table}</p>";
    } catch (Throwable $e) {
        echo "<p class='err'>{$table}: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<p class='info'>Done. VACUUM FULL is intentionally not run (locks tables; use manually if needed).</p>";
echo "</body></html>";

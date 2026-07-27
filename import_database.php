<?php
/**
 * Import Database — runs ensureDatabaseSetup for PostgreSQL
 * (SQL dump import from MySQL is not supported; use schema helpers instead.)
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Import Database</title>
<style>body{font-family:Arial,sans-serif;margin:40px}.ok{color:green}.err{color:red}.info{color:#333;background:#eef;padding:10px;border-radius:4px}</style>
</head><body><h1>Import / Setup Database</h1>";

$conn = getDBConnection();
if ($conn === false) {
    echo "<p class='err'>Database connection failed.</p></body></html>";
    exit;
}

echo "<p class='info'>PostgreSQL mode: schema is applied via ensureDatabaseSetup() (not MySQL dump import).</p>";

try {
    ensureDatabaseSetup($conn);
    ensureAdminUser($conn);
    echo "<p class='ok'>Schema and admin user ensured.</p>";

    $tables = ['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients', 'orders'];
    echo "<ul>";
    foreach ($tables as $t) {
        $exists = db_table_exists($conn, $t);
        $count = 0;
        if ($exists) {
            $row = db_fetch($conn, "SELECT COUNT(*) AS c FROM {$t}");
            $count = (int) ($row['c'] ?? 0);
        }
        echo "<li>{$t}: " . ($exists ? "OK ({$count} rows)" : "MISSING") . "</li>";
    }
    echo "</ul>";
} catch (Throwable $e) {
    echo "<p class='err'>" . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";

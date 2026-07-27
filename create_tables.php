<?php
/**
 * Create Tables — delegates to ensureDatabaseSetup()
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Create Tables</title>
<style>body{font-family:Arial,sans-serif;margin:40px}.ok{color:green}.err{color:red}</style>
</head><body><h1>Create Tables</h1>";

$conn = getDBConnection();
if ($conn === false) {
    echo "<p class='err'>Database connection failed.</p></body></html>";
    exit;
}

try {
    ensureDatabaseSetup($conn);
    echo "<p class='ok'>Database schema created/verified successfully.</p>";

    $tables = ['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients', 'orders'];
    echo "<ul>";
    foreach ($tables as $t) {
        $status = db_table_exists($conn, $t) ? 'OK' : 'MISSING';
        echo "<li>{$t}: {$status}</li>";
    }
    echo "</ul>";
} catch (Throwable $e) {
    echo "<p class='err'>" . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";

<?php
/**
 * Debug Admin — PDO PostgreSQL
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Debug Admin</title>
<style>body{font-family:monospace;margin:40px}pre{background:#f4f4f4;padding:12px;border-radius:4px}.ok{color:green}.err{color:red}</style>
</head><body><h1>Debug Admin</h1>";

$conn = getDBConnection();
if ($conn === false) {
    echo "<p class='err'>Database connection failed.</p></body></html>";
    exit;
}

try {
    if (!db_table_exists($conn, 'users')) {
        echo "<p class='err'>users table missing — running ensureDatabaseSetup()</p>";
        ensureDatabaseSetup($conn);
    }

    echo "<h2>All users</h2><pre>";
    $users = db_fetch_all($conn, 'SELECT id, name, email, role, created_at FROM users ORDER BY id');
    if (empty($users)) {
        echo "(none)\n";
    } else {
        foreach ($users as $row) {
            echo htmlspecialchars(json_encode($row)) . "\n";
        }
    }
    echo "</pre>";

    $email = 'admin@example.com';
    $password = 'admin123';
    $admin = db_fetch($conn, 'SELECT id, name, email, role, password FROM users WHERE email = ?', [$email]);

    if ($admin === null) {
        echo "<p class='err'>Admin not found — inserting via ensureAdminUser()</p>";
        ensureAdminUser($conn);
        $admin = db_fetch($conn, 'SELECT id, name, email, role, password FROM users WHERE email = ?', [$email]);
    }

    if ($admin) {
        echo "<p class='ok'>Admin: " . htmlspecialchars($admin['email']) . " role=" . htmlspecialchars($admin['role']) . "</p>";
        if (!password_verify($password, $admin['password'])) {
            db_exec(
                $conn,
                "UPDATE users SET password = ? WHERE email = ?",
                [password_hash($password, PASSWORD_DEFAULT), $email]
            );
            echo "<p class='ok'>Password hash updated.</p>";
            $admin = db_fetch($conn, 'SELECT id, name, email, role, password FROM users WHERE email = ?', [$email]);
        }

        if (password_verify($password, $admin['password'])) {
            echo "<p class='ok'>password_verify(admin123) = true</p>";
        } else {
            echo "<p class='err'>password_verify(admin123) = false</p>";
        }
    } else {
        echo "<p class='err'>Could not create/find admin.</p>";
    }
} catch (Throwable $e) {
    echo "<p class='err'>" . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";

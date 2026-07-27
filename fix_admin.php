<?php
/**
 * Fix Admin User — PDO PostgreSQL
 */
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Fix Admin</title>
<style>body{font-family:Arial,sans-serif;margin:40px}.ok{color:green}.err{color:red}</style>
</head><body><h1>Fix Admin User</h1>";

$conn = getDBConnection();
if ($conn === false) {
    echo "<p class='err'>Database connection failed.</p></body></html>";
    exit;
}

$email = 'admin@example.com';
$password = 'admin123';

try {
    ensureDatabaseSetup($conn);
    $ok = ensureAdminUser($conn);

    $admin = db_fetch($conn, "SELECT id, name, email, role, password FROM users WHERE email = ?", [$email]);
    if ($admin === null) {
        echo "<p class='err'>Admin user still missing after ensureAdminUser().</p>";
    } else {
        echo "<p class='ok'>Admin user: " . htmlspecialchars($admin['email']) . " (id=" . (int)$admin['id'] . ", role=" . htmlspecialchars($admin['role']) . ")</p>";
        if (password_verify($password, $admin['password'])) {
            echo "<p class='ok'>Password verifies for admin123.</p>";
        } else {
            db_exec(
                $conn,
                "UPDATE users SET password = ? WHERE email = ? AND role = 'admin'",
                [password_hash($password, PASSWORD_DEFAULT), $email]
            );
            echo "<p class='ok'>Password hash reset to admin123.</p>";
        }
    }

    // Login simulation
    $login = db_fetch($conn, 'SELECT id, name, email, password, role FROM users WHERE email = ?', [$email]);
    if ($login && password_verify($password, $login['password'])) {
        echo "<p class='ok'>Login test passed.</p>";
    } else {
        echo "<p class='err'>Login test failed.</p>";
    }
} catch (Throwable $e) {
    echo "<p class='err'>" . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</body></html>";

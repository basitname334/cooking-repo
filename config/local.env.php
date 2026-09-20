<?php
/**
 * Local XAMPP overrides — not used on Render/production.
 * Points Apache/PHP at the local Docker Postgres (port 5436).
 */
if (getenv('DATABASE_URL') || getenv('RENDER')) {
    return;
}

putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=5436');
putenv('DB_USER=postgres');
putenv('DB_PASS=postgres');
putenv('DB_NAME=food_management_system');
putenv('DB_SSLMODE=disable');
$_ENV['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = '5436';
$_ENV['DB_USER'] = 'postgres';
$_ENV['DB_PASS'] = 'postgres';
$_ENV['DB_NAME'] = 'food_management_system';
$_ENV['DB_SSLMODE'] = 'disable';

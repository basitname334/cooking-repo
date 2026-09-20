<?php
/**
 * One-shot local fix: complete MySQL schema, sync MySQL→Postgres, add dish images.
 * Run: C:\xampp\php\php.exe tools\local_fix_all.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$mysqlHost = '127.0.0.1';
$mysqlUser = 'root';
$mysqlPass = '';
$mysqlDb = 'food_management_system';

echo "=== 1) Complete MySQL schema (phpMyAdmin) ===\n";
$mysqli = @new mysqli($mysqlHost, $mysqlUser, $mysqlPass, $mysqlDb);
if ($mysqli->connect_errno) {
    fwrite(STDERR, "MySQL connect failed: {$mysqli->connect_error}\n");
    exit(1);
}
$mysqli->set_charset('utf8mb4');

function mysql_has_column(mysqli $db, string $table, string $column): bool {
    $t = $db->real_escape_string($table);
    $c = $db->real_escape_string($column);
    $r = $db->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return $r && $r->num_rows > 0;
}

function mysql_exec(mysqli $db, string $sql): void {
    if (!$db->query($sql)) {
        echo "  WARN: {$db->error} | SQL: {$sql}\n";
    } else {
        echo "  OK: {$sql}\n";
    }
}

$mysqlAlters = [
    'dishes' => [
        'image' => "ALTER TABLE `dishes` ADD COLUMN `image` TEXT NULL",
    ],
    'orders' => [
        'unit' => "ALTER TABLE `orders` ADD COLUMN `unit` VARCHAR(50) NULL",
        'extra_ingredients' => "ALTER TABLE `orders` ADD COLUMN `extra_ingredients` TEXT NULL",
        'customer_name' => "ALTER TABLE `orders` ADD COLUMN `customer_name` VARCHAR(100) NULL",
        'customer_cell' => "ALTER TABLE `orders` ADD COLUMN `customer_cell` VARCHAR(20) NULL",
        'delivery_date' => "ALTER TABLE `orders` ADD COLUMN `delivery_date` DATE NULL",
        'delivery_time' => "ALTER TABLE `orders` ADD COLUMN `delivery_time` TIME NULL",
        'shift' => "ALTER TABLE `orders` ADD COLUMN `shift` VARCHAR(20) NULL",
        'number_of_persons' => "ALTER TABLE `orders` ADD COLUMN `number_of_persons` INT NULL",
        'advance_amount' => "ALTER TABLE `orders` ADD COLUMN `advance_amount` DECIMAL(10,2) DEFAULT 0",
        'cloth_malmal_quantity' => "ALTER TABLE `orders` ADD COLUMN `cloth_malmal_quantity` INT DEFAULT 0",
        'match_box_quantity' => "ALTER TABLE `orders` ADD COLUMN `match_box_quantity` INT DEFAULT 0",
        'surrf_quantity' => "ALTER TABLE `orders` ADD COLUMN `surrf_quantity` INT DEFAULT 0",
        'sponjis_quantity' => "ALTER TABLE `orders` ADD COLUMN `sponjis_quantity` INT DEFAULT 0",
        'wood_quantity' => "ALTER TABLE `orders` ADD COLUMN `wood_quantity` INT DEFAULT 0",
    ],
    'dish_ingredients' => [
        'unit' => "ALTER TABLE `dish_ingredients` ADD COLUMN `unit` VARCHAR(50) NULL",
    ],
];

foreach ($mysqlAlters as $table => $cols) {
    foreach ($cols as $col => $sql) {
        if (!mysql_has_column($mysqli, $table, $col)) {
            mysql_exec($mysqli, $sql);
        } else {
            echo "  skip {$table}.{$col}\n";
        }
    }
}

// Fix schema DB: ensure orders table exists (from schema.sql)
echo "=== 1b) schema DB missing orders? ===\n";
$mysqli->select_db('schema');
$hasOrders = $mysqli->query("SHOW TABLES LIKE 'orders'");
if (!$hasOrders || $hasOrders->num_rows === 0) {
    mysql_exec($mysqli, "CREATE TABLE IF NOT EXISTS `orders` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `customer_id` INT NOT NULL,
      `dish_id` INT NOT NULL,
      `quantity` DECIMAL(10,2) NOT NULL,
      `total_amount` DECIMAL(10,2) NOT NULL,
      `order_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `status` ENUM('pending','confirmed','preparing','ready','delivered','cancelled') DEFAULT 'pending',
      `notes` TEXT,
      KEY (`customer_id`), KEY (`dish_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} else {
    echo "  schema.orders exists\n";
}
$mysqli->select_db($mysqlDb);

echo "=== 2) Generate dish placeholder images ===\n";
$imgDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'dishes';
if (!is_dir($imgDir)) {
    mkdir($imgDir, 0777, true);
}

function make_placeholder(string $path, string $label, array $rgb): void {
    if (!function_exists('imagecreatetruecolor')) {
        // Minimal 1x1 jpeg fallback
        file_put_contents($path, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBEQEDEAAAANP/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAEFAl//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/AX//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/AX//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAY/Al//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/IX//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Qf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Qf//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAT8hf//Z'));
        return;
    }
    $w = 640;
    $h = 420;
    $im = imagecreatetruecolor($w, $h);
    $bg = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    $white = imagecolorallocate($im, 255, 255, 255);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);
    $text = mb_substr($label, 0, 40);
    imagestring($im, 5, 40, (int) ($h / 2 - 8), $text, $white);
    imagejpeg($im, $path, 85);
    imagedestroy($im);
}

$colors = [
    [102, 126, 234],
    [118, 75, 162],
    [16, 185, 129],
    [245, 158, 11],
    [239, 68, 68],
];

$dishRows = [];
$res = $mysqli->query('SELECT id, name FROM dishes ORDER BY id');
while ($row = $res->fetch_assoc()) {
    $dishRows[] = $row;
}

foreach ($dishRows as $i => $dish) {
    $file = 'dish_' . (int) $dish['id'] . '.jpg';
    $full = $imgDir . DIRECTORY_SEPARATOR . $file;
    $rel = 'uploads/dishes/' . $file;
    make_placeholder($full, (string) $dish['name'], $colors[$i % count($colors)]);
    $id = (int) $dish['id'];
    $relEsc = $mysqli->real_escape_string($rel);
    $mysqli->query("UPDATE dishes SET image = '{$relEsc}' WHERE id = {$id}");
    echo "  dish #{$id} -> {$rel}\n";
}

echo "=== 3) Sync MySQL → Postgres (app DB) ===\n";
$pg = new PDO('pgsql:host=127.0.0.1;port=5436;dbname=food_management_system', 'postgres', 'postgres', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pg->exec('SET session_replication_role = replica'); // disable FK checks while loading
$tables = ['orders', 'dish_ingredients', 'dishes', 'ingredients', 'categories', 'users'];
foreach ($tables as $t) {
    $pg->exec("TRUNCATE TABLE {$t} RESTART IDENTITY CASCADE");
}

function fetch_all_mysql(mysqli $db, string $sql): array {
    $out = [];
    $r = $db->query($sql);
    if (!$r) {
        return $out;
    }
    while ($row = $r->fetch_assoc()) {
        $out[] = $row;
    }
    return $out;
}

$users = fetch_all_mysql($mysqli, 'SELECT * FROM users ORDER BY id');
$st = $pg->prepare('INSERT INTO users (id, name, email, password, role, created_at) VALUES (?,?,?,?,?,?)');
foreach ($users as $u) {
    $st->execute([$u['id'], $u['name'], $u['email'], $u['password'], $u['role'], $u['created_at'] ?: date('Y-m-d H:i:s')]);
}
echo '  users: ' . count($users) . "\n";

$cats = fetch_all_mysql($mysqli, 'SELECT * FROM categories ORDER BY id');
$st = $pg->prepare('INSERT INTO categories (id, name, description, created_at) VALUES (?,?,?,?)');
foreach ($cats as $c) {
    $st->execute([$c['id'], $c['name'], $c['description'], $c['created_at'] ?: date('Y-m-d H:i:s')]);
}
echo '  categories: ' . count($cats) . "\n";

$ings = fetch_all_mysql($mysqli, 'SELECT * FROM ingredients ORDER BY id');
$st = $pg->prepare('INSERT INTO ingredients (id, name, category_id, unit, created_at) VALUES (?,?,?,?,?)');
foreach ($ings as $ing) {
    $st->execute([$ing['id'], $ing['name'], $ing['category_id'], $ing['unit'] ?: '', $ing['created_at'] ?: date('Y-m-d H:i:s')]);
}
echo '  ingredients: ' . count($ings) . "\n";

$dishes = fetch_all_mysql($mysqli, 'SELECT * FROM dishes ORDER BY id');
$st = $pg->prepare('INSERT INTO dishes (id, name, description, category_id, number_of_persons, base_quantity, base_unit, image, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
foreach ($dishes as $d) {
    $st->execute([
        $d['id'], $d['name'], $d['description'], $d['category_id'],
        $d['number_of_persons'] ?? 1, $d['base_quantity'] ?? 1, $d['base_unit'] ?? 'serving',
        $d['image'] ?? null, $d['created_at'] ?: date('Y-m-d H:i:s'),
    ]);
}
echo '  dishes: ' . count($dishes) . "\n";

$di = fetch_all_mysql($mysqli, 'SELECT * FROM dish_ingredients ORDER BY id');
$st = $pg->prepare('INSERT INTO dish_ingredients (id, dish_id, ingredient_id, quantity, unit, created_at) VALUES (?,?,?,?,?,?)');
foreach ($di as $row) {
    $st->execute([
        $row['id'], $row['dish_id'], $row['ingredient_id'], $row['quantity'],
        $row['unit'] ?? null, $row['created_at'] ?: date('Y-m-d H:i:s'),
    ]);
}
echo '  dish_ingredients: ' . count($di) . "\n";

// Ensure extra order columns on Postgres
$extraCols = [
    'cloth_malmal_quantity' => 'INT DEFAULT 0',
    'match_box_quantity' => 'INT DEFAULT 0',
    'surrf_quantity' => 'INT DEFAULT 0',
    'sponjis_quantity' => 'INT DEFAULT 0',
    'wood_quantity' => 'INT DEFAULT 0',
];
foreach ($extraCols as $col => $def) {
    $pg->exec("ALTER TABLE orders ADD COLUMN IF NOT EXISTS {$col} {$def}");
}

$orders = fetch_all_mysql($mysqli, 'SELECT * FROM orders ORDER BY id');
$st = $pg->prepare('INSERT INTO orders (id, order_number, customer_id, dish_id, quantity, unit, total_amount, order_date, status, notes, extra_ingredients, customer_name, customer_cell, delivery_date, delivery_time, shift, number_of_persons, advance_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ($orders as $o) {
    $st->execute([
        $o['id'],
        $o['order_number'] ?? null,
        $o['customer_id'] ?: null,
        $o['dish_id'],
        $o['quantity'],
        $o['unit'] ?? null,
        $o['total_amount'],
        $o['order_date'] ?: date('Y-m-d H:i:s'),
        $o['status'] ?: 'pending',
        $o['notes'] ?? null,
        $o['extra_ingredients'] ?? null,
        $o['customer_name'] ?? null,
        $o['customer_cell'] ?? null,
        $o['delivery_date'] ?? null,
        $o['delivery_time'] ?? null,
        $o['shift'] ?? null,
        $o['number_of_persons'] ?? null,
        $o['advance_amount'] ?? 0,
    ]);
}
echo '  orders: ' . count($orders) . "\n";

// Fix sequences
foreach (['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients', 'orders'] as $t) {
    $pg->exec("SELECT setval(pg_get_serial_sequence('{$t}', 'id'), COALESCE((SELECT MAX(id) FROM {$t}), 1))");
}
$pg->exec('SET session_replication_role = DEFAULT');

echo "=== 4) Test order insert ===\n";
$pg->beginTransaction();
$pg->prepare("INSERT INTO orders (order_number, customer_id, dish_id, quantity, unit, total_amount, status, customer_name, customer_cell, order_date, delivery_date, delivery_time, shift, number_of_persons, notes, advance_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        'ORD-TEST-LOCAL', null, (int) $dishes[0]['id'], 1, 'kg', 1500, 'pending',
        'Test Customer', '03001234567', date('Y-m-d H:i:s'), date('Y-m-d', strtotime('+1 day')),
        '18:00:00', 'evening', 50, 'local fix test', 500,
    ]);
$pg->rollBack();
echo "  order insert OK (rolled back)\n";

echo "=== DONE ===\n";

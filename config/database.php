<?php
/**
 * Database Configuration — PostgreSQL via PDO
 * Supports DATABASE_URL (Render) or DB_HOST/DB_USER/DB_PASS/DB_NAME/DB_PORT.
 */

// -----------------------------------------------------------------------------
// Resolve connection settings from env
// -----------------------------------------------------------------------------
$dbConfig = db_resolve_config();
define('DB_HOST', $dbConfig['host']);
define('DB_USER', $dbConfig['user']);
define('DB_PASS', $dbConfig['pass']);
define('DB_NAME', $dbConfig['name']);
define('DB_PORT', (int) $dbConfig['port']);
define('DB_SSLMODE', $dbConfig['sslmode']);

define('DB_CONNECT_RETRIES', max(1, (int) (getenv('DB_CONNECT_RETRIES') ?: 2)));
define('DB_CONNECT_RETRY_DELAY_MS', max(0, (int) (getenv('DB_CONNECT_RETRY_DELAY_MS') ?: 200)));
define('DB_SCHEMA_MARKER_VERSION', 'v4');

function db_schema_marker_path(): string {
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cooking_schema_' . DB_SCHEMA_MARKER_VERSION . '.ok';
}

function db_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    $row = db_fetch(
        $pdo,
        "SELECT 1 AS ok FROM information_schema.tables
         WHERE table_schema = 'public' AND table_name = ?",
        [$table]
    );
    return $cache[$table] = ($row !== null);
}

function db_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $row = db_fetch(
        $pdo,
        "SELECT 1 AS ok FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = ? AND column_name = ?",
        [$table, $column]
    );
    return $cache[$key] = ($row !== null);
}

/**
 * Parse DATABASE_URL or discrete DB_* env vars.
 */
function db_resolve_config(): array {
    $url = getenv('DATABASE_URL') ?: '';
    if ($url !== '') {
        $parts = parse_url($url);
        if ($parts && !empty($parts['host'])) {
            $sslmode = 'require';
            if (!empty($parts['query'])) {
                parse_str($parts['query'], $q);
                if (!empty($q['sslmode'])) {
                    $sslmode = $q['sslmode'];
                }
            }
            return [
                'host' => $parts['host'],
                'port' => $parts['port'] ?? 5432,
                'user' => isset($parts['user']) ? urldecode($parts['user']) : '',
                'pass' => isset($parts['pass']) ? urldecode($parts['pass']) : '',
                'name' => isset($parts['path']) ? ltrim($parts['path'], '/') : 'postgres',
                'sslmode' => $sslmode,
            ];
        }
    }

    $host = getenv('DB_HOST') ?: 'localhost';
    $sslRequired = getenv('DB_SSL_REQUIRED') === 'true'
        || str_contains($host, 'render.com')
        || str_contains($host, 'aivencloud.com');

    return [
        'host' => $host,
        'port' => (int) (getenv('DB_PORT') ?: 5432),
        'user' => getenv('DB_USER') ?: 'postgres',
        'pass' => getenv('DB_PASS') ?: '',
        'name' => getenv('DB_NAME') ?: 'food_management_system',
        'sslmode' => $sslRequired ? 'require' : (getenv('DB_SSLMODE') ?: 'prefer'),
    ];
}

function db_ssl_required(): bool {
    return DB_SSLMODE === 'require' || DB_SSLMODE === 'verify-full';
}

function db_is_production(): bool {
    $h = DB_HOST;
    return $h !== 'localhost' && $h !== '127.0.0.1';
}

// -----------------------------------------------------------------------------
// PDO helpers
// -----------------------------------------------------------------------------

/**
 * @return PDOStatement
 */
function db_exec(PDO $pdo, string $sql, array $params = []): PDOStatement {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_fetch(PDO $pdo, string $sql, array $params = []): ?array {
    $row = db_exec($pdo, $sql, $params)->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function db_fetch_all(PDO $pdo, string $sql, array $params = []): array {
    return db_exec($pdo, $sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Insert and return new id. Prefer SQL with RETURNING id.
 */
function db_insert(PDO $pdo, string $sql, array $params = []): int {
    if (!preg_match('/\bRETURNING\b/i', $sql)) {
        db_exec($pdo, $sql, $params);
        return (int) $pdo->lastInsertId();
    }
    $row = db_fetch($pdo, $sql, $params);
    return (int) ($row['id'] ?? 0);
}

function db_last_id(PDO $pdo, string $sequenceOrTable = ''): int {
    if ($sequenceOrTable !== '') {
        return (int) $pdo->lastInsertId($sequenceOrTable);
    }
    return (int) $pdo->lastInsertId();
}

// -----------------------------------------------------------------------------
// Connection
// -----------------------------------------------------------------------------

/**
 * @return PDO|false
 */
function getDBConnection() {
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $lastError = null;
    $attempts = DB_CONNECT_RETRIES;

    while ($attempts-- > 0) {
        try {
            $conn = db_connect_once();
            if ($conn instanceof PDO) {
                $connection = $conn;
                db_ensure_schema_once($conn);
                return $conn;
            }
            $lastError = 'Connection failed';
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            error_log('DB connection error: ' . $lastError);
        }

        if ($attempts > 0 && DB_CONNECT_RETRY_DELAY_MS > 0) {
            usleep(DB_CONNECT_RETRY_DELAY_MS * 1000);
        }
    }

    error_log('DB connection failed after retries: ' . ($lastError ?? 'Unknown'));
    return false;
}

/**
 * @return PDO|false
 */
function db_connect_once() {
    $dsn = sprintf(
        'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
        DB_HOST,
        DB_PORT,
        DB_NAME,
        DB_SSLMODE
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function db_ensure_schema_once(?PDO $existingConn = null): void {
    static $done = false;
    if ($done) {
        return;
    }

    $conn = $existingConn;
    $weOpened = false;

    if (!$conn instanceof PDO) {
        try {
            $conn = db_connect_once();
            $weOpened = true;
        } catch (Throwable $e) {
            error_log('db_ensure_schema_once connect failed: ' . $e->getMessage());
            return;
        }
    }

    try {
        ensureDatabaseSetup($conn);
    } catch (Throwable $e) {
        error_log('ensureDatabaseSetup failed: ' . $e->getMessage());
    } finally {
        if ($weOpened) {
            $conn = null;
        }
    }

    $done = true;
}

function ensureDatabaseSetup(PDO $conn): void {
    static $setup_done = false;
    if ($setup_done) {
        return;
    }

    // Skip full migration/seed on warm instances (big win on Render)
    $marker = db_schema_marker_path();
    if (is_file($marker) && (time() - (int) @filemtime($marker)) < 86400) {
        $setup_done = true;
        return;
    }

    $sqls = [
        'users' => "CREATE TABLE IF NOT EXISTS users (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        'categories' => "CREATE TABLE IF NOT EXISTS categories (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        'ingredients' => "CREATE TABLE IF NOT EXISTS ingredients (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            category_id INT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
            unit VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        'dishes' => "CREATE TABLE IF NOT EXISTS dishes (
            id SERIAL PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            category_id INT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
            number_of_persons INT DEFAULT 1,
            base_quantity DECIMAL(10,2) DEFAULT 1.00,
            base_unit VARCHAR(50) DEFAULT 'serving',
            image TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",

        'dish_ingredients' => "CREATE TABLE IF NOT EXISTS dish_ingredients (
            id SERIAL PRIMARY KEY,
            dish_id INT NOT NULL REFERENCES dishes(id) ON DELETE CASCADE,
            ingredient_id INT NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
            quantity DECIMAL(10, 2) NOT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (dish_id, ingredient_id)
        )",

        'orders' => "CREATE TABLE IF NOT EXISTS orders (
            id SERIAL PRIMARY KEY,
            order_number VARCHAR(50) DEFAULT NULL,
            customer_id INT DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
            dish_id INT NOT NULL REFERENCES dishes(id) ON DELETE CASCADE,
            quantity DECIMAL(10, 2) NOT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            total_amount DECIMAL(10, 2) NOT NULL,
            order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) DEFAULT 'pending'
                CHECK (status IN ('pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled')),
            notes TEXT,
            extra_ingredients TEXT,
            customer_name VARCHAR(100) DEFAULT NULL,
            customer_cell VARCHAR(20) DEFAULT NULL,
            delivery_date DATE DEFAULT NULL,
            delivery_time TIME DEFAULT NULL,
            shift VARCHAR(20) DEFAULT NULL,
            number_of_persons INT DEFAULT NULL,
            advance_amount DECIMAL(10, 2) DEFAULT 0
        )",
    ];

    foreach ($sqls as $name => $sql) {
        if (db_table_exists($conn, $name)) {
            continue;
        }
        try {
            $conn->exec($sql);
        } catch (Throwable $e) {
            error_log("Database: create table {$name} failed: " . $e->getMessage());
        }
    }

    db_run_column_migrations($conn);
    ensureAdminUser($conn);
    ensureSeedData($conn);
    @file_put_contents(db_schema_marker_path(), (string) time());
    $setup_done = true;
}

function db_run_column_migrations(PDO $conn): void {
    $migrations = [
        ['dish_ingredients', 'unit', 'ALTER TABLE dish_ingredients ADD COLUMN unit VARCHAR(50) DEFAULT NULL'],
        ['dishes', 'number_of_persons', 'ALTER TABLE dishes ADD COLUMN number_of_persons INT DEFAULT 1'],
        ['dishes', 'base_quantity', 'ALTER TABLE dishes ADD COLUMN base_quantity DECIMAL(10,2) DEFAULT 1.00'],
        ['dishes', 'base_unit', 'ALTER TABLE dishes ADD COLUMN base_unit VARCHAR(50) DEFAULT \'serving\''],
        ['dishes', 'image', 'ALTER TABLE dishes ADD COLUMN image TEXT DEFAULT NULL'],
        ['orders', 'order_number', 'ALTER TABLE orders ADD COLUMN order_number VARCHAR(50) DEFAULT NULL'],
        ['orders', 'unit', 'ALTER TABLE orders ADD COLUMN unit VARCHAR(50) DEFAULT NULL'],
        ['orders', 'extra_ingredients', 'ALTER TABLE orders ADD COLUMN extra_ingredients TEXT'],
        ['orders', 'customer_name', 'ALTER TABLE orders ADD COLUMN customer_name VARCHAR(100) DEFAULT NULL'],
        ['orders', 'customer_cell', 'ALTER TABLE orders ADD COLUMN customer_cell VARCHAR(20) DEFAULT NULL'],
        ['orders', 'delivery_date', 'ALTER TABLE orders ADD COLUMN delivery_date DATE DEFAULT NULL'],
        ['orders', 'delivery_time', 'ALTER TABLE orders ADD COLUMN delivery_time TIME DEFAULT NULL'],
        ['orders', 'shift', 'ALTER TABLE orders ADD COLUMN shift VARCHAR(20) DEFAULT NULL'],
        ['orders', 'number_of_persons', 'ALTER TABLE orders ADD COLUMN number_of_persons INT DEFAULT NULL'],
        ['orders', 'advance_amount', 'ALTER TABLE orders ADD COLUMN advance_amount DECIMAL(10, 2) DEFAULT 0'],
    ];

    foreach ($migrations as [$table, $column, $alter]) {
        if (!db_table_exists($conn, $table)) {
            continue;
        }
        if (!db_column_exists($conn, $table, $column)) {
            try {
                $conn->exec($alter);
            } catch (Throwable $e) {
                error_log("Migration {$table}.{$column} failed: " . $e->getMessage());
            }
        }
    }

    // Helpful indexes (IF NOT EXISTS) — cheap on subsequent boots via schema marker
    try {
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_orders_order_number ON orders (order_number)');
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_orders_order_date ON orders (order_date DESC)');
        $conn->exec('CREATE INDEX IF NOT EXISTS idx_dish_ingredients_dish_id ON dish_ingredients (dish_id)');
    } catch (Throwable $e) {
        // ignore
    }

    // Walk-in orders: customer_id may be NULL
    if (db_table_exists($conn, 'orders') && db_column_exists($conn, 'orders', 'customer_id')) {
        try {
            $conn->exec('ALTER TABLE orders ALTER COLUMN customer_id DROP NOT NULL');
        } catch (Throwable $e) {
            // already nullable
        }
    }
}

function ensureSeedData(PDO $conn): void {
    static $seeded = false;
    if ($seeded) {
        return;
    }

    if (!db_table_exists($conn, 'categories')) {
        return;
    }

    $row = db_fetch($conn, 'SELECT COUNT(*) AS c FROM categories');
    if ((int) ($row['c'] ?? 0) > 0) {
        $seeded = true;
        return;
    }

    try {
        $conn->beginTransaction();

        $categories = [
            ['Spices', 'Spices and dry masala'],
            ['Meat', 'Chicken, mutton and other meats'],
            ['Vegetables', 'Fresh vegetables'],
            ['Dairy & Bakery', 'Milk, yogurt, custard and bakery items'],
            ['Staples', 'Rice, oil and cooking staples'],
        ];

        $catIds = [];
        foreach ($categories as [$name, $desc]) {
            $catIds[$name] = db_insert(
                $conn,
                'INSERT INTO categories (name, description) VALUES (?, ?) RETURNING id',
                [$name, $desc]
            );
        }

        $ingredients = [
            ['Chicken', 'Meat', 'kg'],
            ['Basmati Rice', 'Staples', 'kg'],
            ['Onion', 'Vegetables', 'kg'],
            ['Tomato', 'Vegetables', 'kg'],
            ['Garlic', 'Vegetables', 'g'],
            ['Ginger', 'Vegetables', 'g'],
            ['Green Chili', 'Vegetables', 'g'],
            ['Coriander', 'Vegetables', 'g'],
            ['Salt', 'Spices', 'g'],
            ['Red Chili Powder', 'Spices', 'g'],
            ['Cumin', 'Spices', 'g'],
            ['Garam Masala', 'Spices', 'g'],
            ['Yogurt', 'Dairy & Bakery', 'kg'],
            ['Milk', 'Dairy & Bakery', 'L'],
            ['Sugar', 'Staples', 'kg'],
            ['Custard Powder', 'Dairy & Bakery', 'g'],
            ['Cooking Oil', 'Staples', 'L'],
        ];

        $ingIds = [];
        foreach ($ingredients as [$name, $catName, $unit]) {
            $ingIds[$name] = db_insert(
                $conn,
                'INSERT INTO ingredients (name, category_id, unit) VALUES (?, ?, ?) RETURNING id',
                [$name, $catIds[$catName], $unit]
            );
        }

        $dishes = [
            ['Chicken Biryani', 'Classic chicken biryani', 'Meat', 50, 10.00, 'kg'],
            ['Chicken Qorma', 'Rich chicken qorma', 'Meat', 100, 10.00, 'kg'],
            ['Custard', 'Vanilla custard dessert', 'Dairy & Bakery', 100, 10.00, 'portion'],
        ];

        $dishIds = [];
        foreach ($dishes as [$name, $desc, $catName, $persons, $baseQty, $baseUnit]) {
            $dishIds[$name] = db_insert(
                $conn,
                'INSERT INTO dishes (name, description, category_id, number_of_persons, base_quantity, base_unit)
                 VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                [$name, $desc, $catIds[$catName], $persons, $baseQty, $baseUnit]
            );
        }

        $links = [
            ['Chicken Biryani', 'Chicken', 5.00, 'kg'],
            ['Chicken Biryani', 'Basmati Rice', 10.00, 'kg'],
            ['Chicken Biryani', 'Onion', 2.00, 'kg'],
            ['Chicken Biryani', 'Yogurt', 1.00, 'kg'],
            ['Chicken Biryani', 'Salt', 40.00, 'g'],
            ['Chicken Biryani', 'Garam Masala', 20.00, 'g'],
            ['Chicken Biryani', 'Cooking Oil', 1.00, 'L'],
            ['Chicken Qorma', 'Chicken', 8.00, 'kg'],
            ['Chicken Qorma', 'Onion', 3.00, 'kg'],
            ['Chicken Qorma', 'Tomato', 2.00, 'kg'],
            ['Chicken Qorma', 'Yogurt', 2.00, 'kg'],
            ['Chicken Qorma', 'Ginger', 200.00, 'g'],
            ['Chicken Qorma', 'Garlic', 200.00, 'g'],
            ['Chicken Qorma', 'Red Chili Powder', 50.00, 'g'],
            ['Chicken Qorma', 'Cooking Oil', 1.50, 'L'],
            ['Custard', 'Milk', 10.00, 'L'],
            ['Custard', 'Sugar', 2.00, 'kg'],
            ['Custard', 'Custard Powder', 500.00, 'g'],
        ];

        foreach ($links as [$dishName, $ingName, $qty, $unit]) {
            db_exec(
                $conn,
                'INSERT INTO dish_ingredients (dish_id, ingredient_id, quantity, unit) VALUES (?, ?, ?, ?)',
                [$dishIds[$dishName], $ingIds[$ingName], $qty, $unit]
            );
        }

        $email = 'customer@example.com';
        $exists = db_fetch($conn, 'SELECT id FROM users WHERE email = ?', [$email]);
        if ($exists === null) {
            db_exec(
                $conn,
                'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)',
                ['Demo Customer', $email, password_hash('customer123', PASSWORD_DEFAULT), 'user']
            );
        }

        $conn->commit();
        $seeded = true;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('ensureSeedData failed: ' . $e->getMessage());
    }
}

/**
 * @param PDO $conn
 */
function ensureAdminUser($conn): bool {
    if (!$conn instanceof PDO) {
        return false;
    }

    $email = 'admin@example.com';
    $password = 'admin123';

    if (!db_table_exists($conn, 'users')) {
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        } catch (Throwable $e) {
            error_log('ensureAdminUser: failed to create users table: ' . $e->getMessage());
            return false;
        }
    }

    $admin = db_fetch($conn, "SELECT id FROM users WHERE email = ? AND role = 'admin'", [$email]);

    if ($admin === null) {
        try {
            db_exec(
                $conn,
                'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)',
                ['Admin', $email, password_hash($password, PASSWORD_DEFAULT), 'admin']
            );
            return true;
        } catch (Throwable $e) {
            error_log('ensureAdminUser insert failed: ' . $e->getMessage());
            return false;
        }
    }

    // Do NOT password_verify/reset on every request — that alone can add 100ms+ per page load.
    return true;
}

/**
 * Public URL for a dish image (lazy-loaded endpoint — keeps list pages fast).
 */
function dish_image_url(int $dishId, string $relativePrefix = '../'): string {
    return $relativePrefix . 'api/dish_image.php?id=' . $dishId;
}

/**
 * Resolve a dish image value for <img src="...">.
 * Prefer dish_image_url($id) on list pages so base64 is not embedded in HTML.
 */
function dish_image_src(?string $image, string $relativePrefix = '../'): ?string {
    if ($image === null || $image === '') {
        return null;
    }
    if (str_starts_with($image, 'data:') || str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
        return $image;
    }
    $relative = ltrim($image, '/');
    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
    if (is_file($full) && is_readable($full)) {
        return $relativePrefix . $relative;
    }
    return null;
}

/**
 * Compress/resize an image file with GD, return JPEG binary (or original on failure).
 */
function dish_image_compress_file(string $tmpPath, int $maxWidth = 800, int $jpegQuality = 72): ?array {
    if (!function_exists('imagecreatefromstring')) {
        $binary = @file_get_contents($tmpPath);
        return $binary === false ? null : ['binary' => $binary, 'mime' => 'image/jpeg'];
    }
    $raw = @file_get_contents($tmpPath);
    if ($raw === false || $raw === '') {
        return null;
    }
    $src = @imagecreatefromstring($raw);
    if (!$src) {
        return ['binary' => $raw, 'mime' => 'image/jpeg'];
    }
    $width = imagesx($src);
    $height = imagesy($src);
    if ($width < 1 || $height < 1) {
        imagedestroy($src);
        return null;
    }
    if ($width > $maxWidth) {
        $newW = $maxWidth;
        $newH = (int) max(1, round($height * ($maxWidth / $width)));
        $dst = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $newW, $newH, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($src);
        $src = $dst;
    }
    ob_start();
    imagejpeg($src, null, $jpegQuality);
    $binary = ob_get_clean();
    imagedestroy($src);
    if ($binary === false || $binary === '') {
        return null;
    }
    return ['binary' => $binary, 'mime' => 'image/jpeg'];
}

/**
 * Convert an uploaded image into a compressed data URI for DB storage.
 */
function dish_image_from_upload(array $file, ?string &$errorMessage = null, int $maxBytes = 2097152): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $errorMessage = 'Uploaded file is not valid.';
        return null;
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        $errorMessage = 'Image must be between 1 byte and ' . round($maxBytes / 1048576, 1) . 'MB.';
        return null;
    }
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        $errorMessage = 'Invalid image format. Allowed: JPG, JPEG, PNG, GIF, WEBP';
        return null;
    }
    $compressed = dish_image_compress_file($tmp, 800, 72);
    if ($compressed === null) {
        $errorMessage = 'Could not process uploaded image.';
        return null;
    }
    return 'data:' . $compressed['mime'] . ';base64,' . base64_encode($compressed['binary']);
}

function db_die_connection_error(string $message): void {
    if (db_is_production()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Service Unavailable</title></head><body>';
        echo '<h1>Service Unavailable</h1><p>Database is temporarily unavailable. Please try again later.</p>';
        echo '</body></html>';
        exit;
    }
    die("
    <!DOCTYPE html>
    <html>
    <head>
        <title>Database Not Available</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
            .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h1 { color: #dc3545; border-bottom: 2px solid #dc3545; padding-bottom: 10px; }
            .error { color: #dc3545; background: #f8d7da; padding: 15px; border-radius: 4px; margin: 20px 0; }
            .info { color: #0c5460; background: #d1ecf1; padding: 15px; border-radius: 4px; margin: 20px 0; }
            code { background: #f8f9fa; padding: 2px 6px; border-radius: 3px; }
        </style>
    </head>
    <body>
    <div class='container'>
        <h1>Database Not Available</h1>
        <div class='error'><strong>Error:</strong> " . htmlspecialchars($message) . "</div>
        <div class='info'>
            Set <code>DATABASE_URL</code> (Render Postgres) or <code>DB_HOST</code>/<code>DB_USER</code>/<code>DB_PASS</code>/<code>DB_NAME</code>/<code>DB_PORT</code>.
        </div>
    </div>
    </body>
    </html>
    ");
}

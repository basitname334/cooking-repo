<?php
/**
 * Database Optimization Script
 * Run this once to add indexes and optimize database performance
 * Usage: php optimize_database.php
 */

require_once __DIR__ . '/config/database.php';

$conn = getDBConnection();

if (!$conn || $conn->connect_error) {
    die("Error: Could not connect to database.\n");
}

echo "Starting database optimization...\n\n";

// Add indexes for frequently queried columns
$indexes = [
    // Orders table indexes
    "CREATE INDEX IF NOT EXISTS idx_orders_customer_id ON orders(customer_id)",
    "CREATE INDEX IF NOT EXISTS idx_orders_dish_id ON orders(dish_id)",
    "CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status)",
    "CREATE INDEX IF NOT EXISTS idx_orders_order_date ON orders(order_date)",
    "CREATE INDEX IF NOT EXISTS idx_orders_order_number ON orders(order_number)",
    
    // Dishes table indexes
    "CREATE INDEX IF NOT EXISTS idx_dishes_category_id ON dishes(category_id)",
    "CREATE INDEX IF NOT EXISTS idx_dishes_name ON dishes(name)",
    
    // Ingredients table indexes
    "CREATE INDEX IF NOT EXISTS idx_ingredients_category_id ON ingredients(category_id)",
    "CREATE INDEX IF NOT EXISTS idx_ingredients_name ON ingredients(name)",
    
    // Dish ingredients table indexes
    "CREATE INDEX IF NOT EXISTS idx_dish_ingredients_dish_id ON dish_ingredients(dish_id)",
    "CREATE INDEX IF NOT EXISTS idx_dish_ingredients_ingredient_id ON dish_ingredients(ingredient_id)",
    
    // Users table indexes
    "CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)",
    "CREATE INDEX IF NOT EXISTS idx_users_role ON users(role)",
    
    // Categories table indexes
    "CREATE INDEX IF NOT EXISTS idx_categories_name ON categories(name)",
];

$success_count = 0;
$error_count = 0;

foreach ($indexes as $index_query) {
    // MySQL doesn't support IF NOT EXISTS for indexes, so we need to check first
    $index_name = preg_match('/idx_\w+/', $index_query, $matches) ? $matches[0] : '';
    $table_name = preg_match('/ON (\w+)/', $index_query, $matches) ? $matches[1] : '';
    
    if ($index_name && $table_name) {
        // Check if index exists
        $check_query = "SHOW INDEX FROM `$table_name` WHERE Key_name = '$index_name'";
        $result = $conn->query($check_query);
        
        if ($result && $result->num_rows > 0) {
            echo "✓ Index $index_name already exists on $table_name\n";
            $success_count++;
        } else {
            // Remove IF NOT EXISTS clause and try to create
            $create_query = str_replace(' IF NOT EXISTS', '', $index_query);
            if ($conn->query($create_query)) {
                echo "✓ Created index $index_name on $table_name\n";
                $success_count++;
            } else {
                echo "✗ Failed to create index $index_name: " . $conn->error . "\n";
                $error_count++;
            }
        }
    }
}

echo "\nOptimization complete!\n";
echo "Successfully processed: $success_count indexes\n";
if ($error_count > 0) {
    echo "Errors: $error_count\n";
}

// Analyze tables for better query performance
echo "\nAnalyzing tables for query optimization...\n";
$tables = ['users', 'categories', 'ingredients', 'dishes', 'dish_ingredients', 'orders'];
foreach ($tables as $table) {
    $result = $conn->query("ANALYZE TABLE `$table`");
    if ($result) {
        echo "✓ Analyzed table: $table\n";
    }
}

$conn->close();
echo "\nDone! Your database is now optimized.\n";
?>


<?php
/**
 * Orders Management Page
 * View and manage customer orders
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

requireAdmin();

$conn = getDBConnection();
$error = '';
$success = '';

// Check and add order_number column if it doesn't exist
$result = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'order_number'");
if (!$result || $result->num_rows == 0) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `order_number` VARCHAR(50) DEFAULT NULL AFTER `id`");
    $conn->query("ALTER TABLE `orders` ADD INDEX `idx_order_number` (`order_number`)");
    // Generate order numbers for existing orders
    $conn->query("UPDATE `orders` SET `order_number` = CONCAT('ORD-', LPAD(`id`, 6, '0')) WHERE `order_number` IS NULL");
}

// Update orders table schema if needed - add required columns for order form
$required_columns = [
    'customer_name' => "VARCHAR(100) DEFAULT NULL",
    'customer_cell' => "VARCHAR(20) DEFAULT NULL",
    'delivery_date' => "DATE DEFAULT NULL",
    'delivery_time' => "TIME DEFAULT NULL",
    'shift' => "ENUM('afternoon', 'evening') DEFAULT NULL",
    'number_of_persons' => "INT DEFAULT NULL"
];

foreach ($required_columns as $column => $definition) {
    $result = $conn->query("SHOW COLUMNS FROM `orders` LIKE '$column'");
    if (!$result || $result->num_rows == 0) {
        $conn->query("ALTER TABLE `orders` ADD COLUMN `$column` $definition");
    }
}

// Check if order_date column exists, if not add it
$check_order_date = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'order_date'");
if (!$check_order_date || $check_order_date->num_rows == 0) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `order_date` DATETIME DEFAULT NULL AFTER `customer_cell`");
}

// Check if extra_ingredients column exists, if not add it
$check_extra_ingredients = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'extra_ingredients'");
if (!$check_extra_ingredients || $check_extra_ingredients->num_rows == 0) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `extra_ingredients` TEXT DEFAULT NULL AFTER `notes`");
}

// Make customer_id nullable to support orders without registered customers
$check_customer_id = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'customer_id'");
if ($check_customer_id && $check_customer_id->num_rows > 0) {
    $column_info = $check_customer_id->fetch_assoc();
    if ($column_info['Null'] === 'NO') {
        // Try to drop the foreign key constraint first, then modify the column
        try {
            $conn->query("ALTER TABLE `orders` DROP FOREIGN KEY `orders_ibfk_1`");
        } catch (Exception $e) {
            // Constraint might not exist or have different name, try alternative
            $fk_result = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' 
                AND COLUMN_NAME = 'customer_id' AND REFERENCED_TABLE_NAME IS NOT NULL");
            if ($fk_result && $fk_result->num_rows > 0) {
                $fk_row = $fk_result->fetch_assoc();
                $fk_name = $fk_row['CONSTRAINT_NAME'];
                $conn->query("ALTER TABLE `orders` DROP FOREIGN KEY `$fk_name`");
            }
        }
        // Make column nullable
        $conn->query("ALTER TABLE `orders` MODIFY `customer_id` INT(11) NULL");
        // Re-add foreign key constraint with ON DELETE SET NULL
        try {
            $conn->query("ALTER TABLE `orders` ADD CONSTRAINT `orders_ibfk_1` 
                FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL");
        } catch (Exception $e) {
            // If it fails, we'll continue without the constraint for now
        }
    }
}

// Handle create order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_cell = trim($_POST['customer_cell'] ?? '');
    $order_date = trim($_POST['order_date'] ?? '');
    $order_time = trim($_POST['order_time'] ?? '');
    $number_of_persons = intval($_POST['number_of_persons'] ?? 0);
    $shift = trim($_POST['shift'] ?? '');
    $delivery_date = trim($_POST['delivery_date'] ?? '');
    $delivery_time = trim($_POST['delivery_time'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    
    // Translate notes to Urdu if current language is Urdu
    $notes = translateForDatabase($notes);
    
    // Combine order date and time - default to current datetime if not provided
    $order_datetime = null;
    if (!empty($order_date) && !empty($order_time)) {
        $order_datetime = $order_date . ' ' . $order_time . ':00';
    } else {
        // If order_date or order_time is missing, use current datetime
        $order_datetime = date('Y-m-d H:i:s');
    }
    
    // Get dishes array - can be single or multiple
    $dishes_data = [];
    if (isset($_POST['dishes']) && is_array($_POST['dishes'])) {
        // Multiple dishes format
        $dishes_data = $_POST['dishes'];
    } else {
        // Single dish format (backward compatibility)
        $dish_id = intval($_POST['dish_id'] ?? 0);
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit_price = !empty($_POST['unit_price']) ? floatval($_POST['unit_price']) : null;
        $total_amount_input = !empty($_POST['total_amount']) ? floatval($_POST['total_amount']) : null;
        
        if ($dish_id > 0 && $quantity > 0) {
            $dishes_data[] = [
                'dish_id' => $dish_id,
                'quantity' => $quantity,
                'unit_price' => $unit_price,
                'total_amount' => $total_amount_input
            ];
        }
    }
    
    // Get extra ingredients array
    $extra_ingredients_data = [];
    if (isset($_POST['extra_ingredients']) && is_array($_POST['extra_ingredients'])) {
        foreach ($_POST['extra_ingredients'] as $ingredient_data) {
            $ingredient_id = intval($ingredient_data['ingredient_id'] ?? 0);
            $quantity = floatval($ingredient_data['quantity'] ?? 0);
            $unit = trim($ingredient_data['unit'] ?? '');
            
            if ($ingredient_id > 0 && $quantity > 0) {
                $extra_ingredients_data[] = [
                    'ingredient_id' => $ingredient_id,
                    'quantity' => $quantity,
                    'unit' => $unit
                ];
            }
        }
    }
    
    // Validation - check if using new form fields or old customer selection
    $use_new_form = !empty($customer_name) || !empty($customer_cell);
    
    if ($use_new_form) {
        // New form validation
        if (empty($customer_name) || empty($customer_cell) || empty($order_date) || empty($order_time) || 
            $number_of_persons <= 0 || empty($shift) || empty($delivery_date) || empty($delivery_time)) {
            $error = 'Please fill all required fields in Step 1.';
        } elseif (empty($dishes_data)) {
            $error = 'Please select at least one dish in Step 2.';
        }
    } else {
        // Old form validation (backward compatibility)
        if ($customer_id <= 0 || empty($dishes_data)) {
            $error = t('fill_all_required_fields');
        }
    }
    
    if (empty($error)) {
        $status = 'pending';
        $orders_created = 0;
        $errors = [];
        
        // Generate a single order number for all dishes in this order
        $order_number = 'ORD-' . date('Ymd') . '-' . str_pad(time() % 1000000, 6, '0', STR_PAD_LEFT);
        
        // Calculate grand total
        $grand_total = 0;
        $valid_dishes = [];
        
        foreach ($dishes_data as $dish_data) {
            $dish_id = intval($dish_data['dish_id'] ?? 0);
            $quantity = floatval($dish_data['quantity'] ?? 0);
            $unit_price = !empty($dish_data['unit_price']) ? floatval($dish_data['unit_price']) : null;
            $total_amount_input = !empty($dish_data['total_amount']) ? floatval($dish_data['total_amount']) : null;
            
            if ($dish_id <= 0 || $quantity <= 0) {
                continue; // Skip invalid dishes
            }
            
            // Calculate total_amount: use direct input if provided, otherwise calculate from unit_price
            if ($total_amount_input !== null && $total_amount_input >= 0) {
                $total_amount = $total_amount_input;
            } elseif ($unit_price !== null && $unit_price > 0) {
                $total_amount = $quantity * $unit_price;
            } else {
                $total_amount = 0; // Default to 0 if neither is provided
            }
            
            $grand_total += $total_amount;
            $valid_dishes[] = [
                'dish_id' => $dish_id,
                'quantity' => $quantity,
                'total_amount' => $total_amount
            ];
        }
        
        if (empty($valid_dishes)) {
            $error = t('fill_all_required_fields');
        } else {
            // Create order records for each dish with the same order_number
            $order_id = null;
            foreach ($valid_dishes as $dish_info) {
                // Prepare extra ingredients JSON (same for all dishes in the order)
                $extra_ingredients_json = !empty($extra_ingredients_data) ? json_encode($extra_ingredients_data) : null;
                
                if ($use_new_form) {
                    // New form with all required fields - use NULL for customer_id since customer info is in separate fields
                    // mysqli bind_param doesn't handle NULL well with 'i' type, so we'll use a workaround
                    $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_id, dish_id, quantity, total_amount, status, 
                        customer_name, customer_cell, order_date, delivery_date, delivery_time, shift, number_of_persons, notes, extra_ingredients) 
                        VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        // Skip customer_id in bind_param since we're using NULL directly in the query
                        $stmt->bind_param("siidsssssssiss", 
                            $order_number, 
                            $dish_info['dish_id'], 
                            $dish_info['quantity'], 
                            $dish_info['total_amount'], 
                            $status,
                            $customer_name,
                            $customer_cell,
                            $order_datetime,
                            $delivery_date,
                            $delivery_time,
                            $shift,
                            $number_of_persons,
                            $notes,
                            $extra_ingredients_json
                        );
                    }
                } else {
                    // Old form (backward compatibility)
                    $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_id, dish_id, quantity, total_amount, status, notes, extra_ingredients) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    if ($stmt) {
                        $stmt->bind_param("siiddsss", $order_number, $customer_id, $dish_info['dish_id'], $dish_info['quantity'], $dish_info['total_amount'], $status, $notes, $extra_ingredients_json);
                    }
                }
                
                if ($stmt) {
                    if ($stmt->execute()) {
                        if ($order_id === null) {
                            $order_id = $conn->insert_id;
                        }
                        $orders_created++;
                    } else {
                        $errors[] = 'Failed to create order for dish ID ' . $dish_info['dish_id'] . ': ' . $stmt->error;
                        error_log("Order creation error: " . $stmt->error);
                        error_log("Order number: " . $order_number);
                        error_log("Dish ID: " . $dish_info['dish_id']);
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'Failed to prepare insert query for dish ID ' . $dish_info['dish_id'] . ': ' . $conn->error;
                    error_log("Prepare error: " . $conn->error);
                }
            }
            
            if ($orders_created > 0) {
                $dish_count = count($valid_dishes);
                $success = $dish_count > 1 ? "Order #{$order_number} created successfully with {$dish_count} dishes!" : 'Order created successfully!';
                header('Location: orders.php?success=1&created=1&order_number=' . urlencode($order_number));
                exit();
            } else {
                $error = !empty($errors) ? implode(', ', $errors) : t('failed_to_create') . ' ' . t('orders_title');
            }
        }
    }
}

// Handle order status update - update all items in the same order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $order_id = intval($_POST['order_id'] ?? 0);
    $order_number = trim($_POST['order_number'] ?? '');
    $status = trim($_POST['status'] ?? '');
    
    $valid_statuses = ['pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled'];
    
    if (in_array($status, $valid_statuses)) {
        // Update all orders with the same order_number
        if (!empty($order_number)) {
            $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_number = ?");
            if ($stmt) {
                $stmt->bind_param("ss", $status, $order_number);
                if ($stmt->execute()) {
                    $success = 'Order status updated successfully!';
                    header('Location: orders.php?success=1');
                    exit();
                } else {
                    $error = 'Failed to update order status: ' . $stmt->error;
                }
                $stmt->close();
            }
        } elseif ($order_id > 0) {
            // Fallback: update by order_id if order_number not provided
            $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $status, $order_id);
                if ($stmt->execute()) {
                    $success = 'Order status updated successfully!';
                    header('Location: orders.php?success=1');
                    exit();
                } else {
                    $error = 'Failed to update order status: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    } else {
        $error = 'Invalid order or status.';
    }
}

// Handle delete order - delete all items in the same order
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    // Get order_number first
    $stmt = $conn->prepare("SELECT order_number FROM orders WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            $order_number = $row['order_number'];
            $stmt->close();
            
            // Delete all orders with the same order_number
            $stmt = $conn->prepare("DELETE FROM orders WHERE order_number = ?");
            if ($stmt) {
                $stmt->bind_param("s", $order_number);
                if ($stmt->execute()) {
                    $success = 'Order deleted successfully!';
                    header('Location: orders.php?success=1&deleted=1');
                    exit();
                } else {
                    $error = 'Failed to delete order: ' . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            $stmt->close();
            $error = 'Order not found.';
        }
    }
}

// Handle success message from redirect
if (isset($_GET['success'])) {
    if (isset($_GET['created'])) {
        $count = isset($_GET['count']) ? intval($_GET['count']) : 1;
        $success = $count > 1 ? $count . ' orders created successfully!' : 'Order created successfully!';
    } elseif (isset($_GET['deleted'])) {
        $success = 'Order deleted successfully!';
    } else {
        $success = 'Order status updated successfully!';
    }
}

// Get current language for translation
$currentLang = getCurrentLanguage();

// Get all customers for dropdown
$customers = [];
$result = $conn->query("SELECT id, name, email FROM users WHERE role = 'user' ORDER BY name");
if ($result && $result->num_rows > 0) {
    $customers = $result->fetch_all(MYSQLI_ASSOC);
    // Translate customer names if needed (though names usually don't need translation)
}

// Get previously used customer names from orders (for autocomplete)
$previous_customer_names = [];
$prev_cust_query = "SELECT DISTINCT customer_name, customer_cell 
    FROM orders 
    WHERE customer_name IS NOT NULL AND customer_name != '' 
    ORDER BY customer_name";
$prev_cust_result = $conn->query($prev_cust_query);
if ($prev_cust_result && $prev_cust_result->num_rows > 0) {
    $previous_customer_names = $prev_cust_result->fetch_all(MYSQLI_ASSOC);
}

// Combine registered customers and previously used names for autocomplete
$all_customer_names = [];
// Add registered customers
foreach ($customers as $customer) {
    $all_customer_names[$customer['name']] = [
        'name' => $customer['name'],
        'cell' => $customer['email'] ?? '',
        'type' => 'registered'
    ];
}
// Add previously used customer names (avoid duplicates)
foreach ($previous_customer_names as $prev_cust) {
    if (!empty($prev_cust['customer_name']) && !isset($all_customer_names[$prev_cust['customer_name']])) {
        $all_customer_names[$prev_cust['customer_name']] = [
            'name' => $prev_cust['customer_name'],
            'cell' => $prev_cust['customer_cell'] ?? '',
            'type' => 'previous'
        ];
    }
}
// Sort by name
ksort($all_customer_names);

// Get all dishes for dropdown with images and categories
$dishes = [];
$result = $conn->query("SELECT d.id, d.name, d.image, c.name as category_name 
    FROM dishes d 
    LEFT JOIN categories c ON d.category_id = c.id 
    ORDER BY d.name");
if ($result && $result->num_rows > 0) {
    $dishes = $result->fetch_all(MYSQLI_ASSOC);
    // Translate dish names if needed
    foreach ($dishes as &$dish) {
        if (isset($dish['name']) && $currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $dish['name'])) {
            $dish['name'] = translateToUrdu($dish['name']);
        }
    }
    unset($dish);
}

// Get all ingredients for extra ingredients section
$ingredients = [];
$ingredients_result = $conn->query("SELECT i.id, i.name, i.unit, c.name as category_name 
    FROM ingredients i 
    LEFT JOIN categories c ON i.category_id = c.id 
    ORDER BY i.name");
if ($ingredients_result && $ingredients_result->num_rows > 0) {
    $ingredients = $ingredients_result->fetch_all(MYSQLI_ASSOC);
    // Translate ingredient names if needed
    foreach ($ingredients as &$ingredient) {
        if (isset($ingredient['name']) && $currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $ingredient['name'])) {
            $ingredient['name'] = translateToUrdu($ingredient['name']);
        }
    }
    unset($ingredient);
}

// Get previously used dishes from recent orders (last 30 days)
$previously_used_dishes = [];
$recent_orders_query = "SELECT DISTINCT o.dish_id, d.id, d.name,
    COUNT(o.id) as order_count,
    MAX(o.order_date) as last_used_date
    FROM orders o
    INNER JOIN dishes d ON o.dish_id = d.id
    WHERE o.order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY o.dish_id, d.id
    ORDER BY order_count DESC, last_used_date DESC
    LIMIT 20";
$recent_result = $conn->query($recent_orders_query);
if ($recent_result && $recent_result->num_rows > 0) {
    $previously_used_dishes = $recent_result->fetch_all(MYSQLI_ASSOC);
    // Translate dish names if needed
    foreach ($previously_used_dishes as &$dish) {
        if (isset($dish['name']) && $currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $dish['name'])) {
            $dish['name'] = translateToUrdu($dish['name']);
        }
    }
    unset($dish);
}

// Pagination settings
$items_per_page = 12; // Number of orders per page
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Get all orders grouped by order_number
$orders = [];
// Use COALESCE to get customer_name from orders table first, then fallback to users table
// Also explicitly select o.customer_name and o.customer_cell to ensure they're available
// Show all orders - removed WHERE clause to ensure all orders are displayed
// Simplified query to ensure it works
$query = "SELECT o.id, o.order_number, o.customer_id, o.dish_id, o.quantity, o.total_amount, 
    o.status, o.notes, o.customer_name, o.customer_cell, o.order_date, o.delivery_date, 
    o.delivery_time, o.shift, o.number_of_persons,
    o.customer_name as order_customer_name,
    o.customer_cell as order_customer_cell,
    u.name as user_customer_name,
    u.email as user_customer_email,
    COALESCE(u.name, o.customer_name) as customer_name, 
    COALESCE(u.email, o.customer_cell) as customer_email, 
    d.name as dish_name, d.id as dish_id, d.number_of_persons
    FROM orders o
    LEFT JOIN users u ON o.customer_id = u.id
    LEFT JOIN dishes d ON o.dish_id = d.id
    ORDER BY 
        COALESCE(o.order_date, NOW()) DESC, 
        COALESCE(o.order_number, ''), 
        o.id DESC";

$result = $conn->query($query);
if (!$result) {
    // Log query error for debugging
    $error_msg = "Orders query error: " . $conn->error;
    error_log($error_msg);
    error_log("Query: " . $query);
    
    // Try a simpler query to see if orders table exists and has data
    $test_result = $conn->query("SELECT COUNT(*) as count FROM orders");
    if ($test_result) {
        $test_row = $test_result->fetch_assoc();
        error_log("Total orders in database: " . $test_row['count']);
        if ($test_row['count'] > 0) {
            // Orders exist but main query failed - try simpler query
            $query = "SELECT * FROM orders ORDER BY id DESC LIMIT 100";
            $result = $conn->query($query);
        }
    }
    
    // Also show error in page for debugging (remove in production)
    if (isset($_GET['debug'])) {
        $error = $error_msg;
    }
}

// Always initialize variables even if query fails
$orders = [];
$grouped_orders = [];
$paginated_orders = [];
$total_orders = 0;
$total_pages = 0;

if ($result && $result->num_rows > 0) {
    $orders = $result->fetch_all(MYSQLI_ASSOC);
    
    // If using fallback query, we need to fetch dish and customer info separately
    $using_fallback = !isset($orders[0]['dish_name']);
    
    if ($using_fallback) {
        // Fetch dish and customer info for each order
        foreach ($orders as &$order) {
            // Get dish info
            if (!empty($order['dish_id'])) {
                $dish_result = $conn->query("SELECT id, name, number_of_persons FROM dishes WHERE id = " . intval($order['dish_id']));
                if ($dish_result && $dish_result->num_rows > 0) {
                    $dish = $dish_result->fetch_assoc();
                    $order['dish_name'] = $dish['name'];
                    $order['dish_id'] = $dish['id'];
                    $order['number_of_persons'] = $dish['number_of_persons'] ?? 0;
                }
            }
            
            // Get customer info if customer_id exists
            if (!empty($order['customer_id'])) {
                $cust_result = $conn->query("SELECT name, email FROM users WHERE id = " . intval($order['customer_id']));
                if ($cust_result && $cust_result->num_rows > 0) {
                    $cust = $cust_result->fetch_assoc();
                    // Prioritize customer name from users table
                    $order['user_customer_name'] = $cust['name'];
                    $order['user_customer_email'] = $cust['email'];
                    // Keep order_customer_name as fallback
                    if (empty($order['customer_name'])) {
                        $order['customer_name'] = $cust['name'];
                    }
                    if (empty($order['customer_email']) && empty($order['customer_cell'])) {
                        $order['customer_email'] = $cust['email'];
                    }
                }
            }
            
            // Set aliases for consistency
            $order['order_customer_name'] = $order['customer_name'] ?? '';
            $order['order_customer_cell'] = $order['customer_cell'] ?? '';
            // Ensure user_customer_name is set if customer_id exists
            if (empty($order['user_customer_name']) && !empty($order['customer_id'])) {
                $order['user_customer_name'] = $order['customer_name'] ?? '';
            }
        }
        unset($order);
    }
    
    // Get ingredients for each dish
    foreach ($orders as &$order) {
        // Translate dish name if needed
        if (isset($order['dish_name']) && $currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $order['dish_name'])) {
            $order['dish_name'] = translateToUrdu($order['dish_name']);
        }
        
        // Translate notes if needed
        if (isset($order['notes']) && !empty($order['notes']) && $currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $order['notes'])) {
            $order['notes'] = translateToUrdu($order['notes']);
        }
        
        $order_ingredients = [];
        if (isset($order['dish_id']) && $order['dish_id']) {
            $dish_id = intval($order['dish_id']);
            $stmt = $conn->prepare("SELECT di.quantity, di.unit, i.name as ingredient_name, i.id as ingredient_id, 
                i.category_id, c.name as category_name
                FROM dish_ingredients di
                LEFT JOIN ingredients i ON di.ingredient_id = i.id
                LEFT JOIN categories c ON i.category_id = c.id
                WHERE di.dish_id = ?
                ORDER BY c.name, i.name");
            if ($stmt) {
                $stmt->bind_param("i", $dish_id);
                $stmt->execute();
                $ing_result = $stmt->get_result();
                if ($ing_result && $ing_result->num_rows > 0) {
                    $order_ingredients = $ing_result->fetch_all(MYSQLI_ASSOC);
                    // Translate ingredient names and category names if needed
                    foreach ($order_ingredients as &$ing) {
                        if (isset($ing['ingredient_name']) && $currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $ing['ingredient_name'])) {
                            $ing['ingredient_name'] = translateToUrdu($ing['ingredient_name']);
                        }
                        if (isset($ing['category_name']) && $currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $ing['category_name'])) {
                            $ing['category_name'] = translateToUrdu($ing['category_name']);
                        }
                    }
                    unset($ing);
                }
                $stmt->close();
            }
        }
        $order['ingredients'] = $order_ingredients;
    }
    unset($order); // Break the reference
    
    // Group orders by order_number
    $grouped_orders = [];
    foreach ($orders as $order) {
        $order_num = $order['order_number'] ?? 'ORD-' . str_pad($order['id'], 6, '0', STR_PAD_LEFT);
        if (!isset($grouped_orders[$order_num])) {
            // Prioritize customer name from users table (registered customers), then from orders table, then fallback
            $customer_name = !empty($order['user_customer_name']) ? $order['user_customer_name'] : 
                            (!empty($order['order_customer_name']) ? $order['order_customer_name'] : 
                            (!empty($order['customer_name']) ? $order['customer_name'] : 'Guest Customer'));
            // Prioritize customer email from users table, then customer_cell from orders table
            $customer_email = !empty($order['user_customer_email']) ? $order['user_customer_email'] : 
                             (!empty($order['order_customer_cell']) ? $order['order_customer_cell'] : 
                             (!empty($order['customer_email']) ? $order['customer_email'] : ''));
            
            $grouped_orders[$order_num] = [
                'order_number' => $order_num,
                'customer_id' => $order['customer_id'],
                'customer_name' => $customer_name,
                'customer_email' => $customer_email,
                'customer_cell' => $order['customer_cell'] ?? '',
                'order_date' => !empty($order['order_date']) ? $order['order_date'] : date('Y-m-d H:i:s'),
                'delivery_date' => $order['delivery_date'] ?? '',
                'delivery_time' => $order['delivery_time'] ?? '',
                'shift' => $order['shift'] ?? '',
                'number_of_persons' => $order['number_of_persons'] ?? 0,
                'status' => $order['status'],
                'notes' => $order['notes'],
                'id' => $order['id'], // Use first order ID for reference
                'total_amount' => 0,
                'dishes' => []
            ];
        }
        $grouped_orders[$order_num]['dishes'][] = $order;
        $grouped_orders[$order_num]['total_amount'] += floatval($order['total_amount']);
    }
    
    // Convert to indexed array and sort by date (newest first)
    $grouped_orders = array_values($grouped_orders);
    usort($grouped_orders, function($a, $b) {
        // Get order date, fallback to current time
        $date_a = !empty($a['order_date']) ? strtotime($a['order_date']) : time();
        $date_b = !empty($b['order_date']) ? strtotime($b['order_date']) : time();
        return $date_b - $date_a; // Descending order (newest first)
    });
    
    // Pagination calculations
    $total_orders = count($grouped_orders);
    $total_pages = ceil($total_orders / $items_per_page);
    $offset = ($current_page - 1) * $items_per_page;
    
    // Get paginated orders
    $paginated_orders = array_slice($grouped_orders, $offset, $items_per_page);
} else {
    $grouped_orders = [];
    $paginated_orders = [];
    $total_orders = 0;
    $total_pages = 0;
}

$conn->close();

// Get absolute URL for logo image
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptPath = dirname($_SERVER['SCRIPT_NAME']);
$baseUrl = $protocol . '://' . $host . $scriptPath;
$logoPath = str_replace('/admin', '', $baseUrl) . '/images/logo.jpg';

$pageTitle = t('orders_title');
include __DIR__ . '/../includes/header.php';
?>

<?php
// Calculate statistics from all grouped orders (not just paginated)
$total_orders_count = count($grouped_orders);
$pending_orders = count(array_filter($grouped_orders, fn($o) => $o['status'] == 'pending'));
$delivered_orders = count(array_filter($grouped_orders, fn($o) => $o['status'] == 'delivered'));
$total_revenue = array_sum(array_column($grouped_orders, 'total_amount'));
?>

<style>
.page-header-modern {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 50%, rgba(240, 147, 251, 0.1) 100%);
    border-radius: 20px;
    padding: 2rem;
    margin-bottom: 2rem;
    border: 1px solid rgba(99, 102, 241, 0.2);
}

.order-stat-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}

.order-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: var(--stat-gradient);
    opacity: 0;
}

.order-stat-card:hover::before {
    opacity: 1;
}

.order-stat-card:hover {
    box-shadow: 0 12px 35px rgba(0, 0, 0, 0.15);
    border-color: rgba(99, 102, 241, 0.3);
}

.order-stat-card.primary { --stat-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.order-stat-card.warning { --stat-gradient: linear-gradient(135deg, #f59e0b 0%, #f97316 100%); }
.order-stat-card.success { --stat-gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
.order-stat-card.info { --stat-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }

.order-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 12px;
    font-size: 0.875rem;
}

.order-card:hover {
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.15);
    border-color: rgba(99, 102, 241, 0.3);
}

.order-card .card-header {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid rgba(226, 232, 240, 0.6);
}

.order-card .card-body {
    padding: 1rem;
}

.order-card .card-footer {
    padding: 0.75rem 1rem;
    border-top: 1px solid rgba(226, 232, 240, 0.6);
}

.order-card h6 {
    font-size: 0.9rem;
    margin-bottom: 0.25rem;
}

.order-card .badge {
    font-size: 0.7rem;
    padding: 0.35rem 0.6rem;
}

.order-card .btn-sm {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

.order-card .form-select-sm {
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
}

/* Responsive adjustments for order cards */
@media (max-width: 991.98px) {
    .order-card {
        font-size: 0.8rem;
    }
    
    .order-card .card-body {
        padding: 0.75rem;
    }
}

@media (max-width: 575.98px) {
    .order-card .card-header,
    .order-card .card-footer {
        padding: 0.5rem 0.75rem;
    }
    
    .order-card .card-body {
        padding: 0.5rem;
    }
}

/* 3-Step Order Wizard Styles */
.order-wizard-progress {
    margin-bottom: 2rem;
}

.progress-steps {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    position: relative;
}

.step-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    position: relative;
    z-index: 2;
}

.step-number {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.25rem;
    transition: all 0.3s ease;
    border: 3px solid #e2e8f0;
}

.step-item.active .step-number {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-color: #667eea;
    box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4);
    transform: scale(1.1);
}

.step-item.completed .step-number {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
    color: white;
    border-color: #10b981;
}

.step-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #64748b;
    text-align: center;
    transition: color 0.3s ease;
}

.step-item.active .step-label {
    color: #667eea;
    font-weight: 700;
}

.step-item.completed .step-label {
    color: #10b981;
}

.step-line {
    flex: 1;
    height: 3px;
    background: #e2e8f0;
    position: relative;
    margin: 0 0.5rem;
    transition: background 0.3s ease;
}

.step-line.completed {
    background: linear-gradient(90deg, #10b981 0%, #38f9d7 100%);
}

.order-step {
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.step-header {
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 1rem;
}

.step-actions {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding-top: 1.5rem;
    border-top: 1px solid #e2e8f0;
}

.step-actions .btn {
    min-width: 150px;
}

.dish-modal-card {
    transition: all 0.3s ease;
}

.dish-modal-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 30px rgba(99, 102, 241, 0.2) !important;
    border-color: #6366f1 !important;
}

.category-filter {
    border: 2px solid #e2e8f0;
    background: white;
    color: #64748b;
    font-weight: 600;
    padding: 0.5rem 1rem;
    transition: all 0.3s ease;
}

.category-filter:hover {
    border-color: #6366f1;
    color: #6366f1;
    background: #eef2ff;
}

.category-filter.active {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-color: transparent;
    color: white;
}

.modal-dish-item.hidden {
    display: none;
}

.order-review-card {
    animation: slideIn 0.4s ease-out;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(-20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@media (max-width: 768px) {
    .progress-steps {
        gap: 0.5rem;
    }
    
    .step-number {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }
    
    .step-label {
        font-size: 0.75rem;
    }
    
    .step-actions {
        flex-direction: column;
    }
    
    .step-actions .btn {
        width: 100%;
    }
}
</style>

<!-- Modern Page Header -->
<div class="page-header-modern">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h1 class="display-6 fw-bold mb-2" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <i class="bi bi-cart-check me-3"></i><?php e('orders_title'); ?>
            </h1>
            <p class="lead mb-0" style="color: #64748b;">
                <i class="bi bi-info-circle me-2"></i>
                <?php echo $total_orders; ?> <?php echo $total_orders == 1 ? 'order' : 'orders'; ?> in total
            </p>
        </div>
    </div>
</div>

<!-- Modern Statistics Cards -->
<div class="row g-4 mb-5">
    <div class="col-lg-3 col-md-6">
        <div class="order-stat-card primary h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center">
                    <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 14px; display: flex; align-items: center; justify-content: center; margin-right: 1rem; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);">
                        <i class="bi bi-cart-check fs-4 text-white"></i>
                    </div>
                    <div>
                        <div class="text-muted small mb-1" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Total Orders</div>
                        <div class="h3 mb-0 fw-bold" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"><?php echo $total_orders; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="order-stat-card warning h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center">
                    <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%); border-radius: 14px; display: flex; align-items: center; justify-content: center; margin-right: 1rem; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);">
                        <i class="bi bi-clock-history fs-4 text-white"></i>
                    </div>
                    <div>
                        <div class="text-muted small mb-1" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Pending</div>
                        <div class="h3 mb-0 fw-bold" style="background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"><?php echo $pending_orders; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="order-stat-card success h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center">
                    <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border-radius: 14px; display: flex; align-items: center; justify-content: center; margin-right: 1rem; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);">
                        <i class="bi bi-check-circle fs-4 text-white"></i>
                    </div>
                    <div>
                        <div class="text-muted small mb-1" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Delivered</div>
                        <div class="h3 mb-0 fw-bold" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"><?php echo $delivered_orders; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="order-stat-card info h-100">
            <div class="card-body p-4">
                <div class="d-flex align-items-center">
                    <div style="width: 60px; height: 60px; background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 14px; display: flex; align-items: center; justify-content: center; margin-right: 1rem; box-shadow: 0 4px 12px rgba(6, 182, 212, 0.3);">
                        <i class="bi bi-currency-exchange fs-4 text-white"></i>
                    </div>
                    <div>
                        <div class="text-muted small mb-1" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Total Revenue</div>
                        <div class="h3 mb-0 fw-bold" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">Rs <?php echo number_format($total_revenue, 0); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <strong>Success:</strong> <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Create Order Section - 3-Step Wizard -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0 fw-bold">
                <i class="bi bi-cart-plus-fill me-2"></i>
                <?php e('create_order'); ?>
            </h5>
        </div>
        <div class="card shadow-lg border-0" style="border-radius: 20px; overflow: hidden;">
            <div class="card-header text-white py-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                <h5 class="mb-0 fw-bold">
                    <i class="bi bi-cart-plus-fill me-2"></i>
                    <?php e('create_order'); ?> - 3-Step Process
                </h5>
            </div>
            <div class="card-body p-4">
                <!-- Progress Steps Indicator -->
                <div class="order-wizard-progress mb-4">
                    <div class="progress-steps">
                        <div class="step-item active" data-step="1">
                            <div class="step-number">1</div>
                            <div class="step-label">Customer</div>
                        </div>
                        <div class="step-line"></div>
                        <div class="step-item" data-step="2">
                            <div class="step-number">2</div>
                            <div class="step-label">Dishes</div>
                        </div>
                        <div class="step-line"></div>
                        <div class="step-item" data-step="3">
                            <div class="step-number">3</div>
                            <div class="step-label">Review</div>
                        </div>
                    </div>
                </div>

                <form method="POST" action="" id="orderForm">
                    <input type="hidden" name="create_order" value="1">
                    
                    <!-- Step 1: Customer Information -->
                    <div class="order-step" id="step1" data-step="1">
                        <div class="step-header mb-4">
                            <h4 class="fw-bold">
                                <i class="bi bi-person-fill me-2 text-primary"></i>
                                Step 1: Customer Information
                            </h4>
                            <p class="text-muted">Fill in the customer details and order information</p>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="customer_name" class="form-label fw-semibold">
                                    <i class="bi bi-person me-1 text-primary"></i>
                                    Customer Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control form-control-lg" id="customer_name" name="customer_name" 
                                       list="customer_names_list" autocomplete="off"
                                       value="<?php echo htmlspecialchars($_POST['customer_name'] ?? ''); ?>" required>
                                <datalist id="customer_names_list">
                                    <?php foreach ($all_customer_names as $cust_info): ?>
                                        <option value="<?php echo htmlspecialchars($cust_info['name']); ?>" 
                                                data-cell="<?php echo htmlspecialchars($cust_info['cell']); ?>"
                                                data-type="<?php echo htmlspecialchars($cust_info['type']); ?>">
                                            <?php echo htmlspecialchars($cust_info['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </datalist>
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>Start typing to see previously added customers
                                </small>
                            </div>
                            <div class="col-md-6">
                                <label for="customer_cell" class="form-label fw-semibold">
                                    <i class="bi bi-telephone me-1 text-primary"></i>
                                    Customer Cell No <span class="text-danger">*</span>
                                </label>
                                <input type="tel" class="form-control form-control-lg" id="customer_cell" name="customer_cell" 
                                       value="<?php echo htmlspecialchars($_POST['customer_cell'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="order_date" class="form-label fw-semibold">
                                    <i class="bi bi-calendar me-1 text-primary"></i>
                                    Date & Time <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control mb-2" id="order_date" name="order_date" 
                                       value="<?php echo htmlspecialchars($_POST['order_date'] ?? date('Y-m-d')); ?>" required>
                                <input type="time" class="form-control" id="order_time" name="order_time" 
                                       value="<?php echo htmlspecialchars($_POST['order_time'] ?? date('H:i')); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="number_of_persons" class="form-label fw-semibold">
                                    <i class="bi bi-people me-1 text-primary"></i>
                                    Number of Persons <span class="text-danger">*</span>
                                </label>
                                <input type="number" class="form-control form-control-lg" id="number_of_persons" name="number_of_persons" 
                                       value="<?php echo htmlspecialchars($_POST['number_of_persons'] ?? ''); ?>" required min="1">
                            </div>
                            <div class="col-md-6">
                                <label for="delivery_date" class="form-label fw-semibold">
                                    <i class="bi bi-calendar-check me-1 text-primary"></i>
                                    Delivery Date <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control form-control-lg" id="delivery_date" name="delivery_date" 
                                       value="<?php echo htmlspecialchars($_POST['delivery_date'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="shift" class="form-label fw-semibold">
                                    <i class="bi bi-clock me-1 text-primary"></i>
                                    Shift <span class="text-danger">*</span>
                                </label>
                                <select class="form-select form-select-lg" id="shift" name="shift" required>
                                    <option value="">-- Select Shift --</option>
                                    <option value="afternoon" <?php echo (isset($_POST['shift']) && $_POST['shift'] === 'afternoon') ? 'selected' : ''; ?>>Afternoon</option>
                                    <option value="evening" <?php echo (isset($_POST['shift']) && $_POST['shift'] === 'evening') ? 'selected' : ''; ?>>Evening</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="delivery_time" class="form-label fw-semibold">
                                    <i class="bi bi-clock-history me-1 text-primary"></i>
                                    Delivery Time <span class="text-danger">*</span>
                                </label>
                                <input type="time" class="form-control form-control-lg" id="delivery_time" name="delivery_time" 
                                       value="<?php echo htmlspecialchars($_POST['delivery_time'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="step-actions mt-4">
                            <button type="button" class="btn btn-primary btn-lg" onclick="nextStep(2)">
                                Next: Add Dishes <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 2: Add Dishes -->
                    <div class="order-step" id="step2" data-step="2" style="display: none;">
                        <div class="step-header mb-4">
                            <h4 class="fw-bold">
                                <i class="bi bi-egg-fried me-2 text-primary"></i>
                                Step 2: Add Dishes
                            </h4>
                            <p class="text-muted">Add dishes to the order. You can add multiple dishes.</p>
                        </div>
                        
                        <!-- Dish Selection Tabs -->
                        <div class="dish-tabs mb-3" style="border-bottom: 2px solid #e2e8f0;">
                            <button type="button" class="dish-tab active" onclick="showDishTab('all')" id="tabAll" style="padding: 0.75rem 1.5rem; background: transparent; border: none; border-bottom: 3px solid transparent; color: #64748b; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                                <i class="bi bi-grid me-2"></i>All Dishes
                            </button>
                            <?php if (!empty($previously_used_dishes)): ?>
                            <button type="button" class="dish-tab" onclick="showDishTab('previous')" id="tabPrevious" style="padding: 0.75rem 1.5rem; background: transparent; border: none; border-bottom: 3px solid transparent; color: #64748b; font-weight: 600; cursor: pointer; transition: all 0.3s ease;">
                                <i class="bi bi-clock-history me-2"></i>Previously Added Dishes
                                <span class="badge bg-success ms-2"><?php echo count($previously_used_dishes); ?></span>
                            </button>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Previously Added Dishes Quick Select -->
                        <?php if (!empty($previously_used_dishes)): ?>
                        <div id="previousDishesSection" style="display: none;" class="mb-4">
                            <div class="alert alert-info mb-3">
                                <i class="bi bi-info-circle me-2"></i>
                                <strong>Previously Added Dishes:</strong> These are dishes that were frequently ordered in the last 30 days. Click on a dish to quickly add it to your order.
                            </div>
                            <div class="row g-2 mb-3">
                                <?php foreach ($previously_used_dishes as $dish): ?>
                                <div class="col-md-3 col-sm-6">
                                    <button type="button" class="btn btn-outline-primary w-100 text-start previous-dish-btn" 
                                            data-dish-id="<?php echo $dish['id']; ?>" 
                                            data-dish-name="<?php echo htmlspecialchars($dish['name']); ?>"
                                            style="white-space: normal; text-align: left;">
                                        <i class="bi bi-star-fill me-1 text-warning"></i>
                                        <strong><?php echo htmlspecialchars($dish['name']); ?></strong>
                                        <br>
                                        <small class="text-muted">Ordered <?php echo $dish['order_count']; ?> time(s)</small>
                                    </button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <label class="form-label fw-semibold mb-0">
                                    <i class="bi bi-egg-fried me-1 text-primary"></i>
                                    <?php e('dish'); ?> <span class="text-danger">*</span>
                                </label>
                                <button type="button" class="btn btn-sm btn-primary" id="addDishBtn" onclick="addNewDishRow()">
                                    <i class="bi bi-plus-circle me-1"></i> Add Dish
                                </button>
                            </div>
                            <div id="dishesContainer">
                                <!-- First dish row -->
                                <div class="dish-row mb-3 p-3 border rounded" data-row="0">
                                    <div class="row g-3">
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold small">
                                                <?php e('dish'); ?> <span class="text-danger">*</span>
                                            </label>
                                            <div class="position-relative">
                                                <select class="form-select dish-select" name="dishes[0][dish_id]" required onfocus="openDishSelectionModal(0)">
                                                    <option value=""><?php e('select_dish'); ?></option>
                                                    <?php foreach ($dishes as $dish): ?>
                                                        <option value="<?php echo $dish['id']; ?>">
                                                            <?php echo htmlspecialchars($dish['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button" class="btn btn-sm btn-outline-primary position-absolute end-0 top-0 h-100" 
                                                        style="border-top-left-radius: 0; border-bottom-left-radius: 0; z-index: 10;"
                                                        onclick="openDishSelectionModal(0)" title="Browse dishes with pictures">
                                                    <i class="bi bi-grid-3x3-gap"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold small">
                                                <?php e('quantity'); ?> <span class="text-danger">*</span>
                                            </label>
                                            <input type="number" class="form-control dish-quantity" name="dishes[0][quantity]" 
                                                   placeholder="1" step="0.01" min="0.01" value="1" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold small">
                                                <?php e('unit_price'); ?> (Rs)
                                            </label>
                                            <input type="number" class="form-control dish-unit-price" name="dishes[0][unit_price]" 
                                                   placeholder="0.00" step="0.01" min="0">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold small">
                                                <?php e('total_amount'); ?> (Rs)
                                            </label>
                                            <input type="number" class="form-control dish-total-amount" name="dishes[0][total_amount]" 
                                                   placeholder="0.00" step="0.01" min="0">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold small d-block">&nbsp;</label>
                                            <button type="button" class="btn btn-sm btn-danger remove-dish-btn" style="display: none;">
                                                <i class="bi bi-trash"></i> Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Extra Ingredients Section -->
                        <div class="mt-5 pt-4 border-top">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <label class="form-label fw-bold mb-0">
                                    <i class="bi bi-plus-circle me-2 text-success"></i>
                                    Extra Ingredients (Optional)
                                </label>
                                <button type="button" class="btn btn-sm btn-success" id="addExtraIngredientBtn">
                                    <i class="bi bi-plus-circle me-1"></i> Add Extra Ingredient
                                </button>
                            </div>
                            <p class="text-muted small mb-3">Add additional ingredients that are not part of the selected dishes.</p>
                            <div id="extraIngredientsContainer">
                                <!-- First extra ingredient row -->
                                <div class="extra-ingredient-row mb-3 p-3 border rounded" data-row="0" style="display: none;">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold small">
                                                Ingredient <span class="text-danger">*</span>
                                            </label>
                                            <select class="form-select extra-ingredient-select" name="extra_ingredients[0][ingredient_id]">
                                                <option value="">Select Ingredient</option>
                                                <?php foreach ($ingredients as $ingredient): ?>
                                                    <option value="<?php echo $ingredient['id']; ?>">
                                                        <?php echo htmlspecialchars($ingredient['name']); ?> 
                                                        <?php if (!empty($ingredient['unit'])): ?>
                                                            (<?php echo htmlspecialchars($ingredient['unit']); ?>)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold small">
                                                Quantity <span class="text-danger">*</span>
                                            </label>
                                            <input type="number" class="form-control extra-ingredient-quantity" 
                                                   name="extra_ingredients[0][quantity]" 
                                                   placeholder="0.00" step="0.01" min="0.01">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold small">
                                                Unit
                                            </label>
                                            <input type="text" class="form-control extra-ingredient-unit" 
                                                   name="extra_ingredients[0][unit]" 
                                                   placeholder="kg, g, pieces, etc.">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold small d-block">&nbsp;</label>
                                            <button type="button" class="btn btn-sm btn-danger remove-extra-ingredient-btn">
                                                <i class="bi bi-trash"></i> Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="step-actions mt-4">
                            <button type="button" class="btn btn-secondary btn-lg" onclick="previousStep(1)">
                                <i class="bi bi-arrow-left me-2"></i> Previous
                            </button>
                            <button type="button" class="btn btn-primary btn-lg" onclick="nextStep(3)">
                                Next: Review <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 3: Review & Confirm -->
                    <div class="order-step" id="step3" data-step="3" style="display: none;">
                        <div class="step-header mb-4">
                            <h4 class="fw-bold">
                                <i class="bi bi-check-circle me-2 text-success"></i>
                                Step 3: Review & Confirm
                            </h4>
                            <p class="text-muted">Review your order details before submitting</p>
                        </div>
                        
                        <div class="order-review-card p-4 mb-4" style="background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
                            <h5 class="fw-bold mb-3">
                                <i class="bi bi-person-fill me-2 text-primary"></i>Customer Information
                            </h5>
                            <div id="reviewCustomer" class="mb-4">
                                <p class="text-muted mb-0">Select a customer in Step 1</p>
                            </div>
                            
                            <h5 class="fw-bold mb-3">
                                <i class="bi bi-egg-fried me-2 text-primary"></i>Order Items
                            </h5>
                            <div id="reviewDishes" class="mb-4">
                                <p class="text-muted mb-0">Add dishes in Step 2</p>
                            </div>
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label fw-semibold">
                                    <i class="bi bi-card-text me-1 text-primary"></i>
                                    <?php e('notes'); ?> (Optional)
                                </label>
                                <textarea class="form-control" id="notes" name="notes" rows="3" 
                                          placeholder="<?php e('optional_notes'); ?>"></textarea>
                            </div>
                            
                            <div class="order-total-section p-3 mt-4" style="background: white; border-radius: 8px; border: 2px solid #667eea;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0 fw-bold">Total Amount:</h5>
                                    <h4 class="mb-0 fw-bold text-primary" id="reviewTotal">Rs 0.00</h4>
                                </div>
                            </div>
                        </div>
                        
                        <div class="step-actions mt-4">
                            <button type="button" class="btn btn-secondary btn-lg" onclick="previousStep(2)">
                                <i class="bi bi-arrow-left me-2"></i> Previous
                            </button>
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="bi bi-check-lg me-2"></i> <?php e('create_order'); ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Dish Selection Modal (Browse All Dishes) -->
<div class="modal fade" id="dishSelectionModal" tabindex="-1" aria-labelledby="dishSelectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                <h5 class="modal-title text-white fw-bold" id="dishSelectionModalLabel">
                    <i class="bi bi-egg-fried me-2"></i>Select Dish
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Search Bar -->
                <div class="mb-4">
                    <div class="input-group input-group-lg">
                        <span class="input-group-text" style="background: #f8fafc; border-right: none;">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control" id="dishSearchInput" placeholder="Search dishes by name or category..." 
                               style="border-left: none; border-right: none;" oninput="filterDishesInModal(this.value)">
                        <button class="btn btn-outline-secondary" type="button" onclick="clearDishSearch()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Category Filter -->
                <div class="mb-4">
                    <div class="d-flex flex-wrap gap-2" id="categoryFilters">
                        <button class="btn btn-sm rounded-pill category-filter active" data-category="all" onclick="filterByCategory('all')">
                            All Categories
                        </button>
                        <?php
                        $categories = [];
                        foreach ($dishes as $dish) {
                            $catName = $dish['category_name'] ?? 'Uncategorized';
                            if (!in_array($catName, $categories)) {
                                $categories[] = $catName;
                            }
                        }
                        foreach ($categories as $catName):
                        ?>
                            <button class="btn btn-sm rounded-pill category-filter" data-category="<?php echo htmlspecialchars($catName); ?>" onclick="filterByCategory('<?php echo htmlspecialchars($catName); ?>')">
                                <?php echo htmlspecialchars($catName); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Dishes Grid -->
                <div class="row g-3" id="modalDishesGrid">
                    <?php foreach ($dishes as $dish): 
                        $image_path = !empty($dish['image']) ? '../' . $dish['image'] : '';
                        $image_exists = !empty($dish['image']) && file_exists(__DIR__ . '/../' . $dish['image']);
                    ?>
                        <div class="col-md-4 col-lg-3 modal-dish-item" 
                             data-dish-id="<?php echo $dish['id']; ?>"
                             data-dish-name="<?php echo htmlspecialchars($dish['name']); ?>"
                             data-category="<?php echo htmlspecialchars($dish['category_name'] ?? 'Uncategorized'); ?>"
                             onclick="selectDishFromModal(<?php echo $dish['id']; ?>, '<?php echo htmlspecialchars(addslashes($dish['name'])); ?>')">
                            <div class="card h-100 shadow-sm border-0 dish-modal-card" style="cursor: pointer; transition: all 0.3s ease; border-radius: 16px; overflow: hidden;">
                                <div style="position: relative; overflow: hidden; height: 200px; background: #f1f5f9;">
                                    <?php if ($image_exists): ?>
                                        <img src="<?php echo htmlspecialchars($image_path); ?>" 
                                             class="w-100 h-100" 
                                             style="object-fit: cover; transition: transform 0.3s ease;"
                                             alt="<?php echo htmlspecialchars($dish['name']); ?>"
                                             onmouseover="this.style.transform='scale(1.1)'"
                                             onmouseout="this.style.transform='scale(1)'">
                                    <?php else: ?>
                                        <div class="w-100 h-100 d-flex align-items-center justify-content-center" 
                                             style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                            <i class="bi bi-egg-fried text-white" style="font-size: 4rem;"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="position-absolute top-0 end-0 m-2">
                                        <span class="badge bg-primary rounded-pill">
                                            <i class="bi bi-check-circle me-1"></i>Select
                                        </span>
                                    </div>
                                </div>
                                <div class="card-body p-3">
                                    <h6 class="card-title fw-bold mb-1" style="color: #1e293b;">
                                        <?php echo htmlspecialchars($dish['name']); ?>
                                    </h6>
                                    <small class="text-muted d-block">
                                        <i class="bi bi-folder me-1"></i><?php echo htmlspecialchars($dish['category_name'] ?? 'Uncategorized'); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- No Results Message -->
                <div id="noDishesFound" class="text-center py-5" style="display: none;">
                    <i class="bi bi-search" style="font-size: 4rem; color: #cbd5e1;"></i>
                    <p class="text-muted mt-3">No dishes found matching your search.</p>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #e2e8f0;">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- All Orders Section -->
<div class="row">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-list-ul me-2 text-primary"></i>
                        <?php e('all_orders'); ?> <span class="badge bg-primary ms-2"><?php echo count($grouped_orders); ?></span>
                    </h5>
                    <!-- Search Box -->
                    <div class="flex-grow-1" style="max-width: 400px;">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0" id="searchOrders" 
                                   placeholder="Search orders by customer, dish, or ID..." 
                                   autocomplete="off">
                            <button class="btn btn-outline-secondary border-start-0" type="button" id="clearSearchOrders" style="display: none;">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if (empty($grouped_orders)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-1 text-muted d-block mb-3"></i>
                        <h5 class="text-muted mb-2"><?php e('no_orders'); ?></h5>
                        <p class="text-muted">Create your first order using the form above!</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3" id="ordersList">
                        <?php 
                        function getStatusBadgeClass($status) {
                            $classes = [
                                'pending' => 'warning',
                                'confirmed' => 'info',
                                'preparing' => 'primary',
                                'ready' => 'success',
                                'delivered' => 'success',
                                'cancelled' => 'danger'
                            ];
                            return $classes[$status] ?? 'secondary';
                        }
                        
                        function getStatusIcon($status) {
                            $icons = [
                                'pending' => 'clock-history',
                                'confirmed' => 'check-circle',
                                'preparing' => 'gear',
                                'ready' => 'check2-circle',
                                'delivered' => 'check-circle-fill',
                                'cancelled' => 'x-circle'
                            ];
                            return $icons[$status] ?? 'question-circle';
                        }
                        ?>
                        <?php foreach ($paginated_orders as $grouped_order): ?>
                                <div class="col-lg-4 col-md-6 col-xl-3 order-item" 
                                 data-id="<?php echo $grouped_order['id']; ?>" 
                                 data-order-number="<?php echo htmlspecialchars($grouped_order['order_number']); ?>"
                                 data-customer="<?php echo strtolower(htmlspecialchars($grouped_order['customer_name'])); ?>"
                                 data-status="<?php echo $grouped_order['status']; ?>">
                                <div class="card border-0 shadow-sm h-100 order-card">
                                    <div class="card-header bg-white">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1 fw-bold text-primary">
                                                    <i class="bi bi-hash me-1"></i><?php echo htmlspecialchars($grouped_order['order_number']); ?>
                                                </h6>
                                                <small class="text-muted d-block" style="font-size: 0.7rem;">
                                                    <i class="bi bi-calendar3 me-1"></i>
                                                    <?php echo date('M d, Y', strtotime($grouped_order['order_date'])); ?>
                                                </small>
                                            </div>
                                            <span class="badge bg-<?php echo getStatusBadgeClass($grouped_order['status']); ?> ms-2">
                                                <i class="bi bi-<?php echo getStatusIcon($grouped_order['status']); ?>"></i>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-2">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-primary bg-opacity-10 rounded p-1 me-2" style="width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;">
                                                    <i class="bi bi-person-fill text-primary" style="font-size: 0.75rem;"></i>
                                                </div>
                                                <div class="flex-grow-1" style="min-width: 0;">
                                                    <div class="fw-semibold" style="font-size: 0.8rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($grouped_order['customer_name']); ?></div>
                                                    <small class="text-muted d-block" style="font-size: 0.7rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($grouped_order['customer_email']); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2 pb-2 border-bottom">
                                            <div class="fw-semibold mb-1" style="font-size: 0.75rem;">
                                                <i class="bi bi-egg-fried text-success me-1"></i>Dishes (<?php echo count($grouped_order['dishes']); ?>)
                                            </div>
                                            <div style="max-height: 80px; overflow-y: auto;">
                                                <?php foreach (array_slice($grouped_order['dishes'], 0, 3) as $dish): ?>
                                                    <div class="mb-1">
                                                        <small class="fw-semibold d-block" style="font-size: 0.75rem;"><?php echo htmlspecialchars($dish['dish_name']); ?></small>
                                                        <small class="text-muted" style="font-size: 0.7rem;">
                                                            Qty: <?php echo number_format($dish['quantity'], 2); ?>
                                                            <?php if ($dish['total_amount'] > 0): ?>
                                                                - Rs <?php echo number_format($dish['total_amount'], 2); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if (count($grouped_order['dishes']) > 3): ?>
                                                    <small class="text-muted" style="font-size: 0.7rem;">+<?php echo count($grouped_order['dishes']) - 3; ?> more</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="text-muted" style="font-size: 0.75rem;">Total</span>
                                                <span class="fw-bold text-success" style="font-size: 0.95rem;">Rs <?php echo number_format($grouped_order['total_amount'], 2); ?></span>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($grouped_order['notes'])): ?>
                                            <div class="mb-2">
                                                <small class="text-muted d-block" style="font-size: 0.7rem; max-height: 40px; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                                                    <i class="bi bi-card-text me-1"></i>
                                                    <?php echo htmlspecialchars($grouped_order['notes']); ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="mb-0">
                                            <form method="POST" action="">
                                                <input type="hidden" name="order_id" value="<?php echo $grouped_order['id']; ?>">
                                                <input type="hidden" name="order_number" value="<?php echo htmlspecialchars($grouped_order['order_number']); ?>">
                                                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()" style="font-size: 0.7rem;">
                                                    <option value="pending" <?php echo $grouped_order['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="confirmed" <?php echo $grouped_order['status'] == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                                    <option value="preparing" <?php echo $grouped_order['status'] == 'preparing' ? 'selected' : ''; ?>>Preparing</option>
                                                    <option value="ready" <?php echo $grouped_order['status'] == 'ready' ? 'selected' : ''; ?>>Ready</option>
                                                    <option value="delivered" <?php echo $grouped_order['status'] == 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                                    <option value="cancelled" <?php echo $grouped_order['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                                </select>
                                                <input type="hidden" name="update_status" value="1">
                                            </form>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-white">
                                        <div class="d-flex gap-1 flex-wrap">
                                            <a href="order_preview.php?order_number=<?php echo urlencode($grouped_order['order_number']); ?>" 
                                               class="btn btn-sm btn-success flex-fill" 
                                               title="View Order Preview"
                                               style="font-size: 0.7rem; padding: 0.2rem 0.4rem;">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-info flex-fill" 
                                                    title="<?php e('print_ingredients'); ?>" 
                                                    onclick="printIngredients('<?php echo htmlspecialchars($grouped_order['order_number']); ?>')"
                                                    style="font-size: 0.7rem; padding: 0.2rem 0.4rem;">
                                                <i class="bi bi-printer"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-primary flex-fill" 
                                                    title="<?php e('print_order'); ?>" 
                                                    onclick="printOrder('<?php echo htmlspecialchars($grouped_order['order_number']); ?>')"
                                                    style="font-size: 0.7rem; padding: 0.2rem 0.4rem;">
                                                <i class="bi bi-printer-fill"></i>
                                            </button>
                                            <a href="?delete=<?php echo $grouped_order['id']; ?>" class="btn btn-sm btn-danger" 
                                               title="<?php e('delete'); ?>" 
                                               onclick="return confirm('<?php echo addslashes(t('confirm_delete_order', 'Are you sure you want to delete this order?')); ?>');"
                                               style="font-size: 0.7rem; padding: 0.2rem 0.4rem;">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="noOrdersResults" class="text-center py-5" style="display: none;">
                        <i class="bi bi-search display-1 text-muted d-block mb-3"></i>
                        <h5 class="text-muted mb-2">No orders found</h5>
                        <p class="text-muted">Try adjusting your search terms</p>
                    </div>
                    
                    <!-- Pagination Controls -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Orders pagination" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <!-- Previous Button -->
                                <li class="page-item <?php echo $current_page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo max(1, $current_page - 1); ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                
                                <!-- Page Numbers -->
                                <?php
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);
                                
                                if ($start_page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=1">1</a>
                                    </li>
                                    <?php if ($start_page > 2): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $total_pages; ?>"><?php echo $total_pages; ?></a>
                                    </li>
                                <?php endif; ?>
                                
                                <!-- Next Button -->
                                <li class="page-item <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo min($total_pages, $current_page + 1); ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <div class="text-center mt-2 text-muted">
                            <small>Showing <?php echo count($paginated_orders); ?> of <?php echo $total_orders; ?> orders (Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>)</small>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// 3-Step Order Wizard Functions
let currentStep = 1;
const totalSteps = 3;

// Get customer data for review
const customersData = <?php echo json_encode($customers); ?>;
const dishesData = <?php echo json_encode($dishes); ?>;

// Step navigation functions
function nextStep(step) {
    if (validateCurrentStep()) {
        if (step <= totalSteps) {
            // Hide current step
            document.getElementById('step' + currentStep).style.display = 'none';
            
            // Update progress indicator
            updateProgressIndicator(currentStep, step);
            
            // Show next step
            currentStep = step;
            document.getElementById('step' + currentStep).style.display = 'block';
            
            // If moving to step 3, update review
            if (step === 3) {
                updateReview();
            }
        }
    }
}

function previousStep(step) {
    if (step >= 1) {
        // Hide current step
        document.getElementById('step' + currentStep).style.display = 'none';
        
        // Update progress indicator
        updateProgressIndicator(currentStep, step);
        
        // Show previous step
        currentStep = step;
        document.getElementById('step' + currentStep).style.display = 'block';
    }
}

// Tab switching function for dish selection
function showDishTab(tab) {
    const tabAll = document.getElementById('tabAll');
    const tabPrevious = document.getElementById('tabPrevious');
    const previousSection = document.getElementById('previousDishesSection');
    
    if (tab === 'all') {
        if (tabAll) tabAll.classList.add('active');
        if (tabPrevious) tabPrevious.classList.remove('active');
        if (previousSection) previousSection.style.display = 'none';
    } else if (tab === 'previous') {
        if (tabAll) tabAll.classList.remove('active');
        if (tabPrevious) tabPrevious.classList.add('active');
        if (previousSection) previousSection.style.display = 'block';
    }
}

// Add event listeners for previously used dishes quick select
document.addEventListener('DOMContentLoaded', function() {
    const previousDishBtns = document.querySelectorAll('.previous-dish-btn');
    previousDishBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const dishId = this.dataset.dishId;
            const dishName = this.dataset.dishName;
            
            // Find the first empty dish row or add a new one
            const dishRows = document.querySelectorAll('.dish-row');
            let targetRow = null;
            
            for (let row of dishRows) {
                const dishSelect = row.querySelector('.dish-select');
                if (dishSelect && !dishSelect.value) {
                    targetRow = row;
                    break;
                }
            }
            
            // If no empty row, add a new one
            if (!targetRow) {
                const addBtn = document.getElementById('addDishBtn');
                if (addBtn) {
                    addBtn.click();
                    // Wait a bit for the new row to be added
                    setTimeout(function() {
                        const newRows = document.querySelectorAll('.dish-row');
                        targetRow = newRows[newRows.length - 1];
                        if (targetRow) {
                            const dishSelect = targetRow.querySelector('.dish-select');
                            if (dishSelect) dishSelect.value = dishId;
                        }
                    }, 100);
                }
            } else {
                const dishSelect = targetRow.querySelector('.dish-select');
                if (dishSelect) {
                    dishSelect.value = dishId;
                    // Trigger change event to update any dependent fields
                    dishSelect.dispatchEvent(new Event('change'));
                }
            }
        });
    });
    
    // Update tab styles on hover
    const dishTabs = document.querySelectorAll('.dish-tab');
    dishTabs.forEach(function(tab) {
        tab.addEventListener('mouseenter', function() {
            if (!this.classList.contains('active')) {
                this.style.color = '#6366f1';
                this.style.background = 'rgba(99, 102, 241, 0.05)';
            }
        });
        tab.addEventListener('mouseleave', function() {
            if (!this.classList.contains('active')) {
                this.style.color = '#64748b';
                this.style.background = 'transparent';
            }
        });
    });
    
    // Set active tab styles
    const activeTab = document.querySelector('.dish-tab.active');
    if (activeTab) {
        activeTab.style.color = '#6366f1';
        activeTab.style.borderBottomColor = '#6366f1';
        activeTab.style.background = 'rgba(99, 102, 241, 0.05)';
    }
});

function validateCurrentStep() {
    if (currentStep === 1) {
        // Validate new customer information fields
        const customerName = document.getElementById('customer_name');
        const customerCell = document.getElementById('customer_cell');
        const orderDate = document.getElementById('order_date');
        const orderTime = document.getElementById('order_time');
        const numberOfPersons = document.getElementById('number_of_persons');
        const shift = document.getElementById('shift');
        const deliveryDate = document.getElementById('delivery_date');
        const deliveryTime = document.getElementById('delivery_time');
        
        if (customerName && !customerName.value.trim()) {
            alert('Please enter customer name');
            customerName.focus();
            return false;
        }
        if (customerCell && !customerCell.value.trim()) {
            alert('Please enter customer cell number');
            customerCell.focus();
            return false;
        }
        if (orderDate && !orderDate.value) {
            alert('Please select order date');
            orderDate.focus();
            return false;
        }
        if (orderTime && !orderTime.value) {
            alert('Please select order time');
            orderTime.focus();
            return false;
        }
        if (numberOfPersons && (!numberOfPersons.value || parseInt(numberOfPersons.value) <= 0)) {
            alert('Please enter number of persons (must be greater than 0)');
            numberOfPersons.focus();
            return false;
        }
        if (shift && !shift.value) {
            alert('Please select shift (afternoon or evening)');
            shift.focus();
            return false;
        }
        if (deliveryDate && !deliveryDate.value) {
            alert('Please select delivery date');
            deliveryDate.focus();
            return false;
        }
        if (deliveryTime && !deliveryTime.value) {
            alert('Please select delivery time');
            deliveryTime.focus();
            return false;
        }
        return true;
    } else if (currentStep === 2) {
        // Validate at least one dish is added
        const dishRows = document.querySelectorAll('.dish-row');
        let hasValidDish = false;
        
        dishRows.forEach(function(row) {
            const dishSelect = row.querySelector('.dish-select');
            const quantityInput = row.querySelector('.dish-quantity');
            
            if (dishSelect.value && quantityInput.value && parseFloat(quantityInput.value) > 0) {
                hasValidDish = true;
            }
        });
        
        if (!hasValidDish) {
            alert('Please add at least one dish with quantity');
            return false;
        }
        return true;
    }
    return true;
}

function updateProgressIndicator(fromStep, toStep) {
    // Update step items and lines
    const progressSteps = document.querySelector('.progress-steps');
    if (!progressSteps) return;
    
    const stepItems = progressSteps.querySelectorAll('.step-item');
    const stepLines = progressSteps.querySelectorAll('.step-line');
    
    stepItems.forEach(function(stepItem, index) {
        const stepNum = index + 1;
        
        if (stepNum < toStep) {
            // Completed steps
            stepItem.classList.remove('active');
            stepItem.classList.add('completed');
            // Mark previous line as completed
            if (index > 0 && stepLines[index - 1]) {
                stepLines[index - 1].classList.add('completed');
            }
        } else if (stepNum === toStep) {
            // Active step
            stepItem.classList.remove('completed');
            stepItem.classList.add('active');
        } else {
            // Future steps
            stepItem.classList.remove('active', 'completed');
        }
    });
    
    // Update step lines
    stepLines.forEach(function(stepLine, index) {
        if (index + 1 < toStep) {
            stepLine.classList.add('completed');
        } else {
            stepLine.classList.remove('completed');
        }
    });
}

function updateReview() {
    // Update customer information - check for new form fields first
    const customerName = document.getElementById('customer_name');
    const customerCell = document.getElementById('customer_cell');
    const orderDate = document.getElementById('order_date');
    const orderTime = document.getElementById('order_time');
    const numberOfPersons = document.getElementById('number_of_persons');
    const shift = document.getElementById('shift');
    const deliveryDate = document.getElementById('delivery_date');
    const deliveryTime = document.getElementById('delivery_time');
    const reviewCustomer = document.getElementById('reviewCustomer');
    
    if (customerName && customerName.value) {
        // New form fields
        let customerInfo = `
            <div>
                <strong>Customer Name:</strong> ${escapeHtml(customerName.value)}<br>
                <strong>Cell No:</strong> ${escapeHtml(customerCell ? customerCell.value : '')}<br>
                <strong>Date & Time:</strong> ${escapeHtml(orderDate ? orderDate.value : '')} ${escapeHtml(orderTime ? orderTime.value : '')}<br>
                <strong>Number of Persons:</strong> ${escapeHtml(numberOfPersons ? numberOfPersons.value : '')}<br>
                <strong>Delivery Date:</strong> ${escapeHtml(deliveryDate ? deliveryDate.value : '')}<br>
                <strong>Shift:</strong> ${escapeHtml(shift ? shift.options[shift.selectedIndex].text : '')}<br>
                <strong>Delivery Time:</strong> ${escapeHtml(deliveryTime ? deliveryTime.value : '')}
            </div>
        `;
        reviewCustomer.innerHTML = customerInfo;
    } else {
        // Old form - check customer selection
        const customerSelect = document.getElementById('customer_id');
        const customerId = customerSelect ? customerSelect.value : '';
        
        if (customerId && customersData) {
            const customer = customersData.find(c => c.id == customerId);
            if (customer) {
                reviewCustomer.innerHTML = `
                    <div class="d-flex align-items-center">
                        <div>
                            <strong>${escapeHtml(customer.name)}</strong><br>
                            <small class="text-muted">${escapeHtml(customer.email)}</small>
                        </div>
                    </div>
                `;
            }
        } else {
            reviewCustomer.innerHTML = '<p class="text-muted mb-0">No customer information provided</p>';
        }
    }
    
    // Update dishes information
    const dishRows = document.querySelectorAll('.dish-row');
    const reviewDishes = document.getElementById('reviewDishes');
    let dishesHTML = '';
    let totalAmount = 0;
    
    if (dishRows && dishesData) {
        dishRows.forEach(function(row) {
            const dishSelect = row.querySelector('.dish-select');
            const quantityInput = row.querySelector('.dish-quantity');
            const unitPriceInput = row.querySelector('.dish-unit-price');
            const totalAmountInput = row.querySelector('.dish-total-amount');
            
            if (dishSelect && dishSelect.value && quantityInput && quantityInput.value) {
                const dishId = dishSelect.value;
                const dish = dishesData.find(d => d.id == dishId);
                const quantity = parseFloat(quantityInput.value) || 0;
                const unitPrice = parseFloat(unitPriceInput.value) || 0;
                const total = parseFloat(totalAmountInput.value) || (quantity * unitPrice);
                
                if (dish && quantity > 0) {
                    dishesHTML += `
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2" style="background: white; border-radius: 6px;">
                            <div>
                                <strong>${escapeHtml(dish.name)}</strong><br>
                                <small class="text-muted">Quantity: ${quantity} ${unitPrice > 0 ? '× Rs ' + unitPrice.toFixed(2) : ''}</small>
                            </div>
                            <div class="text-end">
                                <strong class="text-primary">Rs ${total.toFixed(2)}</strong>
                            </div>
                        </div>
                    `;
                    totalAmount += total;
                }
            }
        });
    }
    
    // Add extra ingredients to review
    const extraIngredientRows = document.querySelectorAll('.extra-ingredient-row[style*="block"], .extra-ingredient-row:not([style*="none"])');
    const ingredientsData = typeof window.ingredientsData !== 'undefined' ? window.ingredientsData : [];
    
    if (extraIngredientRows.length > 0 && ingredientsData.length > 0) {
        let hasExtraIngredients = false;
        let extraIngredientsHTML = '<div class="mt-3 pt-3 border-top"><strong class="text-success"><i class="bi bi-plus-circle me-1"></i>Extra Ingredients:</strong></div>';
        
        extraIngredientRows.forEach(function(row) {
            const ingredientSelect = row.querySelector('.extra-ingredient-select');
            const quantityInput = row.querySelector('.extra-ingredient-quantity');
            const unitInput = row.querySelector('.extra-ingredient-unit');
            
            if (ingredientSelect && ingredientSelect.value && quantityInput && quantityInput.value) {
                const ingredientId = ingredientSelect.value;
                const ingredient = ingredientsData.find(i => i.id == ingredientId);
                const quantity = parseFloat(quantityInput.value) || 0;
                const unit = unitInput ? unitInput.value : '';
                
                if (ingredient && quantity > 0) {
                    hasExtraIngredients = true;
                    const unitText = unit ? ' ' + escapeHtml(unit) : '';
                    extraIngredientsHTML += `
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2" style="background: #f0fdf4; border-radius: 6px; border-left: 3px solid #10b981;">
                            <div>
                                <strong class="text-success">${escapeHtml(ingredient.name)}</strong><br>
                                <small class="text-muted">Quantity: ${quantity}${unitText}</small>
                            </div>
                        </div>
                    `;
                }
            }
        });
        
        if (hasExtraIngredients) {
            dishesHTML += extraIngredientsHTML;
        }
    }
    
    if (dishesHTML) {
        reviewDishes.innerHTML = dishesHTML;
    } else {
        reviewDishes.innerHTML = '<p class="text-muted mb-0">No dishes added</p>';
    }
    
    // Update total amount
    const reviewTotal = document.getElementById('reviewTotal');
    if (reviewTotal) {
        reviewTotal.textContent = 'Rs ' + totalAmount.toFixed(2);
    }
}

// Escape HTML function (will be defined in the dishes management section)
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// Listen for changes to update review in real-time
document.addEventListener('DOMContentLoaded', function() {
    // Auto-fill customer cell when customer name is selected from dropdown
    const customerName = document.getElementById('customer_name');
    const customerCell = document.getElementById('customer_cell');
    const customerDatalist = document.getElementById('customer_names_list');
    
    if (customerName && customerCell && customerDatalist) {
        // Store customer data for quick lookup
        const customerData = {};
        <?php foreach ($all_customer_names as $cust_info): ?>
        customerData['<?php echo addslashes($cust_info['name']); ?>'] = '<?php echo addslashes($cust_info['cell']); ?>';
        <?php endforeach; ?>
        
        // Handle input event to auto-fill cell number
        customerName.addEventListener('input', function() {
            const selectedName = this.value.trim();
            if (customerData[selectedName]) {
                customerCell.value = customerData[selectedName];
            }
        });
        
        // Handle change event (when dropdown option is selected)
        customerName.addEventListener('change', function() {
            const selectedName = this.value.trim();
            if (customerData[selectedName]) {
                customerCell.value = customerData[selectedName];
            }
        });
    }
    
    // Listen for new form field changes
    const customerNameField = document.getElementById('customer_name');
    const customerCellField = document.getElementById('customer_cell');
    const orderDate = document.getElementById('order_date');
    const orderTime = document.getElementById('order_time');
    const numberOfPersons = document.getElementById('number_of_persons');
    const shift = document.getElementById('shift');
    const deliveryDate = document.getElementById('delivery_date');
    const deliveryTime = document.getElementById('delivery_time');
    
    [customerNameField, customerCellField, orderDate, orderTime, numberOfPersons, shift, deliveryDate, deliveryTime].forEach(function(field) {
        if (field) {
            field.addEventListener('change', function() {
                if (currentStep === 3) {
                    updateReview();
                }
            });
        }
    });
    
    // Old form - customer selection
    const customerSelect = document.getElementById('customer_id');
    if (customerSelect) {
        customerSelect.addEventListener('change', function() {
            if (currentStep === 3) {
                updateReview();
            }
        });
    }
    
    // Listen for dish changes
    const dishesContainer = document.getElementById('dishesContainer');
    if (dishesContainer) {
        dishesContainer.addEventListener('input', function(e) {
            if (currentStep === 3 && (e.target.classList.contains('dish-select') || 
                e.target.classList.contains('dish-quantity') || 
                e.target.classList.contains('dish-unit-price') || 
                e.target.classList.contains('dish-total-amount'))) {
                updateReview();
            }
        });
    }
});

// Multiple dishes management
document.addEventListener('DOMContentLoaded', function() {
    const dishesContainer = document.getElementById('dishesContainer');
    const addDishBtn = document.getElementById('addDishBtn');
    let dishRowCount = 1; // Start from 1 since we already have row 0
    
    // Get dishes data for cloning
    const dishesData = <?php echo json_encode($dishes); ?>;
    
    // Get ingredients data for extra ingredients
    const ingredientsData = <?php echo json_encode($ingredients); ?>;
    window.ingredientsData = ingredientsData; // Make it globally available for review function
    
    // Function to create dish options HTML
    function getDishOptionsHTML() {
        let html = '<option value=""><?php e('select_dish'); ?></option>';
        dishesData.forEach(function(dish) {
            html += '<option value="' + dish.id + '">' + escapeHtml(dish.name) + '</option>';
        });
        return html;
    }
    
    // Function to create ingredient options HTML
    function getIngredientOptionsHTML() {
        let html = '<option value="">Select Ingredient</option>';
        ingredientsData.forEach(function(ingredient) {
            const unitText = ingredient.unit ? ' (' + escapeHtml(ingredient.unit) + ')' : '';
            html += '<option value="' + ingredient.id + '">' + escapeHtml(ingredient.name) + unitText + '</option>';
        });
        return html;
    }
    
    // Function to escape HTML
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
    
    // Function to setup row event listeners
    function setupRowListeners(row) {
        const quantityInput = row.querySelector('.dish-quantity');
        const unitPriceInput = row.querySelector('.dish-unit-price');
        const totalAmountInput = row.querySelector('.dish-total-amount');
        let isManualTotalEdit = false;
        let lastCalculatedTotal = 0;
        
        function calculateTotal() {
            if (isManualTotalEdit) {
                return;
            }
            
            const quantity = parseFloat(quantityInput.value) || 0;
            const unitPrice = parseFloat(unitPriceInput.value) || 0;
            
            if (unitPrice > 0 && quantity > 0) {
                const calculatedTotal = quantity * unitPrice;
                lastCalculatedTotal = calculatedTotal;
                totalAmountInput.value = calculatedTotal.toFixed(2);
            } else if (unitPrice === 0 || !unitPriceInput.value) {
                if (!isManualTotalEdit) {
                    totalAmountInput.value = '';
                }
            }
            
            // Update review if on step 3
            if (currentStep === 3) {
                updateReview();
            }
        }
        
        quantityInput.addEventListener('input', calculateTotal);
        unitPriceInput.addEventListener('input', calculateTotal);
        
        totalAmountInput.addEventListener('focus', function() {
            isManualTotalEdit = false;
        });
        
        totalAmountInput.addEventListener('input', function() {
            const currentValue = parseFloat(this.value) || 0;
            if (Math.abs(currentValue - lastCalculatedTotal) > 0.01) {
                isManualTotalEdit = true;
            }
            
            // Update review if on step 3
            if (currentStep === 3) {
                updateReview();
            }
        });
        
        totalAmountInput.addEventListener('blur', function() {
            if (!this.value || this.value === '') {
                isManualTotalEdit = false;
                calculateTotal();
            } else if (currentStep === 3) {
                updateReview();
            }
        });
    }
    
    // Setup listeners for existing rows
    document.querySelectorAll('.dish-row').forEach(function(row) {
        setupRowListeners(row);
    });
    
    // Add new dish row
    addDishBtn.addEventListener('click', function() {
        const newRow = document.createElement('div');
        newRow.className = 'dish-row mb-3 p-3 border rounded';
        newRow.setAttribute('data-row', dishRowCount);
        
        newRow.innerHTML = `
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">
                        <?php e('dish'); ?> <span class="text-danger">*</span>
                    </label>
                    <div class="position-relative">
                        <select class="form-select dish-select" name="dishes[${dishRowCount}][dish_id]" required onfocus="openDishSelectionModal(${dishRowCount})">
                            ${getDishOptionsHTML()}
                        </select>
                        <button type="button" class="btn btn-sm btn-outline-primary position-absolute end-0 top-0 h-100" 
                                style="border-top-left-radius: 0; border-bottom-left-radius: 0; z-index: 10;"
                                onclick="openDishSelectionModal(${dishRowCount})" title="Browse dishes with pictures">
                            <i class="bi bi-grid-3x3-gap"></i>
                        </button>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small">
                        <?php e('quantity'); ?> <span class="text-danger">*</span>
                    </label>
                    <input type="number" class="form-control dish-quantity" name="dishes[${dishRowCount}][quantity]" 
                           placeholder="1" step="0.01" min="0.01" value="1" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small">
                        <?php e('unit_price'); ?> (Rs)
                    </label>
                    <input type="number" class="form-control dish-unit-price" name="dishes[${dishRowCount}][unit_price]" 
                           placeholder="0.00" step="0.01" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small">
                        <?php e('total_amount'); ?> (Rs)
                    </label>
                    <input type="number" class="form-control dish-total-amount" name="dishes[${dishRowCount}][total_amount]" 
                           placeholder="0.00" step="0.01" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small d-block">&nbsp;</label>
                    <button type="button" class="btn btn-sm btn-danger remove-dish-btn">
                        <i class="bi bi-trash"></i> Remove
                    </button>
                </div>
            </div>
        `;
        
        dishesContainer.appendChild(newRow);
        setupRowListeners(newRow);
        updateRemoveButtons();
        dishRowCount++;
        
        // Update review if on step 3
        if (currentStep === 3) {
            updateReview();
        }
    });
    
    // Remove dish row
    dishesContainer.addEventListener('click', function(e) {
        if (e.target.closest('.remove-dish-btn')) {
            const row = e.target.closest('.dish-row');
            if (document.querySelectorAll('.dish-row').length > 1) {
                row.remove();
                updateRemoveButtons();
                
                // Update review if on step 3
                if (currentStep === 3) {
                    updateReview();
                }
            }
        }
    });
    
    // Update remove buttons visibility
    function updateRemoveButtons() {
        const rows = document.querySelectorAll('.dish-row');
        rows.forEach(function(row) {
            const removeBtn = row.querySelector('.remove-dish-btn');
            if (rows.length > 1) {
                removeBtn.style.display = 'block';
            } else {
                removeBtn.style.display = 'none';
            }
        });
    }
    
    // Initial setup
    updateRemoveButtons();
    
    // Extra Ingredients Management
    const extraIngredientsContainer = document.getElementById('extraIngredientsContainer');
    const addExtraIngredientBtn = document.getElementById('addExtraIngredientBtn');
    let extraIngredientRowCount = 0;
    
    // Add extra ingredient row
    if (addExtraIngredientBtn && extraIngredientsContainer) {
        addExtraIngredientBtn.addEventListener('click', function() {
            const newRow = document.createElement('div');
            newRow.className = 'extra-ingredient-row mb-3 p-3 border rounded';
            newRow.setAttribute('data-row', extraIngredientRowCount);
            newRow.style.display = 'block';
            
            newRow.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">
                            Ingredient <span class="text-danger">*</span>
                        </label>
                        <select class="form-select extra-ingredient-select" name="extra_ingredients[${extraIngredientRowCount}][ingredient_id]">
                            ${getIngredientOptionsHTML()}
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">
                            Quantity <span class="text-danger">*</span>
                        </label>
                        <input type="number" class="form-control extra-ingredient-quantity" 
                               name="extra_ingredients[${extraIngredientRowCount}][quantity]" 
                               placeholder="0.00" step="0.01" min="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">
                            Unit
                        </label>
                        <input type="text" class="form-control extra-ingredient-unit" 
                               name="extra_ingredients[${extraIngredientRowCount}][unit]" 
                               placeholder="kg, g, pieces, etc.">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small d-block">&nbsp;</label>
                        <button type="button" class="btn btn-sm btn-danger remove-extra-ingredient-btn">
                            <i class="bi bi-trash"></i> Remove
                        </button>
                    </div>
                </div>
            `;
            
            extraIngredientsContainer.appendChild(newRow);
            
            // Auto-fill unit when ingredient is selected
            const select = newRow.querySelector('.extra-ingredient-select');
            const unitInput = newRow.querySelector('.extra-ingredient-unit');
            if (select && unitInput) {
                select.addEventListener('change', function() {
                    const ingredientId = this.value;
                    const ingredient = ingredientsData.find(i => i.id == ingredientId);
                    if (ingredient && ingredient.unit) {
                        unitInput.value = ingredient.unit;
                    }
                });
            }
            
            extraIngredientRowCount++;
        });
        
        // Remove extra ingredient row
        extraIngredientsContainer.addEventListener('click', function(e) {
            if (e.target.closest('.remove-extra-ingredient-btn')) {
                const row = e.target.closest('.extra-ingredient-row');
                if (row) {
                    row.remove();
                }
            }
        });
        
        // Auto-fill unit for existing rows
        document.querySelectorAll('.extra-ingredient-select').forEach(function(select) {
            const row = select.closest('.extra-ingredient-row');
            const unitInput = row ? row.querySelector('.extra-ingredient-unit') : null;
            if (select && unitInput) {
                select.addEventListener('change', function() {
                    const ingredientId = this.value;
                    const ingredient = ingredientsData.find(i => i.id == ingredientId);
                    if (ingredient && ingredient.unit) {
                        unitInput.value = ingredient.unit;
                    }
                });
            }
        });
    }
    
    // Search functionality for orders
    const searchInput = document.getElementById('searchOrders');
    const clearSearchBtn = document.getElementById('clearSearchOrders');
    const ordersList = document.getElementById('ordersList');
    const noOrdersResults = document.getElementById('noOrdersResults');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const orderItems = document.querySelectorAll('.order-item');
            let visibleCount = 0;
            
            if (searchTerm === '') {
                // Show all orders
                orderItems.forEach(item => {
                    item.style.display = '';
                    visibleCount++;
                });
                clearSearchBtn.style.display = 'none';
                if (noOrdersResults) noOrdersResults.style.display = 'none';
                if (ordersList) ordersList.style.display = '';
            } else {
                // Filter orders
                orderItems.forEach(item => {
                    const orderNumber = item.getAttribute('data-order-number') || '';
                    const orderId = item.getAttribute('data-id') || '';
                    const customerName = item.getAttribute('data-customer') || '';
                    
                    // Search in order number, customer name, or order ID
                    if (orderNumber.toLowerCase().includes(searchTerm) || 
                        orderId.includes(searchTerm) || 
                        customerName.includes(searchTerm)) {
                        item.style.display = '';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                });
                
                clearSearchBtn.style.display = searchTerm ? 'block' : 'none';
                
                // Show/hide no results message
                if (visibleCount === 0) {
                    if (noOrdersResults) noOrdersResults.style.display = 'block';
                    if (ordersList) ordersList.style.display = 'none';
                } else {
                    if (noOrdersResults) noOrdersResults.style.display = 'none';
                    if (ordersList) ordersList.style.display = '';
                }
            }
        });
        
        // Clear search
        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function() {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                searchInput.focus();
            });
        }
    }
});

// Get translations from PHP - Force Urdu for print
const translations = <?php 
try {
    // Force Urdu language for print translations
    $originalLang = getCurrentLanguage();
    $_SESSION['lang'] = 'ur'; // Temporarily set to Urdu
    
    // Load Urdu translations directly
    $urduTranslations = include __DIR__ . '/../translations/ur.php';
    
    echo json_encode([
        'brand_name' => $urduTranslations['brand_name'] ?? 'حسن کک',
        'ingredients_list' => $urduTranslations['ingredients_list'] ?? 'آرڈر کے لیے اجزاء کی فہرست',
        'order_id' => $urduTranslations['order_id'] ?? 'آرڈر نمبر',
        'dish' => $urduTranslations['dish'] ?? 'کھانا',
        'quantity' => $urduTranslations['quantity'] ?? 'مقدار',
        'order_date' => $urduTranslations['order_date'] ?? 'آرڈر کی تاریخ',
        'printed_on' => $urduTranslations['printed_on'] ?? 'پرنٹ کی تاریخ',
        'print' => $urduTranslations['print'] ?? 'پرنٹ کریں',
        'close' => $urduTranslations['close'] ?? 'بند کریں',
        'ingredient_label' => $urduTranslations['ingredient_label'] ?? 'جزو',
        'quantity_label' => $urduTranslations['quantity_label'] ?? 'مقدار',
        'unit_label' => $urduTranslations['unit_label'] ?? 'اکائی',
        'no_ingredients_found' => $urduTranslations['no_ingredients_found'] ?? 'اس کھانے کے لیے کوئی جزو نہیں ملا۔',
        'order_receipt' => $urduTranslations['order_receipt'] ?? 'آرڈر کی رسید',
        'order_details' => $urduTranslations['order_details'] ?? 'آرڈر کی تفصیلات',
        'customer' => $urduTranslations['customer'] ?? 'گاہک',
        'email' => $urduTranslations['email'] ?? 'ای میل',
        'unit_price' => $urduTranslations['unit_price'] ?? 'یونٹ قیمت',
        'total_amount' => $urduTranslations['total_amount'] ?? 'کل رقم',
        'notes' => $urduTranslations['notes'] ?? 'نوٹس',
        'thank_you' => $urduTranslations['thank_you'] ?? 'آپ کے آرڈر کا شکریہ!',
        'status' => $urduTranslations['status'] ?? 'حالت',
        'number_of_persons' => $urduTranslations['number_of_persons'] ?? 'افراد کی تعداد',
        'persons' => $urduTranslations['persons'] ?? 'افراد'
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    
    // Restore original language
    $_SESSION['lang'] = $originalLang;
} catch (Exception $e) {
    // Fallback to hardcoded Urdu translations
    echo json_encode([
        'brand_name' => 'حسن کک',
        'ingredients_list' => 'آرڈر کے لیے اجزاء کی فہرست',
        'order_id' => 'آرڈر نمبر',
        'dish' => 'کھانا',
        'quantity' => 'مقدار',
        'order_date' => 'آرڈر کی تاریخ',
        'printed_on' => 'پرنٹ کی تاریخ',
        'print' => 'پرنٹ کریں',
        'close' => 'بند کریں',
        'ingredient_label' => 'جزو',
        'quantity_label' => 'مقدار',
        'unit_label' => 'اکائی',
        'no_ingredients_found' => 'اس کھانے کے لیے کوئی جزو نہیں ملا۔',
        'order_receipt' => 'آرڈر کی رسید',
        'order_details' => 'آرڈر کی تفصیلات',
        'customer' => 'گاہک',
        'email' => 'ای میل',
        'unit_price' => 'یونٹ قیمت',
        'total_amount' => 'کل رقم',
        'notes' => 'نوٹس',
        'thank_you' => 'آپ کے آرڈر کا شکریہ!',
        'status' => 'حالت',
        'number_of_persons' => 'افراد کی تعداد',
        'persons' => 'افراد'
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
?>;
const currentLang = '<?php echo addslashes(getCurrentLanguage()); ?>';
const langDir = '<?php echo addslashes(getLanguageDirection()); ?>';
const ordersData = <?php 
try {
    // Clean grouped orders data for JSON encoding
    $cleanOrders = [];
    foreach ($grouped_orders as $grouped_order) {
        $orderData = [
            'order_number' => $grouped_order['order_number'] ?? '',
            'id' => $grouped_order['id'] ?? 0,
            'customer_name' => $grouped_order['customer_name'] ?? '',
            'customer_email' => $grouped_order['customer_email'] ?? '',
            'order_date' => $grouped_order['order_date'] ?? '',
            'status' => $grouped_order['status'] ?? 'pending',
            'total_amount' => $grouped_order['total_amount'] ?? 0,
            'notes' => $grouped_order['notes'] ?? '',
            'dishes' => []
        ];
        
        foreach ($grouped_order['dishes'] as $dish) {
            $orderData['dishes'][] = [
                'dish_name' => $dish['dish_name'] ?? '',
                'dish_id' => $dish['dish_id'] ?? 0,
                'quantity' => $dish['quantity'] ?? 0,
                'total_amount' => $dish['total_amount'] ?? 0,
                'number_of_persons' => $dish['number_of_persons'] ?? 1,
                'ingredients' => $dish['ingredients'] ?? []
            ];
        }
        $cleanOrders[] = $orderData;
    }
    echo json_encode($cleanOrders, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (Exception $e) {
    echo '[]';
}
?>;

// Print Ingredients Function
function printIngredients(orderNumberOrId) {
    if (!ordersData || ordersData.length === 0) {
        alert('No orders data available.');
        return;
    }
    // Find by order_number or id
    const order = ordersData.find(o => o.order_number == orderNumberOrId || o.id == orderNumberOrId);
    if (!order) {
        alert('Order not found.');
        return;
    }
    
    // Get the base path for the image from PHP
    const logoPath = '<?php echo htmlspecialchars($logoPath, ENT_QUOTES); ?>';
    const basePath = window.location.pathname.includes('/admin/') || 
                     window.location.pathname.includes('/user/') || 
                     window.location.pathname.includes('/auth/') ? '../' : '';
    const cakeImagePath = basePath + 'images/cake.png';
    // Use relative path - base tag in print window will handle it
    const bannerImagePath = 'images/' + encodeURIComponent('ینگ کوکنگ وچائنیز فوڈ اسپیشلسٹ.png');
    
    // Collect all ingredients from all dishes in the order, grouped by category
    let ingredientsByCategory = {};
    let totalPersons = 0;
    
    order.dishes.forEach(function(dish) {
        const orderQuantity = parseFloat(dish.quantity) || 0;
        const ingredients = dish.ingredients || [];
        const persons = parseInt(dish.number_of_persons) || 1;
        totalPersons = Math.max(totalPersons, persons);
        
        ingredients.forEach(function(ing) {
            const key = ing.ingredient_id || ing.ingredient_name;
            const scaledQuantity = (parseFloat(ing.quantity) || 0) * orderQuantity;
            const categoryName = ing.category_name || 'بغیر زمرہ';
            const categoryId = ing.category_id || 'uncategorized';
            
            // Initialize category if not exists
            if (!ingredientsByCategory[categoryId]) {
                ingredientsByCategory[categoryId] = {
                    category_name: categoryName,
                    ingredients: {}
                };
            }
            
            // Add or update ingredient in category
            if (ingredientsByCategory[categoryId].ingredients[key]) {
                ingredientsByCategory[categoryId].ingredients[key].quantity += scaledQuantity;
            } else {
                ingredientsByCategory[categoryId].ingredients[key] = {
                    ingredient_name: ing.ingredient_name || 'N/A',
                    quantity: scaledQuantity,
                    unit: ing.unit || ''
                };
            }
        });
    });
    
    // Force RTL for Urdu
    const textAlign = 'right';
    const textAlignOpposite = 'left';
    const fontFamily = 'Arial, "Noto Sans Arabic", "Segoe UI", Tahoma, sans-serif';
    
    let ingredientsHtml = '<div style="direction: rtl;">';
    
    // Check if we have any ingredients
    const categoryKeys = Object.keys(ingredientsByCategory);
    if (categoryKeys.length === 0) {
        ingredientsHtml += '<table class="ingredients-table" style="width: 100%; border-collapse: collapse; margin-top: 12px; direction: rtl; font-size: 11px;">';
        ingredientsHtml += '<thead><tr style="background-color: #f8fafc;"><th style="padding: 6px 8px; border: 1px solid #e2e8f0; text-align: right; font-family: ' + fontFamily + '; font-size: 11px;">' + translations.ingredient_label + '</th><th style="padding: 6px 8px; border: 1px solid #e2e8f0; text-align: right; font-family: ' + fontFamily + '; font-size: 11px;">' + translations.quantity_label + ' / ' + translations.unit_label + '</th></tr></thead>';
        ingredientsHtml += '<tbody>';
        ingredientsHtml += '<tr><td colspan="2" style="padding: 5px 8px; border: 1px solid #ddd; text-align: center; font-family: ' + fontFamily + '; font-size: 11px; line-height: 1.3;">' + translations.no_ingredients_found + '</td></tr>';
        ingredientsHtml += '</tbody></table>';
    } else {
        // Organize categories and their ingredients
        const allCategories = [];
        categoryKeys.forEach(function(categoryId) {
            const category = ingredientsByCategory[categoryId];
            allCategories.push({
                id: categoryId,
                name: category.category_name || 'بغیر زمرہ',
                ingredients: Object.values(category.ingredients)
            });
        });
        
        // Sort categories by name
        allCategories.sort((a, b) => {
            return (a.name || '').localeCompare(b.name || '');
        });
        
        // Display each category with its ingredients in a simple, clear format
        allCategories.forEach(function(category) {
            // Category header - larger and more prominent
            ingredientsHtml += '<div class="category-section" style="margin-top: 20px; margin-bottom: 12px; page-break-inside: avoid;">';
            ingredientsHtml += '<h3 class="category-header" style="font-size: 16px; font-weight: bold; color: #ffffff; padding: 10px 15px; background-color: #8b5cf6; border-radius: 6px; font-family: ' + fontFamily + '; text-align: right; margin: 0 0 12px 0; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
            ingredientsHtml += '📋 ' + category.name;
            ingredientsHtml += '</h3>';
            ingredientsHtml += '</div>';
            
            // Sort ingredients within category by name
            const sortedIngredients = [...category.ingredients].sort((a, b) => {
                const nameA = a.ingredient_name || '';
                const nameB = b.ingredient_name || '';
                return nameA.localeCompare(nameB);
            });
            
            if (sortedIngredients.length > 0) {
                // Use 6-column layout to save space
                ingredientsHtml += '<div style="display: grid; grid-template-columns: repeat(6, 1fr); gap: 6px; margin-bottom: 15px; page-break-inside: avoid;">';
                
                sortedIngredients.forEach(function(ing) {
                    let quantity = parseFloat(ing.quantity) || 0;
                    let unit = ing.unit || '';
                    
                    // Convert large gram values to kg for better readability
                    if (unit.toLowerCase() === 'g' && quantity >= 1000) {
                        quantity = (quantity / 1000).toFixed(2);
                        unit = 'kg';
                    } else {
                        // Format quantity based on unit type
                        const unitLower = unit.toLowerCase();
                        // For countable items (pieces, piece, serving, servings, etc.), show as whole number
                        if (unitLower === 'piece' || unitLower === 'pieces' || 
                            unitLower === 'serving' || unitLower === 'servings' ||
                            unitLower === 'portion' || unitLower === 'portions' ||
                            unitLower === 'item' || unitLower === 'items') {
                            quantity = Math.round(quantity).toString();
                        } else {
                            // For weight/volume units, show 2 decimal places
                            quantity = quantity.toFixed(2);
                        }
                    }
                    
                    const quantityUnit = quantity + (unit ? ' ' + unit : '');
                    const ingredientName = ing.ingredient_name || 'N/A';
                    
                    // Compact format for 6-column layout: Ingredient Name - Quantity
                    ingredientsHtml += '<div style="padding: 6px 8px; border: 1px solid #e2e8f0; border-radius: 4px; background-color: #ffffff; page-break-inside: avoid; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">';
                    ingredientsHtml += '<div style="font-size: 11px; font-weight: bold; color: #1e293b; margin-bottom: 3px; font-family: ' + fontFamily + '; line-height: 1.4;">' + ingredientName + '</div>';
                    ingredientsHtml += '<div style="font-size: 10px; color: #8b5cf6; font-weight: 600; font-family: ' + fontFamily + ';">' + translations.quantity_label + ': <span style="color: #1e293b;">' + quantityUnit + '</span></div>';
                    ingredientsHtml += '</div>';
                });
                
                ingredientsHtml += '</div>';
            }
        });
    }
    
    ingredientsHtml += '</div>';
    
    const printWindow = window.open('', '_blank');
    // Get base URL for images
    const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/admin/') + 1);
    printWindow.document.write(`
        <!DOCTYPE html>
        <html dir="rtl" lang="ur">
        <head>
            <base href="${baseUrl}">
            <title>${translations.ingredients_list} - ${translations.order_id} ${order.order_number || '#' + order.id}</title>
            <meta charset="UTF-8">
            <style>
                @media print {
                    @page {
                        size: A4;
                        margin: 0.4cm 0.6cm;
                    }
                    * {
                        page-break-inside: avoid !important;
                    }
                    body { 
                        margin: 0 !important; 
                        padding: 0 !important; 
                        position: relative; 
                        font-size: 11px !important;
                        page-break-inside: avoid !important;
                    }
                    .no-print { display: none !important; }
                    .print-banner { 
                        page-break-after: avoid !important;
                        page-break-inside: avoid !important;
                        display: block !important;
                        visibility: visible !important;
                        padding: 5px 8px !important;
                        margin-bottom: 8px !important;
                        min-height: 110px !important;
                    }
                    .fillable-section {
                        page-break-after: avoid !important;
                        page-break-inside: avoid !important;
                        margin: 10px 0 !important;
                        padding: 8px !important;
                    }
                    .content-wrapper {
                        padding: 8px !important;
                        page-break-inside: avoid !important;
                    }
                    .category-section {
                        margin-top: 15px !important;
                        margin-bottom: 10px !important;
                        page-break-inside: avoid !important;
                    }
                    .category-header {
                        font-size: 14px !important;
                        padding: 8px 12px !important;
                        margin: 0 !important;
                    }
                    [style*="grid-template-columns"] {
                        display: grid !important;
                        grid-template-columns: repeat(6, 1fr) !important;
                        gap: 5px !important;
                    }
                    [style*="grid-template-columns"] > div {
                        page-break-inside: avoid !important;
                    }
                    .header {
                        margin-bottom: 8px !important;
                        page-break-after: avoid !important;
                    }
                    .header h1 {
                        font-size: 14px !important;
                        margin-bottom: 5px !important;
                    }
                    .category-section {
                        margin-top: 8px !important;
                        margin-bottom: 4px !important;
                        page-break-inside: avoid !important;
                    }
                    .category-header {
                        font-size: 11px !important;
                        padding: 4px 6px !important;
                        margin: 0 !important;
                    }
                    .ingredients-table {
                        font-size: 10px !important;
                        margin-bottom: 6px !important;
                        page-break-inside: avoid !important;
                    }
                    .ingredients-table th,
                    .ingredients-table td {
                        padding: 4px 6px !important;
                        font-size: 10px !important;
                        line-height: 1.3 !important;
                    }
                    .ingredients-table thead {
                        display: table-header-group;
                    }
                    .ingredients-table tbody {
                        display: table-row-group;
                    }
                    .footer {
                        margin-top: 8px !important;
                        font-size: 9px !important;
                        page-break-inside: avoid !important;
                    }
                    .banner-logo {
                        width: 40px !important;
                        height: 40px !important;
                    }
                    .banner-text-center h1 {
                        font-size: 11px !important;
                    }
                    .banner-text-center h2,
                    .banner-text-center h3 {
                        font-size: 9px !important;
                    }
                    .banner-order-info {
                        padding: 3px 6px !important;
                        min-width: 120px !important;
                    }
                    .banner-order-info p {
                        font-size: 8px !important;
                        margin: 1px 0 !important;
                    }
                    .banner-contact {
                        display: block !important;
                        visibility: visible !important;
                    }
                    .contact-center {
                        font-size: 8px !important;
                        padding: 3px 6px !important;
                    }
                    .contact-center span {
                        font-size: 9px !important;
                        font-weight: bold !important;
                        letter-spacing: 0.5px !important;
                        white-space: nowrap !important;
                        margin-top: 2px !important;
                    }
                    .contact-center span[dir="ltr"] {
                        display: inline !important;
                    }
                }
                body { 
                    font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif; 
                    padding: 0; 
                    margin: 0; 
                    position: relative; 
                    direction: rtl; 
                    background: #fff;
                }
                
                /* Banner Header - Using Colorful Image */
                .print-banner {
                    display: block !important;
                    width: 100%;
                    margin-bottom: 15px;
                    overflow: visible;
                    box-sizing: border-box;
                }
                .print-banner-image {
                    width: 100% !important;
                    height: auto !important;
                    display: block !important;
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    color-adjust: exact !important;
                }
                .fillable-section {
                    margin: 15px 0;
                    padding: 10px;
                    border: 2px dashed #ccc;
                    border-radius: 5px;
                }
                .fillable-field {
                    display: flex;
                    align-items: center;
                    margin-bottom: 12px;
                    direction: rtl;
                }
                .fillable-label {
                    font-weight: bold;
                    font-size: 14px;
                    color: #333;
                    min-width: 80px;
                    margin-left: 15px;
                }
                .fillable-space {
                    flex: 1;
                    border-bottom: 2px solid #000;
                    height: 25px;
                    margin: 0 10px;
                }
                
                .content-wrapper {
                    padding: 12px;
                }
                .category-section {
                    margin-top: 20px;
                    margin-bottom: 12px;
                }
                .category-header {
                    font-size: 16px;
                    font-weight: bold;
                    color: #ffffff;
                    padding: 10px 15px;
                    background-color: #8b5cf6;
                    border-radius: 6px;
                    margin: 0 0 12px 0;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                .header { text-align: center; margin-bottom: 15px; position: relative; z-index: 1; }
                .header h1 { margin: 0; color: #1e293b; font-size: 18px; font-weight: bold; }
                .header p { margin: 4px 0; color: #64748b; font-size: 12px; }
                .ingredients-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 10px;
                    font-size: 12px;
                }
                .ingredients-table th,
                .ingredients-table td {
                    padding: 6px 10px;
                    border: 1px solid #e2e8f0;
                    font-size: 12px;
                    line-height: 1.5;
                }
                .ingredients-table th {
                    background-color: #f8fafc;
                    font-weight: bold;
                }
                .info-section { margin-bottom: 12px; position: relative; z-index: 1; background: #f8f9fa; padding: 10px; border-radius: 5px; }
                .info-section p { margin: 3px 0; font-size: 11px; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; position: relative; z-index: 1; }
                th, td { padding: 6px 8px; border: 1px solid #e2e8f0; font-size: 11px; }
                th { background-color: #f8fafc; font-weight: bold; }
                .footer { margin-top: 15px; text-align: center; color: #64748b; font-size: 12px; position: relative; z-index: 1; }
                .print-btn { margin: 15px 0; text-align: center; }
                button { padding: 8px 16px; background: #8b5cf6; color: white; border: none; cursor: pointer; border-radius: 5px; font-size: 12px; }
                button:hover { background: #7c3aed; }
            </style>
        </head>
        <body>
            <!-- Print Banner -->
            <div class="print-banner">
                <img src="${bannerImagePath}" alt="Advertisement Banner" class="print-banner-image" style="width: 100%; height: auto; display: block; -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact;" onerror="console.error('Failed to load banner image:', this.src);">
            </div>
            
            <!-- Fillable Fields Section -->
            <div class="fillable-section">
                <div class="fillable-field">
                    <span class="fillable-label">تاریخ:</span>
                    <div class="fillable-space" style="text-align: center; font-weight: bold;">${order.order_date ? formatDateForPrint(order.order_date) : formatDateForPrint(new Date().toISOString())}</div>
                </div>
                <div class="fillable-field">
                    <span class="fillable-label">وقت:</span>
                    <div class="fillable-space" style="text-align: center; font-weight: bold;">${order.order_date ? formatTimeForPrint(order.order_date) : formatTimeForPrint(new Date().toISOString())}</div>
                </div>
                <div class="fillable-field">
                    <span class="fillable-label">${translations.number_of_persons}:</span>
                    <div class="fillable-space" style="text-align: center; font-weight: bold;">${totalPersons > 0 ? totalPersons : ''}</div>
                </div>
            </div>
            
            <div class="content-wrapper">
                <div class="header">
                    <h1 style="font-size: 16px; font-weight: bold; color: #1e293b; text-align: center; margin-bottom: 12px;">${translations.ingredients_list}</h1>
                </div>
                ${ingredientsHtml}
                <div class="footer">
                    <p style="text-align: center; color: #64748b; font-size: 10px; margin-top: 12px;">${translations.printed_on}: ${new Date().toLocaleDateString('ur-PK')} ${new Date().toLocaleTimeString('ur-PK')}</p>
                </div>
                <div class="print-btn no-print">
                    <button onclick="window.print()">${translations.print}</button>
                    <button onclick="window.close()">${translations.close}</button>
                </div>
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
    setTimeout(() => printWindow.print(), 250);
}

// Print Order Function
function printOrder(orderNumberOrId) {
    if (!ordersData || ordersData.length === 0) {
        alert('No orders data available.');
        return;
    }
    // Find by order_number or id
    const order = ordersData.find(o => o.order_number == orderNumberOrId || o.id == orderNumberOrId);
    if (!order) {
        alert('Order not found.');
        return;
    }
    
    // Get the base path for the image from PHP
    const logoPath = '<?php echo htmlspecialchars($logoPath, ENT_QUOTES); ?>';
    const basePath = window.location.pathname.includes('/admin/') || 
                     window.location.pathname.includes('/user/') || 
                     window.location.pathname.includes('/auth/') ? '../' : '';
    const cakeImagePath = basePath + 'images/cake.png';
    // Use relative path - base tag in print window will handle it
    const bannerImagePath = 'images/' + encodeURIComponent('ینگ کوکنگ وچائنیز فوڈ اسپیشلسٹ.png');
    
    // Get status translation
    const statusTranslations = <?php echo json_encode([
        'pending' => t('pending'),
        'confirmed' => t('confirmed'),
        'preparing' => t('preparing'),
        'ready' => t('ready'),
        'delivered' => t('delivered'),
        'cancelled' => t('cancelled')
    ]); ?>;
    
    // Determine text alignment and font based on language direction
    const textAlign = langDir === 'rtl' ? 'right' : 'left';
    const textAlignOpposite = langDir === 'rtl' ? 'left' : 'right';
    const fontFamily = langDir === 'rtl' ? 'Arial, "Noto Sans Arabic", "Segoe UI", Tahoma, sans-serif' : 'Arial, sans-serif';
    const orderStatus = statusTranslations[order.status] || order.status;
    
    // Calculate total persons for the order
    let totalPersons = 0;
    order.dishes.forEach(function(dish) {
        const persons = parseInt(dish.number_of_persons) || 1;
        totalPersons = Math.max(totalPersons, persons);
    });
    
    const printWindow = window.open('', '_blank');
    // Get base URL for images
    const baseUrl = window.location.origin + window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/admin/') + 1);
    printWindow.document.write(`
        <!DOCTYPE html>
        <html dir="${langDir}">
        <head>
            <base href="${baseUrl}">
            <title>${translations.order_receipt} - ${translations.order_id} ${order.order_number || '#' + order.id}</title>
            <meta charset="UTF-8">
            <style>
                @media print {
                    @page {
                        size: A4;
                        margin: 0.5cm;
                    }
                    body { margin: 0; padding: 10px; position: relative; font-size: 12px !important; }
                    .no-print { display: none; }
                    .print-banner { min-height: 100px !important; margin-bottom: 10px !important; }
                    .banner-left-name { font-size: 16px !important; margin-bottom: 4px !important; }
                    .banner-left-phone { font-size: 11px !important; }
                    .banner-right-service { font-size: 13px !important; }
                    .banner-right-service.yellow { font-size: 14px !important; }
                    .banner-address-bar { padding: 6px 10px !important; margin-top: 6px !important; }
                    .banner-address-text { font-size: 10px !important; }
                    .fillable-section { margin: 10px 0 !important; padding: 8px !important; }
                    .fillable-field { margin-bottom: 8px !important; }
                    .fillable-label { font-size: 13px !important; }
                    .fillable-space { height: 22px !important; }
                    .header { margin-bottom: 10px !important; padding-bottom: 8px !important; }
                    .header h1 { font-size: 18px !important; }
                    .header p { font-size: 12px !important; margin: 3px 0 !important; }
                    .order-info { margin: 10px 0 !important; }
                    .order-info p { margin: 5px 0 !important; font-size: 11px !important; }
                    .order-details { padding: 12px !important; margin: 10px 0 !important; }
                    .order-details h3 { font-size: 14px !important; margin-top: 0 !important; }
                    .detail-row { margin: 6px 0 !important; padding: 5px 0 !important; font-size: 11px !important; }
                    .total-section { margin-top: 12px !important; padding-top: 12px !important; }
                    .total-row { font-size: 16px !important; margin: 8px 0 !important; }
                    .notes { margin-top: 10px !important; padding: 8px !important; font-size: 11px !important; }
                    .footer { margin-top: 12px !important; padding-top: 10px !important; font-size: 11px !important; }
                }
                body { font-family: ${fontFamily}; padding: 12px; max-width: 600px; margin: 0 auto; position: relative; direction: ${langDir}; font-size: 12px; }
                
                /* Banner Header - Visible and readable */
                .print-banner {
                    display: flex !important;
                    width: 100%;
                    margin-bottom: 12px;
                    border: 2px solid #000;
                    overflow: visible;
                    box-sizing: border-box;
                    min-height: 110px;
                    background: white;
                }
                .banner-left {
                    width: 25%;
                    background: white;
                    padding: 10px 8px;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    align-items: center;
                    box-sizing: border-box;
                    border-right: 2px solid #000;
                }
                .banner-left-name {
                    font-size: 18px;
                    font-weight: 900;
                    color: #000;
                    margin-bottom: 6px;
                    text-align: center;
                    font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif;
                    line-height: 1.4;
                }
                .banner-left-phone {
                    font-size: 12px;
                    color: #000;
                    margin: 2px 0;
                    text-align: center;
                    direction: ltr;
                    font-weight: bold;
                    font-family: Arial, sans-serif;
                }
                .banner-right {
                    width: 75%;
                    background: white;
                    padding: 10px 15px;
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                    align-items: center;
                    position: relative;
                    box-sizing: border-box;
                }
                .banner-right-service {
                    color: #666;
                    font-size: 14px;
                    font-weight: 700;
                    margin: 3px 0;
                    text-align: center;
                    font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif;
                    line-height: 1.4;
                    text-shadow: 1px 1px 1px rgba(0,0,0,0.1);
                }
                .banner-right-service.yellow {
                    color: #FFD700;
                    font-size: 16px;
                    font-weight: 900;
                    text-shadow: 1px 1px 2px rgba(0,0,0,0.2);
                }
                .banner-address-bar {
                    background: white;
                    padding: 8px 12px;
                    border: 1px solid #ccc;
                    border-radius: 5px;
                    margin-top: 8px;
                    width: calc(100% - 70px);
                    text-align: center;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                }
                .banner-address-text {
                    color: #666;
                    font-size: 11px;
                    font-weight: 600;
                    line-height: 1.4;
                    font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif;
                }
                .banner-dessert-graphic {
                    position: absolute;
                    right: 15px;
                    top: 50%;
                    transform: translateY(-50%);
                    width: 60px;
                    height: 60px;
                    background: white;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                    border: 2px solid #ccc;
                    z-index: 10;
                    overflow: hidden;
                }
                .banner-dessert-graphic img {
                    width: 55px;
                    height: 55px;
                    object-fit: contain;
                    border-radius: 50%;
                }
                .fillable-section {
                    margin: 12px 0;
                    padding: 10px;
                    border: 2px dashed #ccc;
                    border-radius: 5px;
                }
                .fillable-field {
                    display: flex;
                    align-items: center;
                    margin-bottom: 10px;
                    direction: rtl;
                }
                .fillable-label {
                    font-weight: bold;
                    font-size: 14px;
                    color: #333;
                    min-width: 80px;
                    margin-left: 15px;
                }
                .fillable-space {
                    flex: 1;
                    border-bottom: 2px solid #000;
                    height: 24px;
                    margin: 0 10px;
                }
                
                .header { text-align: center; margin-bottom: 15px; border-bottom: 2px solid #1e293b; padding-bottom: 12px; position: relative; z-index: 1; }
                .header h1 { margin: 0; color: #1e293b; font-size: 20px; }
                .header p { margin: 4px 0; color: #64748b; font-size: 14px; }
                .order-info { margin: 12px 0; position: relative; z-index: 1; }
                .order-info p { margin: 6px 0; font-size: 12px; }
                .order-details { background: #f8fafc; padding: 15px; border-radius: 5px; margin: 12px 0; position: relative; z-index: 1; }
                .order-details h3 { margin-top: 0; font-size: 16px; }
                .detail-row { display: flex; justify-content: space-between; margin: 8px 0; padding: 6px 0; border-bottom: 1px solid #e2e8f0; flex-direction: ${langDir === 'rtl' ? 'row-reverse' : 'row'}; font-size: 12px; }
                .detail-row:last-child { border-bottom: none; }
                .detail-label { font-weight: bold; }
                ${langDir === 'rtl' ? '.notes { border-left: none; border-right: 4px solid #f59e0b; }' : ''}
                .total-section { margin-top: 15px; padding-top: 15px; border-top: 2px solid #1e293b; position: relative; z-index: 1; }
                .total-row { display: flex; justify-content: space-between; font-size: 18px; font-weight: bold; margin: 10px 0; flex-direction: ${langDir === 'rtl' ? 'row-reverse' : 'row'}; }
                .status-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-weight: bold; margin-top: 8px; font-size: 11px; }
                .status-pending { background: #f59e0b; color: #fff; }
                .status-confirmed { background: #f97316; color: #fff; }
                .status-preparing { background: #64748b; color: #fff; }
                .status-ready { background: #10b981; color: #fff; }
                .status-delivered { background: #10b981; color: #fff; }
                .status-cancelled { background: #ef4444; color: #fff; }
                .footer { margin-top: 15px; text-align: center; color: #64748b; font-size: 12px; border-top: 1px solid #e2e8f0; padding-top: 12px; position: relative; z-index: 1; }
                .print-btn { margin: 20px 0; text-align: center; }
                button { padding: 10px 20px; background: #8b5cf6; color: white; border: none; cursor: pointer; border-radius: 5px; margin: 0 5px; font-size: 13px; }
                button:hover { background: #7c3aed; }
                .notes { margin-top: 12px; padding: 10px; background: #fef3c7; border-left: 4px solid #f59e0b; font-size: 12px; }
            </style>
        </head>
        <body>
            <!-- Print Banner -->
            <div class="print-banner">
                <img src="${bannerImagePath}" alt="Advertisement Banner" class="print-banner-image" style="width: 100%; height: auto; display: block; -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact;" onerror="console.error('Failed to load banner image:', this.src);">
            </div>
            
            <!-- Fillable Fields Section -->
            <div class="fillable-section">
                <div class="fillable-field">
                    <span class="fillable-label">تاریخ:</span>
                    <div class="fillable-space" style="text-align: center; font-weight: bold;">${order.order_date ? formatDateForPrint(order.order_date) : formatDateForPrint(new Date().toISOString())}</div>
                </div>
                <div class="fillable-field">
                    <span class="fillable-label">وقت:</span>
                    <div class="fillable-space" style="text-align: center; font-weight: bold;">${order.order_date ? formatTimeForPrint(order.order_date) : formatTimeForPrint(new Date().toISOString())}</div>
                </div>
                <div class="fillable-field">
                    <span class="fillable-label">${translations.number_of_persons}:</span>
                    <div class="fillable-space" style="text-align: center; font-weight: bold;">${totalPersons > 0 ? totalPersons : ''}</div>
                </div>
            </div>
            
            <div class="header">
                <h1>${translations.brand_name}</h1>
                <p>${translations.order_receipt}</p>
            </div>
            <div class="order-info">
                <p><strong>${translations.order_id}:</strong> ${order.order_number || '#' + order.id}</p>
                <p><strong>${translations.order_date}:</strong> ${order.order_date ? formatDateTimeForPrint(order.order_date) : new Date().toLocaleString()}</p>
                <p><strong>${translations.status}:</strong> <span class="status-badge status-${order.status}">${orderStatus}</span></p>
            </div>
            <div class="order-details">
                <h3>${translations.order_details}</h3>
                <div class="detail-row">
                    <span class="detail-label">${translations.customer}:</span>
                    <span>${order.customer_name || 'N/A'}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">${translations.email}:</span>
                    <span>${order.customer_email || 'N/A'}</span>
                </div>
                <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0;">
                    <strong style="display: block; margin-bottom: 8px; font-size: 14px;">${translations.dish}:</strong>
                    ${order.dishes.map(dish => {
                        const persons = parseInt(dish.number_of_persons) || 1;
                        return `
                        <div class="detail-row" style="margin-left: 15px;">
                            <div style="flex: 1;">
                                <span class="detail-label">${dish.dish_name || 'N/A'}</span>
                                <span style="margin-left: 12px; color: #64748b; font-size: 11px;">(${translations.quantity}: ${dish.quantity} - ${translations.persons}: ${persons}${dish.total_amount > 0 ? ' - Rs ' + parseFloat(dish.total_amount).toFixed(2) : ''})</span>
                            </div>
                        </div>
                    `;
                    }).join('')}
                </div>
                ${order.notes ? `<div class="notes"><strong>${translations.notes}:</strong> ${order.notes}</div>` : ''}
            </div>
            <div class="total-section">
                <div class="total-row">
                    <span>${translations.total_amount}:</span>
                    <span>Rs ${parseFloat(order.total_amount).toFixed(2)}</span>
                </div>
            </div>
            <div class="footer">
                <p>${translations.thank_you}</p>
                <p>${translations.printed_on}: ${new Date().toLocaleString()}</p>
            </div>
            <div class="print-btn no-print">
                <button onclick="window.print()">${translations.print}</button>
                <button onclick="window.close()">${translations.close}</button>
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
    setTimeout(() => printWindow.print(), 250);
}

// Helper functions to format date and time for print
function formatDateForPrint(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${day}/${month}/${year}`;
}

function formatTimeForPrint(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${hours}:${minutes}`;
}

function formatDateTimeForPrint(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleString('en-GB', { 
        day: '2-digit', 
        month: '2-digit', 
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
    });
}

// Dish Selection Modal Functions
let dishSelectionModal = null;
let currentCategoryFilter = 'all';
let currentDishRowIndex = 0;

// Open dish selection modal
function openDishSelectionModal(rowIndex) {
    currentDishRowIndex = rowIndex || 0;
    
    if (!dishSelectionModal) {
        const modalElement = document.getElementById('dishSelectionModal');
        if (modalElement) {
            dishSelectionModal = new bootstrap.Modal(modalElement);
        }
    }
    if (dishSelectionModal) {
        dishSelectionModal.show();
        // Reset filters
        const searchInput = document.getElementById('dishSearchInput');
        if (searchInput) searchInput.value = '';
        filterByCategory('all');
    }
}

// Filter dishes in modal by search term
function filterDishesInModal(searchTerm) {
    const searchLower = searchTerm.toLowerCase().trim();
    const dishItems = document.querySelectorAll('.modal-dish-item');
    let visibleCount = 0;
    
    dishItems.forEach(item => {
        const dishName = item.getAttribute('data-dish-name').toLowerCase();
        const category = item.getAttribute('data-category').toLowerCase();
        const matchesSearch = !searchTerm || dishName.includes(searchLower) || category.includes(searchLower);
        const matchesCategory = currentCategoryFilter === 'all' || item.getAttribute('data-category') === currentCategoryFilter;
        
        if (matchesSearch && matchesCategory) {
            item.classList.remove('hidden');
            visibleCount++;
        } else {
            item.classList.add('hidden');
        }
    });
    
    // Show/hide no results message
    const noResults = document.getElementById('noDishesFound');
    if (noResults) {
        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

// Filter by category
function filterByCategory(category) {
    currentCategoryFilter = category;
    
    // Update active button
    document.querySelectorAll('.category-filter').forEach(btn => {
        btn.classList.remove('active');
        if (btn.getAttribute('data-category') === category) {
            btn.classList.add('active');
        }
    });
    
    // Apply filters
    const searchTerm = document.getElementById('dishSearchInput')?.value || '';
    filterDishesInModal(searchTerm);
}

// Clear search
function clearDishSearch() {
    const searchInput = document.getElementById('dishSearchInput');
    if (searchInput) {
        searchInput.value = '';
        filterDishesInModal('');
    }
}

// Select dish from modal
function selectDishFromModal(dishId, dishName) {
    // Find the select field for the current row
    const row = document.querySelector(`.dish-row[data-row="${currentDishRowIndex}"]`);
    if (row) {
        const select = row.querySelector('.dish-select');
        if (select) {
            select.value = dishId;
            // Trigger change event to update any dependent fields
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
    
    // Close modal
    if (dishSelectionModal) {
        dishSelectionModal.hide();
    }
    
    // Update review if on step 3
    if (typeof updateReview === 'function' && typeof currentStep !== 'undefined' && currentStep === 3) {
        updateReview();
    }
}

// Add new dish row function (called from button)
function addNewDishRow() {
    const addDishBtn = document.getElementById('addDishBtn');
    if (addDishBtn) {
        addDishBtn.click();
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>



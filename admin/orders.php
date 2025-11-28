<?php
/**
 * Orders Management Page
 * View and manage customer orders
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

// Allow both admin and regular users to access orders page
requireLogin();

$conn = getDBConnection();
$error = '';
$success = '';

// Set execution time limit for Render (30 seconds)
@set_time_limit(30);

// Wrap schema modifications in try-catch to prevent crashes
// Cache schema check to avoid running on every request
static $schema_checked = false;
if (!$schema_checked) {
    $schema_checked = true;
    try {
        // Check and add order_number column if it doesn't exist
        $result = @$conn->query("SHOW COLUMNS FROM `orders` LIKE 'order_number'");
        if ($result && $result->num_rows == 0) {
        @$conn->query("ALTER TABLE `orders` ADD COLUMN `order_number` VARCHAR(50) DEFAULT NULL AFTER `id`");
        @$conn->query("ALTER TABLE `orders` ADD INDEX `idx_order_number` (`order_number`)");
        // Generate order numbers for existing orders (limit to prevent timeout)
        @$conn->query("UPDATE `orders` SET `order_number` = CONCAT('ORD-', LPAD(`id`, 6, '0')) WHERE `order_number` IS NULL LIMIT 1000");
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
        $result = @$conn->query("SHOW COLUMNS FROM `orders` LIKE '$column'");
        if ($result && $result->num_rows == 0) {
            @$conn->query("ALTER TABLE `orders` ADD COLUMN `$column` $definition");
        }
    }
    
    // Check if order_date column exists, if not add it
    $check_order_date = @$conn->query("SHOW COLUMNS FROM `orders` LIKE 'order_date'");
    if ($check_order_date && $check_order_date->num_rows == 0) {
        @$conn->query("ALTER TABLE `orders` ADD COLUMN `order_date` DATETIME DEFAULT NULL AFTER `customer_cell`");
    }
    
    // Check if extra_ingredients column exists, if not add it
    $check_extra_ingredients = @$conn->query("SHOW COLUMNS FROM `orders` LIKE 'extra_ingredients'");
    if ($check_extra_ingredients && $check_extra_ingredients->num_rows == 0) {
        @$conn->query("ALTER TABLE `orders` ADD COLUMN `extra_ingredients` TEXT DEFAULT NULL AFTER `notes`");
    }
    
    // Check if unit column exists, if not add it
    $check_unit = @$conn->query("SHOW COLUMNS FROM `orders` LIKE 'unit'");
    if ($check_unit && $check_unit->num_rows == 0) {
        @$conn->query("ALTER TABLE `orders` ADD COLUMN `unit` VARCHAR(50) DEFAULT NULL AFTER `quantity`");
    }
    
    // Make customer_id nullable to support orders without registered customers
    $check_customer_id = @$conn->query("SHOW COLUMNS FROM `orders` LIKE 'customer_id'");
    if ($check_customer_id && $check_customer_id->num_rows > 0) {
        $column_info = $check_customer_id->fetch_assoc();
        if (isset($column_info['Null']) && $column_info['Null'] === 'NO') {
            // Try to drop the foreign key constraint first, then modify the column
            try {
                @$conn->query("ALTER TABLE `orders` DROP FOREIGN KEY `orders_ibfk_1`");
            } catch (Exception $e) {
                // Constraint might not exist or have different name, try alternative
                try {
                    $fk_result = @$conn->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' 
                        AND COLUMN_NAME = 'customer_id' AND REFERENCED_TABLE_NAME IS NOT NULL
                        LIMIT 1");
                    if ($fk_result && $fk_result->num_rows > 0) {
                        $fk_row = $fk_result->fetch_assoc();
                        $fk_name = $fk_row['CONSTRAINT_NAME'];
                        @$conn->query("ALTER TABLE `orders` DROP FOREIGN KEY `$fk_name`");
                    }
                } catch (Exception $e2) {
                    // Ignore errors
                }
            }
            // Make column nullable
            try {
                @$conn->query("ALTER TABLE `orders` MODIFY `customer_id` INT(11) NULL");
                // Re-add foreign key constraint with ON DELETE SET NULL
                try {
                    @$conn->query("ALTER TABLE `orders` ADD CONSTRAINT `orders_ibfk_1` 
                        FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE SET NULL");
                } catch (Exception $e) {
                    // If it fails, we'll continue without the constraint for now
                }
            } catch (Exception $e) {
                // Ignore errors
            }
        }
    }
    } catch (Exception $e) {
        // Log error but don't crash the page
        error_log("Schema modification error: " . $e->getMessage());
    }
}

// Handle update order (All users can update orders)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    // All logged-in users can update orders
    {
        // Set execution time limit for order update
        @set_time_limit(60);
        
        // Check database connection
        if (!$conn || $conn->connect_error) {
            $error = 'Database connection failed. Please try again.';
            error_log("Database connection error: " . ($conn ? $conn->connect_error : 'Connection is null'));
        } else {
            $order_number = trim($_POST['order_number'] ?? '');
            
            if (empty($order_number)) {
                $error = 'Order number is required for update.';
            } else {
                $customer_id = intval($_POST['customer_id'] ?? 0);
                $customer_name = trim($_POST['customer_name'] ?? '');
                $customer_cell = trim($_POST['customer_cell'] ?? '');
                $number_of_persons = intval($_POST['number_of_persons'] ?? 0);
                $shift = trim($_POST['shift'] ?? '');
                $delivery_date = trim($_POST['delivery_date'] ?? '');
                $delivery_time = trim($_POST['delivery_time'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                
                // Translate notes to Urdu if current language is Urdu
                $notes = translateForDatabase($notes);
                
                // Get dishes array
                $dishes_data = [];
                if (isset($_POST['dishes']) && is_array($_POST['dishes'])) {
                    $dishes_data = $_POST['dishes'];
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
                
                // Get additional items
                $additional_items_data = [];
                if (isset($_POST['additional_items']) && is_array($_POST['additional_items'])) {
                    foreach ($_POST['additional_items'] as $item_key => $quantity) {
                        $quantity = intval($quantity);
                        if ($quantity > 0) {
                            $additional_items_data[$item_key] = $quantity;
                        }
                    }
                }
                
                // Validation
                $use_new_form = !empty($customer_name) || !empty($customer_cell);
                
                if ($use_new_form) {
                    if (empty($customer_cell) || 
                        $number_of_persons <= 0 || empty($shift) || empty($delivery_date) || empty($delivery_time)) {
                        $error = 'Please fill all required fields in Step 1.';
                    } elseif (empty($dishes_data)) {
                        $error = 'Please select at least one dish in Step 2.';
                    }
                } else {
                    if ($customer_id <= 0 || empty($dishes_data)) {
                        $error = t('fill_all_required_fields');
                    }
                }
                
                if (empty($error)) {
                    // Prepare extra ingredients JSON
                    $combined_data = [];
                    if (!empty($extra_ingredients_data)) {
                        $combined_data['extra_ingredients'] = $extra_ingredients_data;
                    }
                    if (!empty($additional_items_data)) {
                        $combined_data['additional_items'] = $additional_items_data;
                    }
                    $extra_ingredients_json = !empty($combined_data) ? json_encode($combined_data) : null;
                    
                    // Start transaction
                    $conn->begin_transaction();
                    
                    try {
                        // Delete existing orders with this order_number
                        $delete_stmt = $conn->prepare("DELETE FROM orders WHERE order_number = ?");
                        $delete_stmt->bind_param("s", $order_number);
                        $delete_stmt->execute();
                        $delete_stmt->close();
                        
                        // Insert updated orders
                        $orders_created = 0;
                        $errors = [];
                        
                        foreach ($dishes_data as $dish_data) {
                            $dish_id = intval($dish_data['dish_id'] ?? 0);
                            $quantity = floatval($dish_data['quantity'] ?? 0);
                            $unit = !empty($dish_data['unit']) ? trim($dish_data['unit']) : null;
                            $unit_price = !empty($dish_data['unit_price']) ? floatval($dish_data['unit_price']) : null;
                            $total_amount_input = !empty($dish_data['total_amount']) ? floatval($dish_data['total_amount']) : null;
                            
                            if ($dish_id <= 0 || $quantity <= 0) {
                                continue;
                            }
                            
                            // Calculate total_amount
                            if ($total_amount_input !== null && $total_amount_input >= 0) {
                                $total_amount = $total_amount_input;
                            } elseif ($unit_price !== null && $unit_price > 0) {
                                $total_amount = $quantity * $unit_price;
                            } else {
                                $total_amount = 0;
                            }
                            
                            // Use current datetime for order_date
                            $order_datetime = date('Y-m-d H:i:s');
                            
                            if ($use_new_form) {
                                $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_id, dish_id, quantity, unit, total_amount, status, 
                                    customer_name, customer_cell, order_date, delivery_date, delivery_time, shift, number_of_persons, notes, extra_ingredients) 
                                    VALUES (?, NULL, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                if ($stmt) {
                                    $stmt->bind_param("siisdsssssssiss", 
                                        $order_number, 
                                        $dish_id, 
                                        $quantity,
                                        $unit,
                                        $total_amount, 
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
                                $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_id, dish_id, quantity, unit, total_amount, status, notes, extra_ingredients) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
                                if ($stmt) {
                                    $stmt->bind_param("siisdsss", $order_number, $customer_id, $dish_id, $quantity, $unit, $total_amount, $notes, $extra_ingredients_json);
                                }
                            }
                            
                            if ($stmt) {
                                if ($stmt->execute()) {
                                    $orders_created++;
                                } else {
                                    $errors[] = 'Failed to update order for dish ID ' . $dish_id . ': ' . $stmt->error;
                                }
                                $stmt->close();
                            }
                        }
                        
                        if ($orders_created > 0 && empty($errors)) {
                            $conn->commit();
                            $dish_count = count($dishes_data);
                            $success = $dish_count > 1 ? "Order #{$order_number} updated successfully with {$dish_count} dishes!" : 'Order updated successfully!';
                            header('Location: orders.php?success=1&updated=1&order_number=' . urlencode($order_number));
                            exit();
                        } else {
                            $conn->rollback();
                            $error = !empty($errors) ? implode(', ', $errors) : 'Failed to update order.';
                        }
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error = 'Error updating order: ' . $e->getMessage();
                        error_log("Order update exception: " . $e->getMessage());
                    }
                }
            }
        }
    }
}

// Handle create order (All users can create orders)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    // All logged-in users can create orders
    {
        // Set execution time limit for order creation
        @set_time_limit(60);
        
        // Check database connection
        if (!$conn || $conn->connect_error) {
            $error = 'Database connection failed. Please try again.';
            error_log("Database connection error: " . ($conn ? $conn->connect_error : 'Connection is null'));
        } else {
            $customer_id = intval($_POST['customer_id'] ?? 0);
            $customer_name = trim($_POST['customer_name'] ?? '');
            $customer_cell = trim($_POST['customer_cell'] ?? '');
            $number_of_persons = intval($_POST['number_of_persons'] ?? 0);
            $shift = trim($_POST['shift'] ?? '');
            $delivery_date = trim($_POST['delivery_date'] ?? '');
            $delivery_time = trim($_POST['delivery_time'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            
            // Translate notes to Urdu if current language is Urdu
            $notes = translateForDatabase($notes);
            
            // Use current datetime for order_date
            $order_datetime = date('Y-m-d H:i:s');
            
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
            
            // Get additional items
            $additional_items_data = [];
            if (isset($_POST['additional_items']) && is_array($_POST['additional_items'])) {
                foreach ($_POST['additional_items'] as $item_key => $quantity) {
                    $quantity = intval($quantity);
                    if ($quantity > 0) {
                        $additional_items_data[$item_key] = $quantity;
                    }
                }
            }
            
            // Validation - check if using new form fields or old customer selection
            $use_new_form = !empty($customer_name) || !empty($customer_cell);
            
            if ($use_new_form) {
                // New form validation
                if (empty($customer_cell) || 
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
                    $unit = !empty($dish_data['unit']) ? trim($dish_data['unit']) : null;
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
                        'unit' => $unit,
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
                        // Include both extra ingredients and additional items
                        $combined_data = [];
                        if (!empty($extra_ingredients_data)) {
                            $combined_data['extra_ingredients'] = $extra_ingredients_data;
                        }
                        if (!empty($additional_items_data)) {
                            $combined_data['additional_items'] = $additional_items_data;
                        }
                        $extra_ingredients_json = !empty($combined_data) ? json_encode($combined_data) : null;
                        
                        if ($use_new_form) {
                            // New form with all required fields - use NULL for customer_id since customer info is in separate fields
                            // mysqli bind_param doesn't handle NULL well with 'i' type, so we'll use a workaround
                            $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_id, dish_id, quantity, unit, total_amount, status, 
                                customer_name, customer_cell, order_date, delivery_date, delivery_time, shift, number_of_persons, notes, extra_ingredients) 
                                VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            if ($stmt) {
                                // Extract values to variables for bind_param (must be variables, not expressions)
                                $dish_id = $dish_info['dish_id'];
                                $quantity = $dish_info['quantity'];
                                $unit = $dish_info['unit'] ?? null;
                                $total_amount = $dish_info['total_amount'];
                                
                                // Skip customer_id in bind_param since we're using NULL directly in the query
                                $stmt->bind_param("siisdsssssssiss", 
                                    $order_number, 
                                    $dish_id, 
                                    $quantity,
                                    $unit,
                                    $total_amount, 
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
                            $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_id, dish_id, quantity, unit, total_amount, status, notes, extra_ingredients) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            if ($stmt) {
                                // Extract values to variables for bind_param (must be variables, not expressions)
                                $dish_id = $dish_info['dish_id'];
                                $quantity = $dish_info['quantity'];
                                $unit = $dish_info['unit'] ?? null;
                                $total_amount = $dish_info['total_amount'];
                                
                                $stmt->bind_param("siisdsss", $order_number, $customer_id, $dish_id, $quantity, $unit, $total_amount, $status, $notes, $extra_ingredients_json);
                            }
                        }
                        
                        if ($stmt) {
                            try {
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
                            } catch (Exception $e) {
                                $errors[] = 'Exception creating order for dish ID ' . $dish_info['dish_id'] . ': ' . $e->getMessage();
                                error_log("Order creation exception: " . $e->getMessage());
                            }
                            $stmt->close();
                        } else {
                            $errors[] = 'Failed to prepare insert query for dish ID ' . $dish_info['dish_id'] . ': ' . ($conn->error ?? 'Unknown error');
                            error_log("Prepare error: " . ($conn->error ?? 'Unknown error'));
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
                } // End of empty($valid_dishes) else block
            } // End of empty($error) check
        } // End of database connection else block
    } // End of order creation block
}

// Handle order status update - update all items in the same order (All users can update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    // All logged-in users can update order status
    {
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
            } else {
                $error = 'Invalid order or status.';
            }
        } // End of in_array check
    } // End of status update block
}

// Handle delete order - delete all items in the same order (All users can delete)
if (isset($_GET['delete'])) {
    // All logged-in users can delete orders
    {
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
    } // End of delete order block
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

// Get all categories for dish selection modal
$dish_categories = [];
$cat_result = $conn->query("SELECT DISTINCT c.id, c.name, c.description 
    FROM categories c 
    INNER JOIN dishes d ON d.category_id = c.id 
    ORDER BY c.name");
if ($cat_result && $cat_result->num_rows > 0) {
    $dish_categories = $cat_result->fetch_all(MYSQLI_ASSOC);
}

// Get all dishes for dropdown with images and categories
$dishes = [];
$result = $conn->query("SELECT d.id, d.name, d.image, d.base_unit, d.category_id, c.name as category_name 
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
$ingredients_result = $conn->query("SELECT i.id, i.name, i.unit, i.category_id, c.name as category_name 
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

// Pagination & view settings
$default_items_per_page = 12; // Number of orders per page when viewing all
$recent_items_limit = 2; // Number of orders to show on the main view
$items_per_page = $default_items_per_page;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$view_mode = (isset($_GET['view']) && $_GET['view'] === 'all') ? 'all' : 'recent';
$order_number_search = isset($_GET['order_number']) ? trim($_GET['order_number']) : '';
if (strlen($order_number_search) > 50) {
    $order_number_search = substr($order_number_search, 0, 50);
}
$is_search_active = $order_number_search !== '';

// Get all orders grouped by order_number
$orders = [];
// Use COALESCE to get customer_name from orders table first, then fallback to users table
// Also explicitly select o.customer_name and o.customer_cell to ensure they're available
// Show all orders - removed WHERE clause to ensure all orders are displayed
// Simplified query to ensure it works
$query = "SELECT o.id, o.order_number, o.customer_id, o.dish_id, o.quantity, o.unit, o.total_amount, 
    o.status, o.notes, o.extra_ingredients, o.customer_name, o.customer_cell, o.order_date, o.delivery_date, 
    o.delivery_time, o.shift, o.number_of_persons,
    o.customer_name as order_customer_name,
    o.customer_cell as order_customer_cell,
    u.name as user_customer_name,
    u.email as user_customer_email,
    COALESCE(u.name, o.customer_name) as customer_name, 
    COALESCE(u.email, o.customer_cell) as customer_email, 
    d.name as dish_name, d.id as dish_id, d.number_of_persons as dish_number_of_persons, d.category_id,
    cat.name as dish_category_name
    FROM orders o
    LEFT JOIN users u ON o.customer_id = u.id
    LEFT JOIN dishes d ON o.dish_id = d.id
    LEFT JOIN categories cat ON d.category_id = cat.id";
    
    // Add search filter if active
    if ($is_search_active && !empty($order_number_search)) {
        $escaped_search = $conn->real_escape_string($order_number_search);
        $query .= " WHERE o.order_number LIKE '%$escaped_search%'";
    }
    
    $query .= " ORDER BY 
        COALESCE(o.order_date, NOW()) DESC, 
        COALESCE(o.order_number, ''), 
        o.id DESC";
    
    // Add LIMIT for performance - load more than needed to account for grouping
    // Load 3x the items per page to ensure we have enough after grouping
    $limit = ($view_mode === 'all') ? ($items_per_page * 3) : ($recent_items_limit * 3);
    $query .= " LIMIT $limit";

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
$all_grouped_orders = [];
$display_orders = [];
$total_orders = 0;
$total_pages = 0;
$overall_orders_count = 0;

if ($result && $result->num_rows > 0) {
    $orders = $result->fetch_all(MYSQLI_ASSOC);
    
    // If using fallback query, we need to fetch dish and customer info separately
    $using_fallback = !isset($orders[0]['dish_name']);
    
    if ($using_fallback) {
        // OPTIMIZED: Batch fetch dish and customer info instead of N+1 queries
        $dish_ids = array_filter(array_unique(array_column($orders, 'dish_id')));
        $customer_ids = array_filter(array_unique(array_column($orders, 'customer_id')));
        
        // Batch fetch all dishes
        $dishes_map = [];
        if (!empty($dish_ids)) {
            $dish_ids_str = implode(',', array_map('intval', $dish_ids));
            $dishes_query = "SELECT d.id, d.name, d.number_of_persons, d.category_id, cat.name as dish_category_name 
                FROM dishes d 
                LEFT JOIN categories cat ON d.category_id = cat.id 
                WHERE d.id IN ($dish_ids_str)";
            $dishes_result = $conn->query($dishes_query);
            if ($dishes_result) {
                while ($dish = $dishes_result->fetch_assoc()) {
                    $dishes_map[$dish['id']] = $dish;
                }
            }
        }
        
        // Batch fetch all customers
        $customers_map = [];
        if (!empty($customer_ids)) {
            $customer_ids_str = implode(',', array_map('intval', $customer_ids));
            $customers_query = "SELECT id, name, email FROM users WHERE id IN ($customer_ids_str)";
            $customers_result = $conn->query($customers_query);
            if ($customers_result) {
                while ($cust = $customers_result->fetch_assoc()) {
                    $customers_map[$cust['id']] = $cust;
                }
            }
        }
        
        // Map data to orders
        foreach ($orders as &$order) {
            // Get dish info from map
            if (!empty($order['dish_id']) && isset($dishes_map[$order['dish_id']])) {
                $dish = $dishes_map[$order['dish_id']];
                $order['dish_name'] = $dish['name'];
                $order['dish_id'] = $dish['id'];
                if (empty($order['number_of_persons']) || $order['number_of_persons'] == 0) {
                    $order['number_of_persons'] = $dish['number_of_persons'] ?? 0;
                }
                $order['dish_category_name'] = $dish['dish_category_name'] ?? 'Uncategorized';
                $order['dish_number_of_persons'] = $dish['number_of_persons'] ?? 0;
            }
            
            // Get customer info from map
            if (!empty($order['customer_id']) && isset($customers_map[$order['customer_id']])) {
                $cust = $customers_map[$order['customer_id']];
                $order['user_customer_name'] = $cust['name'];
                $order['user_customer_email'] = $cust['email'];
                if (empty($order['customer_name'])) {
                    $order['customer_name'] = $cust['name'];
                }
                if (empty($order['customer_email']) && empty($order['customer_cell'])) {
                    $order['customer_email'] = $cust['email'];
                }
            }
            
            // Set aliases for consistency
            $order['order_customer_name'] = $order['customer_name'] ?? '';
            $order['order_customer_cell'] = $order['customer_cell'] ?? '';
            if (empty($order['user_customer_name']) && !empty($order['customer_id'])) {
                $order['user_customer_name'] = $order['customer_name'] ?? '';
            }
        }
        unset($order);
    }
    
    // OPTIMIZED: Batch fetch all dish ingredients instead of N+1 queries
    $dish_ids_for_ingredients = array_filter(array_unique(array_column($orders, 'dish_id')));
    $ingredients_map = [];
    
    if (!empty($dish_ids_for_ingredients)) {
        $dish_ids_str = implode(',', array_map('intval', $dish_ids_for_ingredients));
        $ingredients_query = "SELECT di.dish_id, di.quantity, di.unit, i.name as ingredient_name, i.id as ingredient_id, 
            i.category_id, c.name as category_name
            FROM dish_ingredients di
            LEFT JOIN ingredients i ON di.ingredient_id = i.id
            LEFT JOIN categories c ON i.category_id = c.id
            WHERE di.dish_id IN ($dish_ids_str)
            ORDER BY di.dish_id, c.name, i.name";
        
        $ingredients_result = $conn->query($ingredients_query);
        if ($ingredients_result) {
            while ($ing = $ingredients_result->fetch_assoc()) {
                $dish_id = $ing['dish_id'];
                if (!isset($ingredients_map[$dish_id])) {
                    $ingredients_map[$dish_id] = [];
                }
                // Translate ingredient names and category names if needed
                if ($currentLang === 'ur') {
                    if (isset($ing['ingredient_name']) && !preg_match('/[\x{0600}-\x{06FF}]/u', $ing['ingredient_name'])) {
                        $ing['ingredient_name'] = translateToUrdu($ing['ingredient_name']);
                    }
                    if (isset($ing['category_name']) && !preg_match('/[\x{0600}-\x{06FF}]/u', $ing['category_name'])) {
                        $ing['category_name'] = translateToUrdu($ing['category_name']);
                    }
                }
                $ingredients_map[$dish_id][] = $ing;
            }
        }
    }
    
    // Map ingredients to orders and translate dish names/notes
    foreach ($orders as &$order) {
        // Translate dish name if needed
        if (isset($order['dish_name']) && $currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $order['dish_name'])) {
            $order['dish_name'] = translateToUrdu($order['dish_name']);
        }
        
        // Translate notes if needed
        if (isset($order['notes']) && !empty($order['notes']) && $currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $order['notes'])) {
            $order['notes'] = translateToUrdu($order['notes']);
        }
        
        // Get ingredients from map
        $dish_id = isset($order['dish_id']) ? intval($order['dish_id']) : 0;
        $order['ingredients'] = isset($ingredients_map[$dish_id]) ? $ingredients_map[$dish_id] : [];
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
                'extra_ingredients' => $order['extra_ingredients'] ?? null,
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

    // Keep master copy for stats/search and a display copy for pagination
    $all_grouped_orders = $grouped_orders;
    $display_orders = $all_grouped_orders;
    $overall_orders_count = count($all_grouped_orders);

    // Apply order number search on full dataset
    if ($is_search_active) {
        $searchTerm = strtolower($order_number_search);
        $display_orders = array_values(array_filter($display_orders, function($order) use ($searchTerm) {
            $orderNumber = strtolower($order['order_number'] ?? '');
            $orderId = (string)($order['id'] ?? '');
            return ($searchTerm === '' ||
                strpos($orderNumber, $searchTerm) !== false ||
                strpos(strtolower($orderId), $searchTerm) !== false);
        }));
    }

    // Pagination calculations on filtered dataset
    $total_orders = count($display_orders);
    
    if ($is_search_active) {
        $paginated_orders = $display_orders;
        $total_pages = $total_orders > 0 ? 1 : 0;
        $current_page = $total_orders > 0 ? 1 : 0;
    } elseif ($view_mode === 'recent') {
        $paginated_orders = array_slice($display_orders, 0, $recent_items_limit);
        $total_pages = $total_orders > 0 ? 1 : 0;
        $current_page = $total_orders > 0 ? 1 : 0;
    } else {
        $total_pages = $total_orders > 0 ? (int) ceil($total_orders / $items_per_page) : 0;
        if ($total_pages === 0) {
            $paginated_orders = [];
            $current_page = 0;
        } else {
            $current_page = min(max(1, $current_page), $total_pages);
            $offset = ($current_page - 1) * $items_per_page;
            $paginated_orders = array_slice($display_orders, $offset, $items_per_page);
        }
    }
} else {
    $grouped_orders = [];
    $all_grouped_orders = [];
    $display_orders = [];
    $paginated_orders = [];
    $total_orders = 0;
    $total_pages = 0;
    $overall_orders_count = 0;
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
$total_orders_count = count($all_grouped_orders);
$pending_orders = count(array_filter($all_grouped_orders, fn($o) => $o['status'] == 'pending'));
$delivered_orders = count(array_filter($all_grouped_orders, fn($o) => $o['status'] == 'delivered'));
$total_revenue = array_sum(array_column($all_grouped_orders, 'total_amount'));
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

<!-- Create Order Section - 3-Step Wizard (All users can create orders) -->
<?php if (isLoggedIn()): ?>
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
                    <span id="formTitle"><?php e('create_order'); ?> - 4-Step Process</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="resetFormToCreateMode(); document.getElementById('orderForm').reset();" id="newOrderBtn" style="display: none;">
                        <i class="bi bi-plus-circle me-1"></i> New Order
                    </button>
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
                            <div class="step-label">Compulsory Items</div>
                        </div>
                        <div class="step-line"></div>
                        <div class="step-item" data-step="4">
                            <div class="step-number">4</div>
                            <div class="step-label">Review</div>
                        </div>
                    </div>
                </div>

                <form method="POST" action="" id="orderForm" onsubmit="return validateFormSubmission()">
                    <!-- Create Order Input - Only active when creating new order -->
                    <input type="hidden" name="create_order" value="1" id="createOrderInput">
                    <!-- Update Order Input - Only active when editing existing order -->
                    <input type="hidden" id="updateOrderInput" style="display: none;">
                    <!-- Order Number Input - Only active when editing existing order -->
                    <input type="hidden" id="editOrderNumber">
                    
                    <!-- Step 1: Customer Information -->
                    <div class="order-step" id="step1" data-step="1">
                        <div class="step-header mb-4">
                            <h4 class="fw-bold">
                                <i class="bi bi-person-fill me-2 text-primary"></i>
                                مرحلہ 1: گاہک کی معلومات
                            </h4>
                            <p class="text-muted">گاہک کی تفصیلات اور آرڈر کی معلومات درج کریں</p>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="customer_name" class="form-label fw-semibold">
                                    <i class="bi bi-person me-1 text-primary"></i>
                                    گاہک کا نام
                                </label>
                                <input type="text" class="form-control form-control-lg" id="customer_name" name="customer_name" 
                                       list="customer_names_list" autocomplete="off"
                                       value="<?php echo htmlspecialchars($_POST['customer_name'] ?? ''); ?>">
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
                                    <i class="bi bi-info-circle me-1"></i>پہلے شامل کیے گئے گاہکوں کو دیکھنے کے لیے ٹائپ کرنا شروع کریں
                                </small>
                            </div>
                            <div class="col-md-6">
                                <label for="customer_cell" class="form-label fw-semibold">
                                    <i class="bi bi-telephone me-1 text-primary"></i>
                                    گاہک کا نمبر <span class="text-danger">*</span>
                                </label>
                                <input type="tel" class="form-control form-control-lg" id="customer_cell" name="customer_cell" 
                                       value="<?php echo htmlspecialchars($_POST['customer_cell'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="number_of_persons" class="form-label fw-semibold">
                                    <i class="bi bi-people me-1 text-primary"></i>
                                    افراد کی تعداد <span class="text-danger">*</span>
                                </label>
                                <input type="number" class="form-control form-control-lg" id="number_of_persons" name="number_of_persons" 
                                       value="<?php echo htmlspecialchars($_POST['number_of_persons'] ?? ''); ?>" required min="1">
                            </div>
                            <div class="col-md-6">
                                <label for="delivery_date" class="form-label fw-semibold">
                                    <i class="bi bi-calendar-check me-1 text-primary"></i>
                                    ڈیلیوری تاریخ <span class="text-danger">*</span>
                                </label>
                                <input type="date" class="form-control form-control-lg" id="delivery_date" name="delivery_date" 
                                       value="<?php echo htmlspecialchars($_POST['delivery_date'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="shift" class="form-label fw-semibold">
                                    <i class="bi bi-clock me-1 text-primary"></i>
                                    شفٹ <span class="text-danger">*</span>
                                </label>
                                <select class="form-select form-select-lg" id="shift" name="shift" required>
                                    <option value="">-- شفٹ منتخب کریں --</option>
                                    <option value="afternoon" <?php echo (isset($_POST['shift']) && $_POST['shift'] === 'afternoon') ? 'selected' : ''; ?>>دوپہر</option>
                                    <option value="evening" <?php echo (isset($_POST['shift']) && $_POST['shift'] === 'evening') ? 'selected' : ''; ?>>شام</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="delivery_time" class="form-label fw-semibold">
                                    <i class="bi bi-clock-history me-1 text-primary"></i>
                                    ڈیلیوری وقت <span class="text-danger">*</span>
                                </label>
                                <input type="time" class="form-control form-control-lg" id="delivery_time" name="delivery_time" 
                                       value="<?php echo htmlspecialchars($_POST['delivery_time'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="step-actions mt-4">
                            <button type="button" class="btn btn-primary btn-lg" onclick="nextStep(2)">
                                اگلا: ڈشز شامل کریں <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 2: Add Dishes -->
                    <div class="order-step" id="step2" data-step="2" style="display: none;">
                        <div class="step-header mb-4">
                            <h4 class="fw-bold">
                                <i class="bi bi-egg-fried me-2 text-primary"></i>
                                مرحلہ 2: ڈشز شامل کریں
                            </h4>
                            <p class="text-muted">آرڈر میں ڈشز شامل کریں۔ آپ متعدد ڈشز شامل کر سکتے ہیں۔</p>
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
                                        <div class="col-md-1">
                                            <label class="form-label fw-semibold small">
                                                <?php echo t('unit', 'Unit'); ?> <span class="text-danger">*</span>
                                            </label>
                                            <select class="form-select dish-unit" name="dishes[0][unit]">
                                                <option value=""><?php echo t('select_unit', 'Select Unit'); ?></option>
                                            </select>
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
                                    <?php e('extra_ingredients'); ?> (<?php e('optional'); ?>)
                                </label>
                                <button type="button" class="btn btn-sm btn-success" id="addExtraIngredientBtn">
                                    <i class="bi bi-plus-circle me-1"></i> <?php e('add'); ?> <?php e('extra_ingredients'); ?>
                                </button>
                            </div>
                            <p class="text-muted small mb-3"><?php echo t('add_additional_ingredients_not_in_dishes'); ?></p>
                            <div id="extraIngredientsContainer">
                                <!-- First extra ingredient row -->
                                <div class="extra-ingredient-row mb-3 p-3 border rounded" data-row="0" style="display: none;">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-semibold small">
                                                <?php e('ingredient_name'); ?> <span class="text-danger">*</span>
                                            </label>
                                            <select class="form-select extra-ingredient-select" name="extra_ingredients[0][ingredient_id]">
                                                <option value=""><?php e('select_ingredient'); ?></option>
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
                                                <?php e('quantity'); ?> <span class="text-danger">*</span>
                                            </label>
                                            <input type="number" class="form-control extra-ingredient-quantity" 
                                                   name="extra_ingredients[0][quantity]" 
                                                   placeholder="0.00" step="0.01" min="0.01">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-semibold small">
                                                <?php e('unit_label'); ?>
                                            </label>
                                            <input type="text" class="form-control extra-ingredient-unit" 
                                                   name="extra_ingredients[0][unit]" 
                                                   placeholder="<?php echo t('unit_placeholder', 'kg, g, pieces, etc.'); ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label fw-semibold small d-block">&nbsp;</label>
                                            <button type="button" class="btn btn-sm btn-danger remove-extra-ingredient-btn">
                                                <i class="bi bi-trash"></i> <?php e('delete'); ?>
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
                                Next: Compulsory Items <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 3: Compulsory Items -->
                    <div class="order-step" id="step3" data-step="3" style="display: none;">
                        <div class="step-header mb-4">
                            <h4 class="fw-bold">
                                <i class="bi bi-box-seam me-2 text-primary"></i>
                                مرحلہ 3: لازمی اشیاء
                            </h4>
                            <p class="text-muted">آرڈر کے لیے ضروری اضافی اشیاء شامل کریں۔</p>
                        </div>
                        
                        <!-- Additional Items Section -->
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    کپڑا ململ
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[cloth_malmal]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    میچ باکس
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[match_box]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    سرف
                                </label>
                                <div class="input-group">
                                    <input type="number" class="form-control additional-item" 
                                           name="additional_items[surrf]" 
                                           placeholder="0" step="1" min="0" value="0">
                                    <span class="input-group-text">گرام</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    لکڑی
                                </label>
                                <div class="input-group">
                                    <input type="number" class="form-control additional-item" 
                                           name="additional_items[wood]" 
                                           placeholder="0" step="1" min="0" value="0">
                                    <span class="input-group-text">کلو</span>
                                </div>
                            </div>
                          
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    صوبی(لوہے والی )
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[sobi_iron]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    سٹیم پتیلہ جال ڈھکن
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[steam_pot_with_lid]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    دیگ
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[deg]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    کڑاہی
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[karahi]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    چولہے
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[chulhe]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    پرات
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[parat]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    ٹب
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[tub]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    شامیانہ
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[shamiana]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    قنات
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[qanat]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    دری
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[dari]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    چارپائی
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[charpai]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    کوئلہ
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[coal]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">
                                    سٹیم پتیلہ بغیر ڈھکن
                                </label>
                                <input type="number" class="form-control additional-item" 
                                       name="additional_items[steam_pot_without_lid]" 
                                       placeholder="0" step="1" min="0" value="0">
                            </div>
                        </div>
                        
                        <div class="step-actions mt-4">
                            <button type="button" class="btn btn-secondary btn-lg" onclick="previousStep(2)">
                                <i class="bi bi-arrow-left me-2"></i> Previous
                            </button>
                            <button type="button" class="btn btn-primary btn-lg" onclick="nextStep(4)">
                                Next: Review <i class="bi bi-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Step 4: Review & Confirm -->
                    <div class="order-step" id="step4" data-step="4" style="display: none;">
                        <div class="step-header mb-4">
                            <h4 class="fw-bold">
                                <i class="bi bi-check-circle me-2 text-success"></i>
                                مرحلہ 4: جائزہ لیں اور تصدیق کریں
                            </h4>
                            <p class="text-muted">جمع کرانے سے پہلے اپنے آرڈر کی تفصیلات کا جائزہ لیں</p>
                        </div>
                        
                        <div class="order-review-card p-4 mb-4" style="background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
                            <h5 class="fw-bold mb-3">
                                <i class="bi bi-person-fill me-2 text-primary"></i>گاہک کی معلومات
                            </h5>
                            <div id="reviewCustomer" class="mb-4">
                                <p class="text-muted mb-0">مرحلہ 1 میں گاہک منتخب کریں</p>
                            </div>
                            
                            <h5 class="fw-bold mb-3">
                                <i class="bi bi-egg-fried me-2 text-primary"></i>آرڈر کی اشیاء
                            </h5>
                            <div id="reviewDishes" class="mb-4">
                                <p class="text-muted mb-0">مرحلہ 2 میں ڈشز شامل کریں</p>
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
                                    <h5 class="mb-0 fw-bold">کل رقم:</h5>
                                    <h4 class="mb-0 fw-bold text-primary" id="reviewTotal">Rs 0.00</h4>
                                </div>
                            </div>
                        </div>
                        
                        <div class="step-actions mt-4">
                            <button type="button" class="btn btn-secondary btn-lg" onclick="previousStep(3)">
                                <i class="bi bi-arrow-left me-2"></i> پچھلا
                            </button>
                            <button type="submit" class="btn btn-success btn-lg" id="orderSubmitButton">
                                <i class="bi bi-check-lg me-2"></i> <span id="submitButtonText"><?php e('create_order'); ?></span>
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
                <!-- Back Button (shown when viewing dishes) -->
                <div id="backToCategoriesBtn" class="mb-3" style="display: none;">
                    <button type="button" class="btn btn-outline-secondary" onclick="showCategoriesInModal()">
                        <i class="bi bi-arrow-left me-2"></i>Back to Categories
                    </button>
                </div>
                
                <!-- Search Bar -->
                <div class="mb-4">
                    <div class="input-group input-group-lg">
                        <span class="input-group-text" style="background: #f8fafc; border-right: none;">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control" id="dishSearchInput" placeholder="Search..." 
                               style="border-left: none; border-right: none;" oninput="filterItemsInModal(this.value)">
                        <button class="btn btn-outline-secondary" type="button" onclick="clearDishSearch()">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Categories Grid (shown first) -->
                <div id="modalCategoriesGrid" class="row g-3">
                    <?php foreach ($dish_categories as $cat): ?>
                        <div class="col-md-4 col-lg-3 modal-category-item" 
                             data-category-id="<?php echo $cat['id']; ?>"
                             data-category-name="<?php echo htmlspecialchars($cat['name']); ?>"
                             onclick="selectCategoryInModal(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars(addslashes($cat['name'])); ?>')">
                            <div class="card h-100 shadow-sm border-0 category-modal-card" style="cursor: pointer; transition: all 0.3s ease; border-radius: 16px; overflow: hidden;">
                                <div class="w-100 d-flex align-items-center justify-content-center" 
                                     style="height: 200px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                    <i class="bi bi-folder-fill text-white" style="font-size: 4rem;"></i>
                                </div>
                                <div class="card-body p-3">
                                    <h6 class="card-title fw-bold mb-1" style="color: #1e293b;">
                                        <?php echo htmlspecialchars($cat['name']); ?>
                                    </h6>
                                    <?php if (!empty($cat['description'])): ?>
                                        <small class="text-muted d-block">
                                            <?php echo htmlspecialchars($cat['description']); ?>
                                        </small>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <span class="badge bg-primary rounded-pill">
                                            <i class="bi bi-arrow-right me-1"></i>View Dishes
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <!-- Uncategorized option -->
                    <?php
                    $uncategorized_dishes = array_filter($dishes, function($dish) {
                        return empty($dish['category_id']);
                    });
                    if (!empty($uncategorized_dishes)):
                    ?>
                        <div class="col-md-4 col-lg-3 modal-category-item" 
                             data-category-id="0"
                             data-category-name="Uncategorized"
                             onclick="selectCategoryInModal(0, 'Uncategorized')">
                            <div class="card h-100 shadow-sm border-0 category-modal-card" style="cursor: pointer; transition: all 0.3s ease; border-radius: 16px; overflow: hidden;">
                                <div class="w-100 d-flex align-items-center justify-content-center" 
                                     style="height: 200px; background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);">
                                    <i class="bi bi-folder-x text-white" style="font-size: 4rem;"></i>
                                </div>
                                <div class="card-body p-3">
                                    <h6 class="card-title fw-bold mb-1" style="color: #1e293b;">
                                        Uncategorized
                                    </h6>
                                    <small class="text-muted d-block">
                                        Dishes without category
                                    </small>
                                    <div class="mt-2">
                                        <span class="badge bg-secondary rounded-pill">
                                            <i class="bi bi-arrow-right me-1"></i>View Dishes
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Dishes Grid (hidden initially, shown after category selection) -->
                <div id="modalDishesGrid" class="row g-3" style="display: none;">
                    <?php foreach ($dishes as $dish): 
                        $image_path = !empty($dish['image']) ? '../' . $dish['image'] : '';
                        $image_exists = !empty($dish['image']) && file_exists(__DIR__ . '/../' . $dish['image']);
                    ?>
                        <div class="col-md-4 col-lg-3 modal-dish-item" 
                             data-dish-id="<?php echo $dish['id']; ?>"
                             data-dish-name="<?php echo htmlspecialchars($dish['name']); ?>"
                             data-category-id="<?php echo $dish['category_id'] ?? '0'; ?>"
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
                <div id="noItemsFound" class="text-center py-5" style="display: none;">
                    <i class="bi bi-search" style="font-size: 4rem; color: #cbd5e1;"></i>
                    <p class="text-muted mt-3">No items found matching your search.</p>
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #e2e8f0;">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<?php endif; // End logged-in user check for create order section ?>

<?php
$ordersSectionTitle = $is_search_active 
    ? t('search_results', 'Search Results') 
    : ($view_mode === 'recent' ? t('recent_orders', 'Recent Orders') : t('all_orders', 'All Orders'));
$visible_orders_count = count($paginated_orders);
?>

<!-- All Orders Section -->
<div class="row">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <div class="d-flex flex-column gap-3">
                    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                        <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-2">
                            <h5 class="mb-0 fw-bold">
                                <i class="bi bi-list-ul me-2 text-primary"></i>
                                <?php echo htmlspecialchars($ordersSectionTitle); ?>
                                <span class="badge bg-primary ms-2">
                                    <?php echo $visible_orders_count; ?>
                                    <?php if (!$is_search_active && $view_mode === 'recent' && $overall_orders_count > 0): ?>
                                        / <?php echo $overall_orders_count; ?>
                                    <?php elseif (!$is_search_active && $view_mode === 'all'): ?>
                                        of <?php echo $total_orders; ?>
                                    <?php elseif ($is_search_active): ?>
                                        <?php echo $visible_orders_count === 1 ? 'match' : 'matches'; ?>
                                    <?php endif; ?>
                                </span>
                            </h5>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if (!$is_search_active && $view_mode === 'recent' && $overall_orders_count > $recent_items_limit): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="?view=all">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>View all orders
                                    </a>
                                <?php elseif ($view_mode === 'all' || $is_search_active): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="orders.php">
                                        <i class="bi bi-arrow-counterclockwise me-1"></i>Back to recent
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <form class="input-group" method="GET" style="max-width: 500px;">
                            <?php if ($view_mode === 'all' || isset($_GET['view'])): ?>
                                <input type="hidden" name="view" value="<?php echo $view_mode === 'all' ? 'all' : 'recent'; ?>">
                            <?php endif; ?>
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-upc-scan text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0" name="order_number" 
                                   value="<?php echo htmlspecialchars($order_number_search); ?>"
                                   placeholder="Search by order number..." autocomplete="off">
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-search"></i>
                            </button>
                            <?php if ($order_number_search !== ''): ?>
                                <a href="<?php echo $view_mode === 'all' ? 'orders.php?view=all' : 'orders.php'; ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-lg"></i>
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <?php if ($view_mode === 'all' && !$is_search_active): ?>
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
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if (empty($paginated_orders)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-1 text-muted d-block mb-3"></i>
                        <?php if ($is_search_active): ?>
                            <h5 class="text-muted mb-2">No orders matched that number</h5>
                            <p class="text-muted mb-3">Double-check the order number or view the latest orders.</p>
                            <a href="<?php echo $view_mode === 'all' ? 'orders.php?view=all' : 'orders.php'; ?>" class="btn btn-outline-primary">
                                <i class="bi bi-arrow-left me-1"></i>Back to orders
                            </a>
                        <?php else: ?>
                            <h5 class="text-muted mb-2"><?php e('no_orders'); ?></h5>
                            <p class="text-muted">Create your first order using the form above!</p>
                        <?php endif; ?>
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
                                            <div class="d-flex align-items-center gap-1">
                                                <button type="button" 
                                                        class="btn btn-sm btn-warning" 
                                                        title="<?php e('edit'); ?>"
                                                        onclick="editOrder('<?php echo htmlspecialchars($grouped_order['order_number']); ?>')"
                                                        style="font-size: 0.7rem; padding: 0.2rem 0.4rem; min-width: 28px;">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <span class="badge bg-<?php echo getStatusBadgeClass($grouped_order['status']); ?> ms-1">
                                                    <i class="bi bi-<?php echo getStatusIcon($grouped_order['status']); ?>"></i>
                                                </span>
                                            </div>
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
                                            <div>
                                                <?php 
                                                $max_display = 3;
                                                $displayed_dishes = [];
                                                $default_unit_label = t('units', 'Units');
                                                foreach ($grouped_order['dishes'] as $index => $dish): 
                                                    if ($index < $max_display) {
                                                        $quantity_value = number_format(floatval($dish['quantity']), 2);
                                                        $unit_label = !empty($dish['unit']) ? htmlspecialchars($dish['unit']) : $default_unit_label;
                                                        $displayed_dishes[] = htmlspecialchars($dish['dish_name']) . ' (' . $quantity_value . ' ' . $unit_label . ')';
                                                    }
                                                endforeach; 
                                                $unit_totals = [];
                                                foreach ($grouped_order['dishes'] as $dish) {
                                                    $unit_label = !empty($dish['unit']) ? $dish['unit'] : $default_unit_label;
                                                    $unit_totals[$unit_label] = ($unit_totals[$unit_label] ?? 0) + floatval($dish['quantity']);
                                                }
                                                ?>
                                                <small class="fw-semibold d-block" style="font-size: 0.75rem; line-height: 1.4;">
                                                    <?php echo implode(', ', $displayed_dishes); ?>
                                                    <?php if (count($grouped_order['dishes']) > $max_display): ?>
                                                        <span class="text-muted">+<?php echo count($grouped_order['dishes']) - $max_display; ?> more</span>
                                                    <?php endif; ?>
                                                </small>
                                                <small class="text-muted d-block mt-1" style="font-size: 0.7rem;">
                                                    <?php foreach ($unit_totals as $unit_label => $qty): ?>
                                                        <span class="me-2 d-inline-block"><?php echo number_format($qty, 2); ?> <?php echo htmlspecialchars($unit_label); ?></span>
                                                    <?php endforeach; ?>
                                                    <?php if ($grouped_order['total_amount'] > 0): ?>
                                                        • Total: Rs <?php echo number_format($grouped_order['total_amount'], 2); ?>
                                                    <?php endif; ?>
                                                </small>
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
                    <?php if ($view_mode === 'all' && !$is_search_active && $total_pages > 1): ?>
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
                    <?php endif; ?>
                    <div class="text-center mt-3 text-muted">
                        <?php if ($is_search_active): ?>
                            <small>Found <?php echo $visible_orders_count; ?> matching order<?php echo $visible_orders_count === 1 ? '' : 's'; ?>.</small>
                        <?php elseif ($view_mode === 'recent'): ?>
                            <small>
                                Showing the latest <?php echo min($recent_items_limit, $overall_orders_count); ?> of <?php echo $overall_orders_count; ?> orders.
                                <?php if ($overall_orders_count > $recent_items_limit): ?>
                                    <a href="?view=all">View all orders</a>
                                <?php endif; ?>
                            </small>
                        <?php elseif ($view_mode === 'all'): ?>
                            <small>Showing <?php echo count($paginated_orders); ?> of <?php echo $total_orders; ?> orders<?php if ($total_pages > 0): ?> (Page <?php echo max(1, $current_page); ?> of <?php echo max(1, $total_pages); ?>)<?php endif; ?></small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// 4-Step Order Wizard Functions
let currentStep = 1;
const totalSteps = 4;

// Get customer data for review
const customersData = <?php echo json_encode($customers); ?>;
const dishesData = <?php echo json_encode($dishes); ?>;

// Validate form submission - ensure correct mode is set
function validateFormSubmission() {
    const createOrderInput = document.getElementById('createOrderInput');
    const updateOrderInput = document.getElementById('updateOrderInput');
    const editOrderNumberInput = document.getElementById('editOrderNumber');
    
    // Check if we're in update mode by checking if updateOrderInput has name attribute
    const isUpdateMode = updateOrderInput && updateOrderInput.hasAttribute('name') && updateOrderInput.getAttribute('name') === 'update_order';
    
    if (isUpdateMode) {
        // UPDATE MODE: Editing existing order
        // Ensure order_number is set and has value
        if (!editOrderNumberInput || !editOrderNumberInput.value || !editOrderNumberInput.hasAttribute('name')) {
            alert('Error: Order number is missing. Please click the Edit button again to load the order.');
            return false;
        }
        
        // Ensure update_order input has name attribute and value
        if (!updateOrderInput.hasAttribute('name')) {
            updateOrderInput.setAttribute('name', 'update_order');
            updateOrderInput.value = '1';
        }
        
        // CRITICAL: Remove create_order input completely so it doesn't interfere
        if (createOrderInput && createOrderInput.hasAttribute('name')) {
            createOrderInput.removeAttribute('name');
            createOrderInput.value = '';
        }
        
        console.log('Submitting form in UPDATE mode. Order number:', editOrderNumberInput.value);
        return true;
    } else {
        // CREATE MODE: Creating new order
        // Ensure create_order input has name attribute
        if (createOrderInput && !createOrderInput.hasAttribute('name')) {
            createOrderInput.setAttribute('name', 'create_order');
            createOrderInput.value = '1';
        }
        
        // CRITICAL: Remove update_order and order_number inputs completely so they don't interfere
        if (updateOrderInput && updateOrderInput.hasAttribute('name')) {
            updateOrderInput.removeAttribute('name');
            updateOrderInput.value = '';
        }
        
        if (editOrderNumberInput && editOrderNumberInput.hasAttribute('name')) {
            editOrderNumberInput.removeAttribute('name');
            editOrderNumberInput.value = '';
        }
        
        console.log('Submitting form in CREATE mode (new order)');
        return true;
    }
}

// Reset form to create mode
function resetFormToCreateMode() {
    const createOrderInput = document.getElementById('createOrderInput');
    const updateOrderInput = document.getElementById('updateOrderInput');
    const editOrderNumberInput = document.getElementById('editOrderNumber');
    const submitButton = document.getElementById('orderSubmitButton');
    const submitButtonText = document.getElementById('submitButtonText');
    
    if (createOrderInput && updateOrderInput && editOrderNumberInput) {
        // CREATE MODE: Set create_order input properly
        createOrderInput.setAttribute('name', 'create_order');
        createOrderInput.value = '1';
        createOrderInput.style.display = 'block';
        
        // CREATE MODE: Remove update_order input completely
        updateOrderInput.removeAttribute('name');
        updateOrderInput.value = '';
        updateOrderInput.style.display = 'none';
        
        // CREATE MODE: Remove order_number input completely (not needed for new orders)
        editOrderNumberInput.removeAttribute('name');
        editOrderNumberInput.value = '';
        
        console.log('Form reset to CREATE mode (new order)');
    }
    
    if (submitButton && submitButtonText) {
        submitButtonText.textContent = '<?php echo addslashes(t("create_order")); ?>';
        submitButton.classList.remove('btn-warning');
        submitButton.classList.add('btn-success');
    }
    
    const formTitle = document.getElementById('formTitle');
    const newOrderBtn = document.getElementById('newOrderBtn');
    
    if (formTitle) {
        formTitle.textContent = '<?php echo addslashes(t("create_order")); ?> - 4-Step Process';
    }
    
    if (newOrderBtn) {
        newOrderBtn.style.display = 'none';
    }
}

// Edit Order Function
function editOrder(orderNumber) {
    // Wait for ordersData to be available
    if (typeof ordersData === 'undefined' || !ordersData || ordersData.length === 0) {
        alert('Order data not available. Please wait a moment and try again.');
        return;
    }
    
    // Find the order
    const order = ordersData.find(o => o.order_number == orderNumber || o.id == orderNumber);
    if (!order) {
        alert('Order not found.');
        return;
    }
    
    // Get the actual order number from the order object
    const actualOrderNumber = order.order_number || orderNumber;
    
    if (!actualOrderNumber) {
        alert('Order number not found in order data.');
        return;
    }
    
    // Scroll to the order form
    const orderForm = document.getElementById('orderForm');
    if (orderForm) {
        orderForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        
        // Populate Step 1: Customer Information
        const customerName = document.getElementById('customer_name');
        const customerCell = document.getElementById('customer_cell');
        const numberOfPersons = document.getElementById('number_of_persons');
        const shift = document.getElementById('shift');
        const deliveryDate = document.getElementById('delivery_date');
        const deliveryTime = document.getElementById('delivery_time');
        
        if (customerName) customerName.value = order.customer_name || '';
        if (customerCell) customerCell.value = order.customer_cell || '';
        if (numberOfPersons) numberOfPersons.value = order.number_of_persons || '';
        if (shift) shift.value = order.shift || '';
        if (deliveryDate) {
            const date = order.delivery_date ? order.delivery_date.split(' ')[0] : '';
            deliveryDate.value = date;
        }
        if (deliveryTime) {
            const time = order.delivery_time || '';
            deliveryTime.value = time;
        }
        
        // Populate Step 2: Dishes
        const dishesContainer = document.getElementById('dishesContainer');
        if (dishesContainer && order.dishes && order.dishes.length > 0) {
            // Clear existing dish rows except the first one
            const existingRows = dishesContainer.querySelectorAll('.dish-row');
            for (let i = 1; i < existingRows.length; i++) {
                existingRows[i].remove();
            }
            
            // Populate dishes
            order.dishes.forEach(function(dish, index) {
                let row;
                if (index === 0) {
                    // Use first row
                    row = dishesContainer.querySelector('.dish-row');
                } else {
                    // Add new row
                    const addBtn = document.getElementById('addDishBtn');
                    if (addBtn) {
                        addBtn.click();
                        row = dishesContainer.querySelectorAll('.dish-row')[index];
                    } else {
                        return;
                    }
                }
                
                if (row) {
                    const dishSelect = row.querySelector('.dish-select');
                    const unitSelect = row.querySelector('.dish-unit');
                    const quantityInput = row.querySelector('.dish-quantity');
                    const unitPriceInput = row.querySelector('.dish-unit-price');
                    const totalAmountInput = row.querySelector('.dish-total-amount');
                    
                    // Set dish
                    if (dishSelect && dish.dish_id) {
                        dishSelect.value = dish.dish_id;
                        // Trigger change to populate unit dropdown
                        if (typeof updateUnitDropdown === 'function') {
                            updateUnitDropdown(dishSelect);
                            // Set unit after dropdown is populated
                            setTimeout(function() {
                                if (unitSelect && dish.unit) {
                                    unitSelect.value = dish.unit;
                                }
                            }, 200);
                        } else {
                            // If updateUnitDropdown not available yet, initialize unit dropdown manually
                            if (unitSelect && typeof initializeUnitDropdowns === 'function') {
                                initializeUnitDropdowns();
                                setTimeout(function() {
                                    if (unitSelect && dish.unit) {
                                        unitSelect.value = dish.unit;
                                    }
                                }, 100);
                            }
                        }
                    }
                    
                    // Set quantity
                    if (quantityInput) quantityInput.value = dish.quantity || '';
                    
                    // Set unit price and total amount
                    if (unitPriceInput && dish.total_amount && dish.quantity) {
                        const unitPrice = parseFloat(dish.total_amount) / parseFloat(dish.quantity);
                        unitPriceInput.value = unitPrice.toFixed(2);
                    }
                    if (totalAmountInput) totalAmountInput.value = dish.total_amount || '';
                }
            });
        }
        
        // Populate Step 3: Compulsory Items (Additional Items)
        if (order.extra_ingredients && order.extra_ingredients.additional_items) {
            const additionalItems = order.extra_ingredients.additional_items;
            Object.keys(additionalItems).forEach(function(itemKey) {
                const input = document.querySelector(`input[name="additional_items[${itemKey}]"]`);
                if (input) {
                    input.value = additionalItems[itemKey] || 0;
                }
            });
        }
        
        // Show Step 1
        document.querySelectorAll('.order-step').forEach(function(step) {
            step.style.display = 'none';
        });
        const step1 = document.getElementById('step1');
        if (step1) {
            step1.style.display = 'block';
            currentStep = 1;
        }
        
        // Update progress indicator
        updateProgressIndicator(0, 1);
        
        // Set form to update mode
        const createOrderInput = document.getElementById('createOrderInput');
        const updateOrderInput = document.getElementById('updateOrderInput');
        const editOrderNumberInput = document.getElementById('editOrderNumber');
        
        if (createOrderInput && updateOrderInput && editOrderNumberInput) {
            // EDIT MODE: Remove create_order input completely
            createOrderInput.removeAttribute('name');
            createOrderInput.value = '';
            
            // EDIT MODE: Set update_order input properly
            updateOrderInput.setAttribute('name', 'update_order');
            updateOrderInput.value = '1';
            updateOrderInput.style.display = 'block';
            
            // EDIT MODE: Set order number - use actualOrderNumber from order object
            editOrderNumberInput.setAttribute('name', 'order_number');
            editOrderNumberInput.value = actualOrderNumber;
            
            console.log('Form switched to UPDATE/EDIT mode. Order number:', actualOrderNumber);
        } else {
            console.error('Form elements not found:', {
                createOrderInput: !!createOrderInput,
                updateOrderInput: !!updateOrderInput,
                editOrderNumberInput: !!editOrderNumberInput
            });
        }
        
        // Update form submit button text if it exists
        const submitButton = document.getElementById('orderSubmitButton');
        const submitButtonText = document.getElementById('submitButtonText');
        const formTitle = document.getElementById('formTitle');
        const newOrderBtn = document.getElementById('newOrderBtn');
        
        if (submitButton && submitButtonText) {
            submitButtonText.textContent = 'Update Order';
            submitButton.classList.remove('btn-success');
            submitButton.classList.add('btn-warning');
        }
        
        if (formTitle) {
            formTitle.textContent = 'Edit Order - 4-Step Process';
        }
        
        if (newOrderBtn) {
            newOrderBtn.style.display = 'inline-block';
        }
        
        // Show success message
        alert('Order loaded for editing. Please review and update the information, then click "Update Order" to save changes.');
    }
}

// Step navigation functions
function nextStep(step) {
    // Initialize unit dropdowns before validation
    if (currentStep === 2) {
        initializeUnitDropdowns();
    }
    
    if (validateCurrentStep()) {
        if (step <= totalSteps) {
            // Hide current step
            document.getElementById('step' + currentStep).style.display = 'none';
            
            // Update progress indicator
            updateProgressIndicator(currentStep, step);
            
            // Show next step
            currentStep = step;
            document.getElementById('step' + currentStep).style.display = 'block';
            
            // If moving to step 4, update review
            if (step === 4) {
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
            alert('براہ کرم شفٹ منتخب کریں (دوپہر یا شام)');
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
        // Initialize unit dropdowns before validation
        if (typeof initializeUnitDropdowns === 'function') {
            initializeUnitDropdowns();
        }
        
        // Validate at least one dish is added with unit
        const dishRows = document.querySelectorAll('.dish-row');
        let hasValidDish = false;
        
        dishRows.forEach(function(row) {
            const dishSelect = row.querySelector('.dish-select');
            const quantityInput = row.querySelector('.dish-quantity');
            const unitSelect = row.querySelector('.dish-unit');
            
            if (dishSelect.value && quantityInput.value && parseFloat(quantityInput.value) > 0) {
                // If dish is selected, unit must also be selected
                if (unitSelect && unitSelect.value) {
                    hasValidDish = true;
                } else if (dishSelect.value) {
                    // Dish selected but no unit - make unit optional for now, but show warning
                    hasValidDish = true;
                }
            }
        });
        
        if (!hasValidDish) {
            alert('Please add at least one dish with quantity and unit');
            return false;
        }
        
        // Check if any dish has no unit selected
        let missingUnit = false;
        dishRows.forEach(function(row) {
            const dishSelect = row.querySelector('.dish-select');
            const unitSelect = row.querySelector('.dish-unit');
            if (dishSelect.value && (!unitSelect || !unitSelect.value)) {
                missingUnit = true;
                if (unitSelect) {
                    unitSelect.focus();
                }
            }
        });
        
        if (missingUnit) {
            alert('Please select a unit for all selected dishes');
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
                <strong>Number of Persons:</strong> ${escapeHtml(numberOfPersons ? numberOfPersons.value : '')}<br>
                <strong>Delivery Date:</strong> ${escapeHtml(deliveryDate ? deliveryDate.value : '')}<br>
                <strong>شفٹ:</strong> ${escapeHtml(shift ? shift.options[shift.selectedIndex].text : '')}<br>
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
            const unitSelect = row.querySelector('.dish-unit');
            const quantityInput = row.querySelector('.dish-quantity');
            const unitPriceInput = row.querySelector('.dish-unit-price');
            const totalAmountInput = row.querySelector('.dish-total-amount');
            
            if (dishSelect && dishSelect.value && quantityInput && quantityInput.value) {
                const dishId = dishSelect.value;
                const dish = dishesData.find(d => d.id == dishId);
                const quantity = parseFloat(quantityInput.value) || 0;
                const unit = unitSelect ? unitSelect.value : '';
                const unitPrice = parseFloat(unitPriceInput.value) || 0;
                const total = parseFloat(totalAmountInput.value) || (quantity * unitPrice);
                
                if (dish && quantity > 0) {
                    const unitText = unit ? ` ${unit}` : '';
                    dishesHTML += `
                        <div class="d-flex justify-content-between align-items-center mb-2 p-2" style="background: white; border-radius: 6px;">
                            <div>
                                <strong>${escapeHtml(dish.name)}</strong><br>
                                <small class="text-muted">Quantity: ${quantity}${unitText} ${unitPrice > 0 ? '× Rs ' + unitPrice.toFixed(2) : ''}</small>
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
    
    // Get translations from PHP for review section
    const reviewTranslations = typeof window.reviewTranslations !== 'undefined' ? window.reviewTranslations : {
        extra_ingredients: '<?php echo addslashes(t("extra_ingredients")); ?>',
        additional_items: '<?php echo addslashes(t("additional_items")); ?>',
        quantity: '<?php echo addslashes(t("quantity")); ?>',
        cloth_malmal: '<?php echo addslashes(t("cloth_malmal")); ?>',
        match_box: '<?php echo addslashes(t("match_box")); ?>',
        surrf: '<?php echo addslashes(t("surrf")); ?>',
        wood: '<?php echo addslashes(t("wood", "لکڑی")); ?>',
        sponjis_iron: '<?php echo addslashes(t("sponjis_iron")); ?>',
        sobi_iron: '<?php echo addslashes(t("sobi_iron")); ?>',
        steam_pot_with_lid: '<?php echo addslashes(t("steam_pot_with_lid")); ?>',
        deg: '<?php echo addslashes(t("deg")); ?>',
        karahi: '<?php echo addslashes(t("karahi")); ?>',
        chulhe: '<?php echo addslashes(t("chulhe")); ?>',
        parat: '<?php echo addslashes(t("parat")); ?>',
        tub: '<?php echo addslashes(t("tub")); ?>',
        shamiana: '<?php echo addslashes(t("shamiana")); ?>',
        qanat: '<?php echo addslashes(t("qanat")); ?>',
        dari: '<?php echo addslashes(t("dari")); ?>',
        charpai: '<?php echo addslashes(t("charpai")); ?>',
        coal: '<?php echo addslashes(t("coal")); ?>',
        steam_pot_without_lid: '<?php echo addslashes(t("steam_pot_without_lid")); ?>'
    };
    
    if (extraIngredientRows.length > 0 && ingredientsData.length > 0) {
        let hasExtraIngredients = false;
        let extraIngredientsHTML = '<div class="mt-3 pt-3 border-top"><strong class="text-success"><i class="bi bi-plus-circle me-1"></i>' + reviewTranslations.extra_ingredients + ':</strong></div>';
        
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
                                <small class="text-muted">${reviewTranslations.quantity}: ${quantity}${unitText}</small>
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
    
    // Add additional items to review
    const additionalItemInputs = document.querySelectorAll('.additional-item');
    let hasAdditionalItems = false;
    let additionalItemsHTML = '';
    
    additionalItemInputs.forEach(function(input) {
        const quantity = parseInt(input.value) || 0;
        if (quantity > 0) {
            if (!hasAdditionalItems) {
                additionalItemsHTML = '<div class="mt-3 pt-3 border-top"><strong class="text-info"><i class="bi bi-box-seam me-1"></i>' + reviewTranslations.additional_items + ':</strong></div>';
                hasAdditionalItems = true;
            }
            
            // Get the item name from the input name attribute
            const itemName = input.name.match(/\[([^\]]+)\]/);
            let displayName = '';
            let unit = 'عدد'; // Default to pieces
            if (itemName) {
                const key = itemName[1];
                const nameMap = {
                    'cloth_malmal': reviewTranslations.cloth_malmal || 'کپڑا ململ',
                    'match_box': reviewTranslations.match_box || 'میچ باکس',
                    'surrf': reviewTranslations.surrf || 'سرف',
                    'wood': reviewTranslations.wood || 'لکڑی',
                    'sponjis_iron': reviewTranslations.sponjis_iron || 'اسپنجز (آئرن)',
                    'sobi_iron': reviewTranslations.sobi_iron || 'صوبی(لوہے والی )',
                    'steam_pot_with_lid': reviewTranslations.steam_pot_with_lid || 'سٹیم پتیلہ جال ڈھکن',
                    'deg': reviewTranslations.deg || 'دیگ',
                    'karahi': reviewTranslations.karahi || 'کڑاہی',
                    'chulhe': reviewTranslations.chulhe || 'چولہے',
                    'parat': reviewTranslations.parat || 'پرات',
                    'tub': reviewTranslations.tub || 'ٹب',
                    'shamiana': reviewTranslations.shamiana || 'شامیانہ',
                    'qanat': reviewTranslations.qanat || 'قنات',
                    'dari': reviewTranslations.dari || 'دری',
                    'charpai': reviewTranslations.charpai || 'چارپائی',
                    'coal': reviewTranslations.coal || 'کوئلہ',
                    'steam_pot_without_lid': reviewTranslations.steam_pot_without_lid || 'سٹیم پتیلہ بغیر ڈھکن'
                };
                displayName = nameMap[key] || key;
                
                // Set unit: meter for cloth_malmal, gram for surrf, kilo for wood, pieces for others
                if (key === 'cloth_malmal') {
                    unit = 'میٹر'; // Meter for cloth
                } else if (key === 'surrf') {
                    unit = 'گرام'; // Gram for surrf
                } else if (key === 'wood') {
                    unit = 'کلو'; // Kilo for wood
                }
            }
            
            additionalItemsHTML += `
                <div class="d-flex justify-content-between align-items-center mb-2 p-2" style="background: #eff6ff; border-radius: 6px; border-left: 3px solid #3b82f6;">
                    <div>
                        <strong class="text-info">${escapeHtml(displayName)}</strong><br>
                        <small class="text-muted">${reviewTranslations.quantity}: ${quantity} ${unit}</small>
                    </div>
                </div>
            `;
        }
    });
    
    if (hasAdditionalItems) {
        dishesHTML += additionalItemsHTML;
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
    const numberOfPersons = document.getElementById('number_of_persons');
    const shift = document.getElementById('shift');
    const deliveryDate = document.getElementById('delivery_date');
    const deliveryTime = document.getElementById('delivery_time');
    
    [customerNameField, customerCellField, numberOfPersons, shift, deliveryDate, deliveryTime].forEach(function(field) {
        if (field) {
            field.addEventListener('change', function() {
                if (currentStep === 4) {
                    updateReview();
                }
            });
        }
    });
    
    // Old form - customer selection
    const customerSelect = document.getElementById('customer_id');
    if (customerSelect) {
        customerSelect.addEventListener('change', function() {
            if (currentStep === 4) {
                updateReview();
            }
        });
    }
    
    // Listen for dish changes
    const dishesContainer = document.getElementById('dishesContainer');
    if (dishesContainer) {
        dishesContainer.addEventListener('input', function(e) {
            if (currentStep === 4 && (e.target.classList.contains('dish-select') || 
                e.target.classList.contains('dish-quantity') || 
                e.target.classList.contains('dish-unit') ||
                e.target.classList.contains('dish-unit-price') || 
                e.target.classList.contains('dish-total-amount'))) {
                updateReview();
            }
        });
        dishesContainer.addEventListener('change', function(e) {
            if (e.target.classList.contains('dish-select')) {
                // Update unit dropdown when dish is selected
                updateUnitDropdown(e.target);
            }
            if (currentStep === 4 && (e.target.classList.contains('dish-select') || 
                e.target.classList.contains('dish-unit'))) {
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
    
    // Make review translations globally available
    window.reviewTranslations = {
        extra_ingredients: '<?php echo addslashes(t("extra_ingredients")); ?>',
        additional_items: '<?php echo addslashes(t("additional_items")); ?>',
        quantity: '<?php echo addslashes(t("quantity")); ?>',
        ingredient_name: '<?php echo addslashes(t("ingredient_name")); ?>',
        unit_label: '<?php echo addslashes(t("unit_label")); ?>',
        delete: '<?php echo addslashes(t("delete")); ?>',
        select_ingredient: '<?php echo addslashes(t("select_ingredient")); ?>',
        add: '<?php echo addslashes(t("add")); ?>',
        cloth_malmal: '<?php echo addslashes(t("cloth_malmal")); ?>',
        match_box: '<?php echo addslashes(t("match_box")); ?>',
        surrf: '<?php echo addslashes(t("surrf")); ?>',
        wood: '<?php echo addslashes(t("wood", "لکڑی")); ?>',
        sponjis_iron: '<?php echo addslashes(t("sponjis_iron")); ?>',
        sobi_iron: '<?php echo addslashes(t("sobi_iron")); ?>',
        steam_pot_with_lid: '<?php echo addslashes(t("steam_pot_with_lid")); ?>',
        deg: '<?php echo addslashes(t("deg")); ?>',
        karahi: '<?php echo addslashes(t("karahi")); ?>',
        chulhe: '<?php echo addslashes(t("chulhe")); ?>',
        parat: '<?php echo addslashes(t("parat")); ?>',
        tub: '<?php echo addslashes(t("tub")); ?>',
        shamiana: '<?php echo addslashes(t("shamiana")); ?>',
        qanat: '<?php echo addslashes(t("qanat")); ?>',
        dari: '<?php echo addslashes(t("dari")); ?>',
        charpai: '<?php echo addslashes(t("charpai")); ?>',
        coal: '<?php echo addslashes(t("coal")); ?>',
        steam_pot_without_lid: '<?php echo addslashes(t("steam_pot_without_lid")); ?>',
        unit_placeholder: '<?php echo addslashes(t("unit_placeholder", "kg, g, pieces, etc.")); ?>'
    };
    
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
            
            // Update review if on step 4
            if (currentStep === 4) {
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
            
            // Update review if on step 4
            if (currentStep === 4) {
                updateReview();
            }
        });
        
        totalAmountInput.addEventListener('blur', function() {
            if (!this.value || this.value === '') {
                isManualTotalEdit = false;
                calculateTotal();
            } else if (currentStep === 4) {
                updateReview();
            }
        });
    }
    
    // Function to update unit dropdown based on selected dish
    function updateUnitDropdown(dishSelect) {
        const row = dishSelect.closest('.dish-row');
        if (!row) return;
        
        const unitSelect = row.querySelector('.dish-unit');
        if (!unitSelect) return;
        
        const dishId = dishSelect.value;
        const dish = dishesData.find(d => d.id == dishId);
        
        // Clear existing options
        unitSelect.innerHTML = '<option value=""><?php echo t('select_unit', 'Select Unit'); ?></option>';
        
        // Always show these 4 units in the specified order
        const defaultUnits = ['دیگ', 'لیٹر', 'عدد', 'کلو'];
        defaultUnits.forEach(unit => {
            const option = document.createElement('option');
            option.value = unit;
            option.textContent = unit;
            // If dish has base_unit and it matches one of these units, select it
            if (dish && dish.base_unit && dish.base_unit === unit) {
                option.selected = true;
            }
            unitSelect.appendChild(option);
        });
        
        // Make unit required when dish is selected
        if (dishId) {
            unitSelect.setAttribute('required', 'required');
        } else {
            unitSelect.removeAttribute('required');
        }
    }
    
    // Make updateUnitDropdown available globally
    window.updateUnitDropdown = updateUnitDropdown;
    
    // Initialize unit dropdowns for all existing rows on page load
    function initializeUnitDropdowns() {
        document.querySelectorAll('.dish-row').forEach(function(row) {
            const unitSelect = row.querySelector('.dish-unit');
            if (unitSelect) {
                // Always ensure dropdown has options
                if (unitSelect.children.length <= 1) {
                    unitSelect.innerHTML = '<option value=""><?php echo t('select_unit', 'Select Unit'); ?></option>';
                    const defaultUnits = ['دیگ', 'لیٹر', 'عدد', 'کلو'];
                    defaultUnits.forEach(unit => {
                        const option = document.createElement('option');
                        option.value = unit;
                        option.textContent = unit;
                        unitSelect.appendChild(option);
                    });
                }
            }
        });
    }
    
    // Make initializeUnitDropdowns available globally
    window.initializeUnitDropdowns = initializeUnitDropdowns;
    
    // Initialize on page load
    initializeUnitDropdowns();
    
    // Setup listeners for existing rows
    document.querySelectorAll('.dish-row').forEach(function(row) {
        setupRowListeners(row);
        // Add event listener for dish selection to update unit
        const dishSelect = row.querySelector('.dish-select');
        if (dishSelect) {
            // Initialize unit dropdown if dish is already selected
            if (dishSelect.value) {
                updateUnitDropdown(dishSelect);
            }
            // Add change listener
            dishSelect.addEventListener('change', function() {
                updateUnitDropdown(this);
            });
        }
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
                <div class="col-md-1">
                    <label class="form-label fw-semibold small">
                        <?php echo t('unit', 'Unit'); ?> <span class="text-danger">*</span>
                    </label>
                    <select class="form-select dish-unit" name="dishes[${dishRowCount}][unit]" required>
                        <option value=""><?php echo t('select_unit', 'Select Unit'); ?></option>
                    </select>
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
        
        // Initialize unit dropdown with default options for new row
        const unitSelect = newRow.querySelector('.dish-unit');
        if (unitSelect) {
            unitSelect.innerHTML = '<option value=""><?php echo t('select_unit', 'Select Unit'); ?></option>';
            const defaultUnits = ['دیگ', 'لیٹر', 'عدد', 'کلو'];
            defaultUnits.forEach(unit => {
                const option = document.createElement('option');
                option.value = unit;
                option.textContent = unit;
                unitSelect.appendChild(option);
            });
        }
        
        // Add event listener for dish selection to update unit
        const dishSelect = newRow.querySelector('.dish-select');
        if (dishSelect) {
            dishSelect.addEventListener('change', function() {
                updateUnitDropdown(this);
            });
        }
        
        updateRemoveButtons();
        dishRowCount++;
        
        // Update review if on step 4
        if (currentStep === 4) {
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
                
                // Update review if on step 4
                if (currentStep === 4) {
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
            
            const reviewTranslations = window.reviewTranslations || {};
            newRow.innerHTML = `
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold small">
                            ${reviewTranslations.ingredient_name || 'Ingredient'} <span class="text-danger">*</span>
                        </label>
                        <select class="form-select extra-ingredient-select" name="extra_ingredients[${extraIngredientRowCount}][ingredient_id]">
                            ${getIngredientOptionsHTML()}
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">
                            ${reviewTranslations.quantity || 'Quantity'} <span class="text-danger">*</span>
                        </label>
                        <input type="number" class="form-control extra-ingredient-quantity" 
                               name="extra_ingredients[${extraIngredientRowCount}][quantity]" 
                               placeholder="0.00" step="0.01" min="0.01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">
                            ${reviewTranslations.unit_label || 'Unit'}
                        </label>
                        <input type="text" class="form-control extra-ingredient-unit" 
                               name="extra_ingredients[${extraIngredientRowCount}][unit]" 
                               placeholder="${reviewTranslations.unit_placeholder || 'kg, g, pieces, etc.'}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small d-block">&nbsp;</label>
                        <button type="button" class="btn btn-sm btn-danger remove-extra-ingredient-btn">
                            <i class="bi bi-trash"></i> ${reviewTranslations.delete || 'Remove'}
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
        'persons' => $urduTranslations['persons'] ?? 'افراد',
        'extra_ingredients' => $urduTranslations['extra_ingredients'] ?? 'اضافی اجزاء',
        'additional_items' => $urduTranslations['additional_items'] ?? 'اضافی اشیاء',
        'cloth_malmal' => $urduTranslations['cloth_malmal'] ?? 'کپڑا ململ',
        'match_box' => $urduTranslations['match_box'] ?? 'میچ باکس',
        'surrf' => $urduTranslations['surrf'] ?? 'سرف',
        'wood' => $urduTranslations['wood'] ?? 'لکڑی',
        'sponjis_iron' => $urduTranslations['sponjis_iron'] ?? 'اسپنجز (آئرن)',
        'sobi_iron' => $urduTranslations['sobi_iron'] ?? 'صوبی(لوہے والی )',
        'steam_pot_with_lid' => $urduTranslations['steam_pot_with_lid'] ?? 'سٹیم پتیلہ جال ڈھکن',
        'deg' => $urduTranslations['deg'] ?? 'دیگ',
        'karahi' => $urduTranslations['karahi'] ?? 'کڑاہی',
        'chulhe' => $urduTranslations['chulhe'] ?? 'چولہے',
        'parat' => $urduTranslations['parat'] ?? 'پرات',
        'tub' => $urduTranslations['tub'] ?? 'ٹب',
        'shamiana' => $urduTranslations['shamiana'] ?? 'شامیانہ',
        'qanat' => $urduTranslations['qanat'] ?? 'قنات',
        'dari' => $urduTranslations['dari'] ?? 'دری',
        'charpai' => $urduTranslations['charpai'] ?? 'چارپائی',
        'coal' => $urduTranslations['coal'] ?? 'کوئلہ',
        'steam_pot_without_lid' => $urduTranslations['steam_pot_without_lid'] ?? 'سٹیم پتیلہ بغیر ڈھکن',
        'pieces' => $urduTranslations['pieces'] ?? 'عدد'
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
        'persons' => 'افراد',
        'extra_ingredients' => 'اضافی اجزاء',
        'additional_items' => 'اضافی اشیاء',
        'cloth_malmal' => 'کپڑا ململ',
        'match_box' => 'میچ باکس',
        'surrf' => 'سرف',
        'wood' => 'لکڑی',
        'sponjis_iron' => 'اسپنجز (آئرن)',
        'sobi_iron' => 'صوبی(لوہے والی )',
        'steam_pot_with_lid' => 'سٹیم پتیلہ جال ڈھکن',
        'deg' => 'دیگ',
        'karahi' => 'کڑاہی',
        'chulhe' => 'چولہے',
        'parat' => 'پرات',
        'tub' => 'ٹب',
        'shamiana' => 'شامیانہ',
        'qanat' => 'قنات',
        'dari' => 'دری',
        'charpai' => 'چارپائی',
        'coal' => 'کوئلہ',
        'steam_pot_without_lid' => 'سٹیم پتیلہ بغیر ڈھکن',
        'pieces' => 'عدد'
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
?>;
const currentLang = '<?php echo addslashes(getCurrentLanguage()); ?>';
const langDir = '<?php echo addslashes(getLanguageDirection()); ?>';
const ordersData = <?php 
try {
    // Clean grouped orders data for JSON encoding
    $cleanOrders = [];
    foreach ($all_grouped_orders as $grouped_order) {
        // Decode extra_ingredients JSON string if it exists
        $extra_ingredients_data = null;
        if (!empty($grouped_order['extra_ingredients'])) {
            $decoded = json_decode($grouped_order['extra_ingredients'], true);
            if ($decoded !== null) {
                $extra_ingredients_data = $decoded;
            } else {
                // If decode fails, keep as string and let JavaScript handle it
                $extra_ingredients_data = $grouped_order['extra_ingredients'];
            }
        }
        
        $orderData = [
            'order_number' => $grouped_order['order_number'] ?? '',
            'id' => $grouped_order['id'] ?? 0,
            'customer_name' => $grouped_order['customer_name'] ?? '',
            'customer_email' => $grouped_order['customer_email'] ?? '',
            'customer_cell' => $grouped_order['customer_cell'] ?? '',
            'order_date' => $grouped_order['order_date'] ?? '',
            'delivery_date' => $grouped_order['delivery_date'] ?? '',
            'delivery_time' => $grouped_order['delivery_time'] ?? '',
            'shift' => $grouped_order['shift'] ?? '',
            'number_of_persons' => $grouped_order['number_of_persons'] ?? 0,
            'status' => $grouped_order['status'] ?? 'pending',
            'total_amount' => $grouped_order['total_amount'] ?? 0,
            'notes' => $grouped_order['notes'] ?? '',
            'extra_ingredients' => $extra_ingredients_data,
            'dishes' => []
        ];
        
        foreach ($grouped_order['dishes'] as $dish) {
            $orderData['dishes'][] = [
                'dish_name' => $dish['dish_name'] ?? '',
                'dish_id' => $dish['dish_id'] ?? 0,
                'quantity' => $dish['quantity'] ?? 0,
                'unit' => $dish['unit'] ?? '',
                'total_amount' => $dish['total_amount'] ?? 0,
                'number_of_persons' => $orderData['number_of_persons'] ?? ($dish['number_of_persons'] ?? 1),
                'category_name' => $dish['dish_category_name'] ?? 'Uncategorized',
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
    const bannerImagePath = 'images/newimage.png';
    
    // Get number of persons from order level
    const totalPersons = parseInt(order.number_of_persons) || 0;
    
    // Shift translation mapping
    const shiftTranslations = {
        'afternoon': 'دوپہر',
        'evening': 'شام',
        '': ''
    };
    
    // Unit conversion helper functions
    // Check if a unit is a weight unit (can be converted to grams)
    function isWeightUnit(unit) {
        if (!unit) return false;
        const unitLower = unit.toLowerCase().trim();
        const weightUnits = ['kg', 'kilogram', 'kilograms', 'کلو', 'g', 'gram', 'grams', 'گرام', 'mg', 'milligram', 'milligrams', 'oz', 'ounce', 'lb', 'pound'];
        return weightUnits.includes(unitLower) || unit === 'کلو' || unit === 'گرام';
    }
    
    // Check if units are compatible (same type)
    function areUnitsCompatible(unit1, unit2) {
        if (!unit1 || !unit2) return false;
        const u1 = unit1.toLowerCase().trim();
        const u2 = unit2.toLowerCase().trim();
        
        // If both are weight units, they're compatible
        if (isWeightUnit(unit1) && isWeightUnit(unit2)) return true;
        
        // If both are volume units, they're compatible
        const volumeUnits = ['liter', 'litre', 'liters', 'litres', 'l', 'ml', 'milliliter', 'milliliters', 'cup', 'tbsp', 'tablespoon', 'tsp', 'teaspoon', 'oz_fluid', 'fl oz'];
        const isVol1 = volumeUnits.includes(u1);
        const isVol2 = volumeUnits.includes(u2);
        if (isVol1 && isVol2) return true;
        
        // If both are count units, they're compatible
        const countUnits = ['piece', 'pieces', 'عدد'];
        const isCount1 = countUnits.includes(u1);
        const isCount2 = countUnits.includes(u2);
        if (isCount1 && isCount2) return true;
        
        // If units are exactly the same, they're compatible
        if (u1 === u2) return true;
        
        return false;
    }
    
    function convertToGrams(quantity, unit) {
        if (!quantity || isNaN(quantity)) return 0;
        if (!isWeightUnit(unit)) {
            // For non-weight units, return null to indicate cannot convert
            return null;
        }
        
        const unitLower = (unit || '').toLowerCase().trim();
        const qty = parseFloat(quantity);
        
        // Convert to grams (base unit)
        if (unitLower === 'kg' || unitLower === 'kilogram' || unitLower === 'kilograms' || unit === 'کلو') {
            return qty * 1000; // kg to grams
        } else if (unitLower === 'g' || unitLower === 'gram' || unitLower === 'grams' || unit === 'گرام') {
            return qty; // already in grams
        } else if (unitLower === 'mg' || unitLower === 'milligram' || unitLower === 'milligrams') {
            return qty / 1000; // mg to grams
        } else if (unitLower === 'oz' || unitLower === 'ounce') {
            return qty * 28.3495; // oz to grams
        } else if (unitLower === 'lb' || unitLower === 'pound') {
            return qty * 453.592; // lb to grams
        }
        // For other weight units, assume grams
        return qty;
    }
    
    function convertFromGrams(grams, preferredUnit) {
        if (grams === null || grams === undefined) {
            // Cannot convert non-weight units
            return null;
        }
        if (!grams || isNaN(grams) || grams === 0) return { quantity: 0, unit: preferredUnit || 'g' };
        
        // If preferred unit is not a weight unit, preserve it as-is
        if (!isWeightUnit(preferredUnit)) {
            return { quantity: grams, unit: preferredUnit || 'g' };
        }
        
        const prefUnitLower = (preferredUnit || '').toLowerCase().trim();
        
        // Preserve the preferred unit if it's kg/kilogram/کلو
        if (prefUnitLower === 'kg' || prefUnitLower === 'kilogram' || prefUnitLower === 'kilograms' || preferredUnit === 'کلو') {
            return { quantity: grams / 1000, unit: 'kg' };
        }
        
        // If preferred unit is g/gram/grams/گرام, keep it in grams
        if (prefUnitLower === 'g' || prefUnitLower === 'gram' || prefUnitLower === 'grams' || preferredUnit === 'گرام') {
            return { quantity: grams, unit: 'g' };
        }
        
        // If preferred unit is oz/ounce
        if (prefUnitLower === 'oz' || prefUnitLower === 'ounce') {
            return { quantity: grams / 28.3495, unit: 'oz' };
        }
        
        // If preferred unit is lb/pound
        if (prefUnitLower === 'lb' || prefUnitLower === 'pound') {
            return { quantity: grams / 453.592, unit: 'lb' };
        }
        
        // If no preferred unit specified, use best fit: kg if >= 1000g, otherwise grams
        if (grams >= 1000) {
            return { quantity: grams / 1000, unit: 'kg' };
        }
        // Otherwise use grams
        return { quantity: grams, unit: 'g' };
    }
    
    // Collect all ingredients from all dishes in the order, grouped by dish name first, then by category
    // This ensures ingredients are completely shown and combined properly by dish name
    let ingredientsByDish = {};
    
    order.dishes.forEach(function(dish) {
        const dishName = dish.dish_name || 'Unknown Dish';
        const dishId = dish.dish_id || 0;
        const orderQuantity = parseFloat(dish.quantity) || 0;
        const dishUnit = dish.unit || ''; // Get dish unit from order
        const ingredients = dish.ingredients || [];
        
        // Use dish name as key to combine same dishes together
        // If same dish appears multiple times, combine all ingredients
        const dishKey = dishName.toLowerCase().trim();
        
        // Initialize dish if not exists
        if (!ingredientsByDish[dishKey]) {
            ingredientsByDish[dishKey] = {
                dish_name: dishName,
                dish_id: dishId,
                quantity: 0, // Will accumulate total quantity
                unit: dishUnit, // Store dish unit
                categories: {}
            };
        }
        
        // Accumulate dish quantity (in case same dish appears multiple times)
        ingredientsByDish[dishKey].quantity += orderQuantity;
        
        // Update unit if not set or if different (prefer non-empty unit)
        if (dishUnit && (!ingredientsByDish[dishKey].unit || ingredientsByDish[dishKey].unit === '')) {
            ingredientsByDish[dishKey].unit = dishUnit;
        }
        
        // Process all ingredients for this dish
        ingredients.forEach(function(ing) {
            // Use ingredient ID and name as key to properly combine duplicates
            const ingredientId = ing.ingredient_id || 0;
            const ingredientName = ing.ingredient_name || 'N/A';
            const key = ingredientId > 0 ? ingredientId : ingredientName.toLowerCase().trim();
            
            // Scale quantity by order quantity
            const scaledQuantity = (parseFloat(ing.quantity) || 0) * orderQuantity;
            const ingUnit = ing.unit || '';
            const categoryName = ing.category_name || 'بغیر زمرہ';
            const categoryId = ing.category_id || 'uncategorized';
            
            // Initialize category if not exists
            if (!ingredientsByDish[dishKey].categories[categoryId]) {
                ingredientsByDish[dishKey].categories[categoryId] = {
                    category_name: categoryName,
                    ingredients: {}
                };
            }
            
            // Add or update ingredient in category - combine quantities if same ingredient appears
            if (ingredientsByDish[dishKey].categories[categoryId].ingredients[key]) {
                const existingIng = ingredientsByDish[dishKey].categories[categoryId].ingredients[key];
                
                // Check if units are compatible for conversion
                if (areUnitsCompatible(existingIng.unit, ingUnit)) {
                    // Units are compatible - convert and combine
                    if (isWeightUnit(existingIng.unit) && isWeightUnit(ingUnit)) {
                        // Both are weight units - convert to grams, add, convert back
                        const existingGrams = convertToGrams(existingIng.quantity, existingIng.unit);
                        const newGrams = convertToGrams(scaledQuantity, ingUnit);
                        const totalGrams = existingGrams + newGrams;
                        
                        // Preserve the original unit from database - use the first one we encountered
                        const preferredUnit = existingIng.originalUnit || ingUnit || 'g';
                        const converted = convertFromGrams(totalGrams, preferredUnit);
                        
                        if (converted) {
                            existingIng.quantity = converted.quantity;
                            existingIng.unit = converted.unit;
                            // Keep the original unit from database (don't change it)
                            if (!existingIng.originalUnit) {
                                existingIng.originalUnit = preferredUnit;
                            }
                        }
                    } else {
                        // Non-weight units - just add quantities if units match exactly
                        if (existingIng.unit === ingUnit || (!existingIng.unit && !ingUnit)) {
                            existingIng.quantity = parseFloat(existingIng.quantity) + parseFloat(scaledQuantity);
                            existingIng.unit = existingIng.unit || ingUnit;
                            existingIng.originalUnit = existingIng.originalUnit || ingUnit;
                        } else {
                            // Units don't match - keep existing, but track separately
                            existingIng.quantity = parseFloat(existingIng.quantity);
                            existingIng.unit = existingIng.unit;
                            existingIng.originalUnit = existingIng.originalUnit || existingIng.unit;
                        }
                    }
                } else {
                    // Units are not compatible - keep existing unit, don't combine
                    existingIng.quantity = parseFloat(existingIng.quantity);
                    existingIng.unit = existingIng.unit;
                    existingIng.originalUnit = existingIng.originalUnit || existingIng.unit;
                }
            } else {
                // Add new ingredient - preserve the original unit from database
                const originalUnit = ingUnit || '';
                
                ingredientsByDish[dishKey].categories[categoryId].ingredients[key] = {
                    ingredient_id: ingredientId,
                    ingredient_name: ingredientName,
                    quantity: scaledQuantity,
                    unit: originalUnit, // Keep original unit from database
                    originalUnit: originalUnit // Store original unit from database
                };
            }
        });
    });
    
    // Process extra ingredients from Step 2
    // Get ingredientsData from the parent window (before opening new window)
    const ingredientsData = typeof window.ingredientsData !== 'undefined' ? window.ingredientsData : [];
    
    if (order.extra_ingredients) {
        try {
            let extraIngredientsData;
            if (typeof order.extra_ingredients === 'string') {
                extraIngredientsData = JSON.parse(order.extra_ingredients);
            } else {
                extraIngredientsData = order.extra_ingredients;
            }
            
            // Process extra_ingredients array
            if (extraIngredientsData && extraIngredientsData.extra_ingredients && Array.isArray(extraIngredientsData.extra_ingredients)) {
                extraIngredientsData.extra_ingredients.forEach(function(extraIng) {
                    const ingredientId = parseInt(extraIng.ingredient_id) || 0;
                    const quantity = parseFloat(extraIng.quantity) || 0;
                    
                    if (ingredientId > 0 && quantity > 0) {
                        // Look up ingredient details from ingredientsData
                        const ingredientInfo = ingredientsData.find(function(i) {
                            return i.id == ingredientId || parseInt(i.id) == ingredientId;
                        });
                        
                        let categoryName, categoryId, ingredientName, unit;
                        
                        if (ingredientInfo) {
                            // Use data from ingredientsData
                            categoryName = ingredientInfo.category_name || 'بغیر زمرہ';
                            categoryId = ingredientInfo.category_id || 'uncategorized';
                            ingredientName = ingredientInfo.name || 'N/A';
                            unit = extraIng.unit || ingredientInfo.unit || '';
                        } else {
                            // Fallback: use data from extra ingredient or default values
                            categoryName = translations.extra_ingredients || 'اضافی اجزاء';
                            categoryId = 'extra_ingredients';
                            ingredientName = 'جزو #' + ingredientId;
                            unit = extraIng.unit || '';
                            
                            if (ingredientsData.length > 0) {
                                console.warn('Ingredient not found in ingredientsData:', ingredientId, 'Available:', ingredientsData.map(i => i.id));
                            }
                        }
                        
                        const key = ingredientId;
                        
                        // Add extra ingredients to a special "extra" dish or create one
                        const extraDishId = 'extra_ingredients_dish';
                        if (!ingredientsByDish[extraDishId]) {
                            ingredientsByDish[extraDishId] = {
                                dish_name: translations.extra_ingredients || 'اضافی اجزاء',
                                dish_id: extraDishId,
                                quantity: 1,
                                categories: {}
                            };
                        }
                        
                        // Initialize category if not exists
                        if (!ingredientsByDish[extraDishId].categories[categoryId]) {
                            ingredientsByDish[extraDishId].categories[categoryId] = {
                                category_name: categoryName,
                                ingredients: {}
                            };
                        }
                        
                        // Add or update ingredient in category - FIXED: Convert units before combining
                        if (ingredientsByDish[extraDishId].categories[categoryId].ingredients[key]) {
                            const existingIng = ingredientsByDish[extraDishId].categories[categoryId].ingredients[key];
                            
                            // Check if units are compatible
                            if (areUnitsCompatible(existingIng.unit, unit)) {
                                if (isWeightUnit(existingIng.unit) && isWeightUnit(unit)) {
                                    // Both are weight units - convert to grams, add, convert back
                                    const existingGrams = convertToGrams(existingIng.quantity, existingIng.unit);
                                    const newGrams = convertToGrams(quantity, unit);
                                    if (existingGrams !== null && newGrams !== null) {
                                        const totalGrams = existingGrams + newGrams;
                                        // Preserve original unit from database
                                        const preferredUnit = existingIng.originalUnit || unit || 'g';
                                        const converted = convertFromGrams(totalGrams, preferredUnit);
                                        
                                        if (converted) {
                                            existingIng.quantity = converted.quantity;
                                            existingIng.unit = converted.unit;
                                            // Keep the original unit from database (don't change it)
                                            if (!existingIng.originalUnit) {
                                                existingIng.originalUnit = preferredUnit;
                                            }
                                        }
                                    }
                                } else {
                                    // Non-weight units - add if units match
                                    if (existingIng.unit === unit || (!existingIng.unit && !unit)) {
                                        existingIng.quantity = parseFloat(existingIng.quantity) + parseFloat(quantity);
                                        existingIng.unit = existingIng.unit || unit;
                                        existingIng.originalUnit = existingIng.originalUnit || unit;
                                    }
                                }
                            }
                        } else {
                            // Add new ingredient - preserve the original unit from database
                            const originalUnit = unit || '';
                            
                            ingredientsByDish[extraDishId].categories[categoryId].ingredients[key] = {
                                ingredient_name: ingredientName,
                                quantity: quantity,
                                unit: originalUnit, // Keep original unit from database
                                originalUnit: originalUnit // Store original unit from database
                            };
                        }
                    }
                });
            }
            
            // Process additional_items object
            if (extraIngredientsData && extraIngredientsData.additional_items && typeof extraIngredientsData.additional_items === 'object') {
                const additionalItemsMap = {
                    'cloth_malmal': translations.cloth_malmal || 'کپڑا ململ',
                    'match_box': translations.match_box || 'میچ باکس',
                    'surrf': translations.surrf || 'سرف',
                    'wood': translations.wood || 'لکڑی',
                    'sponjis_iron': translations.sponjis_iron || 'اسپنجز (آئرن)',
                    'sobi_iron': translations.sobi_iron || 'صوبی(لوہے والی )',
                    'steam_pot_with_lid': translations.steam_pot_with_lid || 'سٹیم پتیلہ جال ڈھکن',
                    'deg': translations.deg || 'دیگ',
                    'karahi': translations.karahi || 'کڑاہی',
                    'chulhe': translations.chulhe || 'چولہے',
                    'parat': translations.parat || 'پرات',
                    'tub': translations.tub || 'ٹب',
                    'shamiana': translations.shamiana || 'شامیانہ',
                    'qanat': translations.qanat || 'قنات',
                    'dari': translations.dari || 'دری',
                    'charpai': translations.charpai || 'چارپائی',
                    'coal': translations.coal || 'کوئلہ',
                    'steam_pot_without_lid': translations.steam_pot_without_lid || 'سٹیم پتیلہ بغیر ڈھکن'
                };
                
                // Create a special category for additional items
                const additionalItemsCategoryId = 'additional_items';
                const additionalItemsCategoryName = translations.additional_items || 'اضافی اشیاء';
                const additionalItemsDishId = 'additional_items_dish';
                
                if (!ingredientsByDish[additionalItemsDishId]) {
                    ingredientsByDish[additionalItemsDishId] = {
                        dish_name: translations.additional_items || 'اضافی اشیاء',
                        dish_id: additionalItemsDishId,
                        quantity: 1,
                        categories: {}
                    };
                }
                
                // Initialize category if not exists
                if (!ingredientsByDish[additionalItemsDishId].categories[additionalItemsCategoryId]) {
                    ingredientsByDish[additionalItemsDishId].categories[additionalItemsCategoryId] = {
                        category_name: additionalItemsCategoryName,
                        ingredients: {}
                    };
                }
                
                // Process each additional item
                Object.keys(extraIngredientsData.additional_items).forEach(function(itemKey) {
                    const quantity = parseInt(extraIngredientsData.additional_items[itemKey]) || 0;
                    
                    if (quantity > 0) {
                        const itemName = additionalItemsMap[itemKey] || itemKey;
                        const key = 'additional_' + itemKey;
                        
                        // Set unit: meter for cloth_malmal, gram for surrf, kilo for wood, pieces for others
                        let unit = 'عدد'; // Default to pieces
                        if (itemKey === 'cloth_malmal') {
                            unit = 'میٹر'; // Meter for cloth
                        } else if (itemKey === 'surrf') {
                            unit = 'گرام'; // Gram for surrf
                        } else if (itemKey === 'wood') {
                            unit = 'کلو'; // Kilo for wood
                        }
                        
                        // Add or update additional item in category
                        if (ingredientsByDish[additionalItemsDishId].categories[additionalItemsCategoryId].ingredients[key]) {
                            ingredientsByDish[additionalItemsDishId].categories[additionalItemsCategoryId].ingredients[key].quantity += quantity;
                        } else {
                            ingredientsByDish[additionalItemsDishId].categories[additionalItemsCategoryId].ingredients[key] = {
                                ingredient_name: itemName,
                                quantity: quantity,
                                unit: unit
                            };
                        }
                    }
                });
            }
        } catch (e) {
            console.error('Error processing extra ingredients:', e, order.extra_ingredients);
        }
    }
    
    // Force RTL for Urdu
    const textAlign = 'right';
    const textAlignOpposite = 'left';
    const fontFamily = 'Arial, "Noto Sans Arabic", "Segoe UI", Tahoma, sans-serif';
    
    let ingredientsHtml = '<div style="direction: rtl;">';
    
    // Check if we have any ingredients
    const dishKeys = Object.keys(ingredientsByDish);
    if (dishKeys.length === 0) {
        ingredientsHtml += '<table class="ingredients-table" style="width: 100%; border-collapse: collapse; margin-top: 12px; direction: rtl; font-size: 13px;">';
        ingredientsHtml += '<thead><tr style="background-color: #f8fafc;"><th style="padding: 8px 10px; border: 1px solid #e2e8f0; text-align: right; font-family: ' + fontFamily + '; font-size: 13px;">' + translations.ingredient_label + '</th><th style="padding: 8px 10px; border: 1px solid #e2e8f0; text-align: right; font-family: ' + fontFamily + '; font-size: 13px;">' + translations.quantity_label + ' / ' + translations.unit_label + '</th></tr></thead>';
        ingredientsHtml += '<tbody>';
        ingredientsHtml += '<tr><td colspan="2" style="padding: 8px 10px; border: 1px solid #ddd; text-align: center; font-family: ' + fontFamily + '; font-size: 13px; line-height: 1.5;">' + translations.no_ingredients_found + '</td></tr>';
        ingredientsHtml += '</tbody></table>';
    } else {
        // Collect all ingredients grouped by category from all dishes
        const ingredientsByCategory = {};
        
        // Loop through all dishes and collect ingredients by category
        dishKeys.forEach(function(dishKey) {
            const dish = ingredientsByDish[dishKey];
            const categoryKeys = Object.keys(dish.categories);
            
            categoryKeys.forEach(function(categoryId) {
                const category = dish.categories[categoryId];
                const categoryName = category.category_name || 'بغیر زمرہ';
                const categoryIngredients = Object.values(category.ingredients);
                
                // Initialize category if not exists
                if (!ingredientsByCategory[categoryId]) {
                    ingredientsByCategory[categoryId] = {
                        category_name: categoryName,
                        ingredients: {}
                    };
                }
                
                // Add each ingredient to the category map
                categoryIngredients.forEach(function(ing) {
                    const ingredientId = ing.ingredient_id || 0;
                    const ingredientName = ing.ingredient_name || 'N/A';
                    const key = ingredientId > 0 ? ingredientId : ingredientName.toLowerCase().trim();
                    
                    // Combine ingredients with same name/ID within the category - FIXED: Convert units before adding
                    if (ingredientsByCategory[categoryId].ingredients[key]) {
                        const existingIng = ingredientsByCategory[categoryId].ingredients[key];
                        
                        // Check if units are compatible
                        if (areUnitsCompatible(existingIng.unit, ing.unit || '')) {
                            if (isWeightUnit(existingIng.unit) && isWeightUnit(ing.unit || '')) {
                                // Both are weight units - convert to grams, add, convert back
                                const existingGrams = convertToGrams(existingIng.quantity, existingIng.unit);
                                const newGrams = convertToGrams(parseFloat(ing.quantity) || 0, ing.unit || '');
                                
                                if (existingGrams !== null && newGrams !== null) {
                                    const totalGrams = existingGrams + newGrams;
                                    // Preserve original unit from database
                                    const preferredUnit = existingIng.originalUnit || ing.originalUnit || ing.unit || 'g';
                                    const converted = convertFromGrams(totalGrams, preferredUnit);
                                    
                                    if (converted) {
                                        existingIng.quantity = converted.quantity;
                                        existingIng.unit = converted.unit;
                                        // Keep the original unit from database (don't change it)
                                        if (!existingIng.originalUnit) {
                                            existingIng.originalUnit = preferredUnit;
                                        }
                                    }
                                }
                            } else {
                                // Non-weight units - add if units match
                                if (existingIng.unit === (ing.unit || '') || (!existingIng.unit && !ing.unit)) {
                                    existingIng.quantity = parseFloat(existingIng.quantity) + parseFloat(ing.quantity || 0);
                                    existingIng.unit = existingIng.unit || ing.unit || '';
                                    existingIng.originalUnit = existingIng.originalUnit || existingIng.unit || ing.originalUnit || ing.unit || '';
                                }
                            }
                        }
                    } else {
                        // Add new ingredient - preserve original unit
                        const originalUnit = ing.originalUnit || ing.unit || '';
                        ingredientsByCategory[categoryId].ingredients[key] = {
                            ingredient_id: ingredientId,
                            ingredient_name: ingredientName,
                            quantity: parseFloat(ing.quantity) || 0,
                            unit: ing.unit || '',
                            originalUnit: originalUnit
                        };
                    }
                });
            });
        });
        
        // Get category IDs and sort them
        const categoryIds = Object.keys(ingredientsByCategory);
        
        if (categoryIds.length > 0) {
            // Display ingredients grouped by category
            categoryIds.forEach(function(categoryId) {
                const category = ingredientsByCategory[categoryId];
                const categoryName = category.category_name || 'بغیر زمرہ';
                const ingredients = Object.values(category.ingredients);
                
                // Sort ingredients alphabetically by name
                ingredients.sort((a, b) => {
                    const nameA = a.ingredient_name || '';
                    const nameB = b.ingredient_name || '';
                    return nameA.localeCompare(nameB);
                });
                
                if (ingredients.length > 0) {
                    // Category header
                    ingredientsHtml += '<div class="category-section">';
                    ingredientsHtml += '<div class="category-header">' + categoryName + '</div>';
                    
                    // Ingredients grid for this category
                    ingredientsHtml += '<div class="ingredients-grid">';
                    
                    ingredients.forEach(function(ing) {
                        let quantity = parseFloat(ing.quantity) || 0;
                        // Use originalUnit (from database) if available, otherwise use unit
                        let unit = ing.originalUnit || ing.unit || '';
                        
                        // Function to translate unit to Urdu
                        function translateUnitToUrdu(unit) {
                            if (!unit) return '';
                            const unitLower = unit.toLowerCase().trim();
                            
                            const unitTranslations = {
                                'kg': 'کلو',
                                'kilogram': 'کلو',
                                'kilograms': 'کلو',
                                'g': 'گرام',
                                'gram': 'گرام',
                                'grams': 'گرام',
                                'piece': 'عدد',
                                'pieces': 'عدد',
                                'serving': 'حصہ',
                                'servings': 'حصے',
                                'portion': 'حصہ',
                                'portions': 'حصے',
                                'item': 'شے',
                                'items': 'اشیاء',
                                'ml': 'ملی لیٹر',
                                'milliliter': 'ملی لیٹر',
                                'milliliters': 'ملی لیٹر',
                                'l': 'لیٹر',
                                'liter': 'لیٹر',
                                'liters': 'لیٹر',
                                'litre': 'لیٹر',
                                'litres': 'لیٹر',
                                'meter': 'میٹر',
                                'meters': 'میٹر',
                                'metre': 'میٹر',
                                'metres': 'میٹر',
                                'میٹر': 'میٹر',
                                'عدد': 'عدد',
                                'گچھی': 'گچھی',
                                'guchhi': 'گچھی',
                                'bunch': 'گچھی',
                                'کلو': 'کلو',
                                'لیٹر': 'لیٹر',
                                'دیگ': 'دیگ',
                                'ڈیگ': 'دیگ',
                                'deg': 'دیگ',
                                'گرام': 'گرام'
                            };
                            return unitTranslations[unitLower] || unit;
                        }
                        
                        // Format quantity based on unit type
                        // Use original unit from database, only convert g to kg when >= 1000
                        let unitLower = unit.toLowerCase().trim();
                        const gramUnits = ['g', 'gram', 'grams', 'گرام'];
                        let quantityUnit = '';
                        
                        // Only convert g to kg if quantity >= 1000, preserve all other units as-is
                        const isGramUnit = gramUnits.includes(unitLower) || unit === 'گرام';
                        if (isGramUnit && quantity >= 1000) {
                            // Convert grams to kg for display only
                            quantity = quantity / 1000;
                            unit = 'kg';
                            unitLower = 'kg';
                        }
                        
                        const finalUnitLower = unitLower;
                        
                        // Special handling for kg/kilogram: split into kilos and grams
                        if (finalUnitLower === 'kg' || finalUnitLower === 'kilogram' || finalUnitLower === 'kilograms' || unit === 'کلو') {
                            const totalKilos = parseFloat(quantity);
                            const wholeKilos = Math.floor(totalKilos);
                            const decimalPart = totalKilos - wholeKilos;
                            const grams = Math.round(decimalPart * 1000);
                            
                            if (wholeKilos > 0 && grams > 0) {
                                quantityUnit = wholeKilos + ' کلو اور ' + grams + ' گرام';
                            } else if (wholeKilos > 0) {
                                quantityUnit = wholeKilos + ' کلو';
                            } else if (grams > 0) {
                                quantityUnit = grams + ' گرام';
                            } else {
                                quantityUnit = '0 کلو';
                            }
                        } else {
                            let displayQuantity = quantity;
                            const numericQuantity = parseFloat(quantity);
                            const hasNumericQuantity = !isNaN(numericQuantity);
                            
                            if (hasNumericQuantity) {
                                // Check if unit is grams (English or Urdu)
                                const isGramUnitFinal = gramUnits.includes(finalUnitLower) || unit === 'گرام' || unit.trim() === 'گرام';
                                if (isGramUnitFinal) {
                                    // For grams, show as integer if whole number, otherwise show with decimals
                                    if (numericQuantity % 1 === 0) {
                                        displayQuantity = numericQuantity.toString();
                                    } else {
                                        displayQuantity = numericQuantity.toFixed(2).replace(/\.0+$/, '');
                                    }
                                } else {
                                    displayQuantity = Math.round(numericQuantity).toString();
                                }
                            }
                            
                            const unitUrdu = translateUnitToUrdu(unit);
                            quantityUnit = displayQuantity + (unitUrdu ? ' ' + unitUrdu : '');
                        }
                        
                        // Calculation details are hidden from display as requested
                        const ingredientName = ing.ingredient_name || 'N/A';
                        
                        // Format for 6-column layout: Clean aligned display
                        ingredientsHtml += '<div class="ingredient-item">';
                        ingredientsHtml += '<div class="name">' + ingredientName + '</div>';
                        ingredientsHtml += '<div class="quantity">' + quantityUnit + '</div>';
                        ingredientsHtml += '</div>';
                    });
                    
                    ingredientsHtml += '</div>'; // Close grid
                    ingredientsHtml += '</div>'; // Close category section
                }
            });
        } else {
            ingredientsHtml += '<p style="text-align: center; color: #64748b; font-size: 14px; margin: 10px 0; font-family: ' + fontFamily + ';">' + translations.no_ingredients_found + '</p>';
        }
    }
    
    ingredientsHtml += '</div>';
    
    // Format date and time for display
    function formatDateForPrint(dateString) {
        if (!dateString) return '';
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString('ur-PK', { year: 'numeric', month: '2-digit', day: '2-digit' });
        } catch (e) {
            return dateString;
        }
    }
    
    function formatTimeForPrint(dateString) {
        if (!dateString) return '';
        try {
            const date = new Date(dateString);
            return date.toLocaleTimeString('ur-PK', { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return dateString;
        }
    }
    
    // Get order date and time
    const orderDate = order.order_date ? formatDateForPrint(order.order_date) : '';
    const orderTime = order.order_date ? formatTimeForPrint(order.order_date) : '';
    const deliveryDate = order.delivery_date ? formatDateForPrint(order.delivery_date) : '';
    const shiftText = order.shift ? (shiftTranslations[order.shift] || order.shift) : '';
    
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
                        size: Legal;
                        margin: 0.3cm;
                    }
                    * {
                        page-break-inside: avoid !important;
                    }
                    body { 
                        margin: 0 !important; 
                        padding: 0 !important; 
                        position: relative; 
                        font-size: 10px !important;
                        page-break-inside: avoid !important;
                    }
                    
                    body::before {
                        content: '' !important;
                        position: fixed !important;
                        top: 50% !important;
                        left: 50% !important;
                        transform: translate(-50%, -50%) !important;
                        width: 60% !important;
                        height: 60% !important;
                        min-width: 400px !important;
                        min-height: 400px !important;
                        background-image: url('images/watermark.jpg') !important;
                        background-repeat: no-repeat !important;
                        background-position: center center !important;
                        background-size: contain !important;
                        opacity: 0.15 !important;
                        z-index: -1 !important;
                        pointer-events: none !important;
                    }
                    .no-print { display: none !important; }
                    .header-image {
                        page-break-after: avoid !important;
                        page-break-inside: avoid !important;
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                        margin-bottom: 10px !important;
                    }
                    .header-image img {
                        width: 100% !important;
                        height: auto !important;
                        object-fit: contain !important;
                    }
                    .order-details-table {
                        page-break-after: avoid !important;
                        page-break-inside: avoid !important;
                        margin: 5px 0 !important;
                        table-layout: fixed !important;
                    }
                    .order-details-table td {
                        width: 20% !important;
                        height: 35px !important;
                        padding: 6px 4px !important;
                        font-size: 10px !important;
                        vertical-align: middle !important;
                        overflow: hidden !important;
                        word-wrap: break-word !important;
                    }
                    .table-note {
                        text-align: center !important;
                        margin: 8px 0 !important;
                        font-size: 20px !important;
                        font-weight: bold !important;
                    }
                    .ingredients-section {
                        page-break-inside: avoid !important;
                        page-break-before: avoid !important;
                        margin-top: 5px !important;
                    }
                    .ingredients-title {
                        font-size: 18px !important;
                        margin-bottom: 6px !important;
                        margin-top: 3px !important;
                        line-height: 1.2 !important;
                    }
                    .category-section {
                        page-break-inside: avoid !important;
                        margin: 4px 0 !important;
                    }
                    .category-header {
                        font-size: 15px !important;
                        font-weight: 900 !important;
                        padding: 10px 14px !important;
                        margin: 0 0 8px 0 !important;
                        background-color: #4c51bf !important;
                        background: #4c51bf !important;
                        color: #ffffff !important;
                        border: 3px solid #2d3748 !important;
                        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.3) !important;
                        text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.5) !important;
                        -webkit-print-color-adjust: exact !important;
                        print-color-adjust: exact !important;
                        color-adjust: exact !important;
                    }
                    [style*="grid-template-columns"] {
                        display: grid !important;
                        grid-template-columns: repeat(5, 1fr) !important;
                        gap: 3px !important;
                    }
                    [style*="grid-template-columns"] > div {
                        page-break-inside: avoid !important;
                        padding: 3px 4px !important;
                    }
                    .ingredient-item {
                        padding: 5px 6px !important;
                    }
                    .ingredient-item .name {
                        font-size: 13px !important;
                        margin-bottom: 3px !important;
                    }
                    .ingredient-item .quantity {
                        font-size: 11px !important;
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
                
                body::before {
                    content: '';
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%);
                    width: 60%;
                    height: 60%;
                    min-width: 400px;
                    min-height: 400px;
                    background-image: url('images/watermark.jpg');
                    background-repeat: no-repeat;
                    background-position: center center;
                    background-size: contain;
                    opacity: 0.15;
                    z-index: -1;
                    pointer-events: none;
                }
                
                /* Header Image */
                .header-image {
                    width: 100%;
                    margin-bottom: 12px;
                    display: block;
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    color-adjust: exact !important;
                }
                .header-image img {
                    width: 100%;
                    height: auto;
                    display: block;
                    -webkit-print-color-adjust: exact !important;
                    print-color-adjust: exact !important;
                    color-adjust: exact !important;
                }
                
                /* Order Details Table */
                .order-details-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 8px 0;
                    border: 1px solid #ddd;
                    background-color: #fff;
                    table-layout: fixed;
                }
                .order-details-table td {
                    width: 20%;
                    height: 35px;
                    padding: 8px 6px;
                    border-left: 1px solid #ddd;
                    border-right: 1px solid #ddd;
                    text-align: center;
                    border-bottom: 1px dotted #999;
                    background-color: #fff;
                    font-size: 11px;
                    vertical-align: middle;
                    overflow: hidden;
                    word-wrap: break-word;
                }
                .order-details-table tbody tr:first-child td {
                    background-color: #e9ecef;
                    font-weight: normal;
                    font-size: 12px;
                }
                .order-details-table tbody tr:first-child td strong {
                    font-weight: bold;
                    margin-left: 5px;
                }
                .order-details-table tbody tr:last-child td {
                    border-bottom: 1px solid #ddd;
                }
                
                /* Ingredients Section */
                .ingredients-section {
                    margin-top: 10px;
                    page-break-before: avoid;
                }
                .ingredients-title {
                    font-size: 20px;
                    font-weight: bold;
                    text-align: center;
                    margin-bottom: 10px;
                    margin-top: 5px;
                    color: #1e293b;
                    line-height: 1.3;
                }
                .category-section {
                    margin-top: 10px;
                    margin-bottom: 8px;
                }
                .category-header {
                    font-size: 17px;
                    font-weight: 900;
                    color: #ffffff;
                    padding: 12px 16px;
                    background: #4c51bf;
                    background-color: #4c51bf;
                    border-radius: 6px;
                    margin: 0 0 10px 0;
                    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.3);
                    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
                    border: 3px solid #2d3748;
                    letter-spacing: 0.8px;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                    color-adjust: exact;
                }
                .ingredients-grid {
                    display: grid;
                    grid-template-columns: repeat(5, 1fr);
                    gap: 4px;
                    margin-bottom: 8px;
                }
                .ingredient-item {
                    padding: 6px 8px;
                    border: 1px solid #e2e8f0;
                    border-radius: 3px;
                    background-color: #ffffff;
                }
                .ingredient-item .name {
                    font-size: 14px;
                    font-weight: bold;
                    color: #1e293b;
                    margin-bottom: 3px;
                    line-height: 1.4;
                }
                .ingredient-item .quantity {
                    font-size: 12px;
                    color: #8b5cf6;
                    font-weight: 600;
                }
                
                .table-note {
                    text-align: center;
                    margin: 10px 0;
                    font-size: 22px;
                    color: #1e293b;
                    font-weight: bold;
                }
                
                .print-btn { 
                    margin: 15px 0; 
                    text-align: center; 
                }
                button { 
                    padding: 8px 16px; 
                    background: #8b5cf6; 
                    color: white; 
                    border: none; 
                    cursor: pointer; 
                    border-radius: 5px; 
                    font-size: 12px; 
                    margin: 0 5px;
                }
                button:hover { 
                    background: #7c3aed; 
                }
            </style>
        </head>
        <body>
            <!-- Header Image -->
            <div class="header-image">
                <img src="images/newimage.png" alt="Header Banner" onerror="console.error('Failed to load header image:', this.src);">
            </div>
            
            <!-- Order Details Table -->
            <table class="order-details-table">
                <tbody>
                    <!-- First row with header and value in same cell -->
                    <tr>
                        <td><strong>گایک:</strong> ${order.customer_name || ''}${order.customer_cell ? ' (' + order.customer_cell + ')' : ''}</td>
                        <td><strong>افراد:</strong> ${totalPersons > 0 ? totalPersons : ''}</td>
                        <td><strong>تاريخ:</strong> ${deliveryDate}</td>
                        <td><strong>شفٹ:</strong> ${shiftText}</td>
                        <td><strong>وقت:</strong> ${orderTime}</td>
                    </tr>
                    <!-- Additional rows with dish names -->
                    ${(() => {
                        const dishes = order.dishes && order.dishes.length > 0 ? order.dishes : [];
                        let rows = '';
                        
                        // Fill columns sequentially: 4 dishes per column
                        // Column 1: dishes 0-3, Column 2: dishes 4-7, Column 3: dishes 8-11, etc.
                        const dishesPerColumn = 4;
                        const numColumns = 5;
                        const numRows = dishesPerColumn;
                        
                        // Create rows, filling columns sequentially
                        for (let row = 0; row < numRows; row++) {
                            let cells = '';
                            for (let col = 0; col < numColumns; col++) {
                                const dishIndex = col * dishesPerColumn + row; // Column 0: 0-3, Column 1: 4-7, etc.
                                if (dishIndex < dishes.length) {
                                    const dish = dishes[dishIndex];
                                    const dishName = dish ? (dish.dish_name || '') : '';
                                    const dishQuantity = dish ? (parseFloat(dish.quantity) || 0) : 0;
                                    const dishUnit = dish ? (dish.unit || '') : '';
                                    const displayText = dishName + (dishQuantity > 0 ? ' (' + dishQuantity + (dishUnit ? ' ' + dishUnit : '') + ')' : '');
                                    cells += `<td>${displayText}</td>`;
                                } else {
                                    cells += `<td></td>`;
                                }
                            }
                            rows += `<tr>${cells}</tr>`;
                        }
                        return rows;
                    })()}
                </tbody>
            </table>
            
            <!-- Table Note -->
            <div class="table-note">
                (نوٹ :مرغی وزن سوا تا ڈیڑھ کلو)
            </div>
            
            <!-- Ingredients List Section -->
            <div class="ingredients-section">
                ${ingredientsHtml}
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
    const bannerImagePath = 'images/newimage.png';
    
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
    
    // Get number of persons from order level (not from dish)
    const totalPersons = parseInt(order.number_of_persons) || 0;
    
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
                        size: Legal;
                        margin: 0.5cm;
                    }
                    body { margin: 0; padding: 15px; position: relative; font-size: 14px !important; }
                    .no-print { display: none; }
                    .print-banner { min-height: 120px !important; margin-bottom: 15px !important; }
                    .banner-left-name { font-size: 20px !important; margin-bottom: 6px !important; }
                    .banner-left-phone { font-size: 13px !important; }
                    .banner-right-service { font-size: 15px !important; }
                    .banner-right-service.yellow { font-size: 17px !important; }
                    .banner-address-bar { padding: 8px 12px !important; margin-top: 8px !important; }
                    .banner-address-text { font-size: 12px !important; }
                    .dish-names-section {
                        margin: 8px 0 !important;
                        padding: 8px !important;
                        page-break-inside: avoid !important;
                    }
                    .dish-names-section h2 {
                        font-size: 13px !important;
                        margin-bottom: 6px !important;
                        padding: 3px 0 !important;
                    }
                    .dish-names-section [style*="grid-template-columns"] {
                        grid-template-columns: repeat(2, 1fr) !important;
                        gap: 6px !important;
                    }
                    .dish-names-section [style*="grid-template-columns"] > div {
                        padding: 6px 8px !important;
                        font-size: 13px !important;
                    }
                    .fillable-section { 
                        margin: 15px 0 !important; 
                        padding: 12px !important;
                        display: grid !important;
                        grid-template-columns: repeat(2, 1fr) !important;
                        gap: 10px !important;
                    }
                    .fillable-field { margin-bottom: 0 !important; }
                    .fillable-label { font-size: 15px !important; }
                    .fillable-space { height: 28px !important; }
                    .header { margin-bottom: 15px !important; padding-bottom: 12px !important; }
                    .header h1 { font-size: 22px !important; }
                    .header p { font-size: 15px !important; margin: 5px 0 !important; }
                    .order-info { margin: 15px 0 !important; }
                    .order-info p { margin: 8px 0 !important; font-size: 13px !important; }
                    .order-details { padding: 18px !important; margin: 15px 0 !important; }
                    .order-details h3 { font-size: 18px !important; margin-top: 0 !important; }
                    .detail-row { margin: 10px 0 !important; padding: 8px 0 !important; font-size: 13px !important; }
                    .total-section { margin-top: 18px !important; padding-top: 18px !important; }
                    .total-row { font-size: 20px !important; margin: 12px 0 !important; }
                    .notes { margin-top: 15px !important; padding: 12px !important; font-size: 13px !important; }
                    .footer { margin-top: 18px !important; padding-top: 15px !important; font-size: 13px !important; }
                }
                body { font-family: ${fontFamily}; padding: 18px; max-width: 800px; margin: 0 auto; position: relative; direction: ${langDir}; font-size: 14px; }
                
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
                    font-size: 22px;
                    font-weight: 900;
                    color: #000;
                    margin-bottom: 8px;
                    text-align: center;
                    font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif;
                    line-height: 1.4;
                }
                .banner-left-phone {
                    font-size: 14px;
                    color: #000;
                    margin: 4px 0;
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
                    font-size: 16px;
                    font-weight: 700;
                    margin: 4px 0;
                    text-align: center;
                    font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif;
                    line-height: 1.4;
                    text-shadow: 1px 1px 1px rgba(0,0,0,0.1);
                }
                .banner-right-service.yellow {
                    color: #FFD700;
                    font-size: 18px;
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
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 8px;
                }
                .fillable-field {
                    display: flex;
                    align-items: center;
                    margin-bottom: 0;
                    direction: rtl;
                }
                .fillable-label {
                    font-weight: bold;
                    font-size: 16px;
                    color: #333;
                    min-width: 100px;
                    margin-left: 15px;
                }
                .fillable-space {
                    flex: 1;
                    border-bottom: 2px solid #000;
                    height: 30px;
                    margin: 0 12px;
                }
                
                .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #1e293b; padding-bottom: 15px; position: relative; z-index: 1; }
                .header h1 { margin: 0; color: #1e293b; font-size: 24px; }
                .header p { margin: 6px 0; color: #64748b; font-size: 16px; }
                .order-info { margin: 18px 0; position: relative; z-index: 1; }
                .order-info p { margin: 8px 0; font-size: 14px; }
                .order-details { background: #f8fafc; padding: 20px; border-radius: 5px; margin: 18px 0; position: relative; z-index: 1; }
                .order-details h3 { margin-top: 0; font-size: 20px; }
                .detail-row { display: flex; justify-content: space-between; margin: 12px 0; padding: 8px 0; border-bottom: 1px solid #e2e8f0; flex-direction: ${langDir === 'rtl' ? 'row-reverse' : 'row'}; font-size: 14px; }
                .detail-row:last-child { border-bottom: none; }
                .detail-label { font-weight: bold; }
                .category-badge { display: inline-block; padding: 4px 10px; background: #e0e7ff; color: #4338ca; border-radius: 12px; font-size: 12px; font-weight: 600; margin-left: 8px; }
                ${langDir === 'rtl' ? '.notes { border-left: none; border-right: 4px solid #f59e0b; }' : ''}
                .total-section { margin-top: 20px; padding-top: 20px; border-top: 2px solid #1e293b; position: relative; z-index: 1; }
                .total-row { display: flex; justify-content: space-between; font-size: 22px; font-weight: bold; margin: 12px 0; flex-direction: ${langDir === 'rtl' ? 'row-reverse' : 'row'}; }
                .status-badge { display: inline-block; padding: 6px 14px; border-radius: 20px; font-weight: bold; margin-top: 10px; font-size: 13px; }
                .status-pending { background: #f59e0b; color: #fff; }
                .status-confirmed { background: #f97316; color: #fff; }
                .status-preparing { background: #64748b; color: #fff; }
                .status-ready { background: #10b981; color: #fff; }
                .status-delivered { background: #10b981; color: #fff; }
                .status-cancelled { background: #ef4444; color: #fff; }
                .footer { margin-top: 20px; text-align: center; color: #64748b; font-size: 14px; border-top: 1px solid #e2e8f0; padding-top: 15px; position: relative; z-index: 1; }
                .print-btn { margin: 25px 0; text-align: center; }
                button { padding: 12px 24px; background: #8b5cf6; color: white; border: none; cursor: pointer; border-radius: 5px; margin: 0 8px; font-size: 15px; }
                button:hover { background: #7c3aed; }
                .notes { margin-top: 15px; padding: 12px; background: #fef3c7; border-left: 4px solid #f59e0b; font-size: 14px; }
            </style>
        </head>
        <body>
            <!-- Print Banner -->
            <div class="print-banner">
                <img src="${bannerImagePath}" alt="Advertisement Banner" class="print-banner-image" style="width: 100%; height: auto; display: block; -webkit-print-color-adjust: exact; print-color-adjust: exact; color-adjust: exact;" onerror="console.error('Failed to load banner image:', this.src);">
            </div>
            
            <!-- Fillable Fields Section with Dish Names -->
            <div class="fillable-section">
                ${order.dishes.map(dish => {
                    const dishName = dish.dish_name || 'N/A';
                    const categoryName = dish.category_name || 'Uncategorized';
                    const quantity = parseFloat(dish.quantity) || 1;
                    const quantityText = ' ' + quantity.toFixed(2) + ' دیگ';
                    return `
                    <div class="fillable-field">
                        <span class="fillable-label">${translations.dish || 'Dish'}:</span>
                        <div class="fillable-space" style="text-align: center; font-weight: bold;">${dishName}${quantityText} <span class="category-badge">${categoryName}</span></div>
                    </div>
                    `;
                }).join('')}
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
                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;">
                    <h3 style="font-size: 14px; font-weight: bold; color: #64748b; margin-bottom: 10px;">${translations.dish} ${translations.details || 'Details'}:</h3>
                    ${order.dishes.map(dish => {
                        const persons = parseInt(dish.number_of_persons) || 1;
                        const dishName = dish.dish_name || 'N/A';
                        const categoryName = dish.category_name || 'Uncategorized';
                        return `
                        <div class="detail-row" style="margin-left: 20px; margin-bottom: 10px; padding: 12px; background-color: #f0fdf4; border-left: 3px solid #10b981; border-radius: 4px;">
                            <div style="flex: 1;">
                                <div style="font-size: 15px; font-weight: bold; color: #1e293b; margin-bottom: 6px;">${dishName} <span class="category-badge">${categoryName}</span></div>
                                <div style="color: #64748b; font-size: 13px; line-height: 1.6;">
                                    <span>${translations.quantity}: <strong>${dish.quantity}</strong>${dish.unit ? ' ' + dish.unit : ''}</span>
                                    <span style="margin: 0 10px;">|</span>
                                    <span>${translations.persons}: <strong>${persons}</strong></span>
                                    ${dish.total_amount > 0 ? '<span style="margin: 0 10px;">|</span><span>Rs <strong>' + parseFloat(dish.total_amount).toFixed(2) + '</strong></span>' : ''}
                                </div>
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
let currentSelectedCategoryId = null;
let currentDishRowIndex = 0;

// Open dish selection modal
function openDishSelectionModal(rowIndex) {
    currentDishRowIndex = rowIndex || 0;
    currentSelectedCategoryId = null;
    
    if (!dishSelectionModal) {
        const modalElement = document.getElementById('dishSelectionModal');
        if (modalElement) {
            dishSelectionModal = new bootstrap.Modal(modalElement);
        }
    }
    if (dishSelectionModal) {
        dishSelectionModal.show();
        // Reset to show categories
        showCategoriesInModal();
    }
}

// Show categories in modal
function showCategoriesInModal() {
    currentSelectedCategoryId = null;
    const categoriesGrid = document.getElementById('modalCategoriesGrid');
    const dishesGrid = document.getElementById('modalDishesGrid');
    const backBtn = document.getElementById('backToCategoriesBtn');
    const searchInput = document.getElementById('dishSearchInput');
    
    if (categoriesGrid) categoriesGrid.style.display = 'block';
    if (dishesGrid) dishesGrid.style.display = 'none';
    if (backBtn) backBtn.style.display = 'none';
    if (searchInput) {
        searchInput.value = '';
        searchInput.placeholder = 'Search categories...';
    }
    
    // Update modal title
    const modalTitle = document.getElementById('dishSelectionModalLabel');
    if (modalTitle) {
        modalTitle.innerHTML = '<i class="bi bi-folder me-2"></i>Select Category';
    }
    
    filterItemsInModal('');
}

// Select category in modal and show dishes
function selectCategoryInModal(categoryId, categoryName) {
    currentSelectedCategoryId = categoryId;
    const categoriesGrid = document.getElementById('modalCategoriesGrid');
    const dishesGrid = document.getElementById('modalDishesGrid');
    const backBtn = document.getElementById('backToCategoriesBtn');
    const searchInput = document.getElementById('dishSearchInput');
    
    if (categoriesGrid) categoriesGrid.style.display = 'none';
    if (dishesGrid) dishesGrid.style.display = 'block';
    if (backBtn) backBtn.style.display = 'block';
    if (searchInput) {
        searchInput.value = '';
        searchInput.placeholder = 'Search dishes...';
    }
    
    // Update modal title
    const modalTitle = document.getElementById('dishSelectionModalLabel');
    if (modalTitle) {
        modalTitle.innerHTML = `<i class="bi bi-egg-fried me-2"></i>Select Dish - ${categoryName}`;
    }
    
    // Filter dishes by category
    const dishItems = document.querySelectorAll('.modal-dish-item');
    dishItems.forEach(item => {
        const itemCategoryId = item.getAttribute('data-category-id');
        if (categoryId == 0) {
            // Show uncategorized dishes
            item.style.display = (!itemCategoryId || itemCategoryId == '0') ? 'block' : 'none';
        } else {
            // Show dishes from selected category
            item.style.display = (itemCategoryId == categoryId) ? 'block' : 'none';
        }
    });
    
    filterItemsInModal('');
}

// Filter items in modal (categories or dishes) by search term
function filterItemsInModal(searchTerm) {
    const searchLower = searchTerm.toLowerCase().trim();
    let visibleCount = 0;
    
    if (currentSelectedCategoryId === null) {
        // Filtering categories
        const categoryItems = document.querySelectorAll('.modal-category-item');
        categoryItems.forEach(item => {
            const categoryName = item.getAttribute('data-category-name').toLowerCase();
            const matchesSearch = !searchTerm || categoryName.includes(searchLower);
            
            if (matchesSearch) {
                item.style.display = 'block';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });
    } else {
        // Filtering dishes
        const dishItems = document.querySelectorAll('.modal-dish-item');
        dishItems.forEach(item => {
            // Only filter visible dishes (already filtered by category)
            if (item.style.display === 'none') return;
            
            const dishName = item.getAttribute('data-dish-name').toLowerCase();
            const category = item.getAttribute('data-category').toLowerCase();
            const matchesSearch = !searchTerm || dishName.includes(searchLower) || category.includes(searchLower);
            
            if (matchesSearch) {
                item.style.display = 'block';
                visibleCount++;
            } else {
                item.style.display = 'none';
            }
        });
    }
    
    // Show/hide no results message
    const noResults = document.getElementById('noItemsFound');
    if (noResults) {
        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
    }
}

// Clear search
function clearDishSearch() {
    const searchInput = document.getElementById('dishSearchInput');
    if (searchInput) {
        searchInput.value = '';
        filterItemsInModal('');
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
    
    // Update review if on step 4
    if (typeof updateReview === 'function' && typeof currentStep !== 'undefined' && currentStep === 4) {
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



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

// Handle create order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    $customer_id = intval($_POST['customer_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    // Translate notes to Urdu if current language is Urdu
    $notes = translateForDatabase($notes);
    
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
    
    if ($customer_id <= 0 || empty($dishes_data)) {
        $error = t('fill_all_required_fields');
    } else {
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
                $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_id, dish_id, quantity, total_amount, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param("siiddss", $order_number, $customer_id, $dish_info['dish_id'], $dish_info['quantity'], $dish_info['total_amount'], $status, $notes);
                    if ($stmt->execute()) {
                        if ($order_id === null) {
                            $order_id = $conn->insert_id;
                        }
                        $orders_created++;
                    } else {
                        $errors[] = 'Failed to create order for dish ID ' . $dish_info['dish_id'] . ': ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $errors[] = 'Failed to prepare insert query for dish ID ' . $dish_info['dish_id'] . ': ' . $conn->error;
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

// Get all dishes for dropdown
$dishes = [];
$result = $conn->query("SELECT id, name FROM dishes ORDER BY name");
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

// Get all orders grouped by order_number
$orders = [];
$result = $conn->query("SELECT o.*, u.name as customer_name, u.email as customer_email, d.name as dish_name, d.id as dish_id, d.number_of_persons
    FROM orders o
    LEFT JOIN users u ON o.customer_id = u.id
    LEFT JOIN dishes d ON o.dish_id = d.id
    ORDER BY o.order_date DESC, o.order_number, o.id");
if ($result && $result->num_rows > 0) {
    $orders = $result->fetch_all(MYSQLI_ASSOC);
    
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
        if ($order['dish_id']) {
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
            $grouped_orders[$order_num] = [
                'order_number' => $order_num,
                'customer_id' => $order['customer_id'],
                'customer_name' => $order['customer_name'],
                'customer_email' => $order['customer_email'],
                'order_date' => $order['order_date'],
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
    
    // Convert to indexed array and sort by date
    $grouped_orders = array_values($grouped_orders);
    usort($grouped_orders, function($a, $b) {
        return strtotime($b['order_date']) - strtotime($a['order_date']);
    });
} else {
    $grouped_orders = [];
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
// Calculate statistics from grouped orders
$total_orders = count($grouped_orders);
$pending_orders = count(array_filter($grouped_orders, fn($o) => $o['status'] == 'pending'));
$delivered_orders = count(array_filter($grouped_orders, fn($o) => $o['status'] == 'delivered'));
$total_revenue = array_sum(array_column($grouped_orders, 'total_amount'));
?>

<!-- Page Header -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h2 class="mb-2 fw-bold">
                    <i class="bi bi-cart-check me-2 text-primary"></i>
                    <?php e('orders_title'); ?>
                </h2>
                <p class="text-muted mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    <?php echo $total_orders; ?> <?php echo $total_orders == 1 ? 'order' : 'orders'; ?> in total
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-lg-3 col-md-6 col-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 rounded p-3 me-3">
                        <i class="bi bi-cart-check fs-4 text-primary"></i>
                    </div>
                    <div>
                        <div class="text-muted small mb-1">Total Orders</div>
                        <div class="h4 mb-0 fw-bold"><?php echo $total_orders; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="bg-warning bg-opacity-10 rounded p-3 me-3">
                        <i class="bi bi-clock-history fs-4 text-warning"></i>
                    </div>
                    <div>
                        <div class="text-muted small mb-1">Pending</div>
                        <div class="h4 mb-0 fw-bold"><?php echo $pending_orders; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="bg-success bg-opacity-10 rounded p-3 me-3">
                        <i class="bi bi-check-circle fs-4 text-success"></i>
                    </div>
                    <div>
                        <div class="text-muted small mb-1">Delivered</div>
                        <div class="h4 mb-0 fw-bold"><?php echo $delivered_orders; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6 col-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="bg-info bg-opacity-10 rounded p-3 me-3">
                        <i class="bi bi-currency-exchange fs-4 text-info"></i>
                    </div>
                    <div>
                        <div class="text-muted small mb-1">Total Revenue</div>
                        <div class="h4 mb-0 fw-bold">Rs <?php echo number_format($total_revenue, 0); ?></div>
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

<!-- Create Order Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-lg border-0">
            <div class="card-header bg-gradient-primary text-white py-3">
                <h5 class="mb-0 fw-bold">
                    <i class="bi bi-cart-plus-fill me-2"></i>
                    <?php e('create_order'); ?>
                </h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="" id="orderForm">
                    <input type="hidden" name="create_order" value="1">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-md-12">
                            <label for="customer_id" class="form-label fw-semibold">
                                <i class="bi bi-person-fill me-1 text-primary"></i>
                                <?php e('customer'); ?> <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="customer_id" name="customer_id" required>
                                <option value=""><?php e('select_customer'); ?></option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?> (<?php echo htmlspecialchars($customer['email']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Dishes Section -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <label class="form-label fw-semibold mb-0">
                                <i class="bi bi-egg-fried me-1 text-primary"></i>
                                <?php e('dish'); ?> <span class="text-danger">*</span>
                            </label>
                            <button type="button" class="btn btn-sm btn-primary" id="addDishBtn">
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
                                        <select class="form-select dish-select" name="dishes[0][dish_id]" required>
                                            <option value=""><?php e('select_dish'); ?></option>
                                            <?php foreach ($dishes as $dish): ?>
                                                <option value="<?php echo $dish['id']; ?>">
                                                    <?php echo htmlspecialchars($dish['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
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
                    
                    <div class="mb-3">
                        <label for="notes" class="form-label fw-semibold">
                            <i class="bi bi-card-text me-1 text-primary"></i>
                            <?php e('notes'); ?>
                        </label>
                        <textarea class="form-control" id="notes" name="notes" rows="2" 
                                  placeholder="<?php e('optional_notes'); ?>"></textarea>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="bi bi-check-lg me-2"></i> <?php e('create_order'); ?>
                        </button>
                    </div>
                </form>
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
                        <?php foreach ($grouped_orders as $grouped_order): ?>
                            <div class="col-lg-6 col-xl-4 order-item" 
                                 data-id="<?php echo $grouped_order['id']; ?>"
                                 data-order-number="<?php echo htmlspecialchars($grouped_order['order_number']); ?>"
                                 data-customer="<?php echo strtolower(htmlspecialchars($grouped_order['customer_name'])); ?>"
                                 data-status="<?php echo $grouped_order['status']; ?>">
                                <div class="card border shadow-sm h-100 order-card" style="transition: all 0.3s ease;">
                                    <div class="card-header bg-white border-bottom">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <h6 class="mb-0 fw-bold text-primary">
                                                    <i class="bi bi-hash me-1"></i><?php echo htmlspecialchars($grouped_order['order_number']); ?>
                                                </h6>
                                                <small class="text-muted">
                                                    <i class="bi bi-calendar3 me-1"></i>
                                                    <?php echo date('M d, Y H:i', strtotime($grouped_order['order_date'])); ?>
                                                </small>
                                            </div>
                                            <span class="badge bg-<?php echo getStatusBadgeClass($grouped_order['status']); ?>">
                                                <i class="bi bi-<?php echo getStatusIcon($grouped_order['status']); ?> me-1"></i>
                                                <?php echo ucfirst($grouped_order['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body p-3">
                                        <div class="mb-3">
                                            <div class="d-flex align-items-center mb-2">
                                                <div class="bg-primary bg-opacity-10 rounded p-2 me-2">
                                                    <i class="bi bi-person-fill text-primary"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($grouped_order['customer_name']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($grouped_order['customer_email']); ?></small>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3 pb-3 border-bottom">
                                            <div class="fw-semibold mb-2">
                                                <i class="bi bi-egg-fried text-success me-1"></i>Dishes (<?php echo count($grouped_order['dishes']); ?>):
                                            </div>
                                            <?php foreach ($grouped_order['dishes'] as $dish): ?>
                                                <div class="d-flex justify-content-between align-items-center mb-1 ps-3">
                                                    <div>
                                                        <small class="fw-semibold"><?php echo htmlspecialchars($dish['dish_name']); ?></small>
                                                        <small class="text-muted d-block">
                                                            <i class="bi bi-123 me-1"></i>Qty: <?php echo number_format($dish['quantity'], 2); ?>
                                                            <?php if ($dish['total_amount'] > 0): ?>
                                                                - Rs <?php echo number_format($dish['total_amount'], 2); ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="text-muted small">Total Amount</span>
                                                <span class="h5 mb-0 fw-bold text-success">Rs <?php echo number_format($grouped_order['total_amount'], 2); ?></span>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($grouped_order['notes'])): ?>
                                            <div class="mb-3">
                                                <small class="text-muted">
                                                    <i class="bi bi-card-text me-1"></i>
                                                    <strong>Notes:</strong> <?php echo htmlspecialchars($grouped_order['notes']); ?>
                                                </small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="mb-3">
                                            <label class="form-label small fw-semibold mb-2">
                                                <i class="bi bi-arrow-repeat me-1"></i>Update Status
                                            </label>
                                            <form method="POST" action="" class="mb-2">
                                                <input type="hidden" name="order_id" value="<?php echo $grouped_order['id']; ?>">
                                                <input type="hidden" name="order_number" value="<?php echo htmlspecialchars($grouped_order['order_number']); ?>">
                                                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
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
                                    <div class="card-footer bg-white border-top">
                                        <div class="d-flex gap-2">
                                            <button type="button" class="btn btn-sm btn-info flex-fill" 
                                                    title="<?php e('print_ingredients'); ?>" 
                                                    onclick="printIngredients('<?php echo htmlspecialchars($grouped_order['order_number']); ?>')">
                                                <i class="bi bi-printer me-1"></i> Ingredients
                                            </button>
                                            <button type="button" class="btn btn-sm btn-primary flex-fill" 
                                                    title="<?php e('print_order'); ?>" 
                                                    onclick="printOrder('<?php echo htmlspecialchars($grouped_order['order_number']); ?>')">
                                                <i class="bi bi-printer-fill me-1"></i> Receipt
                                            </button>
                                            <a href="?delete=<?php echo $grouped_order['id']; ?>" class="btn btn-sm btn-danger" 
                                               title="<?php e('delete'); ?>" 
                                               onclick="return confirm('<?php echo addslashes(t('confirm_delete_order', 'Are you sure you want to delete this order?')); ?>');">
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
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Multiple dishes management
document.addEventListener('DOMContentLoaded', function() {
    const dishesContainer = document.getElementById('dishesContainer');
    const addDishBtn = document.getElementById('addDishBtn');
    let dishRowCount = 1; // Start from 1 since we already have row 0
    
    // Get dishes data for cloning
    const dishesData = <?php echo json_encode($dishes); ?>;
    
    // Function to create dish options HTML
    function getDishOptionsHTML() {
        let html = '<option value=""><?php e('select_dish'); ?></option>';
        dishesData.forEach(function(dish) {
            html += '<option value="' + dish.id + '">' + escapeHtml(dish.name) + '</option>';
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
        });
        
        totalAmountInput.addEventListener('blur', function() {
            if (!this.value || this.value === '') {
                isManualTotalEdit = false;
                calculateTotal();
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
                    <select class="form-select dish-select" name="dishes[${dishRowCount}][dish_id]" required>
                        ${getDishOptionsHTML()}
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
        updateRemoveButtons();
        dishRowCount++;
    });
    
    // Remove dish row
    dishesContainer.addEventListener('click', function(e) {
        if (e.target.closest('.remove-dish-btn')) {
            const row = e.target.closest('.dish-row');
            if (document.querySelectorAll('.dish-row').length > 1) {
                row.remove();
                updateRemoveButtons();
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
        'status' => $urduTranslations['status'] ?? 'حالت'
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
        'status' => 'حالت'
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
        ingredientsHtml += '<thead><tr style="background-color: #f8fafc;"><th style="padding: 6px 8px; border: 1px solid #e2e8f0; text-align: right; font-family: ' + fontFamily + '; font-size: 11px;">' + translations.ingredient_label + '</th><th style="padding: 6px 8px; border: 1px solid #e2e8f0; text-align: left; font-size: 11px;">' + translations.quantity_label + '</th><th style="padding: 6px 8px; border: 1px solid #e2e8f0; text-align: right; font-family: ' + fontFamily + '; font-size: 11px;">' + translations.unit_label + '</th></tr></thead>';
        ingredientsHtml += '<tbody>';
        ingredientsHtml += '<tr><td colspan="3" style="padding: 5px 8px; border: 1px solid #ddd; text-align: center; font-family: ' + fontFamily + '; font-size: 11px; line-height: 1.3;">' + translations.no_ingredients_found + '</td></tr>';
        ingredientsHtml += '</tbody></table>';
    } else {
        // Sort categories by name
        categoryKeys.sort((a, b) => {
            const nameA = ingredientsByCategory[a].category_name || '';
            const nameB = ingredientsByCategory[b].category_name || '';
            return nameA.localeCompare(nameB);
        });
        
        // Display ingredients grouped by category
        categoryKeys.forEach(function(categoryId) {
            const category = ingredientsByCategory[categoryId];
            const categoryIngredients = Object.values(category.ingredients);
            
            // Category header (compact for print)
            ingredientsHtml += '<div class="category-section" style="margin-top: 12px; margin-bottom: 6px; page-break-inside: avoid;">';
            ingredientsHtml += '<h3 class="category-header" style="font-size: 13px; font-weight: bold; color: #1e293b; padding: 5px 8px; background-color: #f1f5f9; border-right: 3px solid #8b5cf6; border-radius: 3px; font-family: ' + fontFamily + '; text-align: right; margin: 0;">';
            ingredientsHtml += category.category_name || 'بغیر زمرہ';
            ingredientsHtml += '</h3>';
            ingredientsHtml += '</div>';
            
            // Ingredients table for this category (compact)
            ingredientsHtml += '<table class="ingredients-table" style="width: 100%; border-collapse: collapse; margin-bottom: 12px; direction: rtl; font-size: 11px;">';
            ingredientsHtml += '<thead><tr style="background-color: #f8fafc;"><th style="padding: 6px 8px; border: 1px solid #e2e8f0; text-align: right; font-family: ' + fontFamily + '; font-size: 11px;">' + translations.ingredient_label + '</th><th style="padding: 6px 8px; border: 1px solid #e2e8f0; text-align: left; font-size: 11px;">' + translations.quantity_label + '</th><th style="padding: 6px 8px; border: 1px solid #e2e8f0; text-align: right; font-family: ' + fontFamily + '; font-size: 11px;">' + translations.unit_label + '</th></tr></thead>';
            ingredientsHtml += '<tbody>';
            
            // Sort ingredients within category by name
            categoryIngredients.sort((a, b) => {
                const nameA = a.ingredient_name || '';
                const nameB = b.ingredient_name || '';
                return nameA.localeCompare(nameB);
            });
            
            categoryIngredients.forEach(ing => {
                ingredientsHtml += '<tr style="page-break-inside: avoid;">';
                ingredientsHtml += '<td style="padding: 5px 8px; border: 1px solid #ddd; text-align: right; font-family: ' + fontFamily + '; font-size: 11px; line-height: 1.3;">' + (ing.ingredient_name || 'N/A') + '</td>';
                ingredientsHtml += '<td style="padding: 5px 8px; border: 1px solid #ddd; text-align: left; font-size: 11px; line-height: 1.3;">' + (parseFloat(ing.quantity) || 0).toFixed(2) + '</td>';
                ingredientsHtml += '<td style="padding: 5px 8px; border: 1px solid #ddd; text-align: right; font-family: ' + fontFamily + '; font-size: 11px; line-height: 1.3;">' + (ing.unit || '') + '</td>';
                ingredientsHtml += '</tr>';
            });
            
            ingredientsHtml += '</tbody></table>';
        });
    }
    
    ingredientsHtml += '</div>';
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html dir="rtl" lang="ur">
        <head>
            <title>${translations.ingredients_list} - ${translations.order_id} ${order.order_number || '#' + order.id}</title>
            <meta charset="UTF-8">
            <style>
                @media print {
                    @page {
                        size: A4;
                        margin: 0.5cm 0.8cm;
                    }
                    body { margin: 0; padding: 0; position: relative; font-size: 10px; }
                    .no-print { display: none !important; }
                    .print-banner { 
                        page-break-after: avoid;
                        display: block !important;
                        visibility: visible !important;
                        padding: 4px 8px !important;
                        margin-bottom: 8px !important;
                    }
                    .content-wrapper {
                        padding: 8px !important;
                    }
                    .header {
                        margin-bottom: 10px !important;
                    }
                    .header h1 {
                        font-size: 14px !important;
                        margin-bottom: 8px !important;
                    }
                    .category-section {
                        margin-top: 8px !important;
                        margin-bottom: 4px !important;
                    }
                    .category-header {
                        font-size: 11px !important;
                        padding: 4px 6px !important;
                        margin: 0 !important;
                    }
                    .ingredients-table {
                        font-size: 9px !important;
                        margin-bottom: 8px !important;
                    }
                    .ingredients-table th,
                    .ingredients-table td {
                        padding: 3px 5px !important;
                        font-size: 9px !important;
                        line-height: 1.2 !important;
                    }
                    .footer {
                        margin-top: 10px !important;
                        font-size: 9px !important;
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
                body { font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif; padding: 0; margin: 0; position: relative; min-height: 100vh; direction: rtl; background: #fff; }
                
                /* Banner Header - Compact Version */
                .print-banner {
                    background: linear-gradient(135deg, #8B4513 0%, #A0522D 50%, #CD853F 100%);
                    color: white;
                    padding: 6px 12px;
                    margin-bottom: 10px;
                    border-bottom: 2px solid #DAA520;
                    position: relative;
                    overflow: hidden;
                    display: block !important;
                    visibility: visible !important;
                    width: 100%;
                    box-sizing: border-box;
                }
                .banner-content {
                    display: flex !important;
                    align-items: flex-start;
                    justify-content: space-between;
                    max-width: 100%;
                    gap: 10px;
                    width: 100%;
                    box-sizing: border-box;
                }
                .banner-logo {
                    width: 50px;
                    height: 50px;
                    border-radius: 50%;
                    border: 2px solid #DAA520;
                    background: #DAA520;
                    padding: 2px;
                    flex-shrink: 0;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    box-shadow: 0 1px 4px rgba(0,0,0,0.3);
                }
                .banner-logo img {
                    width: 100%;
                    height: 100%;
                    object-fit: contain;
                    border-radius: 50%;
                }
                .banner-text-center {
                    flex: 1;
                    text-align: center;
                    padding: 0 8px;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                }
                .banner-text-center h1 {
                    margin: 0;
                    font-size: 12px;
                    font-weight: bold;
                    color: white;
                    text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
                    margin-bottom: 2px;
                    line-height: 1.2;
                    text-align: center;
                }
                .banner-text-center h2 {
                    margin: 0;
                    font-size: 10px;
                    font-weight: bold;
                    color: #DAA520;
                    text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
                    margin-bottom: 1px;
                    line-height: 1.1;
                    text-align: center;
                }
                .banner-text-center h3 {
                    margin: 0;
                    font-size: 10px;
                    font-weight: bold;
                    color: #FFD700;
                    text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
                    margin-bottom: 3px;
                    line-height: 1.1;
                    text-align: center;
                }
                .banner-contact {
                    background: #DAA520;
                    padding: 3px 8px;
                    margin-top: 4px;
                    border-radius: 3px;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    flex-wrap: wrap;
                }
                .contact-center {
                    background: #228B22;
                    padding: 2px 6px;
                    border-radius: 3px;
                    color: white;
                    font-size: 7px;
                    text-align: center;
                    width: 100%;
                    line-height: 1.2;
                }
                .contact-center strong {
                    display: block;
                    margin-bottom: 1px;
                    font-size: 8px;
                }
                .banner-order-info {
                    background: rgba(255, 255, 255, 0.15);
                    padding: 4px 8px;
                    border-radius: 4px;
                    min-width: 150px;
                    flex-shrink: 0;
                    text-align: right;
                    direction: rtl;
                }
                .banner-order-info p {
                    margin: 1px 0;
                    font-size: 8px;
                    color: white;
                    line-height: 1.2;
                }
                .banner-order-info strong {
                    color: #FFD700;
                    font-weight: bold;
                }
                
                .content-wrapper {
                    padding: 12px;
                }
                .header { text-align: center; margin-bottom: 15px; position: relative; z-index: 1; }
                .header h1 { margin: 0; color: #1e293b; font-size: 16px; }
                .header p { margin: 3px 0; color: #64748b; font-size: 11px; }
                .info-section { margin-bottom: 12px; position: relative; z-index: 1; background: #f8f9fa; padding: 10px; border-radius: 5px; }
                .info-section p { margin: 3px 0; font-size: 11px; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; position: relative; z-index: 1; }
                th, td { padding: 6px 8px; border: 1px solid #e2e8f0; font-size: 11px; }
                th { background-color: #f8fafc; font-weight: bold; }
                .footer { margin-top: 15px; text-align: center; color: #64748b; font-size: 10px; position: relative; z-index: 1; }
                .print-btn { margin: 15px 0; text-align: center; }
                button { padding: 8px 16px; background: #8b5cf6; color: white; border: none; cursor: pointer; border-radius: 5px; font-size: 12px; }
                button:hover { background: #7c3aed; }
            </style>
        </head>
        <body>
            <!-- Print Banner -->
            <div class="print-banner">
                <div class="banner-content">
                    <div class="banner-logo">
                        <img src="${logoPath}" alt="Logo" onerror="this.style.display='none';">
                    </div>
                    <div class="banner-text-center">
                        <h1>ینگ کوکنگ و چائنیز فوڈ اسپیشلسٹ</h1>
                        <h3>حسن کک - Hassan Cook</h3>
                        <div class="banner-contact">
                            <div class="contact-center">
                                <strong>چوک شاہ عباس سورج کنڈ روڈ سوئی گیس روڈ</strong>
                                چاہ کہنے والا زود برف کارخانه<br>
                                <span style="font-size: 9px; margin-top: 2px; display: block; font-weight: bold; letter-spacing: 0.5px; white-space: nowrap;">سلیم: <span dir="ltr" style="display: inline;">+92 3040077884</span> | زونگ: <span dir="ltr" style="display: inline;">+92 3078677779</span> | <span dir="ltr" style="display: inline;">+92 3086977778</span> | حسن کک: <span dir="ltr" style="display: inline;">+92 3126396398</span></span>
                            </div>
                        </div>
                    </div>
                    <div class="banner-order-info">
                        <p><strong>${translations.order_id}:</strong> ${order.order_number || '#' + order.id}</p>
                        <p><strong>افراد کی تعداد:</strong> ${totalPersons}</p>
                        <p><strong>${translations.order_date}:</strong> ${new Date(order.order_date).toLocaleDateString('ur-PK')} ${new Date(order.order_date).toLocaleTimeString('ur-PK')}</p>
                    </div>
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
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html dir="${langDir}">
        <head>
            <title>${translations.order_receipt} - ${translations.order_id} ${order.order_number || '#' + order.id}</title>
            <meta charset="UTF-8">
            <style>
                @media print {
                    body { margin: 0; padding: 20px; position: relative; }
                    .no-print { display: none; }
                }
                body { font-family: ${fontFamily}; padding: 20px; max-width: 600px; margin: 0 auto; position: relative; min-height: 100vh; direction: ${langDir}; }
                body::before {
                    content: '';
                    position: fixed;
                    top: 50%;
                    left: 50%;
                    transform: translate(-50%, -50%) rotate(-45deg);
                    width: 500px;
                    height: 500px;
                    background-image: url('${logoPath}');
                    background-size: contain;
                    background-repeat: no-repeat;
                    background-position: center;
                    opacity: 0.08;
                    z-index: -1;
                    pointer-events: none;
                }
                @media print {
                    body::before {
                        opacity: 0.15;
                    }
                }
                .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #1e293b; padding-bottom: 20px; position: relative; z-index: 1; }
                .header h1 { margin: 0; color: #1e293b; }
                .header p { margin: 5px 0; color: #64748b; }
                .order-info { margin: 20px 0; position: relative; z-index: 1; }
                .order-info p { margin: 8px 0; }
                .order-details { background: #f8fafc; padding: 15px; border-radius: 5px; margin: 20px 0; position: relative; z-index: 1; }
                .order-details h3 { margin-top: 0; }
                .detail-row { display: flex; justify-content: space-between; margin: 10px 0; padding: 8px 0; border-bottom: 1px solid #e2e8f0; flex-direction: ${langDir === 'rtl' ? 'row-reverse' : 'row'}; }
                .detail-row:last-child { border-bottom: none; }
                .detail-label { font-weight: bold; }
                ${langDir === 'rtl' ? '.notes { border-left: none; border-right: 4px solid #f59e0b; }' : ''}
                .total-section { margin-top: 20px; padding-top: 20px; border-top: 2px solid #1e293b; position: relative; z-index: 1; }
                .total-row { display: flex; justify-content: space-between; font-size: 18px; font-weight: bold; margin: 10px 0; flex-direction: ${langDir === 'rtl' ? 'row-reverse' : 'row'}; }
                .status-badge { display: inline-block; padding: 5px 15px; border-radius: 20px; font-weight: bold; margin-top: 10px; }
                .status-pending { background: #f59e0b; color: #fff; }
                .status-confirmed { background: #f97316; color: #fff; }
                .status-preparing { background: #64748b; color: #fff; }
                .status-ready { background: #10b981; color: #fff; }
                .status-delivered { background: #10b981; color: #fff; }
                .status-cancelled { background: #ef4444; color: #fff; }
                .footer { margin-top: 30px; text-align: center; color: #64748b; font-size: 12px; border-top: 1px solid #e2e8f0; padding-top: 20px; position: relative; z-index: 1; }
                .print-btn { margin: 20px 0; text-align: center; }
                button { padding: 10px 20px; background: #8b5cf6; color: white; border: none; cursor: pointer; border-radius: 5px; margin: 0 5px; }
                button:hover { background: #7c3aed; }
                .notes { margin-top: 15px; padding: 10px; background: #fef3c7; border-left: 4px solid #f59e0b; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>${translations.brand_name}</h1>
                <p>${translations.order_receipt}</p>
            </div>
            <div class="order-info">
                <p><strong>${translations.order_id}:</strong> ${order.order_number || '#' + order.id}</p>
                <p><strong>${translations.order_date}:</strong> ${new Date(order.order_date).toLocaleString()}</p>
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
                    <strong style="display: block; margin-bottom: 10px;">${translations.dish}:</strong>
                    ${order.dishes.map(dish => `
                        <div class="detail-row" style="margin-left: 20px;">
                            <div style="flex: 1;">
                                <span class="detail-label">${dish.dish_name || 'N/A'}</span>
                                <span style="margin-left: 10px; color: #64748b;">(${translations.quantity}: ${dish.quantity}${dish.total_amount > 0 ? ' - Rs ' + parseFloat(dish.total_amount).toFixed(2) : ''})</span>
                            </div>
                        </div>
                    `).join('')}
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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>



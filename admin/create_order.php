<?php
/**
 * Create Order - 3 Step Form
 * Step 1: Customer Info
 * Step 2: Dishes Selection
 * Step 3: Compulsory Items
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

requireAdmin();

$conn = getDBConnection();
$error = '';
$success = '';

// Update orders table schema if needed
$required_columns = [
    'customer_name' => "VARCHAR(100) DEFAULT NULL",
    'customer_cell' => "VARCHAR(20) DEFAULT NULL",
    'delivery_date' => "DATE DEFAULT NULL",
    'delivery_time' => "TIME DEFAULT NULL",
    'shift' => "VARCHAR(20) DEFAULT NULL",
    'number_of_persons' => "INT DEFAULT NULL",
    'cloth_malmal_quantity' => "INT DEFAULT 0",
    'match_box_quantity' => "INT DEFAULT 0",
    'surrf_quantity' => "INT DEFAULT 0",
    'sponjis_quantity' => "INT DEFAULT 0",
    'wood_quantity' => "INT DEFAULT 0",
    'order_date' => "TIMESTAMP DEFAULT NULL"
];

foreach ($required_columns as $column => $definition) {
    if (!db_column_exists($conn, 'orders', $column)) {
        try {
            $conn->exec("ALTER TABLE orders ADD COLUMN {$column} {$definition}");
        } catch (PDOException $e) {
            error_log('create_order migration failed: ' . $e->getMessage());
        }
    }
}

// Handle order creation
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
    
    // Compulsory items
    $cloth_malmal_quantity = intval($_POST['cloth_malmal_quantity'] ?? 0);
    $match_box_quantity = intval($_POST['match_box_quantity'] ?? 0);
    $surrf_quantity = intval($_POST['surrf_quantity'] ?? 0);
    $sponjis_quantity = intval($_POST['sponjis_quantity'] ?? 0);
    $wood_quantity = intval($_POST['wood_quantity'] ?? 0);
    
    // Dishes data
    $dishes_data = [];
    if (isset($_POST['selected_dishes']) && is_array($_POST['selected_dishes'])) {
        foreach ($_POST['selected_dishes'] as $dish_id => $quantity) {
            if (intval($dish_id) > 0 && floatval($quantity) > 0) {
                $dishes_data[] = [
                    'dish_id' => intval($dish_id),
                    'quantity' => floatval($quantity)
                ];
            }
        }
    }
    
    // Validation
    if (empty($customer_cell) || empty($order_date) || empty($order_time) || 
        $number_of_persons <= 0 || empty($shift) || empty($delivery_date) || empty($delivery_time)) {
        $error = 'Please fill all required fields in Step 1.';
    } elseif (empty($dishes_data)) {
        $error = 'Please select at least one dish in Step 2.';
    } else {
        // Generate order number
        $order_number = 'ORD-' . date('Ymd') . '-' . str_pad(time() % 1000000, 6, '0', STR_PAD_LEFT);
        
        // Combine order date and time
        $order_datetime = $order_date . ' ' . $order_time . ':00';
        
        // Create order records for each dish
        $orders_created = 0;
        foreach ($dishes_data as $dish_info) {
            $final_customer_id = ($customer_id > 0) ? $customer_id : null;
            
            try {
                db_exec(
                    $conn,
                    "INSERT INTO orders (order_number, customer_id, dish_id, quantity, total_amount, status, 
                        customer_name, customer_cell, order_date, delivery_date, delivery_time, shift, number_of_persons,
                        cloth_malmal_quantity, match_box_quantity, surrf_quantity, sponjis_quantity, wood_quantity) 
                        VALUES (?, ?, ?, ?, 0, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $order_number,
                        $final_customer_id,
                        $dish_info['dish_id'],
                        $dish_info['quantity'],
                        $customer_name,
                        $customer_cell,
                        $order_datetime,
                        $delivery_date,
                        $delivery_time,
                        $shift,
                        $number_of_persons,
                        $cloth_malmal_quantity,
                        $match_box_quantity,
                        $surrf_quantity,
                        $sponjis_quantity,
                        $wood_quantity
                    ]
                );
                $orders_created++;
            } catch (PDOException $e) {
                $error = 'Failed to create order: ' . $e->getMessage();
            }
        }
        
        if ($orders_created > 0) {
            $success = "Order #{$order_number} created successfully!";
            header('Location: order_preview.php?order_number=' . urlencode($order_number));
            exit();
        }
    }
}

// Get all customers (users with role='user') with their last used cell number from orders
$customers = db_fetch_all(
    $conn,
    "SELECT u.id, u.name, u.email, 
    (SELECT o.customer_cell FROM orders o WHERE o.customer_id = u.id ORDER BY o.order_date DESC LIMIT 1) as last_cell
    FROM users u 
    WHERE u.role = 'user' 
    ORDER BY u.name"
);

// Get all categories for dish selection modal
$dish_categories = db_fetch_all(
    $conn,
    "SELECT DISTINCT c.id, c.name, c.description 
    FROM categories c 
    INNER JOIN dishes d ON d.category_id = c.id 
    ORDER BY c.name"
);

// Get all dishes (no image blob — loaded via api/dish_image.php)
$dishes = db_fetch_all(
    $conn,
    "SELECT d.id, d.name, d.description, d.category_id, d.number_of_persons, d.base_quantity, d.base_unit,
            c.name as category_name,
            CASE WHEN d.image IS NOT NULL AND LENGTH(d.image) > 0 THEN 1 ELSE 0 END as has_image
    FROM dishes d 
    LEFT JOIN categories c ON d.category_id = c.id 
    ORDER BY d.name"
);

// Get previously used dishes from recent orders (last 30 days)
$previously_used_dishes = db_fetch_all(
    $conn,
    "SELECT o.dish_id, d.id, d.name, d.category_id, d.number_of_persons, d.base_quantity, d.base_unit,
            c.name as category_name,
            CASE WHEN d.image IS NOT NULL AND LENGTH(d.image) > 0 THEN 1 ELSE 0 END as has_image,
            COUNT(o.id) as order_count,
            MAX(o.order_date) as last_used_date
    FROM orders o
    INNER JOIN dishes d ON o.dish_id = d.id
    LEFT JOIN categories c ON d.category_id = c.id
    WHERE o.order_date >= (NOW() - INTERVAL '30 days')
    GROUP BY o.dish_id, d.id, d.name, d.category_id, d.number_of_persons, d.base_quantity, d.base_unit, c.name,
             CASE WHEN d.image IS NOT NULL AND LENGTH(d.image) > 0 THEN 1 ELSE 0 END
    ORDER BY order_count DESC, last_used_date DESC
    LIMIT 20"
);

$pageTitle = 'Create Order';
include __DIR__ . '/../includes/header.php';
?>

<style>
.order-steps {
    display: flex;
    justify-content: space-between;
    margin-bottom: 2rem;
    position: relative;
}

.order-steps::before {
    content: '';
    position: absolute;
    top: 20px;
    left: 0;
    right: 0;
    height: 2px;
    background: #e2e8f0;
    z-index: 0;
}

.step {
    flex: 1;
    text-align: center;
    position: relative;
    z-index: 1;
}

.step-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: #e2e8f0;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 0.5rem;
    font-weight: 600;
}

.step.active .step-circle {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
}

.step.completed .step-circle {
    background: #10b981;
    color: white;
}

.step-content {
    display: none;
}

.step-content.active {
    display: block;
}

.dish-card {
    cursor: pointer;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
}

.dish-card:hover {
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
    border-color: #6366f1;
}

.dish-card.selected {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
}

.dish-image {
    width: 100%;
    height: 220px;
    object-fit: cover;
    background: #f1f5f9;
    transition: transform 0.3s ease;
}

.dish-card:hover .dish-image {
    transform: scale(1.05);
}

.dish-placeholder {
    width: 100%;
    height: 220px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 3rem;
    transition: transform 0.3s ease;
}

.dish-card:hover .dish-placeholder {
    transform: scale(1.05);
}

.previously-used-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    z-index: 2;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.dish-tabs {
    border-bottom: 2px solid #e2e8f0;
    margin-bottom: 1.5rem;
}

.dish-tab {
    padding: 0.75rem 1.5rem;
    background: transparent;
    border: none;
    border-bottom: 3px solid transparent;
    color: #64748b;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s ease;
}

.dish-tab:hover {
    color: #6366f1;
    background: rgba(99, 102, 241, 0.05);
}

.dish-tab.active {
    color: #6366f1;
    border-bottom-color: #6366f1;
    background: rgba(99, 102, 241, 0.05);
}

.quantity-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #10b981;
    color: white;
    border-radius: 50%;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.9rem;
}

.modal-dish-image {
    width: 100%;
    max-height: 300px;
    object-fit: cover;
    border-radius: 12px;
    margin-bottom: 1rem;
}

.customer-autocomplete-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    max-height: 300px;
    overflow-y: auto;
    z-index: 1000;
    margin-top: 4px;
}

.customer-autocomplete-item {
    padding: 0.75rem 1rem;
    cursor: pointer;
    border-bottom: 1px solid #f1f5f9;
    transition: background-color 0.2s ease;
}

.customer-autocomplete-item:hover {
    background-color: #f8fafc;
}

.customer-autocomplete-item:last-child {
    border-bottom: none;
}

.customer-autocomplete-item.selected {
    background-color: #eef2ff;
    border-left: 3px solid #6366f1;
}

.customer-autocomplete-item .customer-name {
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 0.25rem;
}

.customer-autocomplete-item .customer-email {
    font-size: 0.875rem;
    color: #64748b;
}

.customer-autocomplete-item .customer-cell {
    font-size: 0.875rem;
    color: #10b981;
    margin-top: 0.25rem;
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
</style>

<div class="container-fluid px-4 py-4">
    <div class="page-header-modern mb-4">
        <h1 class="display-6 fw-bold mb-2" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
            <i class="bi bi-cart-plus me-3"></i>Create New Order
        </h1>
        <p class="lead mb-0" style="color: #64748b;">Fill in the order details in 3 simple steps</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Order Steps Indicator -->
    <div class="order-steps mb-4">
        <div class="step active" data-step="1">
            <div class="step-circle">1</div>
            <div class="step-label fw-semibold">Customer Info</div>
        </div>
        <div class="step" data-step="2">
            <div class="step-circle">2</div>
            <div class="step-label fw-semibold">Select Dishes</div>
        </div>
        <div class="step" data-step="3">
            <div class="step-circle">3</div>
            <div class="step-label fw-semibold">Compulsory Items</div>
        </div>
    </div>

    <form method="POST" action="" id="orderForm">
        <!-- Step 1: Customer Info -->
        <div class="step-content active" id="step1">
            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4"><i class="bi bi-person me-2"></i>Customer Information</h5>
                    <div class="row g-3">
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-semibold">Select Customer <span class="text-muted">(Optional)</span></label>
                            <select class="form-select" id="customer_select" onchange="loadCustomerData()">
                                <option value="">-- Select a Customer --</option>
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?php echo $customer['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($customer['name']); ?>"
                                            data-email="<?php echo htmlspecialchars($customer['email']); ?>"
                                            data-cell="<?php echo htmlspecialchars($customer['last_cell'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($customer['name']); ?> (<?php echo htmlspecialchars($customer['email']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Select a customer to auto-fill their information, or enter manually below.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Customer Name</label>
                            <div class="position-relative">
                                <input type="text" class="form-control" name="customer_name" id="customer_name" 
                                       value="<?php echo htmlspecialchars($_POST['customer_name'] ?? ''); ?>"
                                       autocomplete="off" oninput="filterCustomers(this.value)" 
                                       onfocus="showCustomerDropdown()" onblur="hideCustomerDropdown()">
                                <input type="hidden" name="customer_id" id="customer_id" value="<?php echo htmlspecialchars($_POST['customer_id'] ?? '0'); ?>">
                                <div id="customerDropdown" class="customer-autocomplete-dropdown" style="display: none;" onmousedown="event.preventDefault();"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Customer Cell No <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" name="customer_cell" id="customer_cell" required 
                                   value="<?php echo htmlspecialchars($_POST['customer_cell'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Date & Time <span class="text-danger">*</span></label>
                            <input type="date" class="form-control mb-2" name="order_date" required 
                                   value="<?php echo htmlspecialchars($_POST['order_date'] ?? date('Y-m-d')); ?>">
                            <input type="time" class="form-control" name="order_time" required 
                                   value="<?php echo htmlspecialchars($_POST['order_time'] ?? date('H:i')); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Number of Persons <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="number_of_persons" required min="1" 
                                   value="<?php echo htmlspecialchars($_POST['number_of_persons'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Delivery Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="delivery_date" required 
                                   value="<?php echo htmlspecialchars($_POST['delivery_date'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Shift <span class="text-danger">*</span></label>
                            <select class="form-select" name="shift" required>
                                <option value="">-- Select Shift --</option>
                                <option value="afternoon" <?php echo (isset($_POST['shift']) && $_POST['shift'] === 'afternoon') ? 'selected' : ''; ?>>Afternoon</option>
                                <option value="evening" <?php echo (isset($_POST['shift']) && $_POST['shift'] === 'evening') ? 'selected' : ''; ?>>Evening</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Delivery Time <span class="text-danger">*</span></label>
                            <input type="time" class="form-control" name="delivery_time" required 
                                   value="<?php echo htmlspecialchars($_POST['delivery_time'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="button" class="btn btn-primary btn-lg rounded-pill px-5" onclick="goToStep(2)">
                            Next: Select Dishes <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 2: Dishes Selection -->
        <div class="step-content" id="step2">
            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="fw-bold mb-0"><i class="bi bi-egg-fried me-2"></i>Select Dishes</h5>
                        <div class="d-flex align-items-center gap-3">
                            <div id="selectedDishesCount" class="badge bg-primary" style="display: none; font-size: 1rem; padding: 0.5rem 1rem;">
                                <span id="selectedCount">0</span> dish(es) selected
                            </div>
                            <button type="button" class="btn btn-primary btn-lg rounded-pill px-4" onclick="openDishSelectionModal()">
                                <i class="bi bi-grid-3x3-gap me-2"></i>Browse All Dishes
                            </button>
                        </div>
                    </div>
                    
                    <!-- Dish Selection Tabs -->
                    <div class="dish-tabs">
                        <button type="button" class="dish-tab active" onclick="showDishTab('all')" id="tabAll">
                            <i class="bi bi-grid me-2"></i>All Dishes
                        </button>
                        <?php if (!empty($previously_used_dishes)): ?>
                        <button type="button" class="dish-tab" onclick="showDishTab('previous')" id="tabPrevious">
                            <i class="bi bi-clock-history me-2"></i>Previously Added Dishes
                            <span class="badge bg-success ms-2"><?php echo count($previously_used_dishes); ?></span>
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- All Dishes Container -->
                    <div id="allDishesContainer" class="dish-container">
                        <div class="row g-3" id="dishesContainer">
                            <?php foreach ($dishes as $dish): 
                                $image_path = !empty($dish['has_image']) ? dish_image_url((int) $dish['id'], '../') : '';
                                $image_exists = $image_path !== '';
                            ?>
                                <div class="col-md-4 col-lg-3">
                                    <div class="dish-card card h-100 position-relative" data-dish-id="<?php echo $dish['id']; ?>">
                                        <div style="position: relative; overflow: hidden;">
                                            <?php if ($image_exists): ?>
                                                <img src="<?php echo htmlspecialchars($image_path); ?>" class="dish-image" alt="<?php echo htmlspecialchars($dish['name']); ?>" loading="lazy" decoding="async">
                                            <?php else: ?>
                                                <div class="dish-placeholder">
                                                    <i class="bi bi-egg-fried"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="card-body">
                                            <h6 class="card-title fw-bold mb-1"><?php echo htmlspecialchars($dish['name']); ?></h6>
                                            <small class="text-muted d-block mb-2">
                                                <i class="bi bi-folder me-1"></i><?php echo htmlspecialchars($dish['category_name'] ?? 'Uncategorized'); ?>
                                            </small>
                                            <?php if ($dish['number_of_persons']): ?>
                                                <small class="badge bg-info-subtle text-info">
                                                    <i class="bi bi-people me-1"></i><?php echo $dish['number_of_persons']; ?> person(s)
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="quantity-badge" style="display: none;">0</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Previously Used Dishes Container -->
                    <?php if (!empty($previously_used_dishes)): ?>
                    <div id="previousDishesContainer" class="dish-container" style="display: none;">
                        <div class="alert alert-info mb-3">
                            <i class="bi bi-info-circle me-2"></i>
                            <strong>Previously Added Dishes:</strong> These are dishes that were frequently ordered in the last 30 days. Click on any dish to add it to your order.
                        </div>
                        <div class="row g-3" id="previousDishesContainerGrid">
                            <?php foreach ($previously_used_dishes as $dish): 
                                $image_path = !empty($dish['has_image']) ? dish_image_url((int) $dish['id'], '../') : '';
                                $image_exists = $image_path !== '';
                            ?>
                                <div class="col-md-4 col-lg-3">
                                    <div class="dish-card card h-100 position-relative" data-dish-id="<?php echo $dish['id']; ?>">
                                        <div class="previously-used-badge">
                                            <i class="bi bi-star-fill me-1"></i>Popular
                                        </div>
                                        <div style="position: relative; overflow: hidden;">
                                            <?php if ($image_exists): ?>
                                                <img src="<?php echo htmlspecialchars($image_path); ?>" class="dish-image" alt="<?php echo htmlspecialchars($dish['name']); ?>" loading="lazy" decoding="async">
                                            <?php else: ?>
                                                <div class="dish-placeholder">
                                                    <i class="bi bi-egg-fried"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="card-body">
                                            <h6 class="card-title fw-bold mb-1"><?php echo htmlspecialchars($dish['name']); ?></h6>
                                            <small class="text-muted d-block mb-2">
                                                <i class="bi bi-folder me-1"></i><?php echo htmlspecialchars($dish['category_name'] ?? 'Uncategorized'); ?>
                                            </small>
                                            <small class="text-muted d-block">
                                                <i class="bi bi-cart-check me-1"></i>Ordered <?php echo $dish['order_count']; ?> time(s)
                                            </small>
                                            <?php if ($dish['number_of_persons']): ?>
                                                <small class="badge bg-info-subtle text-info mt-1">
                                                    <i class="bi bi-people me-1"></i><?php echo $dish['number_of_persons']; ?> person(s)
                                                </small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="quantity-badge" style="display: none;">0</div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="mt-4 d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary btn-lg rounded-pill px-5" onclick="goToStep(1)">
                            <i class="bi bi-arrow-left me-2"></i>Back
                        </button>
                        <button type="button" class="btn btn-primary btn-lg rounded-pill px-5" onclick="goToStep(3)">
                            Next: Compulsory Items <i class="bi bi-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Compulsory Items -->
        <div class="step-content" id="step3">
            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4"><i class="bi bi-list-check me-2"></i>Compulsory Items</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Cloth Malmal <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="cloth_malmal_quantity" required min="0" 
                                   value="<?php echo htmlspecialchars($_POST['cloth_malmal_quantity'] ?? '0'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Match Box <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="match_box_quantity" required min="0" 
                                   value="<?php echo htmlspecialchars($_POST['match_box_quantity'] ?? '0'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">سرف (Surrf) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="surrf_quantity" required min="0" 
                                       value="<?php echo htmlspecialchars($_POST['surrf_quantity'] ?? '0'); ?>">
                                <span class="input-group-text">گرام</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Sponjis (Iron) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="sponjis_quantity" required min="0" 
                                   value="<?php echo htmlspecialchars($_POST['sponjis_quantity'] ?? '0'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">لکڑی (Wood) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" name="wood_quantity" required min="0" 
                                       value="<?php echo htmlspecialchars($_POST['wood_quantity'] ?? '0'); ?>">
                                <span class="input-group-text">کلو</span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-between">
                        <button type="button" class="btn btn-outline-secondary btn-lg rounded-pill px-5" onclick="goToStep(2)">
                            <i class="bi bi-arrow-left me-2"></i>Back
                        </button>
                        <button type="submit" name="create_order" class="btn btn-success btn-lg rounded-pill px-5">
                            <i class="bi bi-check-lg me-2"></i>Create Order
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Hidden inputs for selected dishes -->
        <div id="selectedDishesInputs"></div>
    </form>
</div>

<!-- Dish Selection Modal (Browse All Dishes) -->
<div class="modal fade" id="dishSelectionModal" tabindex="-1" aria-labelledby="dishSelectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                <h5 class="modal-title text-white fw-bold" id="dishSelectionModalLabel">
                    <i class="bi bi-egg-fried me-2"></i>Select Dishes
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
                        $image_path = !empty($dish['has_image']) ? dish_image_url((int) $dish['id'], '../') : '';
                        $image_exists = $image_path !== '';
                    ?>
                        <div class="col-md-4 col-lg-3 modal-dish-item" 
                             data-dish-id="<?php echo $dish['id']; ?>"
                             data-dish-name="<?php echo htmlspecialchars($dish['name']); ?>"
                             data-category-id="<?php echo $dish['category_id'] ?? '0'; ?>"
                             data-category="<?php echo htmlspecialchars($dish['category_name'] ?? 'Uncategorized'); ?>"
                             onclick="selectDishFromModal(<?php echo $dish['id']; ?>, '<?php echo htmlspecialchars(addslashes($dish['name'])); ?>', '<?php echo htmlspecialchars($image_path); ?>', <?php echo $image_exists ? 'true' : 'false'; ?>)">
                            <div class="card h-100 shadow-sm border-0 dish-modal-card" style="cursor: pointer; transition: all 0.3s ease; border-radius: 16px; overflow: hidden;">
                                <div style="position: relative; overflow: hidden; height: 200px; background: #f1f5f9;">
                                    <?php if ($image_exists): ?>
                                        <img src="<?php echo htmlspecialchars($image_path); ?>" 
                                             class="w-100 h-100" 
                                             style="object-fit: cover; transition: transform 0.3s ease;"
                                             alt="<?php echo htmlspecialchars($dish['name']); ?>"
                                             loading="lazy" decoding="async"
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
                                            <i class="bi bi-plus-circle me-1"></i>Add
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
                                    <?php if ($dish['number_of_persons']): ?>
                                        <small class="badge bg-info-subtle text-info mt-2">
                                            <i class="bi bi-people me-1"></i><?php echo $dish['number_of_persons']; ?> person(s)
                                        </small>
                                    <?php endif; ?>
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
                <div class="d-flex justify-content-between w-100 align-items-center">
                    <div>
                        <span class="text-muted">
                            <span id="modalSelectedCount">0</span> dish(es) selected
                        </span>
                    </div>
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Dish Quantity Modal -->
<div class="modal fade" id="dishQuantityModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px;">
            <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                <h5 class="modal-title text-white fw-bold" id="modalDishName">Dish Name</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="modalDishImage" class="text-center mb-3"></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Quantity</label>
                    <input type="number" class="form-control form-control-lg" id="modalDishQuantity" min="1" value="1" style="border-radius: 12px;">
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid #e2e8f0;">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary rounded-pill px-4" onclick="confirmDishSelection()">
                    <i class="bi bi-check-lg me-2"></i>Add Dish
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentStep = 1;
let selectedDishes = {};

// Customer autocomplete data
let allCustomers = <?php echo json_encode($customers); ?>;
let customerDropdownTimeout = null;

// Initialize - update inputs on page load if needed
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedDishesInputs();
});

// Load customer data when customer is selected from dropdown
function loadCustomerData() {
    const customerSelect = document.getElementById('customer_select');
    const customerIdInput = document.getElementById('customer_id');
    const customerNameInput = document.getElementById('customer_name');
    const customerCellInput = document.getElementById('customer_cell');
    
    if (customerSelect && customerSelect.value) {
        const selectedOption = customerSelect.options[customerSelect.selectedIndex];
        const customerId = selectedOption.value;
        const customerName = selectedOption.getAttribute('data-name');
        const customerEmail = selectedOption.getAttribute('data-email');
        const customerCell = selectedOption.getAttribute('data-cell');
        
        // Populate customer ID and name
        if (customerIdInput) {
            customerIdInput.value = customerId;
        }
        if (customerNameInput) {
            customerNameInput.value = customerName;
        }
        
        // Populate cell number if available from previous orders
        if (customerCellInput && customerCell) {
            customerCellInput.value = customerCell;
        } else if (customerCellInput && !customerCellInput.value) {
            // Focus on cell input if no previous cell number found
            customerCellInput.focus();
        }
    } else {
        // Clear customer ID if no customer selected
        if (customerIdInput) {
            customerIdInput.value = '0';
        }
    }
}

// Filter and show customers in dropdown
function filterCustomers(searchTerm) {
    const dropdown = document.getElementById('customerDropdown');
    const customerNameInput = document.getElementById('customer_name');
    const customerIdInput = document.getElementById('customer_id');
    
    if (!dropdown || !customerNameInput) return;
    
    // Clear previous timeout
    if (customerDropdownTimeout) {
        clearTimeout(customerDropdownTimeout);
    }
    
    // Hide dropdown if search is empty
    if (!searchTerm || searchTerm.trim() === '') {
        dropdown.style.display = 'none';
        customerIdInput.value = '0';
        return;
    }
    
    // Filter customers
    const searchLower = searchTerm.toLowerCase().trim();
    const filtered = allCustomers.filter(customer => {
        const nameMatch = customer.name.toLowerCase().includes(searchLower);
        const emailMatch = customer.email.toLowerCase().includes(searchLower);
        return nameMatch || emailMatch;
    });
    
    // Show dropdown with filtered results
    if (filtered.length > 0) {
        let html = '';
        filtered.forEach(customer => {
            const cellDisplay = customer.last_cell ? `<div class="customer-cell"><i class="bi bi-telephone me-1"></i>${escapeHtml(customer.last_cell)}</div>` : '';
            const escapedName = escapeHtml(customer.name);
            const escapedEmail = escapeHtml(customer.email);
            // Use data attributes for safer data passing
            html += `
                <div class="customer-autocomplete-item" 
                     data-customer-id="${customer.id}" 
                     data-customer-name="${escapeHtmlAttr(customer.name)}" 
                     data-customer-cell="${escapeHtmlAttr(customer.last_cell || '')}"
                     onmousedown="event.preventDefault(); selectCustomerFromItem(this);">
                    <div class="customer-name">${escapedName}</div>
                    <div class="customer-email"><i class="bi bi-envelope me-1"></i>${escapedEmail}</div>
                    ${cellDisplay}
                </div>
            `;
        });
        dropdown.innerHTML = html;
        dropdown.style.display = 'block';
    } else {
        dropdown.style.display = 'none';
        customerIdInput.value = '0';
    }
}

// Escape HTML to prevent XSS (for display)
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Escape HTML attributes to prevent XSS (for data attributes)
function escapeHtmlAttr(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

// Show customer dropdown
function showCustomerDropdown() {
    const dropdown = document.getElementById('customerDropdown');
    const customerNameInput = document.getElementById('customer_name');
    
    if (dropdown && customerNameInput && customerNameInput.value.trim() !== '') {
        filterCustomers(customerNameInput.value);
    }
}

// Hide customer dropdown with delay to allow click events
function hideCustomerDropdown() {
    if (customerDropdownTimeout) {
        clearTimeout(customerDropdownTimeout);
    }
    customerDropdownTimeout = setTimeout(() => {
        const dropdown = document.getElementById('customerDropdown');
        if (dropdown) {
            dropdown.style.display = 'none';
        }
    }, 200);
}

// Select customer from autocomplete dropdown item
function selectCustomerFromItem(item) {
    const customerId = item.getAttribute('data-customer-id');
    const customerName = item.getAttribute('data-customer-name');
    const customerCell = item.getAttribute('data-customer-cell');
    selectCustomer(customerId, customerName, customerCell);
}

// Select customer from autocomplete dropdown
function selectCustomer(customerId, customerName, customerCell) {
    const customerIdInput = document.getElementById('customer_id');
    const customerNameInput = document.getElementById('customer_name');
    const customerCellInput = document.getElementById('customer_cell');
    const dropdown = document.getElementById('customerDropdown');
    const customerSelect = document.getElementById('customer_select');
    
    // Set values (data attributes already contain unescaped text)
    if (customerIdInput) customerIdInput.value = customerId;
    if (customerNameInput) customerNameInput.value = customerName || '';
    if (customerCellInput && customerCell) {
        customerCellInput.value = customerCell;
    }
    
    // Update the select dropdown if it exists
    if (customerSelect) {
        customerSelect.value = customerId;
    }
    
    // Hide dropdown immediately
    if (dropdown) {
        dropdown.style.display = 'none';
    }
    
    // Clear any pending hide timeout
    if (customerDropdownTimeout) {
        clearTimeout(customerDropdownTimeout);
        customerDropdownTimeout = null;
    }
    
    // Focus on cell input if cell is empty
    setTimeout(() => {
        if (customerCellInput && !customerCellInput.value) {
            customerCellInput.focus();
        }
    }, 100);
}

function goToStep(step) {
    // Validate current step before proceeding
    if (step > currentStep) {
        if (currentStep === 1 && !validateStep1()) {
            return;
        }
        if (currentStep === 2) {
            // Update inputs before validation
            updateSelectedDishesInputs();
            
            // Check if any dishes are selected
            const selectedCount = Object.keys(selectedDishes).length;
            console.log('Checking dishes before step 3. Selected count:', selectedCount, selectedDishes);
            
            if (selectedCount === 0) {
                alert('Please select at least one dish by clicking on a dish card and confirming the quantity in the modal before proceeding to the next step.');
                return;
            }
        }
    }
    
    // Update hidden inputs when navigating to step 3
    if (step === 3) {
        updateSelectedDishesInputs();
    }
    
    // Update step indicators
    document.querySelectorAll('.step').forEach((s, index) => {
        const stepNum = index + 1;
        if (stepNum < step) {
            s.classList.remove('active');
            s.classList.add('completed');
        } else if (stepNum === step) {
            s.classList.add('active');
            s.classList.remove('completed');
        } else {
            s.classList.remove('active', 'completed');
        }
    });
    
    // Show/hide step content
    document.querySelectorAll('.step-content').forEach((content, index) => {
        if (index + 1 === step) {
            content.classList.add('active');
        } else {
            content.classList.remove('active');
        }
    });
    
    currentStep = step;
}

function validateStep1() {
    const form = document.getElementById('orderForm');
    const required = form.querySelectorAll('#step1 [required]');
    for (let field of required) {
        if (!field.value.trim()) {
            field.focus();
            alert('Please fill all required fields.');
            return false;
        }
    }
    return true;
}

// Dish selection
let currentDishId = null;
let dishQuantityModal = null;
let dishSelectionModal = null;
let currentSelectedCategoryId = null;

// Tab switching function
function showDishTab(tab) {
    // Update tab buttons
    document.querySelectorAll('.dish-tab').forEach(btn => {
        btn.classList.remove('active');
    });
    
    if (tab === 'all') {
        document.getElementById('tabAll').classList.add('active');
        document.getElementById('allDishesContainer').style.display = 'block';
        document.getElementById('previousDishesContainer').style.display = 'none';
    } else if (tab === 'previous') {
        document.getElementById('tabPrevious').classList.add('active');
        document.getElementById('allDishesContainer').style.display = 'none';
        document.getElementById('previousDishesContainer').style.display = 'block';
    }
}

// Dish Selection Modal Functions
let currentSelectedCategoryId = null;

// Open dish selection modal
function openDishSelectionModal() {
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
        updateModalSelectedCount();
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
function selectDishFromModal(dishId, dishName, imagePath, imageExists) {
    // Close dish selection modal
    if (dishSelectionModal) {
        dishSelectionModal.hide();
    }
    
    // Set current dish and open quantity modal
    currentDishId = dishId;
    const modalDishName = document.getElementById('modalDishName');
    const modalDishQuantity = document.getElementById('modalDishQuantity');
    const modalDishImage = document.getElementById('modalDishImage');
    
    if (modalDishName) modalDishName.textContent = dishName;
    if (modalDishQuantity) modalDishQuantity.value = selectedDishes[dishId] || 1;
    
    // Set image
    if (modalDishImage) {
        if (imageExists && imagePath) {
            modalDishImage.innerHTML = `<img src="${imagePath}" class="modal-dish-image" alt="${dishName}" style="max-width: 100%; max-height: 300px; border-radius: 12px; object-fit: cover;">`;
        } else {
            modalDishImage.innerHTML = `<div class="dish-placeholder modal-dish-image" style="max-width: 100%; max-height: 300px; margin: 0 auto;"><i class="bi bi-egg-fried"></i></div>`;
        }
    }
    
    // Open quantity modal
    if (dishQuantityModal) {
        dishQuantityModal.show();
    }
}

// Update selected count in modal
function updateModalSelectedCount() {
    const count = Object.keys(selectedDishes).length;
    const countElement = document.getElementById('modalSelectedCount');
    if (countElement) {
        countElement.textContent = count;
    }
}

// Initialize dish selection when DOM is ready
function initializeDishSelection() {
    // Initialize modals
    const quantityModalElement = document.getElementById('dishQuantityModal');
    if (quantityModalElement && !dishQuantityModal) {
        dishQuantityModal = new bootstrap.Modal(quantityModalElement);
    }
    
    const selectionModalElement = document.getElementById('dishSelectionModal');
    if (selectionModalElement && !dishSelectionModal) {
        dishSelectionModal = new bootstrap.Modal(selectionModalElement);
    }
    
    // Use event delegation for better reliability - handle both containers
    const dishesContainer = document.getElementById('dishesContainer');
    const previousDishesContainer = document.getElementById('previousDishesContainerGrid');
    
    function setupDishCardClick(container) {
        if (container) {
            container.addEventListener('click', function(e) {
                // Find the closest dish card
                const card = e.target.closest('.dish-card');
                if (!card) return;
                
                // Don't open modal if clicking on the badge (for removal)
                if (e.target.closest('.quantity-badge') || e.target.closest('.previously-used-badge')) {
                    return;
                }
                
                const dishId = String(card.dataset.dishId);
                const dishName = card.querySelector('.card-title')?.textContent || 'Unknown Dish';
                const dishImage = card.querySelector('.dish-image, .dish-placeholder');
                
                if (!dishId) {
                    console.error('Dish ID not found');
                    return;
                }
                
                console.log('Dish card clicked:', dishId, dishName);
                
                currentDishId = dishId;
                const modalDishName = document.getElementById('modalDishName');
                const modalDishQuantity = document.getElementById('modalDishQuantity');
                
                if (modalDishName) modalDishName.textContent = dishName;
                if (modalDishQuantity) modalDishQuantity.value = selectedDishes[dishId] || 1;
                
                // Set image
                const modalImageDiv = document.getElementById('modalDishImage');
                if (modalImageDiv) {
                    if (dishImage && dishImage.tagName === 'IMG') {
                        modalImageDiv.innerHTML = `<img src="${dishImage.src}" class="modal-dish-image" alt="${dishName}">`;
                    } else {
                        modalImageDiv.innerHTML = `<div class="dish-placeholder modal-dish-image"><i class="bi bi-egg-fried"></i></div>`;
                    }
                }
                
                if (dishQuantityModal) {
                    dishQuantityModal.show();
                } else {
                    console.error('Modal not initialized');
                }
            });
        }
    }
    
    setupDishCardClick(dishesContainer);
    setupDishCardClick(previousDishesContainer);
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeDishSelection);
} else {
    initializeDishSelection();
}

function confirmDishSelection() {
    const quantityInput = document.getElementById('modalDishQuantity');
    const quantity = parseInt(quantityInput.value);
    
    console.log('confirmDishSelection called:', { quantity, currentDishId, selectedDishes });
    
    if (!currentDishId) {
        alert('Error: No dish selected. Please click on a dish card first.');
        console.error('No currentDishId set');
        return;
    }
    
    if (isNaN(quantity) || quantity <= 0) {
        alert('Please enter a valid quantity (greater than 0).');
        quantityInput.focus();
        return;
    }
    
    // Ensure dish ID is stored as string for consistency
    const dishId = String(currentDishId);
    selectedDishes[dishId] = quantity;
    
    console.log('Dish added to selectedDishes:', dishId, quantity);
    console.log('Total selected dishes:', Object.keys(selectedDishes).length, selectedDishes);
    
    updateDishCards();
    updateSelectedDishesInputs();
    updateModalSelectedCount();
    
    if (dishQuantityModal) {
        dishQuantityModal.hide();
    }
    
    // Show success message
    const dishName = document.getElementById('modalDishName').textContent;
    console.log(`Successfully added ${quantity} of ${dishName} to order`);
}

function updateDishCards() {
    let selectedCount = 0;
    
    // Update all dish cards in both containers
    document.querySelectorAll('.dish-card').forEach(card => {
        const dishId = String(card.dataset.dishId); // Convert to string for consistency
        const badge = card.querySelector('.quantity-badge');
        
        if (selectedDishes[dishId]) {
            card.classList.add('selected');
            if (badge) {
                badge.textContent = selectedDishes[dishId];
                badge.style.display = 'flex';
            }
            selectedCount++;
        } else {
            card.classList.remove('selected');
            if (badge) {
                badge.style.display = 'none';
            }
        }
    });
    
    // Update the selected dishes count display
    const countDisplay = document.getElementById('selectedDishesCount');
    const countText = document.getElementById('selectedCount');
    if (countDisplay && countText) {
        if (selectedCount > 0) {
            countText.textContent = selectedCount;
            countDisplay.style.display = 'block';
        } else {
            countDisplay.style.display = 'none';
        }
    }
}

function updateSelectedDishesInputs() {
    const container = document.getElementById('selectedDishesInputs');
    container.innerHTML = '';
    
    Object.keys(selectedDishes).forEach(dishId => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = `selected_dishes[${dishId}]`;
        input.value = selectedDishes[dishId];
        container.appendChild(input);
    });
}

// Remove dish on badge click
document.addEventListener('click', function(e) {
    const badge = e.target.closest('.quantity-badge');
    if (badge && badge.style.display !== 'none') {
        e.preventDefault();
        e.stopPropagation();
        
        const card = badge.closest('.dish-card');
        if (card) {
            const dishId = String(card.dataset.dishId);
            const dishName = card.querySelector('.card-title')?.textContent || 'Unknown';
            
            if (confirm(`Remove ${dishName} from the order?`)) {
                delete selectedDishes[dishId];
                updateDishCards();
                updateSelectedDishesInputs();
                updateModalSelectedCount();
                console.log('Dish removed. Remaining:', Object.keys(selectedDishes).length, selectedDishes);
            }
        }
    }
});

// Form submission handler - ensure dishes are selected and inputs are updated
document.getElementById('orderForm').addEventListener('submit', function(e) {
    // Update hidden inputs before submission
    updateSelectedDishesInputs();
    
    // Validate that at least one dish is selected
    const selectedCount = Object.keys(selectedDishes).length;
    console.log('Form submit - Selected dishes count:', selectedCount, selectedDishes);
    
    // Also check if hidden inputs exist
    const hiddenInputs = document.querySelectorAll('#selectedDishesInputs input[type="hidden"]');
    console.log('Form submit - Hidden inputs count:', hiddenInputs.length);
    
    if (selectedCount === 0 && hiddenInputs.length === 0) {
        e.preventDefault();
        alert('Please select at least one dish in Step 2 before creating the order.');
        goToStep(2);
        return false;
    }
    
    // Validate Step 1 fields
    if (!validateStep1()) {
        e.preventDefault();
        goToStep(1);
        return false;
    }
    
    // Validate Step 3 fields (compulsory items)
    const step3Required = document.querySelectorAll('#step3 [required]');
    for (let field of step3Required) {
        if (!field.value.trim() && field.value !== '0') {
            e.preventDefault();
            field.focus();
            alert('Please fill all required fields in Step 3.');
            return false;
        }
    }
    
    return true;
});

</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>


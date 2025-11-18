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
    'shift' => "ENUM('afternoon', 'evening') DEFAULT NULL",
    'number_of_persons' => "INT DEFAULT NULL",
    'cloth_malmal_quantity' => "INT DEFAULT 0",
    'match_box_quantity' => "INT DEFAULT 0",
    'surrf_quantity' => "INT DEFAULT 0",
    'sponjis_quantity' => "INT DEFAULT 0"
];

foreach ($required_columns as $column => $definition) {
    $result = $conn->query("SHOW COLUMNS FROM `orders` LIKE '$column'");
    if (!$result || $result->num_rows == 0) {
        $conn->query("ALTER TABLE `orders` ADD COLUMN `$column` $definition");
    }
}

// Handle order creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
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
    if (empty($customer_name) || empty($customer_cell) || empty($order_date) || empty($order_time) || 
        $number_of_persons <= 0 || empty($shift) || empty($delivery_date) || empty($delivery_time)) {
        $error = 'Please fill all required fields in Step 1.';
    } elseif (empty($dishes_data)) {
        $error = 'Please select at least one dish in Step 2.';
    } else {
        // Generate order number
        $order_number = 'ORD-' . date('Ymd') . '-' . str_pad(time() % 1000000, 6, '0', STR_PAD_LEFT);
        
        // Combine order date and time
        $order_datetime = $order_date . ' ' . $order_time . ':00';
        
        // Check if order_date column exists, if not add it
        $check_order_date = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'order_date'");
        if (!$check_order_date || $check_order_date->num_rows == 0) {
            $conn->query("ALTER TABLE `orders` ADD COLUMN `order_date` DATETIME DEFAULT NULL AFTER `customer_cell`");
        }
        
        // Create order records for each dish
        $orders_created = 0;
        foreach ($dishes_data as $dish_info) {
            $stmt = $conn->prepare("INSERT INTO orders (order_number, customer_id, dish_id, quantity, total_amount, status, 
                customer_name, customer_cell, order_date, delivery_date, delivery_time, shift, number_of_persons,
                cloth_malmal_quantity, match_box_quantity, surrf_quantity, sponjis_quantity) 
                VALUES (?, 0, ?, ?, 0, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt) {
                $stmt->bind_param("siidsssssiiiii", 
                    $order_number, 
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
                    $sponjis_quantity
                );
                
                if ($stmt->execute()) {
                    $orders_created++;
                } else {
                    $error = 'Failed to create order: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
        
        if ($orders_created > 0) {
            $success = "Order #{$order_number} created successfully!";
            header('Location: order_preview.php?order_number=' . urlencode($order_number));
            exit();
        }
    }
}

// Get all dishes with images
$dishes = [];
$result = $conn->query("SELECT d.*, c.name as category_name 
    FROM dishes d 
    LEFT JOIN categories c ON d.category_id = c.id 
    ORDER BY d.name");
if ($result && $result->num_rows > 0) {
    $dishes = $result->fetch_all(MYSQLI_ASSOC);
}

$conn->close();

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
    height: 200px;
    object-fit: cover;
    background: #f1f5f9;
}

.dish-placeholder {
    width: 100%;
    height: 200px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 3rem;
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
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Customer Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="customer_name" required 
                                   value="<?php echo htmlspecialchars($_POST['customer_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Customer Cell No <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" name="customer_cell" required 
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
                    <h5 class="fw-bold mb-4"><i class="bi bi-egg-fried me-2"></i>Select Dishes</h5>
                    <div class="row g-3" id="dishesContainer">
                        <?php foreach ($dishes as $dish): 
                            $image_path = !empty($dish['image']) ? '../' . $dish['image'] : '';
                            $image_exists = !empty($dish['image']) && file_exists(__DIR__ . '/../' . $dish['image']);
                        ?>
                            <div class="col-md-4 col-lg-3">
                                <div class="dish-card card h-100 position-relative" data-dish-id="<?php echo $dish['id']; ?>">
                                    <?php if ($image_exists): ?>
                                        <img src="<?php echo htmlspecialchars($image_path); ?>" class="dish-image" alt="<?php echo htmlspecialchars($dish['name']); ?>">
                                    <?php else: ?>
                                        <div class="dish-placeholder">
                                            <i class="bi bi-egg-fried"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="card-body">
                                        <h6 class="card-title fw-bold"><?php echo htmlspecialchars($dish['name']); ?></h6>
                                        <small class="text-muted"><?php echo htmlspecialchars($dish['category_name'] ?? ''); ?></small>
                                    </div>
                                    <div class="quantity-badge" style="display: none;">0</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
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
                            <label class="form-label fw-semibold">Surrf <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="surrf_quantity" required min="0" 
                                   value="<?php echo htmlspecialchars($_POST['surrf_quantity'] ?? '0'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Sponjis (Iron) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="sponjis_quantity" required min="0" 
                                   value="<?php echo htmlspecialchars($_POST['sponjis_quantity'] ?? '0'); ?>">
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

<!-- Dish Quantity Modal -->
<div class="modal fade" id="dishQuantityModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalDishName">Dish Name</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="modalDishImage"></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Quantity</label>
                    <input type="number" class="form-control" id="modalDishQuantity" min="1" value="1">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmDishSelection()">Add Dish</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentStep = 1;
let selectedDishes = {};

function goToStep(step) {
    // Validate current step before proceeding
    if (step > currentStep) {
        if (currentStep === 1 && !validateStep1()) {
            return;
        }
        if (currentStep === 2 && Object.keys(selectedDishes).length === 0) {
            alert('Please select at least one dish.');
            return;
        }
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
const dishQuantityModal = new bootstrap.Modal(document.getElementById('dishQuantityModal'));

document.querySelectorAll('.dish-card').forEach(card => {
    card.addEventListener('click', function() {
        const dishId = this.dataset.dishId;
        const dishName = this.querySelector('.card-title').textContent;
        const dishImage = this.querySelector('.dish-image, .dish-placeholder');
        
        currentDishId = dishId;
        document.getElementById('modalDishName').textContent = dishName;
        document.getElementById('modalDishQuantity').value = selectedDishes[dishId] || 1;
        
        // Set image
        const modalImageDiv = document.getElementById('modalDishImage');
        if (dishImage.tagName === 'IMG') {
            modalImageDiv.innerHTML = `<img src="${dishImage.src}" class="modal-dish-image" alt="${dishName}">`;
        } else {
            modalImageDiv.innerHTML = `<div class="dish-placeholder modal-dish-image"><i class="bi bi-egg-fried"></i></div>`;
        }
        
        dishQuantityModal.show();
    });
});

function confirmDishSelection() {
    const quantity = parseInt(document.getElementById('modalDishQuantity').value);
    if (quantity > 0 && currentDishId) {
        selectedDishes[currentDishId] = quantity;
        updateDishCards();
        updateSelectedDishesInputs();
        dishQuantityModal.hide();
    }
}

function updateDishCards() {
    document.querySelectorAll('.dish-card').forEach(card => {
        const dishId = card.dataset.dishId;
        const badge = card.querySelector('.quantity-badge');
        
        if (selectedDishes[dishId]) {
            card.classList.add('selected');
            badge.textContent = selectedDishes[dishId];
            badge.style.display = 'flex';
        } else {
            card.classList.remove('selected');
            badge.style.display = 'none';
        }
    });
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
    if (e.target.closest('.quantity-badge')) {
        e.stopPropagation();
        const card = e.target.closest('.dish-card');
        const dishId = card.dataset.dishId;
        delete selectedDishes[dishId];
        updateDishCards();
        updateSelectedDishesInputs();
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>


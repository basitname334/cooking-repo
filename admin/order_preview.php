<?php
/**
 * Order Preview Page
 * Shows customer info, dishes, and categorized ingredients list
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

requireAdmin();

$conn = getDBConnection();
$order_number = $_GET['order_number'] ?? '';

if (empty($order_number)) {
    header('Location: orders.php');
    exit();
}

// Get order details
$orders = [];
$result = $conn->prepare("SELECT o.*, d.name as dish_name, d.image as dish_image, u.name as user_name 
    FROM orders o 
    LEFT JOIN dishes d ON o.dish_id = d.id 
    LEFT JOIN users u ON o.customer_id = u.id 
    WHERE o.order_number = ? 
    ORDER BY o.id");
$result->bind_param("s", $order_number);
$result->execute();
$orders_result = $result->get_result();
if ($orders_result && $orders_result->num_rows > 0) {
    $orders = $orders_result->fetch_all(MYSQLI_ASSOC);
}
$result->close();

if (empty($orders)) {
    header('Location: orders.php');
    exit();
}

// Get first order for customer info (all orders have same customer info)
$first_order = $orders[0];

// Get all ingredients for selected dishes, grouped by category
$dish_ids = array_unique(array_column($orders, 'dish_id'));
$ingredients = [];

if (!empty($dish_ids)) {
    $placeholders = str_repeat('?,', count($dish_ids) - 1) . '?';
    $ingredients_query = $conn->prepare("
        SELECT 
            i.id,
            i.name as ingredient_name,
            i.category_id,
            c.name as category_name,
            di.dish_id,
            di.quantity as base_quantity,
            di.unit as base_unit,
            o.quantity as order_quantity
        FROM dish_ingredients di
        INNER JOIN ingredients i ON di.ingredient_id = i.id
        INNER JOIN categories c ON i.category_id = c.id
        INNER JOIN orders o ON di.dish_id = o.dish_id AND o.order_number = ?
        WHERE di.dish_id IN ($placeholders)
        ORDER BY c.name, i.name
    ");
    $ingredients_query->bind_param("s" . str_repeat("i", count($dish_ids)), $order_number, ...$dish_ids);
    $ingredients_query->execute();
    $ingredients_result = $ingredients_query->get_result();
    if ($ingredients_result && $ingredients_result->num_rows > 0) {
        $ingredients = $ingredients_result->fetch_all(MYSQLI_ASSOC);
    }
    $ingredients_query->close();
}

// Group ingredients by category and calculate totals
$ingredients_by_category = [];
foreach ($ingredients as $ing) {
    $cat_id = $ing['category_id'];
    $cat_name = $ing['category_name'];
    $ing_id = $ing['id'];
    $ing_name = $ing['ingredient_name'];
    $base_qty = floatval($ing['base_quantity']);
    $base_unit = $ing['base_unit'];
    $order_qty = floatval($ing['order_quantity']);
    
    // Calculate total quantity (base_quantity * order_quantity)
    $total_qty = $base_qty * $order_qty;
    
    if (!isset($ingredients_by_category[$cat_id])) {
        $ingredients_by_category[$cat_id] = [
            'category_name' => $cat_name,
            'ingredients' => []
        ];
    }
    
    // If ingredient already exists in this category, add to quantity
    if (isset($ingredients_by_category[$cat_id]['ingredients'][$ing_id])) {
        $ingredients_by_category[$cat_id]['ingredients'][$ing_id]['total_quantity'] += $total_qty;
    } else {
        $ingredients_by_category[$cat_id]['ingredients'][$ing_id] = [
            'name' => $ing_name,
            'total_quantity' => $total_qty,
            'unit' => $base_unit
        ];
    }
}

$conn->close();

$pageTitle = 'Order Preview - ' . $order_number;
include __DIR__ . '/../includes/header.php';
?>

<style>
.preview-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 20px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    margin-bottom: 2rem;
}

.preview-header {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
    border-radius: 20px 20px 0 0;
    padding: 1.5rem;
    border-bottom: 1px solid rgba(226, 232, 240, 0.8);
}

.info-row {
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 600;
    color: #64748b;
    min-width: 150px;
}

.info-value {
    color: #1e293b;
    font-weight: 500;
}

.category-section {
    margin-bottom: 2rem;
}

.category-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 1rem 1.5rem;
    border-radius: 12px 12px 0 0;
    font-weight: 600;
    margin-bottom: 0;
}

.ingredients-list {
    background: white;
    border: 1px solid #e2e8f0;
    border-top: none;
    border-radius: 0 0 12px 12px;
    padding: 1rem;
}

.ingredient-item {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ingredient-item:last-child {
    border-bottom: none;
}

.dish-badge {
    display: inline-block;
    padding: 0.5rem 1rem;
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(6, 182, 212, 0.1) 100%);
    color: #10b981;
    border-radius: 8px;
    font-weight: 600;
    margin: 0.25rem;
}
</style>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="display-6 fw-bold mb-2" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                <i class="bi bi-eye me-3"></i>Order Preview
            </h1>
            <p class="lead mb-0" style="color: #64748b;">Order #<?php echo htmlspecialchars($order_number); ?></p>
        </div>
        <a href="orders.php" class="btn btn-outline-primary rounded-pill px-4">
            <i class="bi bi-arrow-left me-2"></i>Back to Orders
        </a>
    </div>

    <!-- Customer Information -->
    <div class="preview-card">
        <div class="preview-header">
            <h5 class="mb-0 fw-bold"><i class="bi bi-person me-2"></i>Customer Information</h5>
        </div>
        <div class="card-body p-4">
            <div class="info-row d-flex">
                <span class="info-label">Customer Name:</span>
                <span class="info-value"><?php echo htmlspecialchars($first_order['customer_name'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row d-flex">
                <span class="info-label">Customer Cell No:</span>
                <span class="info-value"><?php echo htmlspecialchars($first_order['customer_cell'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row d-flex">
                <span class="info-label">Number of Persons:</span>
                <span class="info-value"><?php echo htmlspecialchars($first_order['number_of_persons'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row d-flex">
                <span class="info-label">Date & Time:</span>
                <span class="info-value">
                    <?php 
                    if (!empty($first_order['order_date'])) {
                        $order_dt = new DateTime($first_order['order_date']);
                        echo $order_dt->format('F j, Y g:i A');
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </span>
            </div>
            <div class="info-row d-flex">
                <span class="info-label">Delivery Date:</span>
                <span class="info-value">
                    <?php 
                    if (!empty($first_order['delivery_date'])) {
                        $delivery_dt = new DateTime($first_order['delivery_date']);
                        echo $delivery_dt->format('F j, Y');
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </span>
            </div>
            <div class="info-row d-flex">
                <span class="info-label">Shift:</span>
                <span class="info-value text-capitalize"><?php echo htmlspecialchars($first_order['shift'] ?? 'N/A'); ?></span>
            </div>
            <div class="info-row d-flex">
                <span class="info-label">Delivery Time:</span>
                <span class="info-value">
                    <?php 
                    if (!empty($first_order['delivery_time'])) {
                        $time = new DateTime($first_order['delivery_time']);
                        echo $time->format('g:i A');
                    } else {
                        echo 'N/A';
                    }
                    ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Selected Dishes -->
    <div class="preview-card">
        <div class="preview-header">
            <h5 class="mb-0 fw-bold"><i class="bi bi-egg-fried me-2"></i>Selected Dishes</h5>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <?php foreach ($orders as $order): 
                    $image_path = !empty($order['dish_image']) ? '../' . $order['dish_image'] : '';
                    $image_exists = !empty($order['dish_image']) && file_exists(__DIR__ . '/../' . $order['dish_image']);
                ?>
                    <div class="col-md-4">
                        <div class="card border-0 shadow-sm h-100">
                            <?php if ($image_exists): ?>
                                <img src="<?php echo htmlspecialchars($image_path); ?>" class="card-img-top" style="height: 200px; object-fit: cover;" alt="<?php echo htmlspecialchars($order['dish_name']); ?>">
                            <?php else: ?>
                                <div class="card-img-top d-flex align-items-center justify-content-center" style="height: 200px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                                    <i class="bi bi-egg-fried text-white" style="font-size: 3rem;"></i>
                                </div>
                            <?php endif; ?>
                            <div class="card-body">
                                <h6 class="card-title fw-bold"><?php echo htmlspecialchars($order['dish_name']); ?></h6>
                                <span class="dish-badge">Quantity: <?php echo htmlspecialchars($order['quantity']); ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Compulsory Items -->
    <div class="preview-card">
        <div class="preview-header">
            <h5 class="mb-0 fw-bold"><i class="bi bi-list-check me-2"></i>Compulsory Items</h5>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="text-center p-3 border rounded">
                        <div class="fw-bold text-muted mb-2">Cloth Malmal</div>
                        <div class="h4 mb-0"><?php echo htmlspecialchars($first_order['cloth_malmal_quantity'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 border rounded">
                        <div class="fw-bold text-muted mb-2">Match Box</div>
                        <div class="h4 mb-0"><?php echo htmlspecialchars($first_order['match_box_quantity'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 border rounded">
                        <div class="fw-bold text-muted mb-2">Surrf</div>
                        <div class="h4 mb-0"><?php echo htmlspecialchars($first_order['surrf_quantity'] ?? 0); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="text-center p-3 border rounded">
                        <div class="fw-bold text-muted mb-2">Sponjis (Iron)</div>
                        <div class="h4 mb-0"><?php echo htmlspecialchars($first_order['sponjis_quantity'] ?? 0); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Ingredients List by Category -->
    <div class="preview-card">
        <div class="preview-header">
            <h5 class="mb-0 fw-bold"><i class="bi bi-basket me-2"></i>Ingredients List</h5>
        </div>
        <div class="card-body p-4">
            <?php if (empty($ingredients_by_category)): ?>
                <div class="text-center py-5">
                    <i class="bi bi-inbox display-4 text-muted d-block mb-3"></i>
                    <p class="text-muted">No ingredients found for selected dishes.</p>
                </div>
            <?php else: ?>
                <?php foreach ($ingredients_by_category as $cat_id => $cat_data): ?>
                    <div class="category-section">
                        <h6 class="category-header">
                            <i class="bi bi-folder me-2"></i><?php echo htmlspecialchars($cat_data['category_name']); ?>
                        </h6>
                        <div class="ingredients-list">
                            <?php foreach ($cat_data['ingredients'] as $ing): ?>
                                <div class="ingredient-item">
                                    <span class="fw-semibold"><?php echo htmlspecialchars($ing['name']); ?></span>
                                    <span class="badge bg-primary rounded-pill px-3 py-2">
                                        <?php 
                                        echo number_format($ing['total_quantity'], 2);
                                        if (!empty($ing['unit'])) {
                                            echo ' ' . htmlspecialchars($ing['unit']);
                                        }
                                        ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>


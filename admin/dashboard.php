<?php
/**
 * Admin Dashboard
 * Overview of the system with statistics
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

requireAdmin();

$conn = getDBConnection();

// Get statistics
$categories_count = 0;
$ingredients_count = 0;
$dishes_count = 0;
$users_count = 0;
$orders_count = 0;
$total_revenue = 0;
$pending_orders = 0;
$today_orders = 0;
$today_revenue = 0;

$result = $conn->query("SELECT COUNT(*) as count FROM categories");
if ($result && $result->num_rows > 0) {
    $categories_count = $result->fetch_assoc()['count'] ?? 0;
}

$result = $conn->query("SELECT COUNT(*) as count FROM ingredients");
if ($result && $result->num_rows > 0) {
    $ingredients_count = $result->fetch_assoc()['count'] ?? 0;
}

$result = $conn->query("SELECT COUNT(*) as count FROM dishes");
if ($result && $result->num_rows > 0) {
    $dishes_count = $result->fetch_assoc()['count'] ?? 0;
}

$result = $conn->query("SELECT COUNT(*) as count FROM users WHERE role = 'user'");
if ($result && $result->num_rows > 0) {
    $users_count = $result->fetch_assoc()['count'] ?? 0;
}

$result = $conn->query("SELECT COUNT(*) as count FROM orders");
if ($result && $result->num_rows > 0) {
    $orders_count = $result->fetch_assoc()['count'] ?? 0;
}

$result = $conn->query("SELECT SUM(total_amount) as total FROM orders WHERE status = 'delivered'");
if ($result && $result->num_rows > 0) {
    $total_revenue = $result->fetch_assoc()['total'] ?? 0;
}

$result = $conn->query("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'");
if ($result && $result->num_rows > 0) {
    $pending_orders = $result->fetch_assoc()['count'] ?? 0;
}

$result = $conn->query("SELECT COUNT(*) as count, SUM(total_amount) as total FROM orders WHERE DATE(order_date) = CURDATE()");
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $today_orders = $row['count'] ?? 0;
    $today_revenue = $row['total'] ?? 0;
}

$conn->close();

$pageTitle = 'Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<style>
.stat-box {
    background: white;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border-top: 3px solid;
    transition: transform 0.2s;
}

.stat-box:hover {
    transform: translateY(-3px);
}

.stat-box.primary { border-top-color: #171717; }
.stat-box.success { border-top-color: #525252; }
.stat-box.warning { border-top-color: #737373; }
.stat-box.info { border-top-color: #404040; }

.stat-box .number {
    font-size: 36px;
    font-weight: bold;
    margin: 10px 0;
    line-height: 1;
    color: #171717;
}

.stat-box .label {
    color: #6b7280;
    font-size: 14px;
    margin-top: 5px;
}

.quick-link {
    display: block;
    background: white;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    text-decoration: none;
    color: #1f2937;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.2s;
    border: 2px solid transparent;
}

.quick-link:hover {
    border-color: #6366f1;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
    color: #1f2937;
    text-decoration: none;
}

.quick-link .icon {
    font-size: 32px;
    margin-bottom: 10px;
    display: block;
}

.quick-link .text {
    font-weight: 600;
    font-size: 16px;
    margin-top: 5px;
}
</style>

<!-- Simple Header -->
<div class="row mb-4">
    <div class="col-12">
        <h2 class="mb-1">Dashboard</h2>
        <p class="text-muted">Welcome, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></p>
    </div>
</div>

<!-- Main Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
        <div class="stat-box primary">
            <div class="number"><?php echo $orders_count; ?></div>
            <div class="label">Total Orders</div>
            <small class="text-muted"><?php echo $today_orders; ?> today</small>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-box success">
            <div class="number">Rs <?php echo number_format($total_revenue, 0); ?></div>
            <div class="label">Total Revenue</div>
            <small class="text-muted">Rs <?php echo number_format($today_revenue, 0); ?> today</small>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-box warning">
            <div class="number"><?php echo $pending_orders; ?></div>
            <div class="label">Pending Orders</div>
            <small class="text-muted">Needs attention</small>
        </div>
    </div>
    <div class="col-md-3 col-sm-6">
        <div class="stat-box info">
            <div class="number"><?php echo $dishes_count; ?></div>
            <div class="label">Dishes</div>
            <small class="text-muted"><?php echo $ingredients_count; ?> ingredients</small>
        </div>
    </div>
</div>

<!-- Quick Links -->
<div class="row g-3">
    <div class="col-md-2 col-sm-4 col-6">
        <a href="categories.php" class="quick-link">
            <span class="icon"><i class="bi bi-folder2-open"></i></span>
            <div class="text">Categories</div>
            <small class="text-muted"><?php echo $categories_count; ?></small>
        </a>
    </div>
    <div class="col-md-2 col-sm-4 col-6">
        <a href="ingredients.php" class="quick-link">
            <span class="icon"><i class="bi bi-basket"></i></span>
            <div class="text">Ingredients</div>
            <small class="text-muted"><?php echo $ingredients_count; ?></small>
        </a>
    </div>
    <div class="col-md-2 col-sm-4 col-6">
        <a href="dishes.php" class="quick-link">
            <span class="icon"><i class="bi bi-egg-fried"></i></span>
            <div class="text">Dishes</div>
            <small class="text-muted"><?php echo $dishes_count; ?></small>
        </a>
    </div>
    <div class="col-md-2 col-sm-4 col-6">
        <a href="customers.php" class="quick-link">
            <span class="icon text-info"><i class="bi bi-people"></i></span>
            <div class="text">Customers</div>
            <small class="text-muted"><?php echo $users_count; ?></small>
        </a>
    </div>
    <div class="col-md-2 col-sm-4 col-6">
        <a href="orders.php" class="quick-link">
            <span class="icon" style="color: #8b5cf6;"><i class="bi bi-cart-check"></i></span>
            <div class="text">Orders</div>
            <small class="text-muted"><?php echo $orders_count; ?></small>
        </a>
    </div>
    <div class="col-md-2 col-sm-4 col-6">
        <a href="reports.php" class="quick-link">
            <span class="icon text-danger"><i class="bi bi-graph-up"></i></span>
            <div class="text">Reports</div>
            <small class="text-muted">View</small>
        </a>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

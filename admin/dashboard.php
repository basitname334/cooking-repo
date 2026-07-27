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

// Get statistics - optimized: combine queries for better performance
$categories_count = 0;
$ingredients_count = 0;
$dishes_count = 0;
$users_count = 0;
$orders_count = 0;
$total_revenue = 0;
$pending_orders = 0;
$today_orders = 0;
$today_revenue = 0;

// Combined query for basic counts (much faster than separate queries)
$counts_query = "SELECT 
    (SELECT COUNT(*) FROM categories) as categories_count,
    (SELECT COUNT(*) FROM ingredients) as ingredients_count,
    (SELECT COUNT(*) FROM dishes) as dishes_count,
    (SELECT COUNT(*) FROM users WHERE role = 'user') as users_count,
    (SELECT COUNT(*) FROM orders) as orders_count,
    (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'delivered') as total_revenue,
    (SELECT COUNT(*) FROM orders WHERE status = 'pending') as pending_orders,
    (SELECT COUNT(*) FROM orders WHERE order_date::date = CURRENT_DATE) as today_orders,
    (SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE order_date::date = CURRENT_DATE) as today_revenue";

$row = db_fetch($conn, $counts_query);
if ($row !== null) {
    $categories_count = (int)($row['categories_count'] ?? 0);
    $ingredients_count = (int)($row['ingredients_count'] ?? 0);
    $dishes_count = (int)($row['dishes_count'] ?? 0);
    $users_count = (int)($row['users_count'] ?? 0);
    $orders_count = (int)($row['orders_count'] ?? 0);
    $total_revenue = (float)($row['total_revenue'] ?? 0);
    $pending_orders = (int)($row['pending_orders'] ?? 0);
    $today_orders = (int)($row['today_orders'] ?? 0);
    $today_revenue = (float)($row['today_revenue'] ?? 0);
}

$pageTitle = 'Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<style>
/* Modern Dashboard Styles */
.dashboard-header {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 50%, rgba(240, 147, 251, 0.1) 100%);
    border-radius: 24px;
    padding: 2.5rem;
    margin-bottom: 2rem;
    border: 1px solid rgba(99, 102, 241, 0.2);
    position: relative;
    overflow: hidden;
}

.dashboard-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.1) 0%, transparent 70%);
    border-radius: 50%;
}

.stat-box {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 2rem;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.8);
    position: relative;
    overflow: hidden;
    height: 100%;
}

.stat-box::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--gradient);
    opacity: 0;
}

.stat-box:hover::before {
    opacity: 1;
}

.stat-box:hover {
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(99, 102, 241, 0.1);
}

.stat-box.primary { 
    --gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.stat-box.success { 
    --gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.stat-box.warning { 
    --gradient: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
}

.stat-box.info { 
    --gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.stat-box .icon-wrapper {
    width: 70px;
    height: 70px;
    margin: 0 auto 1.5rem;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--gradient);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}

.stat-box:hover .icon-wrapper {
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
}

.stat-box .icon-wrapper i {
    font-size: 2rem;
    color: white;
}

.stat-box .number {
    font-size: 2.5rem;
    font-weight: 800;
    margin: 0.5rem 0;
    line-height: 1;
    background: var(--gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.stat-box .label {
    color: #64748b;
    font-size: 0.95rem;
    font-weight: 600;
    margin-top: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-box small {
    color: #94a3b8;
    font-size: 0.875rem;
    margin-top: 0.5rem;
    display: block;
}

.quick-link {
    display: block;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 18px;
    padding: 2rem 1.5rem;
    text-align: center;
    text-decoration: none;
    color: #1e293b;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.8);
    position: relative;
    overflow: hidden;
    height: 100%;
}

.quick-link::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(99, 102, 241, 0.1), transparent);
}

.quick-link:hover::before {
    left: 100%;
}

.quick-link:hover {
    box-shadow: 0 12px 35px rgba(99, 102, 241, 0.25);
    border-color: rgba(99, 102, 241, 0.3);
    color: #1e293b;
    text-decoration: none;
}

.quick-link .icon {
    width: 60px;
    height: 60px;
    margin: 0 auto 1rem;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);
    font-size: 1.75rem;
    color: #6366f1;
}

.quick-link:hover .icon {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
}

.quick-link .text {
    font-weight: 700;
    font-size: 1rem;
    margin-top: 0.5rem;
    color: #1e293b;
}

.quick-link small {
    color: #94a3b8;
    font-size: 0.875rem;
    margin-top: 0.25rem;
    display: block;
}

.import-card {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.1) 0%, rgba(6, 182, 212, 0.1) 100%);
    border-radius: 20px;
    border: 1px solid rgba(16, 185, 129, 0.2);
    overflow: hidden;
}

.import-card .card-header {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
    border: none;
    padding: 1.5rem 2rem;
}

.import-card .card-header h5 {
    color: white;
    font-weight: 700;
    margin: 0;
    font-size: 1.25rem;
}

.import-card .card-body {
    padding: 2rem;
}

</style>

<!-- Modern Dashboard Header -->
<div class="dashboard-header">
    <div class="d-flex align-items-center justify-content-between flex-wrap">
        <div>
            <h1 class="display-5 fw-bold mb-2" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <i class="bi bi-speedometer2 me-3"></i>Dashboard
            </h1>
            <p class="lead mb-0" style="color: #64748b;">
                Welcome back, <strong style="color: #6366f1;"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></strong>! 
                Here's what's happening with your business today.
            </p>
        </div>
        <div class="mt-3 mt-md-0">
            <div style="background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(10px); padding: 1rem 1.5rem; border-radius: 16px; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);">
                <div class="text-muted small mb-1">Today</div>
                <div class="fw-bold" style="color: #1e293b; font-size: 1.1rem;">
                    <?php echo date('F j, Y'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Statistics Cards -->
<div class="row g-4 mb-5">
    <div class="col-lg-3 col-md-6">
        <div class="stat-box primary">
            <div class="icon-wrapper">
                <i class="bi bi-cart-check"></i>
            </div>
            <div class="number"><?php echo $orders_count; ?></div>
            <div class="label">Total Orders</div>
            <small><i class="bi bi-calendar3 me-1"></i><?php echo $today_orders; ?> today</small>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stat-box success">
            <div class="icon-wrapper">
                <i class="bi bi-currency-dollar"></i>
            </div>
            <div class="number">Rs <?php echo number_format($total_revenue, 0); ?></div>
            <div class="label">Total Revenue</div>
            <small><i class="bi bi-arrow-up-circle me-1"></i>Rs <?php echo number_format($today_revenue, 0); ?> today</small>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stat-box warning">
            <div class="icon-wrapper">
                <i class="bi bi-clock-history"></i>
            </div>
            <div class="number"><?php echo $pending_orders; ?></div>
            <div class="label">Pending Orders</div>
            <small><i class="bi bi-exclamation-triangle me-1"></i>Needs attention</small>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stat-box info">
            <div class="icon-wrapper">
                <i class="bi bi-egg-fried"></i>
            </div>
            <div class="number"><?php echo $dishes_count; ?></div>
            <div class="label">Total Dishes</div>
            <small><i class="bi bi-basket me-1"></i><?php echo $ingredients_count; ?> ingredients</small>
        </div>
    </div>
</div>

<!-- Quick Access Links -->
<div class="row mb-4">
    <div class="col-12">
        <h4 class="fw-bold mb-4" style="color: #1e293b;">
            <i class="bi bi-grid-3x3-gap me-2" style="color: #6366f1;"></i>Quick Access
        </h4>
    </div>
</div>
<div class="row g-4 mb-5">
    <div class="col-lg-2 col-md-4 col-sm-6">
        <a href="categories.php" class="quick-link">
            <div class="icon"><i class="bi bi-folder2-open"></i></div>
            <div class="text">Categories</div>
            <small><?php echo $categories_count; ?> items</small>
        </a>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <a href="ingredients.php" class="quick-link">
            <div class="icon"><i class="bi bi-basket"></i></div>
            <div class="text">Ingredients</div>
            <small><?php echo $ingredients_count; ?> items</small>
        </a>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <a href="dishes.php" class="quick-link">
            <div class="icon"><i class="bi bi-egg-fried"></i></div>
            <div class="text">Dishes</div>
            <small><?php echo $dishes_count; ?> items</small>
        </a>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <a href="customers.php" class="quick-link">
            <div class="icon"><i class="bi bi-people"></i></div>
            <div class="text">Customers</div>
            <small><?php echo $users_count; ?> users</small>
        </a>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <a href="orders.php" class="quick-link">
            <div class="icon"><i class="bi bi-cart-check"></i></div>
            <div class="text">Orders</div>
            <small><?php echo $orders_count; ?> total</small>
        </a>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <a href="create_order.php" class="quick-link">
            <div class="icon"><i class="bi bi-cart-plus"></i></div>
            <div class="text">Create Order</div>
            <small>New order</small>
        </a>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <a href="reports.php" class="quick-link">
            <div class="icon"><i class="bi bi-graph-up"></i></div>
            <div class="text">Reports</div>
            <small>Analytics</small>
        </a>
    </div>
</div>

<!-- Excel Import Section -->
<div class="row">
    <div class="col-12">
        <div class="card import-card shadow-lg border-0">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-file-earmark-excel me-2"></i>Bulk Import from Excel/CSV</h5>
            </div>
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-lg-8 mb-3 mb-lg-0">
                        <h6 class="fw-bold mb-3" style="color: #1e293b; font-size: 1.1rem;">
                            <i class="bi bi-lightning-charge-fill me-2" style="color: #10b981;"></i>Save Time with Bulk Import
                        </h6>
                        <p class="mb-3" style="color: #64748b; line-height: 1.7; font-size: 1rem;">
                            Upload an Excel or CSV file to import categories, dishes, ingredients, and dish ingredients all at once. 
                            Perfect for setting up your menu quickly or migrating data from other systems.
                        </p>
                        <div class="d-flex flex-wrap gap-3">
                            <div style="background: rgba(255, 255, 255, 0.9); padding: 0.75rem 1.25rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);">
                                <small class="text-muted d-block mb-1">
                                    <i class="bi bi-filetype-xlsx me-1"></i>Supported Formats
                                </small>
                                <strong style="color: #1e293b; font-size: 0.9rem;">CSV, Excel (.xls, .xlsx)</strong>
                            </div>
                            <div style="background: rgba(255, 255, 255, 0.9); padding: 0.75rem 1.25rem; border-radius: 12px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);">
                                <small class="text-muted d-block mb-1">
                                    <i class="bi bi-hdd me-1"></i>File Size Limit
                                </small>
                                <strong style="color: #1e293b; font-size: 0.9rem;">Maximum 10MB</strong>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4 text-center text-lg-end">
                        <a href="import_excel.php" class="btn btn-success btn-lg rounded-pill shadow-lg px-4 py-3" style="font-weight: 600; font-size: 1.1rem;">
                            <i class="bi bi-upload me-2"></i>Import from Excel
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

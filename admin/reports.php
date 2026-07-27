<?php
/**
 * Reports Page
 * View system reports and analytics
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireAdmin();

$conn = getDBConnection();

// Get statistics
$categories_count = (int) (db_fetch($conn, 'SELECT COUNT(*) as count FROM categories')['count'] ?? 0);
$ingredients_count = (int) (db_fetch($conn, 'SELECT COUNT(*) as count FROM ingredients')['count'] ?? 0);
$dishes_count = (int) (db_fetch($conn, 'SELECT COUNT(*) as count FROM dishes')['count'] ?? 0);
$customers_count = (int) (db_fetch($conn, "SELECT COUNT(*) as count FROM users WHERE role = 'user'")['count'] ?? 0);
$orders_count = (int) (db_fetch($conn, 'SELECT COUNT(*) as count FROM orders')['count'] ?? 0);
$total_revenue = db_fetch($conn, "SELECT SUM(total_amount) as total FROM orders WHERE status = 'delivered'")['total'] ?? 0;
$pending_orders = (int) (db_fetch($conn, "SELECT COUNT(*) as count FROM orders WHERE status = 'pending'")['count'] ?? 0);
$delivered_orders = (int) (db_fetch($conn, "SELECT COUNT(*) as count FROM orders WHERE status = 'delivered'")['count'] ?? 0);

// Get recent orders
$recent_orders = db_fetch_all(
    $conn,
    'SELECT o.*, u.name as customer_name, d.name as dish_name
     FROM orders o
     LEFT JOIN users u ON o.customer_id = u.id
     LEFT JOIN dishes d ON o.dish_id = d.id
     ORDER BY o.order_date DESC LIMIT 10'
);

// Get top dishes
$top_dishes = db_fetch_all(
    $conn,
    'SELECT d.name, COUNT(o.id) as order_count, SUM(o.quantity) as total_quantity, SUM(o.total_amount) as total_revenue
     FROM dishes d
     LEFT JOIN orders o ON d.id = o.dish_id
     GROUP BY d.id, d.name
     ORDER BY order_count DESC, total_revenue DESC
     LIMIT 5'
);

$pageTitle = 'Reports';
include __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-graph-up"></i> Reports & Analytics</h2>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <i class="bi bi-cart-check display-4 text-primary"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-muted mb-1">Total Orders</h6>
                        <h2 class="mb-0"><?php echo $orders_count; ?></h2>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <i class="bi bi-cash-coin display-4 text-success"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-muted mb-1">Total Revenue</h6>
                        <h2 class="mb-0">Rs <?php echo number_format($total_revenue, 2); ?></h2>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <i class="bi bi-hourglass-split display-4 text-warning"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-muted mb-1">Pending Orders</h6>
                        <h2 class="mb-0"><?php echo $pending_orders; ?></h2>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <i class="bi bi-check-circle display-4 text-info"></i>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h6 class="text-muted mb-1">Delivered</h6>
                        <h2 class="mb-0"><?php echo $delivered_orders; ?></h2>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Top Dishes -->
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-trophy"></i> Top Dishes</h5>
            </div>
            <div class="card-body">
                <?php if (empty($top_dishes)): ?>
                    <p class="text-muted text-center py-4">No orders yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Dish Name</th>
                                    <th>Orders</th>
                                    <th>Total Quantity</th>
                                    <th>Revenue</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_dishes as $dish): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($dish['name']); ?></strong></td>
                                        <td><span class="badge bg-primary"><?php echo $dish['order_count']; ?></span></td>
                                        <td><?php echo number_format($dish['total_quantity'] ?? 0, 2); ?></td>
                                        <td><strong>Rs <?php echo number_format($dish['total_revenue'] ?? 0, 2); ?></strong></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Orders -->
    <div class="col-md-6 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Orders</h5>
            </div>
            <div class="card-body">
                <?php if (empty($recent_orders)): ?>
                    <p class="text-muted text-center py-4">No recent orders.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>Order #</th>
                                    <th>Customer</th>
                                    <th>Dish</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_orders as $order): ?>
                                    <tr>
                                        <td>#<?php echo $order['id']; ?></td>
                                        <td><?php echo htmlspecialchars($order['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($order['dish_name']); ?></td>
                                        <td>Rs <?php echo number_format($order['total_amount'], 2); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $order['status'] == 'delivered' ? 'success' : 
                                                    ($order['status'] == 'pending' ? 'warning' : 
                                                    ($order['status'] == 'cancelled' ? 'danger' : 'info')); 
                                            ?>">
                                                <?php echo ucfirst($order['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- System Overview -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-bar-chart"></i> System Overview</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center mb-3">
                        <div class="p-3 bg-light rounded">
                            <h3 class="text-primary mb-1"><?php echo $categories_count; ?></h3>
                            <p class="text-muted mb-0">Categories</p>
                        </div>
                    </div>
                    <div class="col-md-3 text-center mb-3">
                        <div class="p-3 bg-light rounded">
                            <h3 class="text-success mb-1"><?php echo $ingredients_count; ?></h3>
                            <p class="text-muted mb-0">Ingredients</p>
                        </div>
                    </div>
                    <div class="col-md-3 text-center mb-3">
                        <div class="p-3 bg-light rounded">
                            <h3 class="text-info mb-1"><?php echo $dishes_count; ?></h3>
                            <p class="text-muted mb-0">Dishes</p>
                        </div>
                    </div>
                    <div class="col-md-3 text-center mb-3">
                        <div class="p-3 bg-light rounded">
                            <h3 class="text-warning mb-1"><?php echo $customers_count; ?></h3>
                            <p class="text-muted mb-0">Customers</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>


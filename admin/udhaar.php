<?php
/**
 * Udhaar (credit) management — outstanding balances & receive payments
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

requireLogin();

$conn = getDBConnection();
$error = '';
$success = '';

if (!$conn instanceof PDO) {
    $error = 'Database connection failed.';
} else {
    // Ensure columns exist
    try {
        if (!db_column_exists($conn, 'orders', 'payment_type')) {
            $conn->exec("ALTER TABLE orders ADD COLUMN payment_type VARCHAR(20) DEFAULT 'cash'");
        }
        if (!db_column_exists($conn, 'orders', 'paid_amount')) {
            $conn->exec('ALTER TABLE orders ADD COLUMN paid_amount DECIMAL(10, 2) DEFAULT 0');
        }
    } catch (Throwable $e) {
        error_log('Udhaar schema: ' . $e->getMessage());
    }
}

// Receive payment against an order
if ($conn instanceof PDO && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_payment'])) {
    $order_number = trim($_POST['order_number'] ?? '');
    $receive = floatval($_POST['receive_amount'] ?? 0);
    if ($order_number === '' || $receive <= 0) {
        $error = 'Order number and valid amount are required.';
    } else {
        try {
            $rows = db_fetch_all(
                $conn,
                'SELECT COALESCE(SUM(total_amount),0) as order_total, COALESCE(MAX(paid_amount),0) as paid_amount
                 FROM orders WHERE order_number = ?',
                [$order_number]
            );
            $order_total = floatval($rows[0]['order_total'] ?? 0);
            $already_paid = floatval($rows[0]['paid_amount'] ?? 0);
            $due = max(0, $order_total - $already_paid);
            if ($due <= 0) {
                $error = 'This order has no outstanding udhaar.';
            } else {
                $new_paid = min($order_total, $already_paid + $receive);
                $new_type = ($new_paid + 0.009 >= $order_total) ? 'cash' : 'udhaar';
                db_exec(
                    $conn,
                    'UPDATE orders SET paid_amount = ?, payment_type = ? WHERE order_number = ?',
                    [$new_paid, $new_type, $order_number]
                );
                $success = $new_type === 'cash'
                    ? "Payment received. Order {$order_number} is now fully paid."
                    : 'Payment received. Remaining due: Rs ' . number_format($order_total - $new_paid, 0);
            }
        } catch (Throwable $e) {
            $error = 'Failed to record payment: ' . $e->getMessage();
        }
    }
}

$udhaar_orders = [];
$customer_summary = [];
$total_due = 0;

if ($conn instanceof PDO) {
    try {
        $lines = db_fetch_all(
            $conn,
            "SELECT order_number,
                    MAX(customer_name) as customer_name,
                    MAX(customer_cell) as customer_cell,
                    MAX(order_date) as order_date,
                    COALESCE(MAX(payment_type), 'cash') as payment_type,
                    COALESCE(MAX(paid_amount), 0) as paid_amount,
                    COALESCE(SUM(total_amount), 0) as order_total
             FROM orders
             GROUP BY order_number
             HAVING COALESCE(MAX(payment_type), 'cash') = 'udhaar'
                AND COALESCE(SUM(total_amount), 0) > COALESCE(MAX(paid_amount), 0) + 0.009
             ORDER BY MAX(order_date) DESC NULLS LAST"
        );

        foreach ($lines as $row) {
            $due = max(0, floatval($row['order_total']) - floatval($row['paid_amount']));
            $row['due_amount'] = $due;
            $udhaar_orders[] = $row;
            $total_due += $due;
            $key = trim(($row['customer_name'] ?? '') . '|' . ($row['customer_cell'] ?? ''));
            if ($key === '|') {
                $key = 'unknown';
            }
            if (!isset($customer_summary[$key])) {
                $customer_summary[$key] = [
                    'customer_name' => $row['customer_name'] ?: 'Guest',
                    'customer_cell' => $row['customer_cell'] ?: '-',
                    'orders' => 0,
                    'due' => 0,
                ];
            }
            $customer_summary[$key]['orders']++;
            $customer_summary[$key]['due'] += $due;
        }
        usort($customer_summary, fn($a, $b) => $b['due'] <=> $a['due']);
    } catch (Throwable $e) {
        $error = $error ?: ('Failed to load udhaar: ' . $e->getMessage());
    }
}

$pageTitle = 'Udhaar';
include __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1"><i class="bi bi-cash-coin me-2 text-warning"></i>ادھار (Udhaar)</h1>
            <p class="text-muted mb-0">Outstanding credit orders — receive payments here</p>
        </div>
        <a href="orders.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1"></i>Back to Orders</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Open Udhaar Orders</div>
                    <div class="h3 mb-0 fw-bold"><?php echo count($udhaar_orders); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="text-muted small">Customers with Due</div>
                    <div class="h3 mb-0 fw-bold"><?php echo count($customer_summary); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm" style="background: #fff7ed;">
                <div class="card-body">
                    <div class="text-muted small">Total Due</div>
                    <div class="h3 mb-0 fw-bold text-danger">Rs <?php echo number_format($total_due, 0); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">By Customer</div>
                <div class="card-body p-0">
                    <?php if (empty($customer_summary)): ?>
                        <p class="text-muted p-3 mb-0">No outstanding udhaar.</p>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($customer_summary as $cust): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($cust['customer_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($cust['customer_cell']); ?> · <?php echo (int) $cust['orders']; ?> order(s)</small>
                                    </div>
                                    <span class="badge bg-warning text-dark">Rs <?php echo number_format($cust['due'], 0); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Outstanding Orders</div>
                <div class="card-body p-0">
                    <?php if (empty($udhaar_orders)): ?>
                        <p class="text-muted p-3 mb-0">Sab clear — koi udhaar nahi.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Order</th>
                                        <th>Customer</th>
                                        <th>Total</th>
                                        <th>Paid</th>
                                        <th>Due</th>
                                        <th>Receive</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($udhaar_orders as $row): ?>
                                        <tr>
                                            <td>
                                                <a href="orders.php?order_number=<?php echo urlencode($row['order_number']); ?>">
                                                    <?php echo htmlspecialchars($row['order_number']); ?>
                                                </a>
                                                <div class="small text-muted"><?php echo htmlspecialchars(substr((string) ($row['order_date'] ?? ''), 0, 16)); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($row['customer_name'] ?: 'Guest'); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($row['customer_cell'] ?: '-'); ?></small>
                                            </td>
                                            <td>Rs <?php echo number_format($row['order_total'], 0); ?></td>
                                            <td>Rs <?php echo number_format($row['paid_amount'], 0); ?></td>
                                            <td class="fw-bold text-danger">Rs <?php echo number_format($row['due_amount'], 0); ?></td>
                                            <td style="min-width: 220px;">
                                                <form method="POST" class="d-flex gap-2">
                                                    <input type="hidden" name="receive_payment" value="1">
                                                    <input type="hidden" name="order_number" value="<?php echo htmlspecialchars($row['order_number']); ?>">
                                                    <input type="number" step="0.01" min="0.01" max="<?php echo htmlspecialchars((string) $row['due_amount']); ?>"
                                                           name="receive_amount" class="form-control form-control-sm"
                                                           value="<?php echo htmlspecialchars((string) $row['due_amount']); ?>" required>
                                                    <button type="submit" class="btn btn-sm btn-success text-nowrap">
                                                        <i class="bi bi-check2"></i> Receive
                                                    </button>
                                                </form>
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
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

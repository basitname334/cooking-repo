<?php
/**
 * Customers Management Page
 * View and manage customer accounts
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireAdmin();

$conn = getDBConnection();
$error = '';
$success = '';

// Handle create customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_customer'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Check if email already exists
            $existing = db_fetch($conn, 'SELECT id FROM users WHERE email = ?', [$email]);

            if ($existing !== null) {
                $error = 'Email already registered. Please use a different email.';
            } else {
                // Hash password and insert user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                db_exec(
                    $conn,
                    "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'user')",
                    [$name, $email, $hashed_password]
                );
                $success = 'Customer created successfully!';
                header('Location: customers.php?success=1&created=1');
                exit();
            }
        } catch (PDOException $e) {
            $error = 'Failed to create customer: ' . $e->getMessage();
        }
    }
}

// Handle delete customer
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    // Only allow deleting users (not admins)
    try {
        db_exec($conn, "DELETE FROM users WHERE id = ? AND role = 'user'", [$id]);
        $success = 'Customer deleted successfully!';
        header('Location: customers.php?success=1&deleted=1');
        exit();
    } catch (PDOException $e) {
        $error = 'Failed to delete customer: ' . $e->getMessage();
    }
}

// Handle success message from redirect
if (isset($_GET['success'])) {
    if (isset($_GET['created'])) {
        $success = 'Customer created successfully!';
    } elseif (isset($_GET['deleted'])) {
        $success = 'Customer deleted successfully!';
    }
}

// Get all customers (users with role='user')
$customers = db_fetch_all(
    $conn,
    "SELECT id, name, email, created_at FROM users WHERE role = 'user' ORDER BY created_at DESC"
);

$pageTitle = 'Manage Customers';
include __DIR__ . '/../includes/header.php';
?>

<style>
.page-header-modern {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 50%, rgba(240, 147, 251, 0.1) 100%);
    border-radius: 20px;
    padding: 2rem;
    margin-bottom: 2rem;
    border: 1px solid rgba(99, 102, 241, 0.2);
}

.customer-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 16px;
}

.customer-card:hover {
    box-shadow: 0 12px 35px rgba(99, 102, 241, 0.2);
    border-color: rgba(99, 102, 241, 0.3);
}
</style>

<div class="page-header-modern">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h1 class="display-6 fw-bold mb-2" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <i class="bi bi-people-fill me-3"></i>Manage Customers
            </h1>
            <p class="lead mb-0" style="color: #64748b;">
                <i class="bi bi-info-circle me-2"></i>
                <?php echo count($customers); ?> <?php echo count($customers) == 1 ? 'customer' : 'customers'; ?> registered
            </p>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Create Customer Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-lg border-0" style="background: linear-gradient(135deg, rgba(245, 158, 11, 0.05) 0%, rgba(251, 146, 60, 0.05) 100%); border-radius: 20px; border: 1px solid rgba(245, 158, 11, 0.1);">
            <div class="card-header border-0 pb-0" style="background: transparent; border-bottom: 1px solid rgba(245, 158, 11, 0.1) !important;">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-right: 1rem; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);">
                            <i class="bi bi-person-plus text-white fs-5"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold" style="color: #1e293b;">Create Customer</h5>
                            <p class="text-muted small mb-0">Add a new customer account to the system</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="customerForm">
                    <input type="hidden" name="create_customer" value="1">
                    
                    <div class="row mb-3 g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label fw-semibold mb-2" style="color: #1e293b;">
                                <i class="bi bi-person me-1" style="color: #f59e0b;"></i>Full Name <span class="text-danger">*</span>
                            </label>
                            <input type="text" class="form-control" id="name" name="name" required 
                                   placeholder="Enter customer full name"
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                   style="border-radius: 12px; border: 2px solid #e2e8f0; padding: 0.75rem 1rem;">
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label fw-semibold mb-2" style="color: #1e293b;">
                                <i class="bi bi-envelope me-1" style="color: #f59e0b;"></i>Email Address <span class="text-danger">*</span>
                            </label>
                            <input type="email" class="form-control" id="email" name="email" required 
                                   placeholder="Enter customer email address"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   style="border-radius: 12px; border: 2px solid #e2e8f0; padding: 0.75rem 1rem;">
                        </div>
                    </div>
                    
                    <div class="row mb-3 g-3">
                        <div class="col-md-6">
                            <label for="password" class="form-label fw-semibold mb-2" style="color: #1e293b;">
                                <i class="bi bi-lock me-1" style="color: #f59e0b;"></i>Password <span class="text-danger">*</span>
                            </label>
                            <input type="password" class="form-control" id="password" name="password" required 
                                   placeholder="Enter password" minlength="6"
                                   style="border-radius: 12px; border: 2px solid #e2e8f0; padding: 0.75rem 1rem;">
                            <small class="form-text text-muted mt-1">Password must be at least 6 characters long.</small>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label fw-semibold mb-2" style="color: #1e293b;">
                                <i class="bi bi-lock-fill me-1" style="color: #f59e0b;"></i>Confirm Password <span class="text-danger">*</span>
                            </label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required 
                                   placeholder="Confirm password" minlength="6"
                                   style="border-radius: 12px; border: 2px solid #e2e8f0; padding: 0.75rem 1rem;">
                            <small class="form-text text-muted mt-1">Re-enter the password to confirm.</small>
                        </div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-warning btn-lg rounded-pill shadow-lg px-4" style="font-weight: 600; padding: 0.875rem;">
                            <i class="bi bi-check-lg me-2"></i>Create Customer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card shadow-lg border-0" style="border-radius: 20px; overflow: hidden;">
            <div class="card-header text-white py-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                <h5 class="mb-0 fw-bold">
                    <i class="bi bi-list-ul me-2"></i>All Customers
                    <span class="badge bg-white text-primary ms-2 rounded-pill px-3"><?php echo count($customers); ?></span>
                </h5>
            </div>
            <div class="card-body p-4">
                <?php if (empty($customers)): ?>
                    <div class="text-center py-5">
                        <div style="width: 100px; height: 100px; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; box-shadow: 0 8px 24px rgba(99, 102, 241, 0.2);">
                            <i class="bi bi-people display-4" style="color: #6366f1;"></i>
                        </div>
                        <h5 class="text-muted mb-2">No customers found</h5>
                        <p class="text-muted">Customers will appear here when they register.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="border-radius: 12px; overflow: hidden;">
                            <thead style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%);">
                                <tr>
                                    <th style="border: none; padding: 1rem; font-weight: 700; color: #1e293b; text-transform: uppercase; font-size: 0.875rem; letter-spacing: 0.5px;">ID</th>
                                    <th style="border: none; padding: 1rem; font-weight: 700; color: #1e293b; text-transform: uppercase; font-size: 0.875rem; letter-spacing: 0.5px;">Name</th>
                                    <th style="border: none; padding: 1rem; font-weight: 700; color: #1e293b; text-transform: uppercase; font-size: 0.875rem; letter-spacing: 0.5px;">Email</th>
                                    <th style="border: none; padding: 1rem; font-weight: 700; color: #1e293b; text-transform: uppercase; font-size: 0.875rem; letter-spacing: 0.5px;">Registered Date</th>
                                    <th style="border: none; padding: 1rem; font-weight: 700; color: #1e293b; text-transform: uppercase; font-size: 0.875rem; letter-spacing: 0.5px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <tr class="customer-card" style="border-bottom: 1px solid #e2e8f0;">
                                        <td style="padding: 1rem; color: #64748b; font-weight: 600;"><?php echo $customer['id']; ?></td>
                                        <td style="padding: 1rem;">
                                            <div class="d-flex align-items-center">
                                                <div style="width: 40px; height: 40px; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%); border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-right: 0.75rem;">
                                                    <i class="bi bi-person-fill" style="color: #6366f1;"></i>
                                                </div>
                                                <strong style="color: #1e293b;"><?php echo htmlspecialchars($customer['name']); ?></strong>
                                            </div>
                                        </td>
                                        <td style="padding: 1rem; color: #64748b;"><?php echo htmlspecialchars($customer['email']); ?></td>
                                        <td style="padding: 1rem; color: #64748b;">
                                            <i class="bi bi-calendar3 me-1"></i><?php echo date('M d, Y', strtotime($customer['created_at'])); ?>
                                        </td>
                                        <td style="padding: 1rem;">
                                            <a href="?delete=<?php echo $customer['id']; ?>" class="btn btn-sm btn-danger rounded-pill px-3 shadow-sm" 
                                               title="Delete" 
                                               onclick="return confirm('Are you sure you want to delete this customer? This will also delete all their orders.');"
                                               style="font-weight: 600;">
                                                <i class="bi bi-trash me-1"></i>Delete
                                            </a>
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

<script>
// Validate password match
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const form = document.getElementById('customerForm');
    
    function validatePasswords() {
        if (confirmPasswordInput.value !== passwordInput.value) {
            confirmPasswordInput.setCustomValidity('Passwords do not match');
        } else {
            confirmPasswordInput.setCustomValidity('');
        }
    }
    
    passwordInput.addEventListener('input', validatePasswords);
    confirmPasswordInput.addEventListener('input', validatePasswords);
    
    form.addEventListener('submit', function(e) {
        if (passwordInput.value !== confirmPasswordInput.value) {
            e.preventDefault();
            alert('Passwords do not match. Please try again.');
            return false;
        }
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>


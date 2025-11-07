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
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = 'Email already registered. Please use a different email.';
                $stmt->close();
            } else {
                $stmt->close();
                
                // Hash password and insert user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'user')");
                if ($stmt) {
                    $stmt->bind_param("sss", $name, $email, $hashed_password);
                    
                    if ($stmt->execute()) {
                        $success = 'Customer created successfully!';
                        header('Location: customers.php?success=1&created=1');
                        exit();
                    } else {
                        $error = 'Failed to create customer: ' . $stmt->error;
                    }
                    $stmt->close();
                } else {
                    $error = 'Failed to prepare insert query: ' . $conn->error;
                }
            }
        } else {
            $error = 'Failed to prepare select query: ' . $conn->error;
        }
    }
}

// Handle delete customer
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    // Only allow deleting users (not admins)
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'user'");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $success = 'Customer deleted successfully!';
            header('Location: customers.php?success=1&deleted=1');
            exit();
        } else {
            $error = 'Failed to delete customer: ' . $stmt->error;
        }
        $stmt->close();
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
$customers = [];
$result = $conn->query("SELECT id, name, email, created_at FROM users WHERE role = 'user' ORDER BY created_at DESC");
if ($result && $result->num_rows > 0) {
    $customers = $result->fetch_all(MYSQLI_ASSOC);
}

$conn->close();

$pageTitle = 'Manage Customers';
include __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-people"></i> Manage Customers</h2>
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
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white border-0 pb-0" style="border-bottom: 1px solid #e0e0e0 !important;">
                <div class="d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <div class="bg-warning rounded d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                            <i class="bi bi-person-plus text-white"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold">Create Customer</h5>
                            <p class="text-muted small mb-0">Add a new customer account to the system</p>
                        </div>
                    </div>
                    <i class="bi bi-chevron-down text-muted"></i>
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="customerForm">
                    <input type="hidden" name="create_customer" value="1">
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="name" name="name" required 
                                   placeholder="Enter customer full name"
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="email" class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" required 
                                   placeholder="Enter customer email address"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="password" class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="password" name="password" required 
                                   placeholder="Enter password" minlength="6">
                            <small class="form-text text-muted">Password must be at least 6 characters long.</small>
                        </div>
                        <div class="col-md-6">
                            <label for="confirm_password" class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required 
                                   placeholder="Confirm password" minlength="6">
                            <small class="form-text text-muted">Re-enter the password to confirm.</small>
                        </div>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-warning btn-lg rounded-pill px-4">
                            <i class="bi bi-check-lg"></i> Create Customer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> All Customers</h5>
            </div>
            <div class="card-body">
                <?php if (empty($customers)): ?>
                    <p class="text-muted text-center py-4">No customers found. Customers will appear here when they register.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Registered Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td><?php echo $customer['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($customer['name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($customer['email']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($customer['created_at'])); ?></td>
                                        <td>
                                            <a href="?delete=<?php echo $customer['id']; ?>" class="btn btn-sm btn-danger" 
                                               title="Delete" onclick="return confirm('Are you sure you want to delete this customer? This will also delete all their orders.');">
                                                <i class="bi bi-trash"></i>
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


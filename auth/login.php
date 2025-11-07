<?php
/**
 * Login Page
 * Handles user authentication
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

$error = '';
$success = '';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: ../admin/dashboard.php');
    } else {
        header('Location: ../user/dashboard.php');
    }
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = t('please_fill_all_fields');
    } else {
        $conn = getDBConnection();
        
        // Check if connection is valid
        if ($conn === false) {
            $error = 'Database connection failed. Please try again later.';
        } else {
            // Ensure admin user exists with correct password
            ensureAdminUser($conn);
            
            $stmt = $conn->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
            
            // Check if prepare() succeeded
            if ($stmt === false) {
                $error = 'Database error: ' . $conn->error . '. Tables are being created automatically. Please try again.';
                error_log("Login prepare error: " . $conn->error);
                $conn->close();
            } else {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    if (password_verify($password, $user['password'])) {
                        // Set session variables
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['user_role'] = $user['role'];
                        
                        // Redirect based on role
                        if ($user['role'] === 'admin') {
                            header('Location: ../admin/dashboard.php');
                        } else {
                            header('Location: ../user/dashboard.php');
                        }
                        exit();
                    } else {
                        $error = t('invalid_credentials');
                    }
                } else {
                    $error = t('invalid_credentials');
                }
                $stmt->close();
                $conn->close();
            }
        }
    }
}

$pageTitle = t('login_title');
include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center align-items-center min-vh-75">
    <div class="col-md-5">
        <div class="card shadow-lg border-0">
            <div class="card-header bg-gradient-primary text-white text-center py-4">
                <div class="d-flex align-items-center justify-content-center mb-3">
                    <img src="../images/logo.jpg" alt="Logo" style="height: 50px; width: auto; border-radius: 8px; margin-right: 15px; box-shadow: 0 2px 8px rgba(0,0,0,0.2);">
                    <h4 class="mb-0 fw-bold"><i class="bi bi-box-arrow-in-right me-2"></i><?php e('login'); ?></h4>
                </div>
                <p class="mb-0 opacity-75"><?php e('login_welcome'); ?></p>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-4">
                        <label for="email" class="form-label fw-semibold"><?php e('email'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-envelope text-primary"></i></span>
                            <input type="email" class="form-control" id="email" name="email" required 
                                   placeholder="<?php e('enter_email'); ?>"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold"><?php e('password'); ?></label>
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-lock text-primary"></i></span>
                            <input type="password" class="form-control" id="password" name="password" required
                                   placeholder="<?php e('enter_password'); ?>">
                        </div>
                    </div>
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary btn-lg rounded-pill">
                            <i class="bi bi-box-arrow-in-right me-2"></i><?php e('login'); ?>
                        </button>
                    </div>
                </form>
                
                <div class="text-center mt-4">
                    <p class="mb-2"><?php e('dont_have_account'); ?> <a href="register.php" class="fw-bold text-primary"><?php e('register_here'); ?></a></p>
                    <div class="bg-light rounded p-3 mt-3">
                        <p class="text-muted small mb-1"><strong><?php e('demo_credentials'); ?>:</strong></p>
                        <p class="text-muted small mb-0">
                            <strong><?php e('admin'); ?>:</strong> admin@example.com / admin123<br>
                            <strong><?php e('user'); ?>:</strong> <?php e('register'); ?> <?php echo t('to_create_account', 'to create an account'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

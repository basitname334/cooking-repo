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
            try {
                // Ensure admin user exists with correct password
                ensureAdminUser($conn);
                
                $user = db_fetch(
                    $conn,
                    'SELECT id, name, email, password, role FROM users WHERE email = ?',
                    [$email]
                );
                
                if ($user !== null && password_verify($password, $user['password'])) {
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
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage() . '. Tables are being created automatically. Please try again.';
                error_log('Login error: ' . $e->getMessage());
            }
        }
    }
}

$pageTitle = t('login_title');
include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center align-items-center min-vh-75">
    <div class="col-md-5">
        <div class="card shadow-2xl border-0" style="background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-radius: 24px; overflow: hidden;">
            <div class="card-header text-center py-5" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; position: relative;">
                <div style="position: absolute; inset: 0; opacity: 0.3; background-image: linear-gradient(rgba(255,255,255,0.12) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.12) 1px, transparent 1px); background-size: 40px 40px;"></div>
                <div class="d-flex align-items-center justify-content-center mb-3 position-relative" style="z-index: 1;">
                    <img src="../images/logo.jpg" alt="Logo" style="height: 60px; width: auto; border-radius: 16px; margin-right: 15px; box-shadow: 0 8px 24px rgba(0,0,0,0.3);">
                    <h3 class="mb-0 fw-bold text-white"><i class="bi bi-box-arrow-in-right me-2"></i><?php e('login'); ?></h3>
                </div>
                <p class="mb-0 text-white opacity-90 position-relative" style="z-index: 1;"><?php e('login_welcome'); ?></p>
            </div>
            <div class="card-body p-5">
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
                        <label for="email" class="form-label fw-semibold mb-3" style="color: #1e293b; font-size: 1rem;">
                            <i class="bi bi-envelope me-2" style="color: #6366f1;"></i><?php e('email'); ?>
                        </label>
                        <div class="input-group" style="box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1); border-radius: 12px; overflow: hidden;">
                            <span class="input-group-text" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; color: white; padding: 0.75rem 1.25rem;">
                                <i class="bi bi-envelope"></i>
                            </span>
                            <input type="email" class="form-control" id="email" name="email" required 
                                   placeholder="<?php e('enter_email'); ?>"
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   style="border: none; padding: 0.75rem 1.25rem; font-size: 1rem; background: #f8fafc;">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label fw-semibold mb-3" style="color: #1e293b; font-size: 1rem;">
                            <i class="bi bi-lock me-2" style="color: #6366f1;"></i><?php e('password'); ?>
                        </label>
                        <div class="input-group" style="box-shadow: 0 2px 8px rgba(99, 102, 241, 0.1); border-radius: 12px; overflow: hidden;">
                            <span class="input-group-text" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; color: white; padding: 0.75rem 1.25rem;">
                                <i class="bi bi-lock"></i>
                            </span>
                            <input type="password" class="form-control" id="password" name="password" required
                                   placeholder="<?php e('enter_password'); ?>"
                                   style="border: none; padding: 0.75rem 1.25rem; font-size: 1rem; background: #f8fafc;">
                        </div>
                    </div>
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-primary btn-lg rounded-pill shadow-lg" style="padding: 0.875rem 2rem; font-size: 1.1rem; font-weight: 600;">
                            <i class="bi bi-box-arrow-in-right me-2"></i><?php e('login'); ?>
                        </button>
                    </div>
                </form>
                
                <div class="text-center mt-4">
                    <p class="mb-3" style="color: #64748b; font-size: 1rem;"><?php e('dont_have_account'); ?> 
                        <a href="register.php" class="fw-bold" style="color: #6366f1; text-decoration: none;">
                            <?php e('register_here'); ?>
                        </a>
                    </p>
                    <div style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.05) 0%, rgba(139, 92, 246, 0.05) 100%); border-radius: 16px; padding: 1.5rem; border: 1px solid rgba(99, 102, 241, 0.1);">
                        <p class="mb-2" style="color: #1e293b; font-weight: 600; font-size: 0.95rem;">
                            <i class="bi bi-info-circle me-2" style="color: #6366f1;"></i><?php e('demo_credentials'); ?>:
                        </p>
                        <p class="mb-0" style="color: #64748b; font-size: 0.9rem; line-height: 1.8;">
                            <strong style="color: #1e293b;"><?php e('admin'); ?>:</strong> admin@example.com / admin123<br>
                            <strong style="color: #1e293b;"><?php e('user'); ?>:</strong> <?php e('register'); ?> <?php echo t('to_create_account', 'to create an account'); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

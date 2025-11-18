<?php
/**
 * Registration Page
 * Handles new user registration
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

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
        $conn = getDBConnection();
        
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
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
            $stmt->bind_param("sss", $name, $email, $hashed_password);
            
            if ($stmt->execute()) {
                $success = 'Registration successful! You can now <a href="login.php">login</a>.';
            } else {
                $error = 'Registration failed. Please try again.';
            }
            $stmt->close();
        }
        $conn->close();
    }
}

$pageTitle = 'Register';
include __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center align-items-center min-vh-75">
    <div class="col-md-6">
        <div class="card shadow-2xl border-0" style="background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-radius: 24px; overflow: hidden;">
            <div class="card-header text-center py-5" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border: none; position: relative;">
                <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: url('data:image/svg+xml,<svg width=\"100\" height=\"100\" xmlns=\"http://www.w3.org/2000/svg\"><defs><pattern id=\"grid\" width=\"40\" height=\"40\" patternUnits=\"userSpaceOnUse\"><path d=\"M 40 0 L 0 0 0 40\" fill=\"none\" stroke=\"rgba(255,255,255,0.1)\" stroke-width=\"1\"/></pattern></defs><rect width=\"100\" height=\"100\" fill=\"url(%23grid)\" /></svg>'); opacity: 0.3;"></div>
                <div class="d-flex align-items-center justify-content-center mb-3 position-relative" style="z-index: 1;">
                    <img src="../images/logo.jpg" alt="Logo" style="height: 60px; width: auto; border-radius: 16px; margin-right: 15px; box-shadow: 0 8px 24px rgba(0,0,0,0.3);">
                    <h3 class="mb-0 fw-bold text-white"><i class="bi bi-person-plus me-2"></i>Register</h3>
                </div>
                <p class="mb-0 text-white opacity-90 position-relative" style="z-index: 1;">Create your account to get started</p>
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
                        <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="row mb-3">
                        <div class="col-md-12 mb-4">
                            <label for="name" class="form-label fw-semibold mb-3" style="color: #1e293b; font-size: 1rem;">
                                <i class="bi bi-person me-2" style="color: #10b981;"></i>Full Name
                            </label>
                            <div class="input-group" style="box-shadow: 0 2px 8px rgba(16, 185, 129, 0.1); border-radius: 12px; overflow: hidden;">
                                <span class="input-group-text" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border: none; color: white; padding: 0.75rem 1.25rem;">
                                    <i class="bi bi-person"></i>
                                </span>
                                <input type="text" class="form-control" id="name" name="name" required 
                                       placeholder="Enter your full name"
                                       value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                       style="border: none; padding: 0.75rem 1.25rem; font-size: 1rem; background: #f8fafc;">
                            </div>
                        </div>
                        <div class="col-md-12 mb-4">
                            <label for="email" class="form-label fw-semibold mb-3" style="color: #1e293b; font-size: 1rem;">
                                <i class="bi bi-envelope me-2" style="color: #10b981;"></i>Email Address
                            </label>
                            <div class="input-group" style="box-shadow: 0 2px 8px rgba(16, 185, 129, 0.1); border-radius: 12px; overflow: hidden;">
                                <span class="input-group-text" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border: none; color: white; padding: 0.75rem 1.25rem;">
                                    <i class="bi bi-envelope"></i>
                                </span>
                                <input type="email" class="form-control" id="email" name="email" required 
                                       placeholder="Enter your email"
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                       style="border: none; padding: 0.75rem 1.25rem; font-size: 1rem; background: #f8fafc;">
                            </div>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label for="password" class="form-label fw-semibold mb-3" style="color: #1e293b; font-size: 1rem;">
                                <i class="bi bi-lock me-2" style="color: #10b981;"></i>Password
                            </label>
                            <div class="input-group" style="box-shadow: 0 2px 8px rgba(16, 185, 129, 0.1); border-radius: 12px; overflow: hidden;">
                                <span class="input-group-text" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border: none; color: white; padding: 0.75rem 1.25rem;">
                                    <i class="bi bi-lock"></i>
                                </span>
                                <input type="password" class="form-control" id="password" name="password" required 
                                       placeholder="Enter password" minlength="6"
                                       style="border: none; padding: 0.75rem 1.25rem; font-size: 1rem; background: #f8fafc;">
                            </div>
                            <small class="form-text" style="color: #64748b; font-size: 0.875rem; margin-top: 0.5rem;">Password must be at least 6 characters long.</small>
                        </div>
                        <div class="col-md-6 mb-4">
                            <label for="confirm_password" class="form-label fw-semibold mb-3" style="color: #1e293b; font-size: 1rem;">
                                <i class="bi bi-lock-fill me-2" style="color: #10b981;"></i>Confirm Password
                            </label>
                            <div class="input-group" style="box-shadow: 0 2px 8px rgba(16, 185, 129, 0.1); border-radius: 12px; overflow: hidden;">
                                <span class="input-group-text" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border: none; color: white; padding: 0.75rem 1.25rem;">
                                    <i class="bi bi-lock-fill"></i>
                                </span>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required
                                       placeholder="Confirm password" minlength="6"
                                       style="border: none; padding: 0.75rem 1.25rem; font-size: 1rem; background: #f8fafc;">
                            </div>
                        </div>
                    </div>
                    <div class="d-grid mb-3">
                        <button type="submit" class="btn btn-success btn-lg rounded-pill shadow-lg" style="padding: 0.875rem 2rem; font-size: 1.1rem; font-weight: 600;">
                            <i class="bi bi-person-plus me-2"></i>Create Account
                        </button>
                    </div>
                </form>
                
                <div class="text-center mt-4">
                    <p class="mb-0" style="color: #64748b; font-size: 1rem;">Already have an account? 
                        <a href="login.php" class="fw-bold" style="color: #10b981; text-decoration: none; transition: all 0.3s;">Login here</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

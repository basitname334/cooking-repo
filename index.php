<?php
/**
 * Home Page
 * Landing page for the application
 */
require_once __DIR__ . '/config/auth.php';

$pageTitle = 'Home - Hassan Cook Chinese Food Specialist';
include __DIR__ . '/includes/header.php';
?>

<!-- Modern Hero Section -->
<div class="row mb-5">
    <div class="col-12">
        <div class="hero-section" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 50%, rgba(240, 147, 251, 0.1) 100%); border-radius: 24px; padding: 4rem 2rem; position: relative; overflow: hidden;">
            <!-- Background Elements -->
            <div class="hero-bg-decoration" style="position: absolute; top: -50%; right: -10%; width: 500px; height: 500px; background: radial-gradient(circle, rgba(99, 102, 241, 0.15) 0%, transparent 70%); border-radius: 50%;"></div>
            <div class="hero-bg-decoration" style="position: absolute; bottom: -30%; left: -5%; width: 400px; height: 400px; background: radial-gradient(circle, rgba(139, 92, 246, 0.15) 0%, transparent 70%); border-radius: 50%;"></div>
            
            <div class="row align-items-center position-relative" style="z-index: 1;">
                <div class="col-lg-7 mb-4 mb-lg-0">
                    <div class="d-flex align-items-center mb-4">
                        <img src="images/logo.jpg" alt="Logo" style="height: 70px; width: auto; border-radius: 16px; margin-right: 20px; box-shadow: 0 8px 24px rgba(99, 102, 241, 0.3);">
                        <div>
                            <h1 class="display-3 mb-2 fw-bold" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">Welcome to Hassan Cook</h1>
                            <p class="lead mb-0 fw-semibold" style="color: #64748b;">Chinese Food Specialist</p>
                        </div>
                    </div>
                    <p class="lead mb-4" style="font-size: 1.25rem; color: #475569; line-height: 1.8;">Simple and easy food management for everyone. Add ingredients, create recipes, and organize your kitchen effortlessly with our modern, intuitive platform.</p>
                    <p class="mb-4" style="color: #64748b; font-size: 1.1rem;">Perfect for home cooks and restaurant managers. No complicated setup - just start using it!</p>
                    <div class="d-flex flex-wrap gap-3">
                        <?php if (!isLoggedIn()): ?>
                            <a class="btn btn-primary btn-lg px-5 py-3 rounded-pill fw-bold shadow-lg" href="auth/login.php" role="button" style="font-size: 1.1rem;">
                                <i class="bi bi-arrow-right-circle me-2"></i>Get Started
                            </a>
                            <a class="btn btn-outline-primary btn-lg px-5 py-3 rounded-pill fw-semibold" href="auth/register.php" role="button" style="font-size: 1.1rem; border-width: 2px;">
                                <i class="bi bi-person-plus me-2"></i>Create Account
                            </a>
                        <?php else: ?>
                            <?php if (isAdmin()): ?>
                                <a class="btn btn-primary btn-lg px-5 py-3 rounded-pill shadow-lg fw-bold" href="admin/dashboard.php" role="button" style="font-size: 1.1rem;">
                                    <i class="bi bi-speedometer2 me-2"></i>Go to Dashboard
                                </a>
                            <?php else: ?>
                                <a class="btn btn-primary btn-lg px-5 py-3 rounded-pill shadow-lg fw-bold" href="user/dashboard.php" role="button" style="font-size: 1.1rem;">
                                    <i class="bi bi-speedometer2 me-2"></i>Go to Dashboard
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-5 text-center">
                    <div style="position: relative; display: inline-block;">
                        <div style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.2) 0%, rgba(139, 92, 246, 0.2) 100%); border-radius: 50%; padding: 3rem; display: inline-block; box-shadow: 0 20px 60px rgba(99, 102, 241, 0.3);">
                            <i class="bi bi-egg-fried" style="font-size: 8rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.hero-section {
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.5);
}
</style>

<!-- Feature Cards with Modern Design -->
<div class="row g-4 mb-5">
    <div class="col-md-4 mb-4">
        <div class="card h-100 shadow-lg border-0 hover-lift" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.05) 0%, rgba(255, 255, 255, 0.95) 100%);">
            <div class="card-body text-center p-5">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 20px; padding: 2rem; display: inline-block; margin-bottom: 1.5rem; box-shadow: 0 10px 30px rgba(99, 102, 241, 0.3);">
                    <i class="bi bi-folder2-open" style="font-size: 3.5rem; color: white;"></i>
                </div>
                <h4 class="card-title fw-bold mb-3" style="color: #1e293b;">Categories</h4>
                <p class="card-text" style="color: #64748b; line-height: 1.7; font-size: 1.05rem;">Organize your food items into groups like "Vegetables", "Meat", or "Spices" for easy finding and better management.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card h-100 shadow-lg border-0 hover-lift" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(255, 255, 255, 0.95) 100%);">
            <div class="card-body text-center p-5">
                <div style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border-radius: 20px; padding: 2rem; display: inline-block; margin-bottom: 1.5rem; box-shadow: 0 10px 30px rgba(16, 185, 129, 0.3);">
                    <i class="bi bi-basket" style="font-size: 3.5rem; color: white;"></i>
                </div>
                <h4 class="card-title fw-bold mb-3" style="color: #1e293b;">Ingredients</h4>
                <p class="card-text" style="color: #64748b; line-height: 1.7; font-size: 1.05rem;">Keep track of what you have. Add ingredients like "Chicken", "Rice", or "Soy Sauce" to your inventory list.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card h-100 shadow-lg border-0 hover-lift" style="background: linear-gradient(135deg, rgba(6, 182, 212, 0.05) 0%, rgba(255, 255, 255, 0.95) 100%);">
            <div class="card-body text-center p-5">
                <div style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border-radius: 20px; padding: 2rem; display: inline-block; margin-bottom: 1.5rem; box-shadow: 0 10px 30px rgba(6, 182, 212, 0.3);">
                    <i class="bi bi-egg-fried" style="font-size: 3.5rem; color: white;"></i>
                </div>
                <h4 class="card-title fw-bold mb-3" style="color: #1e293b;">Dishes</h4>
                <p class="card-text" style="color: #64748b; line-height: 1.7; font-size: 1.05rem;">Create recipes by combining ingredients. See exactly what and how much you need for each delicious dish.</p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

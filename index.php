<?php
/**
 * Home Page
 * Landing page for the application
 */
require_once __DIR__ . '/config/auth.php';

$pageTitle = 'Home - Hassan Cook Chinese Food Specialist';
include __DIR__ . '/includes/header.php';
?>

<div class="row mb-5">
    <div class="col-12">
        <div class="card shadow-lg border-0" style="background: #ffffff; overflow: hidden;">
            <div class="card-body p-5">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center mb-3">
                            <img src="images/logo.jpg" alt="Logo" style="height: 60px; width: auto; border-radius: 8px; margin-right: 20px; box-shadow: 0 4px 8px rgba(0,0,0,0.2);">
                            <div>
                                <h1 class="display-4 mb-0 fw-bold">Welcome to Hassan Cook</h1>
                                <p class="lead mb-0 opacity-90">Chinese Food Specialist</p>
                            </div>
                        </div>
                        <p class="lead mb-4">Simple and easy food management for everyone. Add ingredients, create recipes, and organize your kitchen effortlessly.</p>
                        <p class="mb-4 opacity-90">Perfect for home cooks and restaurant managers. No complicated setup - just start using it!</p>
                        <?php if (!isLoggedIn()): ?>
                            <a class="btn btn-light btn-lg px-4 rounded-pill shadow-sm fw-bold" href="auth/login.php" role="button">
                                <i class="bi bi-arrow-right-circle me-2"></i>Get Started
                            </a>
                        <?php else: ?>
                            <?php if (isAdmin()): ?>
                                <a class="btn btn-light btn-lg px-4 rounded-pill shadow-sm fw-bold" href="admin/dashboard.php" role="button">
                                    <i class="bi bi-speedometer2 me-2"></i>Go to Dashboard
                                </a>
                            <?php else: ?>
                                <a class="btn btn-light btn-lg px-4 rounded-pill shadow-sm fw-bold" href="user/dashboard.php" role="button">
                                    <i class="bi bi-speedometer2 me-2"></i>Go to Dashboard
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="bg-white bg-opacity-10 rounded-circle p-5 d-inline-block">
                            <i class="bi bi-egg-fried" style="font-size: 5rem; opacity: 0.8;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-5">
    <div class="col-md-4 mb-4">
        <div class="card h-100 shadow-sm border-0 hover-lift">
            <div class="card-body text-center p-4">
                <div class="bg-primary bg-opacity-10 rounded-circle p-4 d-inline-block mb-3">
                    <i class="bi bi-folder2-open display-5 text-primary"></i>
                </div>
                <h5 class="card-title fw-bold mb-3">Categories</h5>
                <p class="card-text text-muted">Organize your food items into groups like "Vegetables", "Meat", or "Spices" for easy finding.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card h-100 shadow-sm border-0 hover-lift">
            <div class="card-body text-center p-4">
                <div class="bg-success bg-opacity-10 rounded-circle p-4 d-inline-block mb-3">
                    <i class="bi bi-basket display-5 text-success"></i>
                </div>
                <h5 class="card-title fw-bold mb-3">Ingredients</h5>
                <p class="card-text text-muted">Keep track of what you have. Add ingredients like "Chicken", "Rice", or "Soy Sauce" to your list.</p>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-4">
        <div class="card h-100 shadow-sm border-0 hover-lift">
            <div class="card-body text-center p-4">
                <div class="bg-warning bg-opacity-10 rounded-circle p-4 d-inline-block mb-3">
                    <i class="bi bi-egg-fried display-5 text-warning"></i>
                </div>
                <h5 class="card-title fw-bold mb-3">Dishes</h5>
                <p class="card-text text-muted">Create recipes by combining ingredients. See exactly what and how much you need for each dish.</p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

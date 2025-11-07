<?php
/**
 * User Dashboard
 * Overview for regular users
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$conn = getDBConnection();

// Get statistics
$categories_count = $conn->query("SELECT COUNT(*) as count FROM categories")->fetch_assoc()['count'];
$ingredients_count = $conn->query("SELECT COUNT(*) as count FROM ingredients")->fetch_assoc()['count'];
$dishes_count = $conn->query("SELECT COUNT(*) as count FROM dishes")->fetch_assoc()['count'];

$conn->close();

$pageTitle = 'User Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="bg-white border p-4 rounded">
            <h2 class="mb-2"><i class="bi bi-person-circle"></i> Welcome, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>!</h2>
            <p class="mb-0 text-muted">Explore our menu and discover delicious Chinese dishes.</p>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body text-center">
                <i class="bi bi-folder2-open display-4 mb-3"></i>
                <h6 class="text-muted mb-1">Categories</h6>
                <h2 class="mb-0"><?php echo $categories_count; ?></h2>
                <a href="categories.php" class="btn btn-sm btn-primary mt-3">View Categories</a>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body text-center">
                <i class="bi bi-basket display-4 mb-3"></i>
                <h6 class="text-muted mb-1">Ingredients</h6>
                <h2 class="mb-0"><?php echo $ingredients_count; ?></h2>
                <a href="categories.php" class="btn btn-sm btn-success mt-3">View Ingredients</a>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-3">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-body text-center">
                <i class="bi bi-egg-fried display-4 mb-3"></i>
                <h6 class="text-muted mb-1">Dishes</h6>
                <h2 class="mb-0"><?php echo $dishes_count; ?></h2>
                <a href="dishes.php" class="btn btn-sm btn-info mt-3">View Dishes</a>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <h5 class="card-title"><i class="bi bi-info-circle"></i> Quick Start</h5>
                <p class="card-text">
                    Browse through our categories to see all available ingredients, or explore our delicious dishes 
                    to see what we're cooking up! Each dish includes detailed information about its ingredients and quantities.
                </p>
                <div class="mt-3">
                    <a href="categories.php" class="btn btn-primary me-2">
                        <i class="bi bi-folder2-open"></i> Browse Categories
                    </a>
                    <a href="dishes.php" class="btn btn-info">
                        <i class="bi bi-egg-fried"></i> View All Dishes
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

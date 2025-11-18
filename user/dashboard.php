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

<style>
.user-dashboard-header {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 50%, rgba(240, 147, 251, 0.1) 100%);
    border-radius: 24px;
    padding: 2.5rem;
    margin-bottom: 2rem;
    border: 1px solid rgba(99, 102, 241, 0.2);
    position: relative;
    overflow: hidden;
}

.user-dashboard-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(99, 102, 241, 0.1) 0%, transparent 70%);
    border-radius: 50%;
}

.user-stat-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 20px;
    padding: 2.5rem 2rem;
    text-align: center;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    border: 1px solid rgba(226, 232, 240, 0.8);
    position: relative;
    overflow: hidden;
    height: 100%;
}

.user-stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: var(--card-gradient);
    opacity: 0;
}

.user-stat-card:hover::before {
    opacity: 1;
}

.user-stat-card:hover {
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(99, 102, 241, 0.1);
}

.user-stat-card.primary { --card-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
.user-stat-card.success { --card-gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
.user-stat-card.info { --card-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }

.user-stat-card .icon-wrapper {
    width: 80px;
    height: 80px;
    margin: 0 auto 1.5rem;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--card-gradient);
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}

.user-stat-card:hover .icon-wrapper {
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
}

.user-stat-card .icon-wrapper i {
    font-size: 2.5rem;
    color: white;
}

.user-stat-card .number {
    font-size: 3rem;
    font-weight: 800;
    margin: 1rem 0;
    line-height: 1;
    background: var(--card-gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.user-stat-card .label {
    color: #64748b;
    font-size: 1rem;
    font-weight: 600;
    margin-top: 0.5rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

</style>

<!-- Modern User Dashboard Header -->
<div class="user-dashboard-header">
    <div class="d-flex align-items-center justify-content-between flex-wrap">
        <div>
            <h1 class="display-5 fw-bold mb-2" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <i class="bi bi-person-circle me-3"></i>Welcome, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?>!
            </h1>
            <p class="lead mb-0" style="color: #64748b;">
                Explore our menu and discover delicious Chinese dishes. Browse categories, ingredients, and recipes.
            </p>
        </div>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row g-4 mb-5">
    <div class="col-md-4">
        <div class="user-stat-card primary">
            <div class="icon-wrapper">
                <i class="bi bi-folder2-open"></i>
            </div>
            <div class="number"><?php echo $categories_count; ?></div>
            <div class="label">Categories</div>
            <a href="categories.php" class="btn btn-primary btn-sm rounded-pill mt-4 shadow-sm">
                <i class="bi bi-arrow-right me-2"></i>View Categories
            </a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="user-stat-card success">
            <div class="icon-wrapper">
                <i class="bi bi-basket"></i>
            </div>
            <div class="number"><?php echo $ingredients_count; ?></div>
            <div class="label">Ingredients</div>
            <a href="categories.php" class="btn btn-success btn-sm rounded-pill mt-4 shadow-sm">
                <i class="bi bi-arrow-right me-2"></i>View Ingredients
            </a>
        </div>
    </div>
    <div class="col-md-4">
        <div class="user-stat-card info">
            <div class="icon-wrapper">
                <i class="bi bi-egg-fried"></i>
            </div>
            <div class="number"><?php echo $dishes_count; ?></div>
            <div class="label">Dishes</div>
            <a href="dishes.php" class="btn btn-info btn-sm rounded-pill mt-4 shadow-sm">
                <i class="bi bi-arrow-right me-2"></i>View Dishes
            </a>
        </div>
    </div>
</div>

<!-- Quick Start Card -->
<div class="row">
    <div class="col-12">
        <div class="card shadow-lg border-0" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.05) 0%, rgba(139, 92, 246, 0.05) 100%); border-radius: 20px; border: 1px solid rgba(99, 102, 241, 0.1);">
            <div class="card-body p-4">
                <h5 class="card-title fw-bold mb-3" style="color: #1e293b;">
                    <i class="bi bi-lightning-charge-fill me-2" style="color: #6366f1;"></i>Quick Start Guide
                </h5>
                <p class="card-text mb-4" style="color: #64748b; line-height: 1.8; font-size: 1.05rem;">
                    Browse through our categories to see all available ingredients, or explore our delicious dishes 
                    to see what we're cooking up! Each dish includes detailed information about its ingredients and quantities.
                </p>
                <div class="d-flex flex-wrap gap-3">
                    <a href="categories.php" class="btn btn-primary btn-lg rounded-pill shadow-lg px-4">
                        <i class="bi bi-folder2-open me-2"></i>Browse Categories
                    </a>
                    <a href="dishes.php" class="btn btn-info btn-lg rounded-pill shadow-lg px-4">
                        <i class="bi bi-egg-fried me-2"></i>View All Dishes
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

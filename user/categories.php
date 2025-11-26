<?php
/**
 * User Categories Page
 * View all categories with their ingredients and dishes
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$conn = getDBConnection();

// Get all categories with counts - optimized using LEFT JOIN instead of subqueries
$categories = $conn->query("SELECT c.*, 
    COALESCE(COUNT(DISTINCT i.id), 0) as ingredients_count,
    COALESCE(COUNT(DISTINCT d.id), 0) as dishes_count
    FROM categories c 
    LEFT JOIN ingredients i ON c.id = i.category_id
    LEFT JOIN dishes d ON c.id = d.category_id
    GROUP BY c.id, c.name, c.description, c.created_at
    ORDER BY c.name")->fetch_all(MYSQLI_ASSOC);

// Get category details if selected
$selected_category = null;
$category_ingredients = [];
$category_dishes = [];

if (isset($_GET['id'])) {
    $category_id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $selected_category = $result->fetch_assoc();
    $stmt->close();
    
    if ($selected_category) {
        // Get ingredients for this category
        $stmt = $conn->prepare("SELECT * FROM ingredients WHERE category_id = ? ORDER BY name");
        $stmt->bind_param("i", $category_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $category_ingredients = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Get dishes for this category
        $stmt = $conn->prepare("SELECT * FROM dishes WHERE category_id = ? ORDER BY name");
        $stmt->bind_param("i", $category_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $category_dishes = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

$conn->close();

$pageTitle = 'Categories';
include __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-folder2-open"></i> Categories</h2>
    </div>
</div>

<div class="row">
    <!-- Categories List -->
    <div class="col-md-4 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-list-ul"></i> All Categories</h5>
            </div>
            <div class="card-body">
                <?php if (empty($categories)): ?>
                    <p class="text-muted">No categories available.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($categories as $category): ?>
                            <a href="?id=<?php echo $category['id']; ?>" 
                               class="list-group-item list-group-item-action <?php echo ($selected_category && $selected_category['id'] == $category['id']) ? 'active' : ''; ?>">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?php echo htmlspecialchars($category['name']); ?></h6>
                                    <small>
                                        <span class="badge bg-success"><?php echo $category['ingredients_count']; ?> ingredients</span>
                                        <span class="badge bg-info"><?php echo $category['dishes_count']; ?> dishes</span>
                                    </small>
                                </div>
                                <?php if ($category['description']): ?>
                                    <p class="mb-1 small text-muted"><?php echo htmlspecialchars(substr($category['description'], 0, 100)); ?><?php echo strlen($category['description']) > 100 ? '...' : ''; ?></p>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Category Details -->
    <div class="col-md-8">
        <?php if ($selected_category): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-folder2-open"></i> <?php echo htmlspecialchars($selected_category['name']); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($selected_category['description']): ?>
                        <p><?php echo nl2br(htmlspecialchars($selected_category['description'])); ?></p>
                    <?php endif; ?>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <span class="badge bg-success">
                                <i class="bi bi-basket"></i> <?php echo count($category_ingredients); ?> Ingredients
                            </span>
                        </div>
                        <div class="col-md-6">
                            <span class="badge bg-info">
                                <i class="bi bi-egg-fried"></i> <?php echo count($category_dishes); ?> Dishes
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Ingredients in this Category -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-basket"></i> Ingredients</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($category_ingredients)): ?>
                        <p class="text-muted">No ingredients in this category.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Unit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($category_ingredients as $ingredient): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($ingredient['name']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($ingredient['unit']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Dishes in this Category -->
            <div class="card shadow-sm">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-egg-fried"></i> Dishes</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($category_dishes)): ?>
                        <p class="text-muted">No dishes in this category.</p>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($category_dishes as $dish): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title"><?php echo htmlspecialchars($dish['name']); ?></h6>
                                            <?php if ($dish['description']): ?>
                                                <p class="card-text small"><?php echo htmlspecialchars(substr($dish['description'], 0, 100)); ?><?php echo strlen($dish['description']) > 100 ? '...' : ''; ?></p>
                                            <?php endif; ?>
                                            <a href="dishes.php?id=<?php echo $dish['id']; ?>" class="btn btn-sm btn-info">
                                                View Details <i class="bi bi-arrow-right"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="bi bi-folder2-open display-4 text-muted mb-3"></i>
                    <h5 class="text-muted">Select a category to view details</h5>
                    <p class="text-muted">Choose a category from the list to see its ingredients and dishes.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

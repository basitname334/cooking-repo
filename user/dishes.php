<?php
/**
 * User Dishes Page
 * View all dishes with their ingredients and quantities
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireLogin();

$conn = getDBConnection();

// Get dish details if selected
$selected_dish = null;
$dish_ingredients = [];

if (isset($_GET['id'])) {
    $dish_id = intval($_GET['id']);
    $selected_dish = db_fetch(
        $conn,
        'SELECT d.*, c.name as category_name FROM dishes d
         LEFT JOIN categories c ON d.category_id = c.id
         WHERE d.id = ?',
        [$dish_id]
    );

    if ($selected_dish) {
        // Get dish ingredients with quantities
        $dish_ingredients = db_fetch_all(
            $conn,
            'SELECT di.*, i.name as ingredient_name, i.unit
             FROM dish_ingredients di
             JOIN ingredients i ON di.ingredient_id = i.id
             WHERE di.dish_id = ?
             ORDER BY i.name',
            [$dish_id]
        );
    }
}

// Get all dishes grouped by category
$categories = db_fetch_all(
    $conn,
    'SELECT DISTINCT c.id, c.name
     FROM categories c
     JOIN dishes d ON c.id = d.category_id
     ORDER BY c.name'
);

$dishes_by_category = [];
foreach ($categories as $category) {
    $dishes_by_category[$category['id']] = [
        'category' => $category,
        'dishes' => db_fetch_all(
            $conn,
            'SELECT d.*,
                (SELECT COUNT(*) FROM dish_ingredients WHERE dish_id = d.id) as ingredients_count
             FROM dishes d
             WHERE d.category_id = ?
             ORDER BY d.name',
            [$category['id']]
        )
    ];
}

$pageTitle = 'Dishes';
include __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <h2><i class="bi bi-egg-fried"></i> Dishes</h2>
    </div>
</div>

<?php if ($selected_dish): ?>
    <!-- Dish Details View -->
    <div class="row mb-4">
        <div class="col-12">
            <a href="dishes.php" class="btn btn-secondary mb-3">
                <i class="bi bi-arrow-left"></i> Back to All Dishes
            </a>
        </div>
    </div>
    
    <div class="row">
        <div class="col-md-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-info text-white">
                    <h4 class="mb-0">
                        <i class="bi bi-egg-fried"></i> <?php echo htmlspecialchars($selected_dish['name']); ?>
                    </h4>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <span class="badge bg-primary">
                            <i class="bi bi-folder2-open"></i> <?php echo htmlspecialchars($selected_dish['category_name']); ?>
                        </span>
                    </div>
                    
                    <?php if ($selected_dish['description']): ?>
                        <p class="lead"><?php echo nl2br(htmlspecialchars($selected_dish['description'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-basket"></i> Ingredients & Quantities</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($dish_ingredients)): ?>
                        <p class="text-muted">No ingredients listed for this dish.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Ingredient</th>
                                        <th>Quantity</th>
                                        <th>Unit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dish_ingredients as $di): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($di['ingredient_name']); ?></strong></td>
                                            <td><?php echo number_format($di['quantity'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($di['unit']); ?></td>
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
<?php else: ?>
    <!-- All Dishes View -->
    <div class="row">
        <?php if (empty($dishes_by_category)): ?>
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body text-center py-5">
                        <i class="bi bi-egg-fried display-4 text-muted mb-3"></i>
                        <h5 class="text-muted">No dishes available</h5>
                        <p class="text-muted">Check back later for delicious dishes!</p>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($dishes_by_category as $category_data): ?>
                <div class="col-12 mb-4">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-folder2-open"></i> <?php echo htmlspecialchars($category_data['category']['name']); ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($category_data['dishes'] as $dish): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="card h-100">
                                            <div class="card-body">
                                                <h6 class="card-title">
                                                    <i class="bi bi-egg-fried"></i> <?php echo htmlspecialchars($dish['name']); ?>
                                                </h6>
                                                <?php if ($dish['description']): ?>
                                                    <p class="card-text small"><?php echo htmlspecialchars(substr($dish['description'], 0, 100)); ?><?php echo strlen($dish['description']) > 100 ? '...' : ''; ?></p>
                                                <?php endif; ?>
                                                <div class="mb-2">
                                                    <span class="badge bg-info">
                                                        <i class="bi bi-basket"></i> <?php echo $dish['ingredients_count']; ?> ingredients
                                                    </span>
                                                </div>
                                                <a href="?id=<?php echo $dish['id']; ?>" class="btn btn-sm btn-primary">
                                                    View Details <i class="bi bi-arrow-right"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

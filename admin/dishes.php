<?php
/**
 * Dishes Management Page
 * CRUD operations for dishes with ingredient selection and quantities
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

requireAdmin();

$conn = getDBConnection();
$error = '';
$success = '';

// Handle form submission - Create or Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $dish_id = $_POST['dish_id'] ?? null;
    $ingredients = $_POST['ingredients'] ?? [];
    $quantities = $_POST['quantities'] ?? [];
    $number_of_persons = intval($_POST['number_of_persons'] ?? 1);
    $base_quantity = floatval($_POST['base_quantity'] ?? 1);
    $base_unit = trim($_POST['base_unit'] ?? 'serving');
    
    // Translate to Urdu if current language is Urdu
    $name = translateForDatabase($name);
    $description = translateForDatabase($description);
    
    if (empty($name) || $category_id <= 0) {
        $error = 'Dish name and category are required.';
    } else {
        // Check if number_of_persons column exists, if not add it
        $check_column = $conn->query("SHOW COLUMNS FROM dishes LIKE 'number_of_persons'");
        if ($check_column->num_rows == 0) {
            $conn->query("ALTER TABLE dishes ADD COLUMN number_of_persons INT DEFAULT 1 AFTER category_id");
        }
        
        // Check if base_quantity and base_unit columns exist, if not add them
        $check_base_quantity = $conn->query("SHOW COLUMNS FROM dishes LIKE 'base_quantity'");
        if ($check_base_quantity->num_rows == 0) {
            $conn->query("ALTER TABLE dishes ADD COLUMN base_quantity DECIMAL(10,2) DEFAULT 1 AFTER number_of_persons");
        }
        $check_base_unit = $conn->query("SHOW COLUMNS FROM dishes LIKE 'base_unit'");
        if ($check_base_unit->num_rows == 0) {
            $conn->query("ALTER TABLE dishes ADD COLUMN base_unit VARCHAR(50) DEFAULT 'serving' AFTER base_quantity");
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            if ($dish_id) {
                // Update existing dish
                $stmt = $conn->prepare("UPDATE dishes SET name = ?, description = ?, category_id = ?, number_of_persons = ?, base_quantity = ?, base_unit = ? WHERE id = ?");
                $stmt->bind_param("ssiidsi", $name, $description, $category_id, $number_of_persons, $base_quantity, $base_unit, $dish_id);
                $stmt->execute();
                $stmt->close();
                
                // Delete existing dish ingredients
                $stmt = $conn->prepare("DELETE FROM dish_ingredients WHERE dish_id = ?");
                $stmt->bind_param("i", $dish_id);
                $stmt->execute();
                $stmt->close();
                
                $current_dish_id = $dish_id;
            } else {
                // Create new dish
                $stmt = $conn->prepare("INSERT INTO dishes (name, description, category_id, number_of_persons, base_quantity, base_unit) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssiids", $name, $description, $category_id, $number_of_persons, $base_quantity, $base_unit);
                $stmt->execute();
                $current_dish_id = $stmt->insert_id;
                $stmt->close();
            }
            
            // Insert dish ingredients
            if (!empty($ingredients) && is_array($ingredients)) {
                $units = $_POST['units'] ?? [];
                $ingredient_categories = $_POST['ingredient_categories'] ?? [];
                $stmt = $conn->prepare("INSERT INTO dish_ingredients (dish_id, ingredient_id, quantity, unit) VALUES (?, ?, ?, ?)");
                foreach ($ingredients as $index => $ingredient_id) {
                    if (!empty($ingredient_id) && isset($quantities[$index]) && $quantities[$index] > 0) {
                        $quantity = floatval($quantities[$index]);
                        $unit = isset($units[$index]) ? trim($units[$index]) : '';
                        $stmt->bind_param("iids", $current_dish_id, $ingredient_id, $quantity, $unit);
                        $stmt->execute();
                    }
                }
                $stmt->close();
            }
            
            // Commit transaction
            $conn->commit();
            $success = $dish_id ? 'Dish updated successfully!' : 'Dish created successfully!';
            
            // Redirect after update to show success message and reload edit form
            if ($dish_id) {
                header('Location: dishes.php?edit=' . $dish_id . '&success=1');
                exit();
            }
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = 'Failed to save dish: ' . $e->getMessage();
        }
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM dishes WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $success = 'Dish deleted successfully!';
        // Redirect to refresh the list
        header('Location: dishes.php?success=1&deleted=1');
        exit();
    } else {
        $error = 'Failed to delete dish.';
    }
    $stmt->close();
}

// Handle success message from redirect
if (isset($_GET['success'])) {
    if (isset($_GET['deleted'])) {
        $success = 'Dish deleted successfully!';
    } elseif (isset($_GET['edited'])) {
        $success = 'Dish updated successfully!';
    } else {
        $success = 'Dish created successfully!';
    }
}

// Get dish for editing
$edit_dish = null;
$edit_dish_ingredients = [];
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM dishes WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_dish = $result->fetch_assoc();
    $stmt->close();
    
    // Get dish ingredients with category information
    if ($edit_dish) {
        $stmt = $conn->prepare("SELECT di.*, i.category_id, i.name as ingredient_name 
            FROM dish_ingredients di 
            LEFT JOIN ingredients i ON di.ingredient_id = i.id 
            WHERE di.dish_id = ?
            ORDER BY di.id ASC");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $edit_dish_ingredients = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Debug: Log ingredient count
        error_log("Edit dish ID: $id, Ingredients found: " . count($edit_dish_ingredients));
    }
}

// Get all categories for dropdown with error handling
$categories = [];
$result = $conn->query("SELECT * FROM categories ORDER BY name");
if ($result && $result->num_rows > 0) {
    $categories = $result->fetch_all(MYSQLI_ASSOC);
}

// Get all dishes with category names and ingredient counts with error handling
$dishes = [];
$result = $conn->query("SELECT d.*, c.name as category_name,
    (SELECT COUNT(*) FROM dish_ingredients WHERE dish_id = d.id) as ingredients_count
    FROM dishes d 
    LEFT JOIN categories c ON d.category_id = c.id 
    ORDER BY c.name, d.name");
if ($result && $result->num_rows > 0) {
    $dishes = $result->fetch_all(MYSQLI_ASSOC);
}

$conn->close();

$pageTitle = 'Manage Dishes';
include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="../assets/css/user-friendly.css">

<!-- Page Header -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h2 class="mb-2 fw-bold">
                    <i class="bi bi-egg-fried me-2 text-primary"></i>
                    Manage Dishes
                </h2>
                <p class="text-muted mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    <?php echo count($dishes); ?> <?php echo count($dishes) == 1 ? 'dish' : 'dishes'; ?> in your menu
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Alert Messages -->
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi bi-exclamation-circle-fill me-2"></i>
        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i>
        <strong>Success:</strong> <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Add/Edit Dish Form Section -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-lg border-0">
            <div class="card-header bg-gradient-primary text-white py-3">
                <h5 class="mb-0 fw-bold">
                    <i class="bi bi-<?php echo $edit_dish ? 'pencil-square' : 'plus-circle-fill'; ?> me-2"></i>
                    <?php echo $edit_dish ? 'Edit Dish' : 'Add New Dish'; ?>
                </h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="" id="dishForm">
                    <?php if ($edit_dish): ?>
                        <input type="hidden" name="dish_id" value="<?php echo $edit_dish['id']; ?>">
                    <?php endif; ?>
                    
                    <div class="row g-3">
                        <!-- Left Column -->
                        <div class="col-md-6">
                            <!-- Dish Name -->
                            <div class="mb-3">
                                <label for="name" class="form-label fw-semibold">
                                    <i class="bi bi-tag me-1 text-primary"></i>
                                    Dish Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="name" name="name" required 
                                       placeholder="e.g., Fried Rice, Chicken Curry"
                                       value="<?php echo htmlspecialchars($edit_dish['name'] ?? ''); ?>">
                            </div>
                            
                            <!-- Category -->
                            <div class="mb-3">
                                <label for="category_id" class="form-label fw-semibold">
                                    <i class="bi bi-folder me-1 text-primary"></i>
                                    Category <span class="text-danger">*</span>
                                </label>
                                <select class="form-select" id="category_id" name="category_id" required>
                                    <option value="">-- Select Category --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>" 
                                            <?php echo ($edit_dish && $edit_dish['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Description -->
                            <div class="mb-3">
                                <label for="description" class="form-label fw-semibold">
                                    <i class="bi bi-card-text me-1 text-primary"></i>
                                    Description
                                </label>
                                <textarea class="form-control" id="description" name="description" rows="3"
                                          placeholder="Describe this dish, its taste, and cooking style..."><?php echo htmlspecialchars($edit_dish['description'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        
                        <!-- Right Column -->
                        <div class="col-md-6">
                            <!-- Serves and Quantity -->
                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <label for="number_of_persons" class="form-label fw-semibold">
                                        <i class="bi bi-people me-1 text-primary"></i>
                                        Serves
                                    </label>
                                    <input type="number" class="form-control" id="number_of_persons" name="number_of_persons" 
                                           placeholder="4" step="1" min="1"
                                           value="<?php echo htmlspecialchars($edit_dish['number_of_persons'] ?? '1'); ?>">
                                </div>
                                <div class="col-6">
                                    <label for="base_quantity" class="form-label fw-semibold">
                                        <i class="bi bi-123 me-1 text-primary"></i>
                                        Quantity
                                    </label>
                                    <input type="number" class="form-control" id="base_quantity" name="base_quantity" 
                                           placeholder="1" step="0.01" min="0"
                                           value="<?php echo htmlspecialchars($edit_dish['base_quantity'] ?? '1'); ?>">
                                </div>
                            </div>
                            
                            <!-- Unit -->
                            <div class="mb-3">
                                <label for="base_unit" class="form-label fw-semibold">
                                    <i class="bi bi-rulers me-1 text-primary"></i>
                                    Unit
                                </label>
                                <select class="form-select" id="base_unit" name="base_unit">
                                    <option value="serving" <?php echo ($edit_dish && isset($edit_dish['base_unit']) && $edit_dish['base_unit'] == 'serving') ? 'selected' : ''; ?>>Serving</option>
                                    <option value="portion" <?php echo ($edit_dish && isset($edit_dish['base_unit']) && $edit_dish['base_unit'] == 'portion') ? 'selected' : ''; ?>>Portion</option>
                                    <option value="piece" <?php echo ($edit_dish && isset($edit_dish['base_unit']) && $edit_dish['base_unit'] == 'piece') ? 'selected' : ''; ?>>Piece</option>
                                </select>
                            </div>
                            
                            <!-- Ingredients Section -->
                            <div class="mb-3">
                                <div class="d-flex justify-content-between align-items-center mb-3 p-2 bg-light rounded">
                                    <label class="form-label fw-bold mb-0 fs-6">
                                        <i class="bi bi-basket-fill me-2 text-primary"></i>
                                        Ingredients
                                    </label>
                                    <button type="button" class="btn btn-success btn-sm px-3" onclick="addIngredientRow()">
                                        <i class="bi bi-plus-lg me-1"></i> Add Ingredient
                                    </button>
                                </div>
                                <div id="ingredientsContainer" class="border border-2 border-dashed rounded-3 p-3 bg-light" style="min-height: 200px; max-height: 400px; overflow-y: auto; background: #f8f9fa !important;">
                                    <?php if ($edit_dish && !empty($edit_dish_ingredients)): ?>
                                        <p class="text-muted text-center mb-0 py-4">
                                            <i class="bi bi-hourglass-split me-2"></i>
                                            Loading ingredients...
                                        </p>
                                    <?php else: ?>
                                        <div class="text-center py-4">
                                            <i class="bi bi-inbox display-6 text-muted d-block mb-2"></i>
                                            <p class="text-muted mb-0 small fw-semibold">No ingredients added yet</p>
                                            <p class="text-muted small mb-0">Click "Add Ingredient" to start building your recipe</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Actions -->
                    <div class="d-flex gap-2 mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-2"></i>
                            <?php echo $edit_dish ? 'Update Dish' : 'Save Dish'; ?>
                        </button>
                        <?php if ($edit_dish): ?>
                            <a href="dishes.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-lg me-2"></i>Cancel
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Dishes List Section -->
<div class="row">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-list-ul me-2 text-primary"></i>
                        All Dishes <span class="badge bg-primary ms-2"><?php echo count($dishes); ?></span>
                    </h5>
                    <!-- Search Box -->
                    <div class="flex-grow-1" style="max-width: 400px;">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0" id="searchDishes" 
                                   placeholder="Search dishes by name or category..." 
                                   autocomplete="off">
                            <button class="btn btn-outline-secondary border-start-0" type="button" id="clearSearch" style="display: none;">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">
                <?php if (empty($dishes)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox display-1 text-muted d-block mb-3"></i>
                        <h5 class="text-muted mb-2">No dishes found</h5>
                        <p class="text-muted">Create your first dish using the form above!</p>
                    </div>
                <?php else: ?>
                    <div class="row g-3" id="dishesList">
                        <?php foreach ($dishes as $dish): ?>
                            <?php 
                            $ingredients_count = intval($dish['ingredients_count'] ?? 0);
                            $category_name = htmlspecialchars($dish['category_name'] ?? 'Uncategorized');
                            ?>
                            <div class="col-md-6 col-lg-4 col-xl-3 dish-item" 
                                 data-name="<?php echo strtolower(htmlspecialchars($dish['name'])); ?>"
                                 data-category="<?php echo strtolower($category_name); ?>">
                                <div class="card h-100 border shadow-sm dish-card" 
                                     style="cursor: pointer; transition: all 0.3s ease;" 
                                     onclick="window.location.href='?edit=<?php echo $dish['id']; ?>'"
                                     onmouseover="this.style.transform='translateY(-5px)'; this.style.boxShadow='0 8px 16px rgba(0,0,0,0.15)'"
                                     onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'">
                                    <div class="card-body p-3">
                                        <div class="d-flex align-items-start justify-content-between mb-2">
                                            <div class="flex-grow-1">
                                                <div class="d-flex align-items-center mb-2">
                                                    <div class="bg-primary bg-opacity-10 rounded p-2 me-2">
                                                        <i class="bi bi-egg-fried text-primary"></i>
                                                    </div>
                                                    <h6 class="card-title mb-0 fw-bold text-dark" style="font-size: 0.95rem;">
                                                        <?php echo htmlspecialchars($dish['name']); ?>
                                                    </h6>
                                                </div>
                                                <div class="mb-2">
                                                    <span class="badge bg-info bg-opacity-10 text-info small">
                                                        <i class="bi bi-folder me-1"></i><?php echo $category_name; ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="d-flex gap-1">
                                                <button class="btn btn-sm btn-primary rounded-circle p-2" 
                                                        onclick="event.stopPropagation(); window.location.href='?edit=<?php echo $dish['id']; ?>'"
                                                        title="Edit Dish">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button class="btn btn-sm btn-danger rounded-circle p-2" 
                                                        onclick="event.stopPropagation(); if(confirm('Are you sure you want to delete <?php echo htmlspecialchars(addslashes($dish['name'])); ?>?')) window.location.href='?delete=<?php echo $dish['id']; ?>'"
                                                        title="Delete Dish">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="d-flex align-items-center text-muted small">
                                            <i class="bi bi-basket me-1"></i>
                                            <span><?php echo $ingredients_count; ?> <?php echo $ingredients_count == 1 ? 'ingredient' : 'ingredients'; ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="noResults" class="text-center py-5" style="display: none;">
                        <i class="bi bi-search display-1 text-muted d-block mb-3"></i>
                        <h5 class="text-muted mb-2">No dishes found</h5>
                        <p class="text-muted">Try adjusting your search terms</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Search functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchDishes');
    const clearSearchBtn = document.getElementById('clearSearch');
    const dishesList = document.getElementById('dishesList');
    const noResults = document.getElementById('noResults');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const dishItems = document.querySelectorAll('.dish-item');
            let visibleCount = 0;
            
            if (searchTerm === '') {
                // Show all dishes
                dishItems.forEach(item => {
                    item.style.display = '';
                    visibleCount++;
                });
                clearSearchBtn.style.display = 'none';
                if (noResults) noResults.style.display = 'none';
                if (dishesList) dishesList.style.display = '';
            } else {
                // Filter dishes
                dishItems.forEach(item => {
                    const dishName = item.getAttribute('data-name') || '';
                    const dishCategory = item.getAttribute('data-category') || '';
                    
                    if (dishName.includes(searchTerm) || dishCategory.includes(searchTerm)) {
                        item.style.display = '';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                });
                
                clearSearchBtn.style.display = searchTerm ? 'block' : 'none';
                
                // Show/hide no results message
                if (visibleCount === 0) {
                    if (noResults) noResults.style.display = 'block';
                    if (dishesList) dishesList.style.display = 'none';
                } else {
                    if (noResults) noResults.style.display = 'none';
                    if (dishesList) dishesList.style.display = '';
                }
            }
        });
        
        // Clear search
        if (clearSearchBtn) {
            clearSearchBtn.addEventListener('click', function() {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
                searchInput.focus();
            });
        }
    }
});

// Store ingredients by category
let ingredientsByCategory = {};
let ingredientRowCount = 0;

// Make functions globally accessible
window.addIngredientRow = function() {
    const container = document.getElementById('ingredientsContainer');
    
    // Check if container exists
    if (!container) {
        console.error('Ingredients container not found!');
        alert('Error: Could not find ingredients container. Please refresh the page.');
        return;
    }
    
    // Remove placeholder styling and content when first ingredient is added
    if (container.querySelector('p.text-muted, .text-center')) {
        container.innerHTML = '';
        container.className = '';
        container.style.minHeight = '100px';
        container.style.maxHeight = '400px';
        container.style.overflowY = 'auto';
        container.style.backgroundColor = '';
        container.style.border = '';
        container.style.padding = '';
    }
    
    const rowId = 'ingredient_row_' + ingredientRowCount;
    
    // Build category options
    let categoryOptions = '<option value="">Select Category</option>';
    
    // Check if ingredients are loaded
    if (ingredientsByCategory && Object.keys(ingredientsByCategory).length > 0) {
        for (const [catId, ingredients] of Object.entries(ingredientsByCategory)) {
            // Get category name from first ingredient or use category ID
            const catName = ingredients.length > 0 ? ingredients[0].category_name || `Category ${catId}` : `Category ${catId}`;
            categoryOptions += `<option value="${catId}">${catName}</option>`;
        }
    } else {
        // If ingredients aren't loaded yet, show a loading message
        categoryOptions += '<option value="" disabled>Loading categories...</option>';
        // Try to reload ingredients in the background
        loadAllIngredients();
    }
    
    const row = document.createElement('div');
    row.className = 'ingredient-row-item mb-3';
    row.id = rowId;
    row.innerHTML = `
        <div class="card border shadow-sm ingredient-row-card">
            <div class="card-body p-3">
                <div class="row g-3 align-items-end">
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label fw-semibold mb-2 d-block">
                            <i class="bi bi-folder-fill me-2 text-primary"></i>
                            Category <span class="text-danger">*</span>
                        </label>
                        <select class="form-select ingredient-category-select" name="ingredient_categories[]" onchange="loadIngredientsForRow('${rowId}', this.value)" required>
                            ${categoryOptions}
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label fw-semibold mb-2 d-block">
                            <i class="bi bi-basket-fill me-2 text-success"></i>
                            Ingredient <span class="text-danger">*</span>
                        </label>
                        <select class="form-select ingredient-select" name="ingredients[]" required>
                            <option value="">Select Category First</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <label class="form-label fw-semibold mb-2 d-block">
                            <i class="bi bi-123 me-2 text-info"></i>
                            Quantity <span class="text-danger">*</span>
                        </label>
                        <input type="number" class="form-control" name="quantities[]" 
                               placeholder="0.00" step="0.01" min="0" required>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <label class="form-label fw-semibold mb-2 d-block">
                            <i class="bi bi-rulers me-2 text-warning"></i>
                            Unit <span class="text-danger">*</span>
                        </label>
                        <select class="form-select" name="units[]" required>
                            <option value="">Select Unit</option>
                            <option value="kg">kg</option>
                            <option value="g">g</option>
                            <option value="mg">mg</option>
                            <option value="liter">L</option>
                            <option value="ml">mL</option>
                            <option value="cup">cup</option>
                            <option value="tbsp">tbsp</option>
                            <option value="tsp">tsp</option>
                            <option value="piece">piece</option>
                            <option value="pieces">pieces</option>
                            <option value="oz">oz</option>
                            <option value="lb">lb</option>
                            <option value="oz_fluid">fl oz</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4 col-sm-6">
                        <label class="form-label fw-semibold mb-2 d-block text-transparent">Action</label>
                        <button type="button" class="btn btn-danger w-100 d-flex align-items-center justify-content-center" onclick="removeIngredientRow('${rowId}')" title="Remove ingredient">
                            <i class="bi bi-trash-fill me-2"></i>
                            <span>Remove</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    container.appendChild(row);
    ingredientRowCount++;
};

// Load ingredients when page loads
document.addEventListener('DOMContentLoaded', function() {
    loadAllIngredients();
});

// Load all ingredients grouped by category
function loadAllIngredients() {
    const basePath = window.location.pathname.includes('/admin/') || 
                     window.location.pathname.includes('/user/') || 
                     window.location.pathname.includes('/auth/') ? '../' : '';
    fetch(basePath + 'api/get_ingredients.php')
        .then(response => response.json())
        .then(data => {
            ingredientsByCategory = data;
            // Refresh all category dropdowns
            refreshCategoryDropdowns();
            <?php if ($edit_dish): ?>
                loadIngredients();
                // Populate edit form after data is loaded
                populateEditForm();
            <?php endif; ?>
        })
        .catch(error => {
            console.error('Error loading ingredients:', error);
            <?php if ($edit_dish): ?>
                // Still try to populate form even if API fails
                populateEditForm();
            <?php endif; ?>
        });
}

// Refresh all category dropdowns with loaded ingredients
function refreshCategoryDropdowns() {
    if (!ingredientsByCategory || Object.keys(ingredientsByCategory).length === 0) {
        return;
    }
    
    const categorySelects = document.querySelectorAll('.ingredient-category-select');
    categorySelects.forEach(select => {
        // Skip if already populated
        if (select.options.length > 1 && !select.options[1].disabled) {
            return;
        }
        
        // Rebuild category options
        let categoryOptions = '<option value="">Select Category</option>';
        for (const [catId, ingredients] of Object.entries(ingredientsByCategory)) {
            const catName = ingredients.length > 0 ? ingredients[0].category_name || `Category ${catId}` : `Category ${catId}`;
            const selected = select.value == catId ? 'selected' : '';
            categoryOptions += `<option value="${catId}" ${selected}>${catName}</option>`;
        }
        select.innerHTML = categoryOptions;
    });
}

// Load ingredients based on selected category (for dish category - legacy support)
function loadIngredients() {
    // This function is kept for backward compatibility but no longer restricts ingredient selection
    // Ingredients can now be selected from any category
}


// Load ingredients for a specific row based on selected category
window.loadIngredientsForRow = function(rowId, categoryId) {
    const row = document.getElementById(rowId);
    if (!row) return;
    
    const ingredientSelect = row.querySelector('.ingredient-select');
    if (!ingredientSelect) return;
    
    if (!categoryId || categoryId === '') {
        ingredientSelect.innerHTML = '<option value="">Select Category First</option>';
        ingredientSelect.disabled = true;
        return;
    }
    
    const ingredients = ingredientsByCategory[categoryId] || [];
    
    let options = '<option value="">Select ingredient</option>';
    ingredients.forEach(ing => {
        const unitText = ing.unit ? ` (${ing.unit})` : '';
        options += `<option value="${ing.id}">${ing.name}${unitText}</option>`;
    });
    
    ingredientSelect.innerHTML = options;
    ingredientSelect.disabled = false;
}

// Remove ingredient row
window.removeIngredientRow = function(rowId) {
    const row = document.getElementById(rowId);
    const container = document.getElementById('ingredientsContainer');
    
    if (row) {
        row.remove();
        ingredientRowCount--;
        
        // If container becomes empty, show placeholder again
        if (container.children.length === 0) {
            container.className = 'border border-2 border-dashed rounded-3 p-4 bg-light';
            container.style.minHeight = '200px';
            container.style.maxHeight = '300px';
            container.style.overflowY = 'auto';
            container.style.backgroundColor = '#f8f9fa';
            container.innerHTML = `
                <div class="text-center py-4">
                    <i class="bi bi-inbox display-6 text-muted d-block mb-2"></i>
                    <p class="text-muted mb-0 small">No ingredients added yet</p>
                    <p class="text-muted small mb-0">Click "Add Ingredient" to start building your recipe</p>
                </div>
            `;
        }
    }
}

// Populate edit form with existing dish ingredients
window.populateEditForm = function() {
    console.log('populateEditForm called');
    const container = document.getElementById('ingredientsContainer');
    if (!container) {
        console.warn('Container not found, retrying...');
        setTimeout(populateEditForm, 500);
        return;
    }
    
    // Check if ingredients are loaded
    if (!ingredientsByCategory || Object.keys(ingredientsByCategory).length === 0) {
        console.warn('Ingredients not loaded yet, retrying in 500ms...');
        setTimeout(populateEditForm, 500);
        return;
    }
    
    console.log('IngredientsByCategory structure:', Object.keys(ingredientsByCategory).length, 'categories');
    console.log('Sample category:', Object.keys(ingredientsByCategory)[0], ingredientsByCategory[Object.keys(ingredientsByCategory)[0]]);
    
    // Clear container and remove placeholder styling
    container.innerHTML = '';
    container.className = '';
    container.style.minHeight = '';
    container.style.backgroundColor = '';
    ingredientRowCount = 0;
    
    <?php if ($edit_dish && !empty($edit_dish_ingredients)): ?>
        console.log('Populating <?php echo count($edit_dish_ingredients); ?> ingredients');
        <?php foreach ($edit_dish_ingredients as $di): ?>
            try {
                const ingredientCategoryId = <?php echo isset($di['category_id']) && $di['category_id'] ? (int)$di['category_id'] : 'null'; ?>;
                const ingredientId = <?php echo (int)$di['ingredient_id']; ?>;
                const currentUnit = '<?php echo isset($di['unit']) ? htmlspecialchars($di['unit'], ENT_QUOTES) : ''; ?>';
                const quantityValue = <?php echo floatval($di['quantity']); ?>;
                
                const rowId = 'ingredient_row_' + ingredientRowCount;
                
                // Build category options
                let categoryOptions = '<option value="">Select Category</option>';
                for (const [catId, ingredients] of Object.entries(ingredientsByCategory)) {
                    const catIdNum = parseInt(catId);
                    const catName = ingredients.length > 0 ? ingredients[0].category_name || `Category ${catId}` : `Category ${catId}`;
                    const selected = (ingredientCategoryId !== null && catIdNum == ingredientCategoryId) ? 'selected' : '';
                    categoryOptions += `<option value="${catId}" ${selected}>${catName}</option>`;
                }
                
                // Build ingredient options for the selected category
                const categoryKey = ingredientCategoryId !== null ? String(ingredientCategoryId) : null;
                const ingredients = (categoryKey && ingredientsByCategory[categoryKey]) ? ingredientsByCategory[categoryKey] : [];
                let ingredientOptions = '<option value="">Select ingredient</option>';
                
                // If we have ingredients in the category, populate them
                if (ingredients.length > 0) {
                    ingredients.forEach(ing => {
                        const unitText = ing.unit ? ` (${ing.unit})` : '';
                        const selected = parseInt(ing.id) == ingredientId ? 'selected' : '';
                        ingredientOptions += `<option value="${ing.id}" ${selected}>${ing.name}${unitText}</option>`;
                    });
                } else {
                    // If ingredient not found in category, still show it
                    ingredientOptions += `<option value="${ingredientId}" selected>Ingredient #${ingredientId}</option>`;
                }
                
                let unitOptions = '<option value="">Unit</option>';
                const unitList = [
                    {value: 'kg', label: 'kg'},
                    {value: 'g', label: 'g'},
                    {value: 'mg', label: 'mg'},
                    {value: 'liter', label: 'L'},
                    {value: 'ml', label: 'mL'},
                    {value: 'cup', label: 'cup'},
                    {value: 'tbsp', label: 'tbsp'},
                    {value: 'tsp', label: 'tsp'},
                    {value: 'piece', label: 'piece'},
                    {value: 'pieces', label: 'pieces'},
                    {value: 'oz', label: 'oz'},
                    {value: 'lb', label: 'lb'},
                    {value: 'oz_fluid', label: 'fl oz'}
                ];
                
                unitList.forEach(unit => {
                    const selected = unit.value === currentUnit ? 'selected' : '';
                    unitOptions += `<option value="${unit.value}" ${selected}>${unit.label}</option>`;
                });
                
                const row = document.createElement('div');
                row.className = 'ingredient-row-item mb-3';
                row.id = rowId;
                row.innerHTML = `
                    <div class="card border shadow-sm ingredient-row-card">
                        <div class="card-body p-3">
                            <div class="row g-3 align-items-end">
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label fw-semibold mb-2 d-block">
                                        <i class="bi bi-folder-fill me-2 text-primary"></i>
                                        Category <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select ingredient-category-select" name="ingredient_categories[]" onchange="loadIngredientsForRow('${rowId}', this.value)" required>
                                        ${categoryOptions}
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label fw-semibold mb-2 d-block">
                                        <i class="bi bi-basket-fill me-2 text-success"></i>
                                        Ingredient <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select ingredient-select" name="ingredients[]" required>
                                        ${ingredientOptions}
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-4 col-sm-6">
                                    <label class="form-label fw-semibold mb-2 d-block">
                                        <i class="bi bi-123 me-2 text-info"></i>
                                        Quantity <span class="text-danger">*</span>
                                    </label>
                                    <input type="number" class="form-control" name="quantities[]" 
                                           placeholder="0.00" step="0.01" min="0" 
                                           value="${quantityValue}" required>
                                </div>
                                <div class="col-lg-2 col-md-4 col-sm-6">
                                    <label class="form-label fw-semibold mb-2 d-block">
                                        <i class="bi bi-rulers me-2 text-warning"></i>
                                        Unit <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" name="units[]" required>
                                        ${unitOptions}
                                    </select>
                                </div>
                                <div class="col-lg-2 col-md-4 col-sm-6">
                                    <label class="form-label fw-semibold mb-2 d-block text-transparent">Action</label>
                                    <button type="button" class="btn btn-danger w-100 d-flex align-items-center justify-content-center" onclick="removeIngredientRow('${rowId}')" title="Remove ingredient">
                                        <i class="bi bi-trash-fill me-2"></i>
                                        <span>Remove</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                container.appendChild(row);
                ingredientRowCount++;
            } catch (error) {
                console.error('Error creating ingredient row:', error);
                console.error('Ingredient data:', {
                    categoryId: <?php echo isset($di['category_id']) ? $di['category_id'] : 'null'; ?>,
                    ingredientId: <?php echo $di['ingredient_id']; ?>,
                    quantity: <?php echo $di['quantity']; ?>
                });
            }
        <?php endforeach; ?>
        console.log('Finished populating <?php echo count($edit_dish_ingredients); ?> ingredients, total rows: ' + ingredientRowCount);
    <?php else: ?>
        <?php if ($edit_dish): ?>
            console.log('Edit mode but no ingredients found');
        <?php else: ?>
            addIngredientRow();
        <?php endif; ?>
    <?php endif; ?>
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

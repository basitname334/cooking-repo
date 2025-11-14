<?php
/**
 * Ingredients Management Page
 * CRUD operations for ingredients
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

requireAdmin();

$conn = getDBConnection();
$error = '';
$success = '';
$show_delete_all_confirmation = false;

// Handle form submission - Create or Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $ingredient_id = $_POST['ingredient_id'] ?? null;
    
    // Translate to Urdu if current language is Urdu
    $name = translateForDatabase($name);
    
    if (empty($name) || $category_id <= 0) {
        $error = 'Ingredient name and category are required.';
    } else {
        if ($ingredient_id) {
            // Update existing ingredient (keep existing unit)
            $stmt = $conn->prepare("UPDATE ingredients SET name = ?, category_id = ? WHERE id = ?");
            
            if ($stmt === false) {
                $error = 'Failed to prepare update query: ' . $conn->error;
            } else {
                $stmt->bind_param("sii", $name, $category_id, $ingredient_id);
                
                if ($stmt->execute()) {
                    $success = 'Ingredient updated successfully!';
                    // Redirect to refresh the list
                    header('Location: ingredients.php?success=1&edited=1');
                    exit();
                } else {
                    $error = 'Failed to update ingredient: ' . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            // Create new ingredient (set unit to empty string)
            $unit = ''; // Set empty unit
            $stmt = $conn->prepare("INSERT INTO ingredients (name, category_id, unit) VALUES (?, ?, ?)");
            
            if ($stmt === false) {
                $error = 'Failed to prepare insert query: ' . $conn->error . ' Make sure the ingredients table exists.';
            } else {
                $stmt->bind_param("sis", $name, $category_id, $unit);
                
                if ($stmt->execute()) {
                    $success = 'Ingredient created successfully!';
                    // Redirect to refresh the list
                    header('Location: ingredients.php?success=1');
                    exit();
                } else {
                    $error = 'Failed to create ingredient: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// Handle delete all (reset)
$show_delete_all_confirmation = false;
if (isset($_GET['delete_all']) && $_GET['delete_all'] === '1') {
    // Double confirmation via POST to prevent accidental deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete_all'])) {
        $conn->begin_transaction();
        try {
            // Delete all dish_ingredients first (due to foreign key constraint)
            $conn->query("DELETE FROM dish_ingredients");
            
            // Delete all ingredients
            $conn->query("DELETE FROM ingredients");
            
            $conn->commit();
            $conn->close();
            $success = 'All ingredients have been deleted successfully!';
            header('Location: ingredients.php?success=1&deleted_all=1');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Failed to delete all ingredients: ' . $e->getMessage();
        }
    } else {
        // Show confirmation form - don't close connection yet, need counts
        $show_delete_all_confirmation = true;
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM ingredients WHERE id = ?");
    
    if ($stmt === false) {
        $error = 'Failed to prepare delete query: ' . $conn->error;
    } else {
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $success = 'Ingredient deleted successfully!';
            // Redirect to refresh the list
            header('Location: ingredients.php?success=1&deleted=1');
            exit();
        } else {
            $error = 'Failed to delete ingredient: ' . $stmt->error;
        }
        $stmt->close();
    }
}

// Handle success message from redirect
if (isset($_GET['success'])) {
    if (isset($_GET['deleted_all'])) {
        $success = 'All ingredients have been deleted successfully!';
    } elseif (isset($_GET['deleted'])) {
        $success = 'Ingredient deleted successfully!';
    } elseif (isset($_GET['edited'])) {
        $success = 'Ingredient updated successfully!';
    } else {
        $success = 'Ingredient created successfully!';
    }
}

// Get ingredient for editing
$edit_ingredient = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM ingredients WHERE id = ?");
    
    if ($stmt === false) {
        $error = 'Failed to prepare select query: ' . $conn->error;
    } else {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $edit_ingredient = $result->fetch_assoc();
        $stmt->close();
    }
}

// Get all categories for dropdown with error handling
$categories = [];
$result = $conn->query("SELECT * FROM categories ORDER BY name");
if ($result && $result->num_rows > 0) {
    $categories = $result->fetch_all(MYSQLI_ASSOC);
}

// Get all ingredients with category names with error handling
$ingredients = [];
$result = $conn->query("SELECT i.*, c.name as category_name 
    FROM ingredients i 
    LEFT JOIN categories c ON i.category_id = c.id 
    ORDER BY c.name, i.name");
if ($result && $result->num_rows > 0) {
    $ingredients = $result->fetch_all(MYSQLI_ASSOC);
}

// Get count for delete all confirmation
$total_ingredients = count($ingredients);

// Close connection after getting all data
if (isset($conn) && !isset($show_delete_all_confirmation)) {
    $conn->close();
}

$pageTitle = 'Manage Ingredients';
include __DIR__ . '/../includes/header.php';
?>

<?php if (isset($show_delete_all_confirmation) && $show_delete_all_confirmation): ?>
    <div class="modal fade show" id="deleteAllModal" tabindex="-1" style="display: block;" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Delete All Ingredients</h5>
                    <button type="button" class="btn-close btn-close-white" onclick="window.location.href='ingredients.php'"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <strong>Warning:</strong> This action cannot be undone!
                    </div>
                    <p>You are about to delete <strong>ALL</strong> ingredients:</p>
                    <ul>
                        <li><strong><?php echo $total_ingredients; ?></strong> Ingredients will be deleted</li>
                        <li>All dish-ingredient relationships will also be deleted</li>
                    </ul>
                    <p class="mb-0">This will permanently delete all ingredients and their relationships with dishes.</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" action="?delete_all=1">
                        <input type="hidden" name="confirm_delete_all" value="1">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='ingredients.php'">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-2"></i>Yes, Delete All Ingredients
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-backdrop fade show"></div>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-3">
            <div>
                <h2 class="mb-1">
                    <i class="bi bi-basket-fill me-2 text-primary"></i>
                    Manage Ingredients
                </h2>
                <p class="text-muted mb-0"><?php echo count($ingredients); ?> <?php echo count($ingredients) == 1 ? 'ingredient' : 'ingredients'; ?></p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <!-- Search Box -->
                <div style="max-width: 300px;">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0" id="searchIngredients" 
                               placeholder="Search ingredients..." 
                               autocomplete="off">
                        <button class="btn btn-outline-secondary border-start-0" type="button" id="clearSearchIngredients" style="display: none;">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn btn-link text-muted p-0" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-chevron-down fs-5"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="?delete_all=1"><i class="bi bi-trash me-2"></i>Delete All</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Add/Edit Ingredient Form -->
<?php if ($edit_ingredient): ?>
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="POST" action="" class="row align-items-end">
                <input type="hidden" name="ingredient_id" value="<?php echo $edit_ingredient['id']; ?>">
                <div class="col-md-4 mb-3 mb-md-0">
                    <label for="name" class="form-label mb-1">Ingredient Name</label>
                    <input type="text" class="form-control" id="name" name="name" required
                           placeholder="Ingredient name (e.g., Chicken Breast, Rice)"
                           value="<?php echo htmlspecialchars($edit_ingredient['name'] ?? ''); ?>">
                </div>
                <div class="col-md-4 mb-3 mb-md-0">
                    <label for="category_id" class="form-label mb-1">Category</label>
                    <select class="form-select" id="category_id" name="category_id" required>
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?php echo $category['id']; ?>" 
                                <?php echo ($edit_ingredient && $edit_ingredient['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-check-lg me-2"></i>Update
                    </button>
                    <a href="ingredients.php" class="btn btn-secondary w-100 mt-2">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="mb-4">
        <form method="POST" action="" class="d-flex gap-2">
            <input type="text" class="form-control flex-grow-1" id="name" name="name" required
                   placeholder="Ingredient name (e.g., Chicken Breast, Rice)">
            <select class="form-select" id="category_id" name="category_id" required style="max-width: 200px;">
                <option value="">-- Category --</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo $category['id']; ?>">
                        <?php echo htmlspecialchars($category['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-success">
                <i class="bi bi-plus-lg me-2"></i>Add
            </button>
        </form>
    </div>
<?php endif; ?>

<!-- Ingredients List -->
<div class="row">
    <?php if (empty($ingredients)): ?>
        <div class="col-12">
            <div class="text-center py-5">
                <i class="bi bi-inbox display-4 text-muted d-block mb-3"></i>
                <p class="text-muted">No ingredients found. Add your first ingredient above!</p>
            </div>
        </div>
    <?php else: ?>
        <div class="col-12">
            <div class="row g-3" id="ingredientsList">
                <?php foreach ($ingredients as $ingredient): ?>
                    <div class="col-md-6 col-lg-4 ingredient-item" 
                         data-name="<?php echo strtolower(htmlspecialchars($ingredient['name'])); ?>"
                         data-category="<?php echo strtolower(htmlspecialchars($ingredient['category_name'] ?? '')); ?>">
                        <div class="card h-100 border-0 shadow-sm ingredient-card" style="cursor: pointer;" onclick="window.location.href='?edit=<?php echo $ingredient['id']; ?>'">
                            <div class="card-body d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="bi bi-basket me-2 text-success fs-5"></i>
                                        <h6 class="card-title mb-0 fw-bold"><?php echo htmlspecialchars($ingredient['name']); ?></h6>
                                    </div>
                                    <p class="text-muted small mb-0">
                                        <span class="badge bg-primary">
                                            <?php echo htmlspecialchars($ingredient['category_name']); ?>
                                        </span>
                                    </p>
                                </div>
                                <div class="d-flex gap-1 ms-2">
                                    <button class="btn btn-sm btn-primary rounded-circle p-2" 
                                            onclick="event.stopPropagation(); window.location.href='?edit=<?php echo $ingredient['id']; ?>'"
                                            title="Edit Ingredient">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger rounded-circle p-2" 
                                            onclick="event.stopPropagation(); if(confirm('Delete this ingredient?')) window.location.href='?delete=<?php echo $ingredient['id']; ?>'"
                                            title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="noResultsIngredients" class="text-center py-5" style="display: none;">
                <i class="bi bi-search display-4 text-muted d-block mb-3"></i>
                <h5 class="text-muted mb-2">No ingredients found</h5>
                <p class="text-muted">Try adjusting your search terms</p>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Search functionality for ingredients
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchIngredients');
    const clearSearchBtn = document.getElementById('clearSearchIngredients');
    const ingredientsList = document.getElementById('ingredientsList');
    const noResults = document.getElementById('noResultsIngredients');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const ingredientItems = document.querySelectorAll('.ingredient-item');
            let visibleCount = 0;
            
            if (searchTerm === '') {
                // Show all ingredients
                ingredientItems.forEach(item => {
                    item.style.display = '';
                    visibleCount++;
                });
                clearSearchBtn.style.display = 'none';
                if (noResults) noResults.style.display = 'none';
                if (ingredientsList) ingredientsList.style.display = '';
            } else {
                // Filter ingredients
                ingredientItems.forEach(item => {
                    const ingredientName = item.getAttribute('data-name') || '';
                    const ingredientCategory = item.getAttribute('data-category') || '';
                    
                    if (ingredientName.includes(searchTerm) || ingredientCategory.includes(searchTerm)) {
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
                    if (ingredientsList) ingredientsList.style.display = 'none';
                } else {
                    if (noResults) noResults.style.display = 'none';
                    if (ingredientsList) ingredientsList.style.display = '';
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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

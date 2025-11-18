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

<style>
.page-header-modern {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 50%, rgba(240, 147, 251, 0.1) 100%);
    border-radius: 20px;
    padding: 2rem;
    margin-bottom: 2rem;
    border: 1px solid rgba(99, 102, 241, 0.2);
}

.ingredient-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}

.ingredient-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
    opacity: 0;
}

.ingredient-card:hover::before {
    opacity: 1;
}

.ingredient-card:hover {
    box-shadow: 0 12px 35px rgba(16, 185, 129, 0.25);
    border-color: rgba(16, 185, 129, 0.3);
}

.search-modern {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
}

.search-modern:focus-within {
    border-color: rgba(16, 185, 129, 0.3);
    box-shadow: 0 4px 20px rgba(16, 185, 129, 0.2);
}
</style>

<div class="page-header-modern">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h1 class="display-6 fw-bold mb-2" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <i class="bi bi-basket-fill me-3"></i>Manage Ingredients
            </h1>
            <p class="lead mb-0" style="color: #64748b;">
                <i class="bi bi-info-circle me-2"></i>
                <?php echo count($ingredients); ?> <?php echo count($ingredients) == 1 ? 'ingredient' : 'ingredients'; ?> in your inventory
            </p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <!-- Search Box -->
            <div class="search-modern" style="max-width: 350px;">
                <div class="input-group border-0">
                    <span class="input-group-text bg-transparent border-0" style="color: #6366f1;">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" class="form-control border-0 bg-transparent" id="searchIngredients" 
                           placeholder="Search ingredients..." 
                           autocomplete="off"
                           style="box-shadow: none;">
                    <button class="btn btn-link border-0 text-muted p-2" type="button" id="clearSearchIngredients" style="display: none;">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>
            <div class="dropdown">
                <button class="btn btn-outline-primary rounded-pill px-3" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-three-dots-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0" style="border-radius: 12px;">
                    <li><a class="dropdown-item" href="?delete_all=1"><i class="bi bi-trash me-2 text-danger"></i>Delete All</a></li>
                </ul>
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
    <div class="card shadow-lg border-0 mb-4" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(6, 182, 212, 0.05) 100%); border-radius: 20px; border: 1px solid rgba(16, 185, 129, 0.1);">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-4" style="color: #1e293b;">
                <i class="bi bi-pencil-square me-2" style="color: #10b981;"></i>Edit Ingredient
            </h5>
            <form method="POST" action="" class="row align-items-end g-3">
                <input type="hidden" name="ingredient_id" value="<?php echo $edit_ingredient['id']; ?>">
                <div class="col-md-4">
                    <label for="name" class="form-label fw-semibold mb-2" style="color: #1e293b;">
                        <i class="bi bi-tag me-1" style="color: #10b981;"></i>Ingredient Name
                    </label>
                    <input type="text" class="form-control" id="name" name="name" required
                           placeholder="Ingredient name (e.g., Chicken Breast, Rice)"
                           value="<?php echo htmlspecialchars($edit_ingredient['name'] ?? ''); ?>"
                           style="border-radius: 12px; border: 2px solid #e2e8f0; padding: 0.75rem 1rem;">
                </div>
                <div class="col-md-4">
                    <label for="category_id" class="form-label fw-semibold mb-2" style="color: #1e293b;">
                        <i class="bi bi-folder me-1" style="color: #10b981;"></i>Category
                    </label>
                    <select class="form-select" id="category_id" name="category_id" required
                            style="border-radius: 12px; border: 2px solid #e2e8f0; padding: 0.75rem 1rem;">
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
                    <button type="submit" class="btn btn-success w-100 rounded-pill shadow-lg" style="padding: 0.75rem; font-weight: 600;">
                        <i class="bi bi-check-lg me-2"></i>Update Ingredient
                    </button>
                    <a href="ingredients.php" class="btn btn-outline-secondary w-100 mt-2 rounded-pill">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-lg border-0 mb-4" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.05) 0%, rgba(6, 182, 212, 0.05) 100%); border-radius: 20px; border: 1px solid rgba(16, 185, 129, 0.1);">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3" style="color: #1e293b;">
                <i class="bi bi-plus-circle-fill me-2" style="color: #10b981;"></i>Add New Ingredient
            </h5>
            <form method="POST" action="" class="d-flex gap-3 flex-wrap">
                <input type="text" class="form-control flex-grow-1" id="name" name="name" required
                       placeholder="Ingredient name (e.g., Chicken Breast, Rice)"
                       style="border-radius: 12px; border: 2px solid #e2e8f0; padding: 0.75rem 1rem; max-width: 400px;">
                <select class="form-select" id="category_id" name="category_id" required 
                        style="border-radius: 12px; border: 2px solid #e2e8f0; padding: 0.75rem 1rem; max-width: 250px;">
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>">
                            <?php echo htmlspecialchars($category['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-success rounded-pill shadow-lg px-4" style="font-weight: 600; padding: 0.75rem 1.5rem;">
                    <i class="bi bi-plus-lg me-2"></i>Add Ingredient
                </button>
            </form>
        </div>
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
                        <div class="card h-100 border-0 shadow-lg ingredient-card" style="cursor: pointer;" onclick="window.location.href='?edit=<?php echo $ingredient['id']; ?>'">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-start justify-content-between mb-3">
                                    <div class="d-flex align-items-center">
                                        <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-right: 1rem; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);">
                                            <i class="bi bi-basket text-white fs-5"></i>
                                        </div>
                                        <div>
                                            <h6 class="card-title mb-1 fw-bold" style="color: #1e293b; font-size: 1.1rem;">
                                                <?php echo htmlspecialchars($ingredient['name']); ?>
                                            </h6>
                                            <span class="badge rounded-pill px-3 py-1" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 100%); color: #6366f1; border: 1px solid rgba(99, 102, 241, 0.2); font-weight: 600;">
                                                <?php echo htmlspecialchars($ingredient['category_name']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 justify-content-end">
                                    <button class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm" 
                                            onclick="event.stopPropagation(); window.location.href='?edit=<?php echo $ingredient['id']; ?>'"
                                            title="Edit Ingredient"
                                            style="font-weight: 600;">
                                        <i class="bi bi-pencil me-1"></i>Edit
                                    </button>
                                    <button class="btn btn-sm btn-danger rounded-pill px-3 shadow-sm" 
                                            onclick="event.stopPropagation(); if(confirm('Delete this ingredient?')) window.location.href='?delete=<?php echo $ingredient['id']; ?>'"
                                            title="Delete"
                                            style="font-weight: 600;">
                                        <i class="bi bi-trash me-1"></i>Delete
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

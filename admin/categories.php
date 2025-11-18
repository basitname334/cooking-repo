<?php
/**
 * Categories Management Page
 * CRUD operations for categories
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
    $description = trim($_POST['description'] ?? '');
    $category_id = $_POST['category_id'] ?? null;
    
    // Translate to Urdu if current language is Urdu
    $name = translateForDatabase($name);
    $description = translateForDatabase($description);
    
    if (empty($name)) {
        $error = t('category_name_required');
    } else {
        if ($category_id) {
            // Update existing category
            $stmt = $conn->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?");
            $stmt->bind_param("ssi", $name, $description, $category_id);
            
            if ($stmt->execute()) {
                $success = t('category_updated');
                // Redirect to refresh the list and prevent form resubmission
                header('Location: categories.php?success=1&edited=1');
                exit();
            } else {
                $error = t('failed_to_update') . ' ' . t('categories_title') . ': ' . $stmt->error . ' ' . t('may_already_exist');
            }
            $stmt->close();
        } else {
            // Create new category
            $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
            $stmt->bind_param("ss", $name, $description);
            
            if ($stmt->execute()) {
                $success = t('category_created');
                // Redirect to refresh the list and prevent form resubmission
                header('Location: categories.php?success=1');
                exit();
            } else {
                $error = t('failed_to_create') . ' ' . t('categories_title') . ': ' . $stmt->error . ' ' . t('may_already_exist');
            }
            $stmt->close();
        }
    }
}

// Handle delete all (reset)
if (isset($_GET['delete_all']) && $_GET['delete_all'] === '1') {
    // Double confirmation via POST to prevent accidental deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete_all'])) {
        $conn->begin_transaction();
        try {
            // Delete all dish_ingredients first (due to foreign key constraint)
            $conn->query("DELETE FROM dish_ingredients");
            
            // Delete all ingredients (due to foreign key constraint)
            $conn->query("DELETE FROM ingredients");
            
            // Delete all dishes (due to foreign key constraint)
            $conn->query("DELETE FROM dishes");
            
            // Delete all categories
            $conn->query("DELETE FROM categories");
            
            $conn->commit();
            $conn->close();
            $success = 'All categories and related data have been deleted successfully!';
            header('Location: categories.php?success=1&deleted_all=1');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'Failed to delete all data: ' . $e->getMessage();
        }
    } else {
        // Show confirmation form - will need counts later
        $show_delete_all_confirmation = true;
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $success = t('category_deleted');
        // Redirect to refresh the list
        header('Location: categories.php?success=1&deleted=1');
        exit();
    } else {
        $error = t('failed_to_delete') . ' ' . t('categories_title') . ': ' . $stmt->error . ' ' . t('may_have_associated');
    }
    $stmt->close();
}

// Handle success message from redirect
if (isset($_GET['success'])) {
    if (isset($_GET['deleted_all'])) {
        $success = 'All categories and related data have been deleted successfully!';
    } elseif (isset($_GET['deleted'])) {
        $success = t('category_deleted');
    } elseif (isset($_GET['edited'])) {
        $success = t('category_updated');
    } else {
        $success = t('category_created');
    }
}

// Get category for editing
$edit_category = null;
if (isset($_GET['edit'])) {
    $id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_category = $result->fetch_assoc();
    $stmt->close();
}

// Get all categories with error handling
$categories = [];

// First check if ingredients and dishes tables exist
$ingredients_table_exists = false;
$dishes_table_exists = false;

$check_result = $conn->query("SHOW TABLES LIKE 'ingredients'");
if ($check_result && $check_result->num_rows > 0) {
    $ingredients_table_exists = true;
}

$check_result = $conn->query("SHOW TABLES LIKE 'dishes'");
if ($check_result && $check_result->num_rows > 0) {
    $dishes_table_exists = true;
}

// Build query based on which tables exist
if ($ingredients_table_exists && $dishes_table_exists) {
    // Both tables exist - use full query with counts
    $result = $conn->query("SELECT c.*, 
        (SELECT COUNT(*) FROM ingredients WHERE category_id = c.id) as ingredients_count,
        (SELECT COUNT(*) FROM dishes WHERE category_id = c.id) as dishes_count
        FROM categories c ORDER BY c.name");
} elseif ($ingredients_table_exists) {
    // Only ingredients table exists
    $result = $conn->query("SELECT c.*, 
        (SELECT COUNT(*) FROM ingredients WHERE category_id = c.id) as ingredients_count,
        0 as dishes_count
        FROM categories c ORDER BY c.name");
} elseif ($dishes_table_exists) {
    // Only dishes table exists
    $result = $conn->query("SELECT c.*, 
        0 as ingredients_count,
        (SELECT COUNT(*) FROM dishes WHERE category_id = c.id) as dishes_count
        FROM categories c ORDER BY c.name");
} else {
    // Neither table exists - just get categories
    $result = $conn->query("SELECT c.*, 0 as ingredients_count, 0 as dishes_count FROM categories c ORDER BY c.name");
}

if ($result) {
    // Get all rows even if table is empty (for proper display)
    if ($result->num_rows > 0) {
        $categories = $result->fetch_all(MYSQLI_ASSOC);
    }
} else {
    // Query failed - try simpler query without subqueries
    $result = $conn->query("SELECT * FROM categories ORDER BY name");
    if ($result) {
        $categories = $result->fetch_all(MYSQLI_ASSOC);
        // Add default counts
        foreach ($categories as &$category) {
            $category['ingredients_count'] = 0;
            $category['dishes_count'] = 0;
        }
    } else {
        // Both queries failed - show error
        $error = 'Failed to load categories: ' . $conn->error;
    }
}

// Get counts for delete all confirmation
$total_categories = count($categories);
$total_ingredients = 0;
$total_dishes = 0;
foreach ($categories as $cat) {
    $total_ingredients += intval($cat['ingredients_count'] ?? 0);
    $total_dishes += intval($cat['dishes_count'] ?? 0);
}

// Close connection after getting all data
if (isset($conn) && !isset($show_delete_all_confirmation)) {
    $conn->close();
}

$pageTitle = t('categories_title');
include __DIR__ . '/../includes/header.php';
?>

<?php if (isset($show_delete_all_confirmation) && $show_delete_all_confirmation): ?>
    <div class="modal fade show" id="deleteAllModal" tabindex="-1" style="display: block;" aria-modal="true" role="dialog">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Delete All Categories</h5>
                    <button type="button" class="btn-close btn-close-white" onclick="window.location.href='categories.php'"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <strong>Warning:</strong> This action cannot be undone!
                    </div>
                    <p>You are about to delete <strong>ALL</strong> data:</p>
                    <ul>
                        <li><strong><?php echo $total_categories; ?></strong> Categories</li>
                        <li><strong><?php echo $total_ingredients; ?></strong> Ingredients</li>
                        <li><strong><?php echo $total_dishes; ?></strong> Dishes</li>
                    </ul>
                    <p class="mb-0">This will permanently delete all categories, ingredients, dishes, and dish-ingredient relationships.</p>
                </div>
                <div class="modal-footer">
                    <form method="POST" action="?delete_all=1">
                        <input type="hidden" name="confirm_delete_all" value="1">
                        <button type="button" class="btn btn-secondary" onclick="window.location.href='categories.php'">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash me-2"></i>Yes, Delete All Data
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

.category-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}

.category-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    opacity: 0;
}

.category-card:hover::before {
    opacity: 1;
}

.category-card:hover {
    box-shadow: 0 12px 35px rgba(99, 102, 241, 0.25);
    border-color: rgba(99, 102, 241, 0.3);
}

.search-modern {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
}

.search-modern:focus-within {
    border-color: rgba(99, 102, 241, 0.3);
    box-shadow: 0 4px 20px rgba(99, 102, 241, 0.2);
}
</style>

<div class="page-header-modern">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h1 class="display-6 fw-bold mb-2" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <i class="bi bi-folder2-open-fill me-3"></i>Manage Categories
            </h1>
            <p class="lead mb-0" style="color: #64748b;">
                <i class="bi bi-info-circle me-2"></i>
                <?php echo count($categories); ?> <?php echo count($categories) == 1 ? 'category' : 'categories'; ?> organized
            </p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <!-- Search Box -->
            <div class="search-modern" style="max-width: 350px;">
                <div class="input-group border-0">
                    <span class="input-group-text bg-transparent border-0" style="color: #6366f1;">
                        <i class="bi bi-search"></i>
                    </span>
                    <input type="text" class="form-control border-0 bg-transparent" id="searchCategories" 
                           placeholder="Search categories..." 
                           autocomplete="off"
                           style="box-shadow: none;">
                    <button class="btn btn-link border-0 text-muted p-2" type="button" id="clearSearchCategories" style="display: none;">
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

<!-- Add Category Form -->
<?php if ($edit_category): ?>
    <div class="card shadow-lg border-0 mb-4" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.05) 0%, rgba(139, 92, 246, 0.05) 100%); border-radius: 20px; border: 1px solid rgba(99, 102, 241, 0.1);">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-4" style="color: #1e293b;">
                <i class="bi bi-pencil-square me-2" style="color: #6366f1;"></i>Edit Category
            </h5>
            <form method="POST" action="" class="row align-items-end g-3">
                <input type="hidden" name="category_id" value="<?php echo $edit_category['id']; ?>">
                <div class="col-md-4">
                    <label for="name" class="form-label fw-semibold mb-2" style="color: #1e293b;">
                        <i class="bi bi-tag me-1" style="color: #6366f1;"></i>Category Name
                    </label>
                    <input type="text" class="form-control" id="name" name="name" required
                           placeholder="Category name (e.g., Grains, Vegetables, Spices)"
                           value="<?php echo htmlspecialchars($edit_category['name'] ?? ''); ?>"
                           style="border-radius: 12px; border: 2px solid #e2e8f0; padding: 0.75rem 1rem;">
                </div>
                <div class="col-md-5">
                    <label for="description" class="form-label fw-semibold mb-2" style="color: #1e293b;">
                        <i class="bi bi-card-text me-1" style="color: #6366f1;"></i>Description (Optional)
                    </label>
                    <input type="text" class="form-control" id="description" name="description"
                           placeholder="Optional description..."
                           value="<?php echo htmlspecialchars($edit_category['description'] ?? ''); ?>"
                           style="border-radius: 12px; border: 2px solid #e2e8f0; padding: 0.75rem 1rem;">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill shadow-lg" style="padding: 0.75rem; font-weight: 600;">
                        <i class="bi bi-check-lg me-2"></i>Update Category
                    </button>
                    <a href="categories.php" class="btn btn-outline-secondary w-100 mt-2 rounded-pill">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="card shadow-lg border-0 mb-4" style="background: linear-gradient(135deg, rgba(99, 102, 241, 0.05) 0%, rgba(139, 92, 246, 0.05) 100%); border-radius: 20px; border: 1px solid rgba(99, 102, 241, 0.1);">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3" style="color: #1e293b;">
                <i class="bi bi-plus-circle-fill me-2" style="color: #6366f1;"></i>Add New Category
            </h5>
            <form method="POST" action="" class="d-flex gap-3 flex-wrap">
                <input type="text" class="form-control flex-grow-1" id="name" name="name" required
                       placeholder="Category name (e.g., Grains, Vegetables, Spices)"
                       style="border-radius: 12px; border: 2px solid #e2e8f0; padding: 0.75rem 1rem; max-width: 500px;">
                <input type="hidden" name="description" value="">
                <button type="submit" class="btn btn-primary rounded-pill shadow-lg px-4" style="font-weight: 600; padding: 0.75rem 1.5rem;">
                    <i class="bi bi-plus-lg me-2"></i>Add Category
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Categories Grid -->
<?php if (empty($categories)): ?>
    <div class="text-center py-5">
        <i class="bi bi-inbox display-4 text-muted d-block mb-3"></i>
        <p class="text-muted">No categories found. Add your first category above!</p>
    </div>
<?php else: ?>
    <div class="row g-3" id="categoriesList">
        <?php foreach ($categories as $category): ?>
            <?php 
            $ingredients_count = isset($category['ingredients_count']) ? intval($category['ingredients_count']) : 0;
            ?>
            <div class="col-md-6 col-lg-4 category-item" 
                 data-name="<?php echo strtolower(htmlspecialchars($category['name'])); ?>"
                 data-description="<?php echo strtolower(htmlspecialchars($category['description'] ?? '')); ?>">
                <div class="card h-100 border-0 shadow-lg category-card" style="cursor: pointer;" onclick="window.location.href='?edit=<?php echo $category['id']; ?>'">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-start justify-content-between mb-3">
                            <div class="d-flex align-items-center">
                                <div style="width: 50px; height: 50px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-right: 1rem; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);">
                                    <i class="bi bi-folder2 text-white fs-5"></i>
                                </div>
                                <div>
                                    <h6 class="card-title mb-1 fw-bold" style="color: #1e293b; font-size: 1.1rem;">
                                        <?php echo htmlspecialchars($category['name']); ?>
                                    </h6>
                                    <p class="text-muted small mb-0">
                                        <i class="bi bi-basket me-1"></i>
                                        <?php echo $ingredients_count; ?> <?php echo $ingredients_count == 1 ? 'ingredient' : 'ingredients'; ?>
                                        <?php if (isset($category['dishes_count']) && $category['dishes_count'] > 0): ?>
                                            <span class="ms-2">
                                                <i class="bi bi-egg-fried me-1"></i>
                                                <?php echo $category['dishes_count']; ?> <?php echo $category['dishes_count'] == 1 ? 'dish' : 'dishes'; ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex gap-2 justify-content-end">
                            <button class="btn btn-sm btn-primary rounded-pill px-3 shadow-sm" 
                                    onclick="event.stopPropagation(); window.location.href='?edit=<?php echo $category['id']; ?>'"
                                    title="Edit Category"
                                    style="font-weight: 600;">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </button>
                            <button class="btn btn-sm btn-danger rounded-pill px-3 shadow-sm" 
                                    onclick="event.stopPropagation(); if(confirm('Delete this category?')) window.location.href='?delete=<?php echo $category['id']; ?>'"
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
    <div id="noResultsCategories" class="text-center py-5" style="display: none;">
        <i class="bi bi-search display-4 text-muted d-block mb-3"></i>
        <h5 class="text-muted mb-2">No categories found</h5>
        <p class="text-muted">Try adjusting your search terms</p>
    </div>
<?php endif; ?>

<script>
// Search functionality for categories
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchCategories');
    const clearSearchBtn = document.getElementById('clearSearchCategories');
    const categoriesList = document.getElementById('categoriesList');
    const noResults = document.getElementById('noResultsCategories');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const categoryItems = document.querySelectorAll('.category-item');
            let visibleCount = 0;
            
            if (searchTerm === '') {
                // Show all categories
                categoryItems.forEach(item => {
                    item.style.display = '';
                    visibleCount++;
                });
                clearSearchBtn.style.display = 'none';
                if (noResults) noResults.style.display = 'none';
                if (categoriesList) categoriesList.style.display = '';
            } else {
                // Filter categories
                categoryItems.forEach(item => {
                    const categoryName = item.getAttribute('data-name') || '';
                    const categoryDescription = item.getAttribute('data-description') || '';
                    
                    if (categoryName.includes(searchTerm) || categoryDescription.includes(searchTerm)) {
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
                    if (categoriesList) categoriesList.style.display = 'none';
                } else {
                    if (noResults) noResults.style.display = 'none';
                    if (categoriesList) categoriesList.style.display = '';
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

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
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $dish_id = $_POST['dish_id'] ?? null;
    $ingredients = $_POST['ingredients'] ?? [];
    $quantities = $_POST['quantities'] ?? [];
    $number_of_persons = intval($_POST['number_of_persons'] ?? 1);
    $base_quantity = floatval($_POST['base_quantity'] ?? 1);
    $base_unit = trim($_POST['base_unit'] ?? 'kg');
    
    // Translate to Urdu if current language is Urdu
    $name = translateForDatabase($name);
    $description = translateForDatabase($description);
    
    // Handle image upload
    $image_path = null;
    if (isset($_FILES['dish_image']) && $_FILES['dish_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/dishes/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES['dish_image']['name'], PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array($file_extension, $allowed_extensions)) {
            $file_name = uniqid('dish_', true) . '.' . $file_extension;
            $target_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['dish_image']['tmp_name'], $target_path)) {
                $image_path = 'uploads/dishes/' . $file_name;
            } else {
                $error = 'Failed to upload image.';
            }
        } else {
            $error = 'Invalid image format. Allowed formats: JPG, JPEG, PNG, GIF, WEBP';
        }
    } elseif (isset($_FILES['dish_image']) && $_FILES['dish_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $error = 'Error uploading image: ' . $_FILES['dish_image']['error'];
    }
    
    if (empty($name)) {
        $error = 'Dish name is required.';
    } else {
        // Check if category_id allows NULL, if not modify it
        $check_category_null = $conn->query("SHOW COLUMNS FROM dishes WHERE Field = 'category_id'");
        if ($check_category_null && $check_category_null->num_rows > 0) {
            $column_info = $check_category_null->fetch_assoc();
            if ($column_info['Null'] === 'NO') {
                // First, get and drop all foreign key constraints on category_id
                $fk_check = $conn->query("SELECT CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'dishes' 
                    AND COLUMN_NAME = 'category_id' 
                    AND REFERENCED_TABLE_NAME IS NOT NULL");
                if ($fk_check && $fk_check->num_rows > 0) {
                    while ($fk_row = $fk_check->fetch_assoc()) {
                        $fk_name = $fk_row['CONSTRAINT_NAME'];
                        $conn->query("ALTER TABLE dishes DROP FOREIGN KEY `" . $conn->real_escape_string($fk_name) . "`");
                    }
                }
                // Now modify the column to allow NULL
                $conn->query("ALTER TABLE dishes MODIFY COLUMN category_id INT(11) NULL");
                // Re-add foreign key constraint (foreign keys can work with NULL values)
                // Check if constraint already exists before adding
                $existing_fk = $conn->query("SELECT CONSTRAINT_NAME 
                    FROM information_schema.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND TABLE_NAME = 'dishes' 
                    AND CONSTRAINT_NAME = 'dishes_category_fk'");
                if (!$existing_fk || $existing_fk->num_rows == 0) {
                    $conn->query("ALTER TABLE dishes ADD CONSTRAINT dishes_category_fk 
                        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE");
                }
            }
        }
        
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
        
        // Check if image column exists, if not add it
        $check_image = $conn->query("SHOW COLUMNS FROM dishes LIKE 'image'");
        if ($check_image->num_rows == 0) {
            $conn->query("ALTER TABLE dishes ADD COLUMN image VARCHAR(255) DEFAULT NULL AFTER base_unit");
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            if ($dish_id) {
                // Get existing image path if no new image uploaded
                if ($image_path === null) {
                    $existing_stmt = $conn->prepare("SELECT image FROM dishes WHERE id = ?");
                    $existing_stmt->bind_param("i", $dish_id);
                    $existing_stmt->execute();
                    $existing_result = $existing_stmt->get_result();
                    if ($existing_row = $existing_result->fetch_assoc()) {
                        $image_path = $existing_row['image'];
                    }
                    $existing_stmt->close();
                } else {
                    // Delete old image if new one is uploaded
                    $old_stmt = $conn->prepare("SELECT image FROM dishes WHERE id = ?");
                    $old_stmt->bind_param("i", $dish_id);
                    $old_stmt->execute();
                    $old_result = $old_stmt->get_result();
                    if ($old_row = $old_result->fetch_assoc() && !empty($old_row['image'])) {
                        $old_image_path = __DIR__ . '/../' . $old_row['image'];
                        if (file_exists($old_image_path)) {
                            unlink($old_image_path);
                        }
                    }
                    $old_stmt->close();
                }
                
                // Update existing dish
                if ($category_id === null) {
                    $stmt = $conn->prepare("UPDATE dishes SET name = ?, description = ?, category_id = NULL, number_of_persons = ?, base_quantity = ?, base_unit = ?, image = ? WHERE id = ?");
                    $stmt->bind_param("ssidssi", $name, $description, $number_of_persons, $base_quantity, $base_unit, $image_path, $dish_id);
                } else {
                    $stmt = $conn->prepare("UPDATE dishes SET name = ?, description = ?, category_id = ?, number_of_persons = ?, base_quantity = ?, base_unit = ?, image = ? WHERE id = ?");
                    $stmt->bind_param("ssiidssi", $name, $description, $category_id, $number_of_persons, $base_quantity, $base_unit, $image_path, $dish_id);
                }
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
                if ($category_id === null) {
                    $stmt = $conn->prepare("INSERT INTO dishes (name, description, category_id, number_of_persons, base_quantity, base_unit, image) VALUES (?, ?, NULL, ?, ?, ?, ?)");
                    $stmt->bind_param("ssidss", $name, $description, $number_of_persons, $base_quantity, $base_unit, $image_path);
                } else {
                    $stmt = $conn->prepare("INSERT INTO dishes (name, description, category_id, number_of_persons, base_quantity, base_unit, image) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssiidss", $name, $description, $category_id, $number_of_persons, $base_quantity, $base_unit, $image_path);
                }
                if (!$stmt->execute()) {
                    throw new Exception('Failed to insert dish: ' . $stmt->error);
                }
                $current_dish_id = $stmt->insert_id;
                if (!$current_dish_id) {
                    throw new Exception('Failed to get dish ID after insert');
                }
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
            } else {
                // Redirect after creating new dish to refresh the list (go to page 1 to show newest)
                header('Location: dishes.php?success=1&created=1&page=1');
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
    } elseif (isset($_GET['created'])) {
        $success = 'Dish created successfully!';
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

// Get all dish categories for dropdown with error handling (only categories used by dishes)
$categories = [];
$result = $conn->query("SELECT DISTINCT c.* 
    FROM categories c 
    INNER JOIN dishes d ON d.category_id = c.id 
    ORDER BY c.name");
if ($result && $result->num_rows > 0) {
    $categories = $result->fetch_all(MYSQLI_ASSOC);
}

// Pagination settings
$items_per_page = 12; // Number of dishes per page
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Get total count of dishes
$total_dishes = 0;
$count_result = $conn->query("SELECT COUNT(*) as total FROM dishes");
if ($count_result && $count_result->num_rows > 0) {
    $total_dishes = $count_result->fetch_assoc()['total'] ?? 0;
}

// Calculate pagination
$total_pages = ceil($total_dishes / $items_per_page);
$offset = ($current_page - 1) * $items_per_page;

// Get paginated dishes with category names and ingredient counts with error handling
$dishes = [];
$items_per_page_int = intval($items_per_page);
$offset_int = intval($offset);
$result = $conn->query("SELECT d.*, c.name as category_name,
    (SELECT COUNT(*) FROM dish_ingredients WHERE dish_id = d.id) as ingredients_count
    FROM dishes d 
    LEFT JOIN categories c ON d.category_id = c.id 
    ORDER BY d.id DESC, COALESCE(c.name, 'zzz'), d.name
    LIMIT $items_per_page_int OFFSET $offset_int");
if ($result && $result->num_rows > 0) {
    $dishes = $result->fetch_all(MYSQLI_ASSOC);
}

$conn->close();

$pageTitle = 'Manage Dishes';
include __DIR__ . '/../includes/header.php';
?>

<link rel="stylesheet" href="../assets/css/user-friendly.css">

<style>
.page-header-modern {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.1) 0%, rgba(139, 92, 246, 0.1) 50%, rgba(240, 147, 251, 0.1) 100%);
    border-radius: 20px;
    padding: 2rem;
    margin-bottom: 2rem;
    border: 1px solid rgba(99, 102, 241, 0.2);
}

.dish-card {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 16px;
    position: relative;
    overflow: hidden;
}

.dish-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    opacity: 0;
}

.dish-card:hover::before {
    opacity: 1;
}

.dish-card:hover {
    box-shadow: 0 12px 35px rgba(6, 182, 212, 0.25);
    border-color: rgba(6, 182, 212, 0.3);
}

.search-modern {
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(226, 232, 240, 0.8);
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
}

.search-modern:focus-within {
    border-color: rgba(6, 182, 212, 0.3);
    box-shadow: 0 4px 20px rgba(6, 182, 212, 0.2);
}
</style>

<!-- Modern Page Header -->
<div class="page-header-modern">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
            <h1 class="display-6 fw-bold mb-2" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                <i class="bi bi-egg-fried me-3"></i>Manage Dishes
            </h1>
            <p class="lead mb-0" style="color: #64748b;">
                <i class="bi bi-info-circle me-2"></i>
                <?php echo $total_dishes; ?> <?php echo $total_dishes == 1 ? 'dish' : 'dishes'; ?> in your menu
            </p>
        </div>
        <div>
            <button type="button" class="btn btn-primary rounded-pill shadow-lg px-4" onclick="printDishesPage()" style="font-weight: 600;">
                <i class="bi bi-printer-fill me-2"></i>Print Page
            </button>
        </div>
    </div>
</div>

<!-- Print Banner (Hidden on screen, shown when printing) -->
<div class="print-banner no-print-screen">
    <img src="../images/<?php echo urlencode('ینگ کوکنگ وچائنیز فوڈ اسپیشلسٹ.png'); ?>" alt="Advertisement Banner" class="print-banner-image" style="width: 100%; height: auto; display: block;">
</div>

<!-- Contact Information (Shown below banner when printing) -->
<div class="banner-contact-info no-print-screen">
    <h4>Contact Information</h4>
    <p><strong>حسن کک:</strong> 0308-6977778, 0312-6396398</p>
    <p><strong>سلیم:</strong> 0308-6977778, 0312-6396398</p>
</div>

<!-- Number of Persons Section (Shown below banner when printing) -->
<div class="persons-info-section no-print-screen">
    <h4>Number of Persons per Dish</h4>
    <div class="persons-list">
        <?php if (!empty($dishes)): ?>
            <?php foreach ($dishes as $dish): ?>
                <div class="person-item">
                    <span class="dish-name"><?php echo htmlspecialchars($dish['name']); ?></span>
                    <span class="person-count"><?php echo intval($dish['number_of_persons'] ?? 1); ?> <?php echo (intval($dish['number_of_persons'] ?? 1) == 1) ? 'Person' : 'Persons'; ?></span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-muted">No dishes available</p>
        <?php endif; ?>
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
        <div class="card shadow-lg border-0" style="border-radius: 20px; overflow: hidden;">
            <div class="card-header text-white py-4" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border: none;">
                <h5 class="mb-0 fw-bold">
                    <i class="bi bi-<?php echo $edit_dish ? 'pencil-square' : 'plus-circle-fill'; ?> me-2"></i>
                    <?php echo $edit_dish ? 'Edit Dish' : 'Add New Dish'; ?>
                </h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="" id="dishForm" enctype="multipart/form-data">
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
                            
                            <!-- Description -->
                            <div class="mb-3">
                                <label for="description" class="form-label fw-semibold">
                                    <i class="bi bi-card-text me-1 text-primary"></i>
                                    Description
                                </label>
                                <textarea class="form-control" id="description" name="description" rows="3"
                                          placeholder="Describe this dish, its taste, and cooking style..."><?php echo htmlspecialchars($edit_dish['description'] ?? ''); ?></textarea>
                            </div>
                            
                            <!-- Category -->
                            <div class="mb-3">
                                <label for="category_id" class="form-label fw-semibold">
                                    <i class="bi bi-folder me-1 text-primary"></i>
                                    Category
                                </label>
                                <div class="d-flex gap-2">
                                    <div class="searchable-select-wrapper flex-grow-1">
                                        <input type="text" class="form-control searchable-select-input" 
                                               placeholder="Search categories..." 
                                               autocomplete="off"
                                               style="display: none;">
                                        <select class="form-select searchable-select" id="category_id" name="category_id">
                                            <option value=""><?php echo empty($edit_dish['category_id']) ? 'No Category - Click to Add' : 'Select Category'; ?></option>
                                            <?php foreach ($categories as $cat): ?>
                                                <option value="<?php echo $cat['id']; ?>" 
                                                        <?php echo (isset($edit_dish['category_id']) && $edit_dish['category_id'] == $cat['id']) ? 'selected' : ''; ?>
                                                        data-search="<?php echo htmlspecialchars(strtolower($cat['name'])); ?>">
                                                    <?php echo htmlspecialchars($cat['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addCategoryModal" data-context="dish" title="Add New Category">
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                </div>
                                <?php if (empty($edit_dish['category_id'])): ?>
                                    <small class="form-text text-warning">
                                        <i class="bi bi-exclamation-triangle me-1"></i>This dish has no category. Please add one.
                                    </small>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Dish Image -->
                            <div class="mb-3">
                                <label for="dish_image" class="form-label fw-semibold">
                                    <i class="bi bi-image me-1 text-primary"></i>
                                    Dish Image
                                </label>
                                <input type="file" class="form-control" id="dish_image" name="dish_image" 
                                       accept="image/jpeg,image/jpg,image/png,image/gif,image/webp"
                                       onchange="previewImage(this)">
                                <small class="form-text text-muted">Allowed formats: JPG, JPEG, PNG, GIF, WEBP</small>
                                <?php if ($edit_dish && !empty($edit_dish['image'])): ?>
                                    <div class="mt-2">
                                        <img src="../<?php echo htmlspecialchars($edit_dish['image']); ?>" 
                                             alt="<?php echo htmlspecialchars($edit_dish['name']); ?>" 
                                             id="current_image_preview"
                                             class="img-thumbnail mt-2" 
                                             style="max-width: 200px; max-height: 200px; object-fit: cover;">
                                        <p class="text-muted small mb-0 mt-1">Current image</p>
                                    </div>
                                <?php endif; ?>
                                <div id="image_preview" class="mt-2" style="display: none;">
                                    <img id="preview_img" src="" alt="Preview" class="img-thumbnail" style="max-width: 200px; max-height: 200px; object-fit: cover;">
                                    <p class="text-muted small mb-0 mt-1">New image preview</p>
                                </div>
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
                                <input type="hidden" id="base_unit" name="base_unit" value="kg">
                                <input type="text" class="form-control" value="kg" disabled style="background-color: #e9ecef;">
                                <small class="form-text text-muted">Unit is fixed as kg</small>
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
        <div class="card shadow-lg border-0" style="border-radius: 20px; overflow: hidden;">
            <div class="card-header text-white py-4" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); border: none;">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <h5 class="mb-0 fw-bold">
                        <i class="bi bi-list-ul me-2"></i>
                        All Dishes <span class="badge bg-white text-info ms-2 rounded-pill px-3"><?php echo count($dishes); ?></span>
                    </h5>
                    <!-- Search Box -->
                    <div class="search-modern flex-grow-1" style="max-width: 400px;">
                        <div class="input-group border-0">
                            <span class="input-group-text bg-transparent border-0" style="color: #06b6d4;">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" class="form-control border-0 bg-transparent" id="searchDishes" 
                                   placeholder="Search dishes by name or category..." 
                                   autocomplete="off"
                                   style="box-shadow: none;">
                            <button class="btn btn-link border-0 text-muted p-2" type="button" id="clearSearch" style="display: none;">
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
                                <div class="card h-100 border-0 shadow-lg dish-card" 
                                     style="cursor: pointer;" 
                                     onclick="window.location.href='?edit=<?php echo $dish['id']; ?>'">
                                    <?php 
                                    $dish_image_path = !empty($dish['image']) ? htmlspecialchars($dish['image']) : '';
                                    $dish_image_full_path = !empty($dish_image_path) ? '../' . $dish_image_path : '';
                                    $dish_image_exists = !empty($dish_image_path) && file_exists(__DIR__ . '/../' . $dish_image_path);
                                    ?>
                                    <?php if (!empty($dish_image_path) && $dish_image_exists): ?>
                                        <div style="height: 150px; overflow: hidden; background: #f8f9fa;">
                                            <img src="<?php echo $dish_image_full_path; ?>" 
                                                 alt="<?php echo htmlspecialchars($dish['name']); ?>" 
                                                 style="width: 100%; height: 100%; object-fit: cover;"
                                                 onerror="this.parentElement.innerHTML='<div style=\'height: 150px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center;\'><i class=\'bi bi-egg-fried text-white\' style=\'font-size: 3rem;\'></i></div>'">
                                        </div>
                                    <?php else: ?>
                                        <div style="height: 150px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); display: flex; align-items: center; justify-content: center;">
                                            <i class="bi bi-egg-fried text-white" style="font-size: 3rem;"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="card-body p-3">
                                        <div class="d-flex align-items-start justify-content-between mb-2">
                                            <div class="flex-grow-1">
                                                <h6 class="card-title mb-2 fw-bold text-dark" style="font-size: 0.95rem;">
                                                    <?php echo htmlspecialchars($dish['name']); ?>
                                                </h6>
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
                    
                    <!-- Pagination Controls -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Dishes pagination" class="mt-4">
                            <ul class="pagination justify-content-center">
                                <!-- Previous Button -->
                                <li class="page-item <?php echo $current_page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo max(1, $current_page - 1); ?><?php echo isset($_GET['edit']) ? '&edit=' . intval($_GET['edit']) : ''; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                
                                <!-- Page Numbers -->
                                <?php
                                $start_page = max(1, $current_page - 2);
                                $end_page = min($total_pages, $current_page + 2);
                                
                                if ($start_page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=1<?php echo isset($_GET['edit']) ? '&edit=' . intval($_GET['edit']) : ''; ?>">1</a>
                                    </li>
                                    <?php if ($start_page > 2): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?><?php echo isset($_GET['edit']) ? '&edit=' . intval($_GET['edit']) : ''; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo isset($_GET['edit']) ? '&edit=' . intval($_GET['edit']) : ''; ?>"><?php echo $total_pages; ?></a>
                                    </li>
                                <?php endif; ?>
                                
                                <!-- Next Button -->
                                <li class="page-item <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo min($total_pages, $current_page + 1); ?><?php echo isset($_GET['edit']) ? '&edit=' . intval($_GET['edit']) : ''; ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <div class="text-center mt-2 text-muted">
                            <small>Showing <?php echo count($dishes); ?> of <?php echo $total_dishes; ?> dishes (Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>)</small>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.searchable-select-wrapper {
    position: relative;
    cursor: pointer;
    min-height: 38px;
}

.searchable-select-wrapper:not(.active) {
    cursor: pointer;
}

.searchable-select-wrapper:not(.active) .searchable-select {
    cursor: pointer;
}

.searchable-select-input {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    border-radius: 0.375rem;
    background: white;
    font-size: 1rem;
    padding: 0.5rem 0.75rem;
    height: calc(1.5em + 1rem + 2px);
    border: 1px solid #ced4da;
    width: 100%;
}

.searchable-select-input:focus {
    border-color: #86b7fe;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    outline: none;
}

.searchable-select {
    position: relative;
    z-index: 1;
    pointer-events: auto;
}

.searchable-select-wrapper.active .searchable-select {
    display: none !important;
    pointer-events: none;
}

.searchable-select-wrapper.active .searchable-select-input {
    display: block !important;
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
    border-bottom: none;
}

/* When not active, hide search input and show select */
.searchable-select-wrapper:not(.active) .searchable-select-input {
    display: none !important;
}

.ingredient-row-item .searchable-select-wrapper {
    margin-bottom: 0;
}

/* Custom dropdown styling - clean and simple */
.searchable-select-dropdown {
    position: absolute !important;
    top: 100% !important;
    left: 0 !important;
    right: 0 !important;
    width: 100% !important;
    background: white !important;
    border: 1px solid #ced4da !important;
    border-top: none !important;
    border-radius: 0 0 0.375rem 0.375rem !important;
    max-height: 350px !important;
    min-height: 120px !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    z-index: 1001 !important;
    display: none !important;
    margin-top: -1px !important;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15) !important;
    padding: 0 !important;
}

.searchable-select-dropdown::-webkit-scrollbar {
    width: 6px;
}

.searchable-select-dropdown::-webkit-scrollbar-track {
    background: #f8f9fa;
}

.searchable-select-dropdown::-webkit-scrollbar-thumb {
    background: #dee2e6;
    border-radius: 3px;
}

.searchable-select-dropdown::-webkit-scrollbar-thumb:hover {
    background: #adb5bd;
}

.searchable-select-option {
    padding: 10px 12px !important;
    cursor: pointer !important;
    font-size: 0.95rem !important;
    line-height: 1.5 !important;
    color: #495057 !important;
    background: white !important;
    transition: background-color 0.15s ease !important;
    border: none !important;
    text-align: left !important;
    width: 100% !important;
}

.searchable-select-option:hover {
    background-color: #f8f9fa !important;
    color: #212529 !important;
}

.searchable-select-option.selected {
    background-color: #e9ecef !important;
    color: #212529 !important;
    font-weight: normal !important;
}

/* No results message */
.searchable-select-dropdown .no-results {
    padding: 16px 12px !important;
    text-align: center !important;
    color: #6c757d !important;
    font-size: 0.9rem !important;
    font-style: italic !important;
    background: white !important;
}

/* Banner Styles (for screen and print) */
.print-banner.no-print-screen {
    display: none;
}

.print-banner {
    width: 100%;
    margin-bottom: 20px;
    overflow: visible;
    box-sizing: border-box;
}

.print-banner-image {
    width: 100%;
    height: auto;
    display: block;
}

.banner-contact-info.no-print-screen {
    display: none;
}

.banner-contact-info {
    margin-top: 15px;
    padding: 10px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    text-align: center;
}

.banner-contact-info h4 {
    font-size: 14px;
    font-weight: bold;
    margin-bottom: 8px;
    color: #000;
}

.banner-contact-info p {
    font-size: 12px;
    margin: 3px 0;
    color: #333;
}

.persons-info-section.no-print-screen {
    display: none;
}

.persons-info-section {
    margin-top: 20px;
    padding: 15px;
    background: #f8f9fa;
    border: 2px solid #dee2e6;
    border-radius: 5px;
    page-break-inside: avoid;
}

.persons-info-section h4 {
    font-size: 16px;
    font-weight: bold;
    margin-bottom: 12px;
    color: #000;
    text-align: center;
    border-bottom: 2px solid #dee2e6;
    padding-bottom: 8px;
}

.persons-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 10px;
}

.person-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    background: white;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

.person-item .dish-name {
    font-weight: 600;
    color: #333;
    flex: 1;
}

.person-item .person-count {
    font-weight: bold;
    color: #0d6efd;
    font-size: 14px;
}

/* Print Styles - Preserve Colors */
@media print {
    @page {
        size: A4;
        margin: 0.5cm;
    }
    
    /* Preserve colors when printing - CRITICAL for color printing */
    * {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    
    html, body {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    
    body {
        margin: 0 !important;
        padding: 0 !important;
        font-size: 12px !important;
    }
    
    /* Hide non-printable elements */
    .no-print,
    button,
    .btn,
    .card-header,
    .alert,
    .searchable-select-wrapper,
    .input-group {
        display: none !important;
    }
    
    /* Show print-only elements */
    .no-print-screen {
        display: block !important;
        visibility: visible !important;
    }
    
    .print-banner.no-print-screen {
        display: flex !important;
        visibility: visible !important;
    }
    
    .persons-info-section.no-print-screen {
        display: block !important;
        visibility: visible !important;
    }
    
    /* Print Banner Styles */
    .print-banner {
        display: block !important;
        width: 100% !important;
        margin-bottom: 20px !important;
        overflow: visible !important;
        box-sizing: border-box !important;
        page-break-inside: avoid !important;
        page-break-after: avoid !important;
        visibility: visible !important;
    }
    
    .print-banner-image {
        width: 100% !important;
        height: auto !important;
        display: block !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        color-adjust: exact !important;
    }
    
    .banner-contact-info {
        display: block !important;
        visibility: visible !important;
    }
    
    .persons-info-section {
        display: block !important;
        visibility: visible !important;
        margin-top: 20px !important;
        padding: 15px !important;
        background: #f8f9fa !important;
        border: 2px solid #dee2e6 !important;
        border-radius: 5px !important;
        page-break-inside: avoid !important;
    }
    
    .persons-info-section h4 {
        font-size: 16px !important;
        font-weight: bold !important;
        margin-bottom: 12px !important;
        color: #000 !important;
        text-align: center !important;
        border-bottom: 2px solid #dee2e6 !important;
        padding-bottom: 8px !important;
    }
    
    .persons-list {
        display: grid !important;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)) !important;
        gap: 10px !important;
    }
    
    .person-item {
        display: flex !important;
        justify-content: space-between !important;
        align-items: center !important;
        padding: 8px 12px !important;
        background: white !important;
        border: 1px solid #dee2e6 !important;
        border-radius: 4px !important;
        page-break-inside: avoid !important;
    }
    
    .person-item .dish-name {
        font-weight: 600 !important;
        color: #333 !important;
        flex: 1 !important;
    }
    
    .person-item .person-count {
        font-weight: bold !important;
        color: #0d6efd !important;
        font-size: 14px !important;
    }
    
    .banner-address-text {
        color: #FFFFFF !important;
        font-size: 11px !important;
        font-weight: 600 !important;
        line-height: 1.5 !important;
        font-family: 'Arial', 'Noto Sans Arabic', 'Segoe UI', 'Tahoma', sans-serif !important;
    }
    
    .banner-contact-info {
        margin-top: 15px !important;
        padding: 10px !important;
        background: #f8f9fa !important;
        border: 1px solid #dee2e6 !important;
        border-radius: 5px !important;
        text-align: center !important;
        page-break-inside: avoid !important;
    }
    
    .banner-contact-info h4 {
        font-size: 14px !important;
        font-weight: bold !important;
        margin-bottom: 8px !important;
        color: #000 !important;
    }
    
    .banner-contact-info p {
        font-size: 12px !important;
        margin: 3px 0 !important;
        color: #333 !important;
    }
    
    /* Card styles for print */
    .card {
        border: 1px solid #dee2e6 !important;
        page-break-inside: avoid !important;
        margin-bottom: 15px !important;
    }
    
    .card-body {
        padding: 15px !important;
    }
    
    /* Table styles */
    table {
        width: 100% !important;
        border-collapse: collapse !important;
    }
    
    table th,
    table td {
        border: 1px solid #dee2e6 !important;
        padding: 8px !important;
    }
}
</style>

<script>
// Searchable Select Functionality
function initSearchableSelect(selectElement) {
    if (!selectElement || selectElement.classList.contains('searchable-initialized')) {
        return;
    }
    
    selectElement.classList.add('searchable-initialized');
    const wrapper = selectElement.closest('.searchable-select-wrapper');
    if (!wrapper) return;
    
    let searchInput = wrapper.querySelector('.searchable-select-input');
    if (!searchInput) {
        searchInput = document.createElement('input');
        searchInput.type = 'text';
        searchInput.className = 'form-control searchable-select-input';
        searchInput.placeholder = 'Search...';
        searchInput.autocomplete = 'off';
        searchInput.style.display = 'none';
        wrapper.insertBefore(searchInput, selectElement);
    }
    
    // Show search on focus or click
    function showSearch() {
        if (wrapper.classList.contains('active')) {
            return; // Already active
        }
        
        wrapper.classList.add('active');
        searchInput.style.display = 'block';
        searchInput.value = ''; // Clear search to show all options
        searchInput.placeholder = 'Search...';
        
        // Show all options initially
        const options = selectElement.querySelectorAll('option:not([value=""])');
        let visibleCount = 0;
        options.forEach(option => {
            option.style.display = '';
            visibleCount++;
        });
        
        // Show all options in custom dropdown immediately
        updateCustomDropdown(visibleCount);
        
        // Focus the search input and ensure dropdown is visible
        setTimeout(() => {
            searchInput.focus();
            searchInput.select();
            // Double-check dropdown is visible after a brief delay
            if (customDropdown && visibleCount > 0) {
                customDropdown.style.display = 'block';
            }
        }, 50);
    }
    
    // Primary click handler on wrapper - catches all clicks
    wrapper.addEventListener('click', function(e) {
        // Don't trigger if clicking inside the custom dropdown
        const dropdown = wrapper.querySelector('.searchable-select-dropdown');
        if (dropdown && dropdown.contains(e.target)) {
            return;
        }
        
        // Don't trigger if clicking on the search input (let it handle its own focus)
        if (e.target === searchInput) {
            return;
        }
        
        // If not active, show search for any click on wrapper/select
        if (!wrapper.classList.contains('active')) {
            e.preventDefault();
            e.stopPropagation();
            showSearch();
        }
    });
    
    // Also handle mousedown on select to catch it early
    selectElement.addEventListener('mousedown', function(e) {
        if (!wrapper.classList.contains('active')) {
            e.preventDefault();
            e.stopPropagation();
            showSearch();
            return false;
        }
    });
    
    // Handle focus event
    selectElement.addEventListener('focus', function(e) {
        if (!wrapper.classList.contains('active')) {
            showSearch();
        }
    });
    
    // Backup click handler on select
    selectElement.addEventListener('click', function(e) {
        if (!wrapper.classList.contains('active')) {
            e.preventDefault();
            e.stopPropagation();
            showSearch();
            return false;
        }
    });
    
    // Close on Escape key
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            hideSearch();
            selectElement.blur();
        }
    });
    
    // Filter options on search and show dropdown
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        const options = selectElement.querySelectorAll('option');
        let visibleCount = 0;
        
        options.forEach(option => {
            const searchText = option.getAttribute('data-search') || option.textContent.toLowerCase();
            if (searchText.includes(searchTerm) || option.value === '') {
                option.style.display = '';
                if (option.value !== '') {
                    visibleCount++;
                }
            } else {
                option.style.display = 'none';
            }
        });
        
        // Show filtered options in a custom dropdown
        updateCustomDropdown(visibleCount);
        
        // Ensure dropdown is visible when typing
        if (customDropdown && wrapper.classList.contains('active')) {
            customDropdown.style.display = 'block';
        }
    });
    
    // Create custom dropdown for displaying filtered options
    let customDropdown = null;
    function updateCustomDropdown(visibleCount) {
        if (!customDropdown) {
            customDropdown = document.createElement('div');
            customDropdown.className = 'searchable-select-dropdown';
            wrapper.appendChild(customDropdown);
        }
        
        // Always show dropdown when active
        if (wrapper.classList.contains('active')) {
            customDropdown.innerHTML = '';
            const options = selectElement.querySelectorAll('option:not([value=""])');
            let hasVisible = false;
            
            options.forEach(option => {
                if (option.style.display !== 'none') {
                    hasVisible = true;
                    const item = document.createElement('div');
                    item.className = 'searchable-select-option';
                    
                    // Add selected class if this is the current value
                    if (selectElement.value === option.value) {
                        item.classList.add('selected');
                    }
                    
                    // Simple text content - clean and minimal
                    item.textContent = option.textContent.trim();
                    
                    item.addEventListener('click', function(e) {
                        e.stopPropagation();
                        selectElement.value = option.value;
                        selectElement.dispatchEvent(new Event('change', { bubbles: true }));
                        hideSearch();
                    });
                    
                    customDropdown.appendChild(item);
                }
            });
            
            // Show dropdown if we have visible options
            if (hasVisible && visibleCount > 0) {
                customDropdown.style.display = 'block';
            } else if (searchInput && searchInput.value.trim()) {
                // Show no results message if searching but nothing found
                customDropdown.innerHTML = '<div class="no-results">No results found</div>';
                customDropdown.style.display = 'block';
            } else if (visibleCount > 0) {
                // Show all options if no search term and we have options
                customDropdown.style.display = 'block';
            } else {
                // Even if no visible options, show dropdown if active (might be loading)
                if (wrapper.classList.contains('active')) {
                    customDropdown.innerHTML = '<div class="no-results">No options available</div>';
                    customDropdown.style.display = 'block';
                } else {
                    customDropdown.style.display = 'none';
                }
            }
        } else {
            // Hide dropdown when not active
            customDropdown.style.display = 'none';
        }
    }
    
    function hideCustomDropdown() {
        if (customDropdown) {
            customDropdown.style.display = 'none';
        }
    }
    
    // Handle keyboard navigation in search
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'Enter') {
            e.preventDefault();
            selectElement.focus();
            // Trigger keyboard event on select
            const keyEvent = new KeyboardEvent('keydown', {
                key: e.key,
                bubbles: true,
                cancelable: true
            });
            selectElement.dispatchEvent(keyEvent);
        }
    });
    
    // Hide search when clicking outside or on option select
    selectElement.addEventListener('change', function() {
        hideSearch();
    });
    
    function hideSearch() {
        wrapper.classList.remove('active');
        searchInput.style.display = 'none';
        searchInput.value = '';
        selectElement.size = 1;
        hideCustomDropdown();
        // Reset all options visibility
        const options = selectElement.querySelectorAll('option');
        options.forEach(option => {
            option.style.display = '';
        });
    }
    
    // Hide search when clicking outside (using event delegation)
    if (!window.searchableSelectClickHandler) {
        window.searchableSelectClickHandler = function(e) {
            document.querySelectorAll('.searchable-select-wrapper.active').forEach(wrapper => {
                if (!wrapper.contains(e.target)) {
                    const searchInput = wrapper.querySelector('.searchable-select-input');
                    const selectElement = wrapper.querySelector('.searchable-select');
                    const customDropdown = wrapper.querySelector('.searchable-select-dropdown');
                    wrapper.classList.remove('active');
                    if (searchInput) {
                        searchInput.style.display = 'none';
                        searchInput.value = '';
                    }
                    if (customDropdown) {
                        customDropdown.style.display = 'none';
                    }
                    if (selectElement) {
                        selectElement.size = 1;
                        // Reset all options visibility
                        const options = selectElement.querySelectorAll('option');
                        options.forEach(option => {
                            option.style.display = '';
                        });
                    }
                }
            });
        };
        document.addEventListener('click', window.searchableSelectClickHandler);
    }
}

// Initialize all searchable selects on page load
document.addEventListener('DOMContentLoaded', function() {
    // Category field removed - no longer initializing dish category select
    
    // Initialize existing ingredient selects and dish category select
    document.querySelectorAll('.ingredient-category-select, .ingredient-select, #category_id').forEach(select => {
        initSearchableSelect(select);
    });
    
    // Use MutationObserver to initialize dynamically added selects
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) { // Element node
                    const selects = node.querySelectorAll ? node.querySelectorAll('.ingredient-category-select, .ingredient-select') : [];
                    selects.forEach(select => {
                        initSearchableSelect(select);
                    });
                    // Also check if the node itself is a select
                    if (node.classList && (node.classList.contains('ingredient-category-select') || node.classList.contains('ingredient-select'))) {
                        initSearchableSelect(node);
                    }
                }
            });
        });
    });
    
    const container = document.getElementById('ingredientsContainer');
    if (container) {
        observer.observe(container, { childList: true, subtree: true });
    }
});

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
            const searchText = catName.toLowerCase();
            categoryOptions += `<option value="${catId}" data-search="${searchText}">${catName}</option>`;
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
                                    <div class="d-flex gap-2">
                                        <div class="searchable-select-wrapper flex-grow-1">
                                            <input type="text" class="form-control searchable-select-input" 
                                                   placeholder="Search..." 
                                                   autocomplete="off"
                                                   style="display: none;">
                                            <select class="form-select searchable-select ingredient-category-select" name="ingredient_categories[]" onchange="loadIngredientsForRow('${rowId}', this.value)" required>
                                                ${categoryOptions}
                                            </select>
                                        </div>
                                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addCategoryModal" data-context="ingredient" data-row-id="${rowId}" title="Add New Category">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label fw-semibold mb-2 d-block">
                                        <i class="bi bi-basket-fill me-2 text-success"></i>
                                        Ingredient <span class="text-danger">*</span>
                                    </label>
                                    <div class="d-flex gap-2">
                                        <div class="searchable-select-wrapper flex-grow-1">
                                            <input type="text" class="form-control searchable-select-input" 
                                                   placeholder="Search..." 
                                                   autocomplete="off"
                                                   style="display: none;">
                                            <select class="form-select searchable-select ingredient-select" name="ingredients[]" required>
                                                <option value="">Select Category First</option>
                                            </select>
                                        </div>
                                        <button type="button" class="btn btn-success btn-sm" onclick="openAddIngredientModal('${rowId}')" title="Add New Ingredient">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>
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
                            <option value="گچھی">گچھی</option>
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
    
    // Initialize searchable selects for the new row
    const newCategorySelect = row.querySelector('.ingredient-category-select');
    const newIngredientSelect = row.querySelector('.ingredient-select');
    if (newCategorySelect) initSearchableSelect(newCategorySelect);
    if (newIngredientSelect) initSearchableSelect(newIngredientSelect);
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
            console.log('Ingredients loaded:', Object.keys(data).length, 'categories');
            // Refresh all category dropdowns
            refreshCategoryDropdowns();
            // Also refresh any existing ingredient rows
            document.querySelectorAll('.ingredient-category-select').forEach(select => {
                if (select.value) {
                    const row = select.closest('.ingredient-row-item');
                    if (row) {
                        const rowId = row.id;
                        loadIngredientsForRow(rowId, select.value);
                    }
                }
            });
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
            const searchText = catName.toLowerCase();
            categoryOptions += `<option value="${catId}" data-search="${searchText}" ${selected}>${catName}</option>`;
        }
        select.innerHTML = categoryOptions;
        
        // Re-initialize searchable select after updating options
        initSearchableSelect(select);
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
        const searchText = (ing.name + unitText).toLowerCase();
        options += `<option value="${ing.id}" data-search="${searchText}">${ing.name}${unitText}</option>`;
    });
    
    ingredientSelect.innerHTML = options;
    ingredientSelect.disabled = false;
    
    // Re-initialize searchable select for the new options
    initSearchableSelect(ingredientSelect);
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
                    const searchText = catName.toLowerCase();
                    categoryOptions += `<option value="${catId}" data-search="${searchText}" ${selected}>${catName}</option>`;
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
                        const searchText = (ing.name + unitText).toLowerCase();
                        ingredientOptions += `<option value="${ing.id}" data-search="${searchText}" ${selected}>${ing.name}${unitText}</option>`;
                    });
                } else {
                    // If ingredient not found in category, still show it
                    ingredientOptions += `<option value="${ingredientId}" data-search="ingredient #${ingredientId}" selected>Ingredient #${ingredientId}</option>`;
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
                    {value: 'oz_fluid', label: 'fl oz'},
                    {value: 'گچھی', label: 'گچھی'}
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
                                    <div class="d-flex gap-2">
                                        <div class="searchable-select-wrapper flex-grow-1">
                                            <input type="text" class="form-control searchable-select-input" 
                                                   placeholder="Search..." 
                                                   autocomplete="off"
                                                   style="display: none;">
                                            <select class="form-select searchable-select ingredient-category-select" name="ingredient_categories[]" onchange="loadIngredientsForRow('${rowId}', this.value)" required>
                                                ${categoryOptions}
                                            </select>
                                        </div>
                                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addCategoryModal" data-context="ingredient" data-row-id="${rowId}" title="Add New Category">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label fw-semibold mb-2 d-block">
                                        <i class="bi bi-basket-fill me-2 text-success"></i>
                                        Ingredient <span class="text-danger">*</span>
                                    </label>
                                    <div class="d-flex gap-2">
                                        <div class="searchable-select-wrapper flex-grow-1">
                                            <input type="text" class="form-control searchable-select-input" 
                                                   placeholder="Search..." 
                                                   autocomplete="off"
                                                   style="display: none;">
                                            <select class="form-select searchable-select ingredient-select" name="ingredients[]" required>
                                                ${ingredientOptions}
                                            </select>
                                        </div>
                                        <button type="button" class="btn btn-success btn-sm" onclick="openAddIngredientModal('${rowId}')" title="Add New Ingredient">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </div>
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
                
                // Initialize searchable selects for the new row
                const newCategorySelect = row.querySelector('.ingredient-category-select');
                const newIngredientSelect = row.querySelector('.ingredient-select');
                if (newCategorySelect) initSearchableSelect(newCategorySelect);
                if (newIngredientSelect) initSearchableSelect(newIngredientSelect);
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

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-labelledby="addCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="addCategoryModalLabel">
                    <i class="bi bi-folder-plus me-2"></i>Select or Add Category
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Tabs -->
                <ul class="nav nav-tabs mb-3" id="categoryModalTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="browse-categories-tab" data-bs-toggle="tab" data-bs-target="#browse-categories" type="button" role="tab">
                            <i class="bi bi-list-ul me-2"></i>Browse Categories
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="add-category-tab" data-bs-toggle="tab" data-bs-target="#add-category" type="button" role="tab">
                            <i class="bi bi-plus-circle me-2"></i>Add New
                        </button>
                    </li>
                </ul>
                
                <!-- Tab Content -->
                <div class="tab-content" id="categoryModalTabContent">
                    <!-- Browse Categories Tab -->
                    <div class="tab-pane fade show active" id="browse-categories" role="tabpanel">
                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchCategoriesModal" 
                                       placeholder="Search categories...">
                            </div>
                        </div>
                        <div id="categoriesListModal" style="max-height: 400px; overflow-y: auto;" class="border rounded p-2">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="text-muted mt-2">Loading categories...</p>
                            </div>
                        </div>
                        <div id="noCategoriesFoundModal" class="text-center py-4" style="display: none;">
                            <i class="bi bi-search display-6 text-muted d-block mb-2"></i>
                            <p class="text-muted">No categories found</p>
                        </div>
                    </div>
                    
                    <!-- Add New Category Tab -->
                    <div class="tab-pane fade" id="add-category" role="tabpanel">
                        <form id="addCategoryForm">
                            <div class="mb-3">
                                <label for="newCategoryName" class="form-label fw-semibold">
                                    Category Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="newCategoryName" name="name" required 
                                       placeholder="e.g., Grains, Vegetables, Spices">
                            </div>
                            <div class="mb-3">
                                <label for="newCategoryDescription" class="form-label fw-semibold">
                                    Description (Optional)
                                </label>
                                <textarea class="form-control" id="newCategoryDescription" name="description" rows="2"
                                          placeholder="Brief description of this category"></textarea>
                            </div>
                            <div id="addCategoryError" class="alert alert-danger" style="display: none;"></div>
                            <div id="addCategorySuccess" class="alert alert-success" style="display: none;"></div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveCategoryBtn" onclick="saveCategory()" style="display: none;">
                    <i class="bi bi-check-lg me-2"></i>Add Category
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Ingredient Modal -->
<div class="modal fade" id="addIngredientModal" tabindex="-1" aria-labelledby="addIngredientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="addIngredientModalLabel">
                    <i class="bi bi-basket-fill me-2"></i>Select or Add Ingredient
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Tabs -->
                <ul class="nav nav-tabs mb-3" id="ingredientModalTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="browse-ingredients-tab" data-bs-toggle="tab" data-bs-target="#browse-ingredients" type="button" role="tab">
                            <i class="bi bi-list-ul me-2"></i>Browse Ingredients
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="add-ingredient-tab" data-bs-toggle="tab" data-bs-target="#add-ingredient" type="button" role="tab">
                            <i class="bi bi-plus-circle me-2"></i>Add New
                        </button>
                    </li>
                </ul>
                
                <!-- Tab Content -->
                <div class="tab-content" id="ingredientModalTabContent">
                    <!-- Browse Ingredients Tab -->
                    <div class="tab-pane fade show active" id="browse-ingredients" role="tabpanel">
                        <div class="mb-3">
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="bi bi-search"></i>
                                </span>
                                <input type="text" class="form-control" id="searchIngredientsModal" 
                                       placeholder="Search ingredients...">
                            </div>
                        </div>
                        <div id="ingredientsListModal" style="max-height: 400px; overflow-y: auto;" class="border rounded p-2">
                            <div class="text-center py-4">
                                <div class="spinner-border text-success" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="text-muted mt-2">Loading ingredients...</p>
                            </div>
                        </div>
                        <div id="noIngredientsFoundModal" class="text-center py-4" style="display: none;">
                            <i class="bi bi-search display-6 text-muted d-block mb-2"></i>
                            <p class="text-muted">No ingredients found</p>
                        </div>
                    </div>
                    
                    <!-- Add New Ingredient Tab -->
                    <div class="tab-pane fade" id="add-ingredient" role="tabpanel">
                        <form id="addIngredientForm">
                            <div class="mb-3">
                                <label for="newIngredientName" class="form-label fw-semibold">
                                    Ingredient Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control" id="newIngredientName" name="name" required 
                                       placeholder="e.g., Chicken Breast, Rice, Onion">
                            </div>
                            <div class="mb-3">
                                <label for="newIngredientCategory" class="form-label fw-semibold">
                                    Category <span class="text-danger">*</span>
                                </label>
                                <div class="d-flex gap-2">
                                    <select class="form-select flex-grow-1" id="newIngredientCategory" name="category_id" required>
                                        <option value="">-- Select Category --</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>">
                                                <?php echo htmlspecialchars($cat['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCategoryModal" data-bs-dismiss="modal" title="Add New Category">
                                        <i class="bi bi-plus-lg"></i>
                                    </button>
                                </div>
                            </div>
                            <div id="addIngredientError" class="alert alert-danger" style="display: none;"></div>
                            <div id="addIngredientSuccess" class="alert alert-success" style="display: none;"></div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="saveIngredientBtn" onclick="saveIngredient()" style="display: none;">
                    <i class="bi bi-check-lg me-2"></i>Add Ingredient
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Store the current row ID for adding ingredient
let currentIngredientRowId = null;
let allCategoriesData = [];
let allIngredientsData = [];
let categoryModalContext = null; // 'dish' or 'ingredient'

// Function to open add ingredient modal with row context
window.openAddIngredientModal = function(rowId) {
    currentIngredientRowId = rowId;
    const row = document.getElementById(rowId);
    if (row) {
        const categorySelect = row.querySelector('.ingredient-category-select');
        if (categorySelect && categorySelect.value) {
            document.getElementById('newIngredientCategory').value = categorySelect.value;
        }
    }
    const modal = new bootstrap.Modal(document.getElementById('addIngredientModal'));
    modal.show();
    loadIngredientsForModal();
};

// Load categories for modal
function loadCategoriesForModal() {
    const basePath = window.location.pathname.includes('/admin/') || 
                     window.location.pathname.includes('/user/') || 
                     window.location.pathname.includes('/auth/') ? '../' : '';
    
    // Determine type based on context
    const type = categoryModalContext === 'dish' ? 'dish' : (categoryModalContext === 'ingredient' ? 'ingredient' : 'all');
    const url = basePath + 'api/get_categories.php?type=' + type;
    
    fetch(url)
        .then(response => response.json())
        .then(categories => {
            allCategoriesData = categories;
            displayCategoriesInModal(categories);
        })
        .catch(error => {
            console.error('Error loading categories:', error);
            document.getElementById('categoriesListModal').innerHTML = 
                '<div class="alert alert-danger">Error loading categories. Please try again.</div>';
        });
}

// Display categories in modal
function displayCategoriesInModal(categories, searchTerm = '') {
    const container = document.getElementById('categoriesListModal');
    const noResults = document.getElementById('noCategoriesFoundModal');
    
    if (!categories || categories.length === 0) {
        container.innerHTML = '';
        noResults.style.display = 'block';
        return;
    }
    
    noResults.style.display = 'none';
    
    // Filter categories if search term provided
    const filtered = searchTerm ? 
        categories.filter(cat => 
            cat.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
            (cat.description && cat.description.toLowerCase().includes(searchTerm.toLowerCase()))
        ) : categories;
    
    if (filtered.length === 0) {
        container.innerHTML = '';
        noResults.style.display = 'block';
        return;
    }
    
    container.innerHTML = '<div class="row g-2">' + 
        filtered.map(cat => `
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border shadow-sm category-card-modal" 
                     style="cursor: pointer;"
                     onclick="selectCategory(${cat.id}, '${cat.name.replace(/'/g, "\\'")}')">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-folder2 me-2 text-primary fs-5"></i>
                            <div class="flex-grow-1">
                                <h6 class="card-title mb-1 fw-bold" style="font-size: 0.9rem;">${cat.name}</h6>
                                ${cat.description ? `<p class="text-muted small mb-0" style="font-size: 0.75rem;">${cat.description}</p>` : ''}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `).join('') + '</div>';
}

// Select category from modal
function selectCategory(categoryId, categoryName) {
    // Determine which select to update based on context
    if (categoryModalContext === 'ingredient' && currentIngredientRowId) {
        // Update ingredient row category
        const row = document.getElementById(currentIngredientRowId);
        if (row) {
            const categorySelect = row.querySelector('.ingredient-category-select');
            if (categorySelect) {
                categorySelect.value = categoryId;
                categorySelect.dispatchEvent(new Event('change'));
            }
        }
    } else if (categoryModalContext === 'dish') {
        // Update dish category select
        const dishCategorySelect = document.getElementById('category_id');
        if (dishCategorySelect) {
            // Check if option exists, if not add it
            let option = dishCategorySelect.querySelector(`option[value="${categoryId}"]`);
            if (!option) {
                option = document.createElement('option');
                option.value = categoryId;
                option.textContent = categoryName;
                option.setAttribute('data-search', categoryName.toLowerCase());
                // Insert before the last option (which might be empty)
                dishCategorySelect.appendChild(option);
            }
            dishCategorySelect.value = categoryId;
            // Re-initialize searchable select if it exists
            if (dishCategorySelect.classList.contains('searchable-select')) {
                initSearchableSelect(dishCategorySelect);
            }
        }
    }
    
    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('addCategoryModal'));
    modal.hide();
    categoryModalContext = null;
}

// Load ingredients for modal
function loadIngredientsForModal() {
    const basePath = window.location.pathname.includes('/admin/') || 
                     window.location.pathname.includes('/user/') || 
                     window.location.pathname.includes('/auth/') ? '../' : '';
    
    fetch(basePath + 'api/get_all_ingredients.php')
        .then(response => response.json())
        .then(ingredients => {
            allIngredientsData = ingredients;
            displayIngredientsInModal(ingredients);
        })
        .catch(error => {
            console.error('Error loading ingredients:', error);
            document.getElementById('ingredientsListModal').innerHTML = 
                '<div class="alert alert-danger">Error loading ingredients. Please try again.</div>';
        });
}

// Display ingredients in modal
function displayIngredientsInModal(ingredients, searchTerm = '') {
    const container = document.getElementById('ingredientsListModal');
    const noResults = document.getElementById('noIngredientsFoundModal');
    
    if (!ingredients || ingredients.length === 0) {
        container.innerHTML = '';
        noResults.style.display = 'block';
        return;
    }
    
    noResults.style.display = 'none';
    
    // Filter ingredients if search term provided
    const filtered = searchTerm ? 
        ingredients.filter(ing => 
            ing.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
            (ing.category_name && ing.category_name.toLowerCase().includes(searchTerm.toLowerCase()))
        ) : ingredients;
    
    if (filtered.length === 0) {
        container.innerHTML = '';
        noResults.style.display = 'block';
        return;
    }
    
    // Group by category
    const grouped = {};
    filtered.forEach(ing => {
        const catName = ing.category_name || 'Uncategorized';
        if (!grouped[catName]) {
            grouped[catName] = [];
        }
        grouped[catName].push(ing);
    });
    
    let html = '';
    for (const [categoryName, categoryIngredients] of Object.entries(grouped)) {
        html += `
            <div class="mb-3">
                <h6 class="text-muted small fw-bold mb-2">
                    <i class="bi bi-folder me-1"></i>${categoryName}
                </h6>
                <div class="row g-2">
                    ${categoryIngredients.map(ing => `
                        <div class="col-md-6 col-lg-4">
                            <div class="card border shadow-sm ingredient-card-modal" 
                                 style="cursor: pointer;"
                                 onclick="selectIngredient(${ing.id}, ${ing.category_id}, '${ing.name.replace(/'/g, "\\'")}')">
                                <div class="card-body p-2">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-basket me-2 text-success"></i>
                                        <h6 class="card-title mb-0 fw-bold" style="font-size: 0.85rem;">${ing.name}</h6>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    }
    
    container.innerHTML = html;
}

// Select ingredient from modal
function selectIngredient(ingredientId, categoryId, ingredientName) {
    if (!currentIngredientRowId) {
        alert('Error: No ingredient row context found');
        return;
    }
    
    const row = document.getElementById(currentIngredientRowId);
    if (!row) {
        alert('Error: Ingredient row not found');
        return;
    }
    
    const categorySelect = row.querySelector('.ingredient-category-select');
    const ingredientSelect = row.querySelector('.ingredient-select');
    
    // Set category first
    if (categorySelect) {
        categorySelect.value = categoryId;
        categorySelect.dispatchEvent(new Event('change'));
    }
    
    // Wait a bit for ingredients to load, then select the ingredient
    setTimeout(() => {
        if (ingredientSelect) {
            ingredientSelect.value = ingredientId;
            ingredientSelect.dispatchEvent(new Event('change'));
        }
    }, 300);
    
    // Close modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('addIngredientModal'));
    modal.hide();
}

// Handle modal show events
document.getElementById('addCategoryModal').addEventListener('shown.bs.modal', function(e) {
    // Determine context - check data attribute or if opened from ingredient row
    const trigger = e.relatedTarget;
    if (trigger && (trigger.getAttribute('data-context') === 'ingredient' || trigger.closest('.ingredient-row-item'))) {
        categoryModalContext = 'ingredient';
        const rowId = trigger.getAttribute('data-row-id');
        if (rowId) {
            currentIngredientRowId = rowId;
        }
    } else {
        categoryModalContext = 'dish';
        currentIngredientRowId = null;
    }
    
    loadCategoriesForModal();
    // Show save button only on Add New tab
    document.getElementById('saveCategoryBtn').style.display = 'none';
    // Switch to browse tab
    const browseTab = document.getElementById('browse-categories-tab');
    if (browseTab) {
        browseTab.click();
    }
});

document.getElementById('addIngredientModal').addEventListener('shown.bs.modal', function() {
    loadIngredientsForModal();
    // Show save button only on Add New tab
    document.getElementById('saveIngredientBtn').style.display = 'none';
});

// Handle tab changes to show/hide save button
document.getElementById('add-category-tab').addEventListener('shown.bs.tab', function() {
    document.getElementById('saveCategoryBtn').style.display = 'block';
});

document.getElementById('browse-categories-tab').addEventListener('shown.bs.tab', function() {
    document.getElementById('saveCategoryBtn').style.display = 'none';
});

document.getElementById('add-ingredient-tab').addEventListener('shown.bs.tab', function() {
    document.getElementById('saveIngredientBtn').style.display = 'block';
});

document.getElementById('browse-ingredients-tab').addEventListener('shown.bs.tab', function() {
    document.getElementById('saveIngredientBtn').style.display = 'none';
});

// Search functionality for categories modal
document.getElementById('searchCategoriesModal').addEventListener('input', function() {
    const searchTerm = this.value.trim();
    displayCategoriesInModal(allCategoriesData, searchTerm);
});

// Search functionality for ingredients modal
document.getElementById('searchIngredientsModal').addEventListener('input', function() {
    const searchTerm = this.value.trim();
    displayIngredientsInModal(allIngredientsData, searchTerm);
});

// Function to save category
window.saveCategory = function() {
    const name = document.getElementById('newCategoryName')?.value.trim() || '';
    const description = document.getElementById('newCategoryDescription')?.value.trim() || '';
    const errorDiv = document.getElementById('addCategoryError');
    const successDiv = document.getElementById('addCategorySuccess');
    
    // Hide previous messages
    if (errorDiv) errorDiv.style.display = 'none';
    if (successDiv) successDiv.style.display = 'none';
    
    if (!name) {
        if (errorDiv) {
            errorDiv.textContent = 'Category name is required';
            errorDiv.style.display = 'block';
        }
        return;
    }
    
    // Disable submit button
    const submitBtn = event?.target || document.getElementById('saveCategoryBtn');
    if (!submitBtn) {
        console.error('Submit button not found');
        return;
    }
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Adding...';
    
    const basePath = window.location.pathname.includes('/admin/') || 
                     window.location.pathname.includes('/user/') || 
                     window.location.pathname.includes('/auth/') ? '../' : '';
    
    fetch(basePath + 'api/add_category.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            name: name,
            description: description
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (successDiv) {
                successDiv.textContent = 'Category added successfully!';
                successDiv.style.display = 'block';
            }
            
            // Add to category selects based on context
            const category = data.category;
            
            if (categoryModalContext === 'dish') {
                // Add to dish category select only
                const dishCategorySelect = document.getElementById('category_id');
                if (dishCategorySelect) {
                    const option = document.createElement('option');
                    option.value = category.id;
                    option.textContent = category.name;
                    option.setAttribute('data-search', category.name.toLowerCase());
                    dishCategorySelect.appendChild(option);
                    if (dishCategorySelect.classList.contains('searchable-select')) {
                        initSearchableSelect(dishCategorySelect);
                    }
                }
            } else if (categoryModalContext === 'ingredient') {
                // Add to ingredient category selects only
                const ingredientCategorySelects = document.querySelectorAll('.ingredient-category-select, #newIngredientCategory');
                ingredientCategorySelects.forEach(select => {
                    const option = document.createElement('option');
                    option.value = category.id;
                    option.textContent = category.name;
                    option.setAttribute('data-search', category.name.toLowerCase());
                    select.appendChild(option);
                    if (select.classList.contains('searchable-select')) {
                        initSearchableSelect(select);
                    }
                });
            }
            
            // Add to modal categories list
            allCategoriesData.push(category);
            displayCategoriesInModal(allCategoriesData);
            
            // Switch to browse tab to show the new category
            const browseTab = document.getElementById('browse-categories-tab');
            if (browseTab) {
                browseTab.click();
            }
            
            // Update ingredients data
            reloadIngredientsAndWait();
            
            // Reset form but don't close modal - let user see the new category
            const form = document.getElementById('addCategoryForm');
            if (form) form.reset();
            if (errorDiv) errorDiv.style.display = 'none';
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-lg me-2"></i>Add Category';
            }
            
            // Auto-select the newly added category after a short delay
            setTimeout(() => {
                selectCategory(category.id, category.name);
            }, 500);
        } else {
            if (errorDiv) {
                errorDiv.textContent = data.error || 'Failed to add category';
                errorDiv.style.display = 'block';
            }
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bi bi-check-lg me-2"></i>Add Category';
            }
        }
    })
    .catch(error => {
        if (errorDiv) {
            errorDiv.textContent = 'Error: ' + error.message;
            errorDiv.style.display = 'block';
        }
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-lg me-2"></i>Add Category';
        }
    });
};

// Function to save ingredient
window.saveIngredient = function() {
    const name = document.getElementById('newIngredientName').value.trim();
    const categoryId = document.getElementById('newIngredientCategory').value;
    const errorDiv = document.getElementById('addIngredientError');
    const successDiv = document.getElementById('addIngredientSuccess');
    
    // Hide previous messages
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';
    
    if (!name || !categoryId) {
        errorDiv.textContent = 'Ingredient name and category are required';
        errorDiv.style.display = 'block';
        return;
    }
    
    // Disable submit button
    const submitBtn = event.target;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Adding...';
    
    const basePath = window.location.pathname.includes('/admin/') || 
                     window.location.pathname.includes('/user/') || 
                     window.location.pathname.includes('/auth/') ? '../' : '';
    
    fetch(basePath + 'api/add_ingredient.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            name: name,
            category_id: categoryId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            successDiv.textContent = 'Ingredient added successfully!';
            successDiv.style.display = 'block';
            
            // Add to modal ingredients list
            allIngredientsData.push(data.ingredient);
            displayIngredientsInModal(allIngredientsData);
            
            // Switch to browse tab to show the new ingredient
            const browseTab = document.getElementById('browse-ingredients-tab');
            if (browseTab) {
                browseTab.click();
            }
            
            // Reload ingredients to get the new one
            reloadIngredientsAndWait(() => {
                // If we have a current row, update it
                if (currentIngredientRowId) {
                    const row = document.getElementById(currentIngredientRowId);
                    if (row) {
                        const categorySelect = row.querySelector('.ingredient-category-select');
                        const ingredientSelect = row.querySelector('.ingredient-select');
                        
                        // Set category if not already set
                        if (categorySelect && !categorySelect.value) {
                            categorySelect.value = data.ingredient.category_id;
                            loadIngredientsForRow(currentIngredientRowId, data.ingredient.category_id);
                        }
                        
                        // Wait a bit for ingredients to load, then select the new ingredient
                        setTimeout(() => {
                            if (ingredientSelect) {
                                ingredientSelect.value = data.ingredient.id;
                                ingredientSelect.dispatchEvent(new Event('change'));
                            }
                        }, 300);
                    }
                }
            });
            
            // Reset form but don't close modal - let user see the new ingredient
            document.getElementById('addIngredientForm').reset();
            errorDiv.style.display = 'none';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-lg me-2"></i>Add Ingredient';
            
            // Auto-select the newly added ingredient after a short delay
            setTimeout(() => {
                selectIngredient(data.ingredient.id, data.ingredient.category_id, data.ingredient.name);
            }, 500);
        } else {
            errorDiv.textContent = data.error || 'Failed to add ingredient';
            errorDiv.style.display = 'block';
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-check-lg me-2"></i>Add Ingredient';
        }
    })
    .catch(error => {
        errorDiv.textContent = 'Error: ' + error.message;
        errorDiv.style.display = 'block';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-check-lg me-2"></i>Add Ingredient';
    });
};

// Handle category modal close - refresh category dropdown in ingredient modal
document.getElementById('addCategoryModal').addEventListener('hidden.bs.modal', function() {
    // Refresh category dropdown in ingredient modal
    const basePath = window.location.pathname.includes('/admin/') || 
                     window.location.pathname.includes('/user/') || 
                     window.location.pathname.includes('/auth/') ? '../' : '';
    
    fetch(basePath + 'api/get_ingredients.php')
        .then(response => response.json())
        .then(data => {
            ingredientsByCategory = data;
            // Update category dropdown in ingredient modal
            const categorySelect = document.getElementById('newIngredientCategory');
            const currentValue = categorySelect.value;
            categorySelect.innerHTML = '<option value="">-- Select Category --</option>';
            
            for (const [catId, ingredients] of Object.entries(data)) {
                if (ingredients.length > 0) {
                    const catName = ingredients[0].category_name || `Category ${catId}`;
                    const option = document.createElement('option');
                    option.value = catId;
                    option.textContent = catName;
                    categorySelect.appendChild(option);
                }
            }
            
            // Restore previous selection if it still exists
            if (currentValue) {
                categorySelect.value = currentValue;
            }
        });
});

// Helper function to reload ingredients and wait for completion
function reloadIngredientsAndWait(callback) {
    const basePath = window.location.pathname.includes('/admin/') || 
                     window.location.pathname.includes('/user/') || 
                     window.location.pathname.includes('/auth/') ? '../' : '';
    fetch(basePath + 'api/get_ingredients.php')
        .then(response => response.json())
        .then(data => {
            ingredientsByCategory = data;
            console.log('Ingredients reloaded:', Object.keys(data).length, 'categories');
            // Refresh all category dropdowns
            refreshCategoryDropdowns();
            // Also refresh any existing ingredient rows
            document.querySelectorAll('.ingredient-category-select').forEach(select => {
                if (select.value) {
                    const row = select.closest('.ingredient-row-item');
                    if (row) {
                        const rowId = row.id;
                        loadIngredientsForRow(rowId, select.value);
                    }
                }
            });
            if (callback) callback(data);
        })
        .catch(error => {
            console.error('Error reloading ingredients:', error);
            if (callback) callback(null);
        });
}

// Print Dishes Page Function
function printDishesPage() {
    // Note: For colors to print correctly, users may need to enable 
    // "Background graphics" in their browser's print settings
    // Trigger print dialog - CSS will handle showing the banner and contact info
    window.print();
}

// Image Preview Function
function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            const previewDiv = document.getElementById('image_preview');
            const previewImg = document.getElementById('preview_img');
            const currentImagePreview = document.getElementById('current_image_preview');
            
            if (previewDiv && previewImg) {
                previewImg.src = e.target.result;
                previewDiv.style.display = 'block';
            }
            
            // Hide current image preview if editing
            if (currentImagePreview) {
                currentImagePreview.style.display = 'none';
            }
        };
        
        reader.readAsDataURL(input.files[0]);
    } else {
        const previewDiv = document.getElementById('image_preview');
        if (previewDiv) {
            previewDiv.style.display = 'none';
        }
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

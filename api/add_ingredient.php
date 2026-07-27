<?php
/**
 * API Endpoint: Add Ingredient
 * Creates a new ingredient via AJAX
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

header('Content-Type: application/json');

// Check if user is admin
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$name = trim($_POST['name'] ?? '');
$category_id = intval($_POST['category_id'] ?? 0);

// Translate to Urdu if current language is Urdu
$name = translateForDatabase($name);

if (empty($name) || $category_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Ingredient name and category are required']);
    exit;
}

$conn = getDBConnection();
if ($conn === false) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

try {
    // Check if ingredient already exists
    $existing = db_fetch($conn, 'SELECT id FROM ingredients WHERE name = ?', [$name]);
    if ($existing !== null) {
        echo json_encode(['success' => false, 'error' => 'Ingredient already exists']);
        exit;
    }

    // Create new ingredient (set unit to empty string)
    $unit = '';
    $ingredient_id = db_insert(
        $conn,
        'INSERT INTO ingredients (name, category_id, unit) VALUES (?, ?, ?) RETURNING id',
        [$name, $category_id, $unit]
    );

    // Get category name
    $category = db_fetch($conn, 'SELECT name FROM categories WHERE id = ?', [$category_id]);
    $category_name = $category['name'] ?? '';

    // Get current language for translation
    $currentLang = getCurrentLanguage();
    $ingredientName = $name;
    if ($currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $ingredientName)) {
        $ingredientName = translateToUrdu($ingredientName);
    }

    if ($currentLang === 'ur' && !empty($category_name) && !preg_match('/[\x{0600}-\x{06FF}]/u', $category_name)) {
        $category_name = translateToUrdu($category_name);
    }

    echo json_encode([
        'success' => true,
        'ingredient' => [
            'id' => $ingredient_id,
            'name' => $ingredientName,
            'category_id' => $category_id,
            'category_name' => $category_name,
            'unit' => $unit
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to create ingredient: ' . $e->getMessage()]);
}
?>

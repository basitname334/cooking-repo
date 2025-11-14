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

// Check if ingredient already exists
$stmt = $conn->prepare("SELECT id FROM ingredients WHERE name = ?");
$stmt->bind_param("s", $name);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'error' => 'Ingredient already exists']);
    exit;
}
$stmt->close();

// Create new ingredient (set unit to empty string)
$unit = '';
$stmt = $conn->prepare("INSERT INTO ingredients (name, category_id, unit) VALUES (?, ?, ?)");
$stmt->bind_param("sis", $name, $category_id, $unit);

if ($stmt->execute()) {
    $ingredient_id = $stmt->insert_id;
    $stmt->close();
    
    // Get category name
    $stmt = $conn->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $category = $result->fetch_assoc();
    $category_name = $category['name'] ?? '';
    $stmt->close();
    
    // Get current language for translation
    $currentLang = getCurrentLanguage();
    $ingredientName = $name;
    if ($currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $ingredientName)) {
        $ingredientName = translateToUrdu($ingredientName);
    }
    
    if ($currentLang === 'ur' && !empty($category_name) && !preg_match('/[\x{0600}-\x{06FF}]/u', $category_name)) {
        $category_name = translateToUrdu($category_name);
    }
    
    $conn->close();
    
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
} else {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'error' => 'Failed to create ingredient: ' . $error]);
}
?>


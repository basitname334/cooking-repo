<?php
/**
 * API Endpoint: Add Category
 * Creates a new category via AJAX
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
$description = trim($_POST['description'] ?? '');

// Translate to Urdu if current language is Urdu
$name = translateForDatabase($name);
$description = translateForDatabase($description);

if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Category name is required']);
    exit;
}

$conn = getDBConnection();
if ($conn === false) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

try {
    // Check if category already exists
    $existing = db_fetch($conn, 'SELECT id FROM categories WHERE name = ?', [$name]);
    if ($existing !== null) {
        echo json_encode(['success' => false, 'error' => 'Category already exists']);
        exit;
    }

    // Create new category
    $category_id = db_insert(
        $conn,
        'INSERT INTO categories (name, description) VALUES (?, ?) RETURNING id',
        [$name, $description]
    );

    // Get current language for translation
    $currentLang = getCurrentLanguage();
    $categoryName = $name;
    if ($currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $categoryName)) {
        $categoryName = translateToUrdu($categoryName);
    }

    echo json_encode([
        'success' => true,
        'category' => [
            'id' => $category_id,
            'name' => $categoryName,
            'description' => $description
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Failed to create category: ' . $e->getMessage()]);
}
?>

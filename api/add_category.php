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

// Check if category already exists
$stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
$stmt->bind_param("s", $name);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'error' => 'Category already exists']);
    exit;
}
$stmt->close();

// Create new category
$stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
$stmt->bind_param("ss", $name, $description);

if ($stmt->execute()) {
    $category_id = $stmt->insert_id;
    $stmt->close();
    $conn->close();
    
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
} else {
    $error = $stmt->error;
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'error' => 'Failed to create category: ' . $error]);
}
?>


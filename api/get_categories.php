<?php
/**
 * API Endpoint: Get All Categories
 * Returns JSON data of all categories
 * Translates data based on current language selection
 * Optional: Filter by type (dish or ingredient)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/language.php';

header('Content-Type: application/json');

$conn = getDBConnection();

// Get current language
$currentLang = getCurrentLanguage();

// Get type parameter (dish or ingredient)
$type = $_GET['type'] ?? 'all';

// Build query based on type
if ($type === 'dish') {
    // Only categories that are used by dishes
    $result = $conn->query("SELECT DISTINCT c.id, c.name, c.description 
        FROM categories c 
        INNER JOIN dishes d ON d.category_id = c.id 
        ORDER BY c.name");
} elseif ($type === 'ingredient') {
    // Only categories that are used by ingredients
    $result = $conn->query("SELECT DISTINCT c.id, c.name, c.description 
        FROM categories c 
        INNER JOIN ingredients i ON i.category_id = c.id 
        ORDER BY c.name");
} else {
    // Get all categories
    $result = $conn->query("SELECT id, name, description FROM categories ORDER BY name");
}

$categories = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categoryName = $row['name'];
        $categoryDescription = $row['description'] ?? '';
        
        // Translate category name if needed
        if ($currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $categoryName)) {
            $categoryName = translateToUrdu($categoryName);
        }
        
        // Translate description if needed
        if ($currentLang === 'ur' && !empty($categoryDescription) && !preg_match('/[\x{0600}-\x{06FF}]/u', $categoryDescription)) {
            $categoryDescription = translateToUrdu($categoryDescription);
        }
        
        $categories[] = [
            'id' => $row['id'],
            'name' => $categoryName,
            'description' => $categoryDescription
        ];
    }
}

$conn->close();

echo json_encode($categories);
?>


<?php
/**
 * API Endpoint: Get All Ingredients
 * Returns JSON data of all ingredients with category information
 * Translates data based on current language selection
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/language.php';

header('Content-Type: application/json');

$conn = getDBConnection();
if ($conn === false) {
    echo json_encode([]);
    exit;
}

// Get current language
$currentLang = getCurrentLanguage();

// Get all ingredients with category information
$rows = db_fetch_all(
    $conn,
    'SELECT i.id, i.name, i.category_id, i.unit, c.name as category_name
     FROM ingredients i
     LEFT JOIN categories c ON i.category_id = c.id
     ORDER BY c.name, i.name'
);

$ingredients = [];

foreach ($rows as $row) {
    $ingredientName = $row['name'];
    $categoryName = $row['category_name'] ?? '';

    // Translate ingredient name if needed
    if ($currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $ingredientName)) {
        $ingredientName = translateToUrdu($ingredientName);
    }

    // Translate category name if needed
    if ($currentLang === 'ur' && !empty($categoryName) && !preg_match('/[\x{0600}-\x{06FF}]/u', $categoryName)) {
        $categoryName = translateToUrdu($categoryName);
    }

    $ingredients[] = [
        'id' => $row['id'],
        'name' => $ingredientName,
        'category_id' => $row['category_id'],
        'category_name' => $categoryName,
        'unit' => $row['unit'] ?? ''
    ];
}

echo json_encode($ingredients);
?>

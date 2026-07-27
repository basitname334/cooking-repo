<?php
/**
 * API Endpoint: Get Ingredients by Category
 * Returns JSON data of ingredients grouped by category
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

// First, get all categories (even if they have no ingredients)
$allCategories = [];
foreach (db_fetch_all($conn, 'SELECT id, name FROM categories ORDER BY name') as $catRow) {
    $catId = $catRow['id'];
    $catName = $catRow['name'];

    // Translate category name if needed
    if ($currentLang === 'ur' && !empty($catName) && !preg_match('/[\x{0600}-\x{06FF}]/u', $catName)) {
        $catName = translateToUrdu($catName);
    }

    $allCategories[$catId] = [
        'id' => $catId,
        'name' => $catName
    ];
}

// Get all ingredients with category information
$rows = db_fetch_all(
    $conn,
    'SELECT i.*, c.id as cat_id, c.name as category_name FROM ingredients i
     LEFT JOIN categories c ON i.category_id = c.id
     ORDER BY c.name, i.name'
);

$ingredientsByCategory = [];

// Initialize all categories (even if empty)
foreach ($allCategories as $catId => $catInfo) {
    $ingredientsByCategory[$catId] = [];
}

foreach ($rows as $row) {
    $categoryId = $row['cat_id'];
    if ($categoryId && !isset($ingredientsByCategory[$categoryId])) {
        $ingredientsByCategory[$categoryId] = [];
    }

    if ($categoryId) {
        // Translate ingredient name if needed (if stored in English but Urdu is selected)
        $ingredientName = $row['name'];
        // Only translate if current language is Urdu and text appears to be in English
        if ($currentLang === 'ur' && !preg_match('/[\x{0600}-\x{06FF}]/u', $ingredientName)) {
            $ingredientName = translateToUrdu($ingredientName);
        }

        // Translate category name if needed
        $categoryName = $row['category_name'];
        if ($currentLang === 'ur' && !empty($categoryName) && !preg_match('/[\x{0600}-\x{06FF}]/u', $categoryName)) {
            $categoryName = translateToUrdu($categoryName);
        }

        $ingredientsByCategory[$categoryId][] = [
            'id' => $row['id'],
            'name' => $ingredientName,
            'unit' => $row['unit'],
            'category_name' => $categoryName ?: $allCategories[$categoryId]['name']
        ];
    }
}

// Ensure all categories have category_name set
foreach ($ingredientsByCategory as $catId => $ingredients) {
    if (count($ingredients) > 0 && !empty($ingredients[0]['category_name'])) {
        // Already has category name
    } else if (isset($allCategories[$catId])) {
        // Set category name from allCategories
        foreach ($ingredientsByCategory[$catId] as &$ing) {
            $ing['category_name'] = $allCategories[$catId]['name'];
        }
        unset($ing);
    }
}

echo json_encode($ingredientsByCategory);
?>

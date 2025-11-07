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

// Get current language
$currentLang = getCurrentLanguage();

// Get all ingredients with category information
$result = $conn->query("SELECT i.*, c.id as cat_id, c.name as category_name FROM ingredients i 
    LEFT JOIN categories c ON i.category_id = c.id 
    ORDER BY c.name, i.name");

$ingredientsByCategory = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categoryId = $row['cat_id'];
        if (!isset($ingredientsByCategory[$categoryId])) {
            $ingredientsByCategory[$categoryId] = [];
        }
        
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
            'category_name' => $categoryName
        ];
    }
}

$conn->close();

echo json_encode($ingredientsByCategory);
?>

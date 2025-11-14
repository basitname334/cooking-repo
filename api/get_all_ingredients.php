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

// Get current language
$currentLang = getCurrentLanguage();

// Get all ingredients with category information
$result = $conn->query("SELECT i.id, i.name, i.category_id, i.unit, c.name as category_name 
    FROM ingredients i 
    LEFT JOIN categories c ON i.category_id = c.id 
    ORDER BY c.name, i.name");

$ingredients = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
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
}

$conn->close();

echo json_encode($ingredients);
?>


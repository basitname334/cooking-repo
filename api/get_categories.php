<?php
/**
 * API Endpoint: Get All Categories
 * Returns JSON data of all categories
 * Translates data based on current language selection
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/language.php';

header('Content-Type: application/json');

$conn = getDBConnection();

// Get current language
$currentLang = getCurrentLanguage();

// Get all categories
$result = $conn->query("SELECT id, name, description FROM categories ORDER BY name");

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


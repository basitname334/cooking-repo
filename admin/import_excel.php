<?php
/**
 * Comprehensive Excel Import
 * Imports categories, dishes, ingredients, and dish_ingredients from Excel/CSV
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireAdmin();

$conn = getDBConnection();
$messages = [];
$errors = [];
$import_stats = [
    'categories' => ['created' => 0, 'skipped' => 0],
    'ingredients' => ['created' => 0, 'skipped' => 0],
    'dishes' => ['created' => 0, 'skipped' => 0],
    'dish_ingredients' => ['created' => 0, 'skipped' => 0]
];

/**
 * Helper function to ensure UTF-8 encoding for text
 * Handles BOM and encoding conversion for proper Urdu/Arabic text support
 */
function ensureUtf8($text) {
    if (empty($text)) {
        return $text;
    }
    
    // Remove UTF-8 BOM if present
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
        $text = substr($text, 3);
    }
    
    // Check if already UTF-8
    if (mb_check_encoding($text, 'UTF-8')) {
        return $text;
    }
    
    // Try to detect and convert encoding
    $detected = mb_detect_encoding($text, ['UTF-8', 'Windows-1256', 'ISO-8859-1', 'Windows-1252'], true);
    if ($detected && $detected !== 'UTF-8') {
        $text = mb_convert_encoding($text, 'UTF-8', $detected);
    }
    
    return $text;
}

/**
 * Read CSV file with proper UTF-8 encoding support for Urdu/Arabic text
 */
function readCsvFile($file_path) {
    $rows = [];
    
    // Try to detect file encoding
    $file_content = file_get_contents($file_path);
    
    // Remove BOM if present
    if (substr($file_content, 0, 3) === "\xEF\xBB\xBF") {
        $file_content = substr($file_content, 3);
    }
    
    // Detect encoding
    $encoding = mb_detect_encoding($file_content, ['UTF-8', 'Windows-1256', 'ISO-8859-1', 'Windows-1252'], true);
    
    // Convert to UTF-8 if not already
    if ($encoding && $encoding !== 'UTF-8') {
        $file_content = mb_convert_encoding($file_content, 'UTF-8', $encoding);
    } elseif (!$encoding) {
        // If detection fails, assume UTF-8
        $encoding = 'UTF-8';
    }
    
    // Write converted content to temporary file
    $temp_file = tempnam(sys_get_temp_dir(), 'csv_utf8_');
    file_put_contents($temp_file, $file_content);
    
    // Read CSV with UTF-8 encoding
    if (($handle = fopen($temp_file, 'r')) !== false) {
        while (($row = fgetcsv($handle)) !== false) {
            // Ensure each cell is UTF-8
            $utf8_row = array_map('ensureUtf8', $row);
            $rows[] = $utf8_row;
        }
        fclose($handle);
    }
    
    // Clean up temp file
    @unlink($temp_file);
    
    return $rows;
}

/**
 * Parse Excel/CSV file and extract data
 * Expected format:
 * Sheet 1 or CSV: Categories (Column A: Category Name, Column B: Description)
 * Sheet 2 or continuation: Ingredients (Column A: Ingredient Name, Column B: Category Name, Column C: Unit)
 * Sheet 3 or continuation: Dishes (Column A: Dish Name, Column B: Description, Column C: Category Name, Column D: Number of Persons, Column E: Base Quantity, Column F: Base Unit)
 * Sheet 4 or continuation: Dish Ingredients (Column A: Dish Name, Column B: Ingredient Name, Column C: Quantity, Column D: Unit)
 */
function parseExcelFile($file_path) {
    $data = [
        'categories' => [],
        'ingredients' => [],
        'dishes' => [],
        'dish_ingredients' => []
    ];
    
    $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    
    if ($file_extension === 'csv') {
        // Parse CSV file with UTF-8 encoding support for Urdu/Arabic text
        $csv_rows = readCsvFile($file_path);
        
        if (!empty($csv_rows)) {
            // Skip header row if exists
            $header = array_shift($csv_rows);
            $current_section = null;
            
            foreach ($csv_rows as $row) {
                if (count($row) < 2) continue;
                
                // Ensure UTF-8 encoding for all text
                $first_col = ensureUtf8(trim($row[0]));
                
                // Detect section markers (case-insensitive, supports Urdu)
                $first_col_lower = mb_strtolower($first_col, 'UTF-8');
                if (in_array($first_col_lower, ['[categories]', '[category]', 'categories', 'category', '[اقسام]', 'اقسام'])) {
                    $current_section = 'categories';
                    continue;
                } elseif (in_array($first_col_lower, ['[ingredients]', '[ingredient]', 'ingredients', 'ingredient', '[اجزاء]', 'اجزاء'])) {
                    $current_section = 'ingredients';
                    continue;
                } elseif (in_array($first_col_lower, ['[dishes]', '[dish]', 'dishes', 'dish', '[پکوان]', 'پکوان'])) {
                    $current_section = 'dishes';
                    continue;
                } elseif (in_array($first_col_lower, ['[dish_ingredients]', '[dish ingredients]', 'dish_ingredients', 'dish ingredients', '[پکوان اجزاء]', 'پکوان اجزاء'])) {
                    $current_section = 'dish_ingredients';
                    continue;
                }
                
                // Parse based on column count and section
                if ($current_section === 'categories' || (count($row) >= 2 && !$current_section)) {
                    if (count($row) >= 2) {
                        $data['categories'][] = [
                            'name' => ensureUtf8(trim($row[0])),
                            'description' => ensureUtf8(isset($row[1]) ? trim($row[1]) : '')
                        ];
                    }
                } elseif ($current_section === 'ingredients' || count($row) >= 3) {
                    if (count($row) >= 3) {
                        $data['ingredients'][] = [
                            'name' => ensureUtf8(trim($row[0])),
                            'category_name' => ensureUtf8(trim($row[1])),
                            'unit' => ensureUtf8(isset($row[2]) ? trim($row[2]) : '')
                        ];
                    }
                } elseif ($current_section === 'dishes' || count($row) >= 4) {
                    if (count($row) >= 4) {
                        $data['dishes'][] = [
                            'name' => ensureUtf8(trim($row[0])),
                            'description' => ensureUtf8(isset($row[1]) ? trim($row[1]) : ''),
                            'category_name' => ensureUtf8(trim($row[2])),
                            'number_of_persons' => isset($row[3]) ? intval($row[3]) : 1,
                            'base_quantity' => isset($row[4]) ? floatval($row[4]) : 1.0,
                            'base_unit' => ensureUtf8(isset($row[5]) ? trim($row[5]) : 'serving')
                        ];
                    }
                } elseif ($current_section === 'dish_ingredients' || count($row) >= 4) {
                    if (count($row) >= 4) {
                        $data['dish_ingredients'][] = [
                            'dish_name' => ensureUtf8(trim($row[0])),
                            'ingredient_name' => ensureUtf8(trim($row[1])),
                            'quantity' => floatval($row[2]),
                            'unit' => ensureUtf8(isset($row[3]) ? trim($row[3]) : '')
                        ];
                    }
                }
            }
        }
    } elseif (in_array($file_extension, ['xls', 'xlsx'])) {
        // Try to use PhpSpreadsheet if available
        if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
                $sheet_count = $spreadsheet->getSheetCount();
                
                // Try to get data from different sheets
                for ($sheet_index = 0; $sheet_index < $sheet_count; $sheet_index++) {
                    $worksheet = $spreadsheet->getSheet($sheet_index);
                    $sheet_name = strtolower($worksheet->getTitle());
                    $highestRow = $worksheet->getHighestRow();
                    
                    // Determine sheet type based on name or structure
                    if (strpos($sheet_name, 'categor') !== false || ($sheet_index == 0 && $highestRow > 0)) {
                        // Categories sheet
                        for ($row = 2; $row <= $highestRow; $row++) {
                            $name = ensureUtf8(trim($worksheet->getCell('A' . $row)->getFormattedValue()));
                            $description = ensureUtf8(trim($worksheet->getCell('B' . $row)->getFormattedValue()));
                            if (!empty($name)) {
                                $data['categories'][] = ['name' => $name, 'description' => $description];
                            }
                        }
                    } elseif (strpos($sheet_name, 'ingredient') !== false || ($sheet_index == 1 && $highestRow > 0)) {
                        // Ingredients sheet
                        for ($row = 2; $row <= $highestRow; $row++) {
                            $name = ensureUtf8(trim($worksheet->getCell('A' . $row)->getFormattedValue()));
                            $category = ensureUtf8(trim($worksheet->getCell('B' . $row)->getFormattedValue()));
                            $unit = ensureUtf8(trim($worksheet->getCell('C' . $row)->getFormattedValue()));
                            if (!empty($name) && !empty($category)) {
                                $data['ingredients'][] = ['name' => $name, 'category_name' => $category, 'unit' => $unit];
                            }
                        }
                    } elseif (strpos($sheet_name, 'dish') !== false && strpos($sheet_name, 'ingredient') === false || ($sheet_index == 2 && $highestRow > 0)) {
                        // Dishes sheet
                        for ($row = 2; $row <= $highestRow; $row++) {
                            $name = ensureUtf8(trim($worksheet->getCell('A' . $row)->getFormattedValue()));
                            $description = ensureUtf8(trim($worksheet->getCell('B' . $row)->getFormattedValue()));
                            $category = ensureUtf8(trim($worksheet->getCell('C' . $row)->getFormattedValue()));
                            $persons = intval($worksheet->getCell('D' . $row)->getValue() ?: 1);
                            $base_qty = floatval($worksheet->getCell('E' . $row)->getValue() ?: 1);
                            $base_unit = ensureUtf8(trim($worksheet->getCell('F' . $row)->getFormattedValue() ?: 'serving'));
                            if (!empty($name) && !empty($category)) {
                                $data['dishes'][] = [
                                    'name' => $name,
                                    'description' => $description,
                                    'category_name' => $category,
                                    'number_of_persons' => $persons,
                                    'base_quantity' => $base_qty,
                                    'base_unit' => $base_unit
                                ];
                            }
                        }
                    } elseif (strpos($sheet_name, 'dish') !== false && strpos($sheet_name, 'ingredient') !== false || ($sheet_index == 3 && $highestRow > 0)) {
                        // Dish Ingredients sheet
                        for ($row = 2; $row <= $highestRow; $row++) {
                            $dish = ensureUtf8(trim($worksheet->getCell('A' . $row)->getFormattedValue()));
                            $ingredient = ensureUtf8(trim($worksheet->getCell('B' . $row)->getFormattedValue()));
                            $quantity = floatval($worksheet->getCell('C' . $row)->getValue() ?: 0);
                            $unit = ensureUtf8(trim($worksheet->getCell('D' . $row)->getFormattedValue()));
                            if (!empty($dish) && !empty($ingredient) && $quantity > 0) {
                                $data['dish_ingredients'][] = [
                                    'dish_name' => $dish,
                                    'ingredient_name' => $ingredient,
                                    'quantity' => $quantity,
                                    'unit' => $unit
                                ];
                            }
                        }
                    }
                }
                
                // If no sheets detected, try single sheet format (check first sheet more thoroughly)
                if (empty($data['categories']) && empty($data['ingredients']) && empty($data['dishes'])) {
                    $worksheet = $spreadsheet->getActiveSheet();
                    $highestRow = $worksheet->getHighestRow();
                    $highestCol = $worksheet->getHighestColumn();
                    
                    // Check column headers to determine format
                    $header_row = [];
                    for ($col = 'A'; $col <= $highestCol; $col++) {
                        $header_row[] = mb_strtolower(ensureUtf8(trim($worksheet->getCell($col . '1')->getFormattedValue())), 'UTF-8');
                    }
                    
                    // Multi-column format detection
                    for ($row = 2; $row <= $highestRow; $row++) {
                        $col_a = ensureUtf8(trim($worksheet->getCell('A' . $row)->getFormattedValue()));
                        $col_b = ensureUtf8(trim($worksheet->getCell('B' . $row)->getFormattedValue()));
                        $col_c = ensureUtf8(trim($worksheet->getCell('C' . $row)->getFormattedValue()));
                        
                        if (empty($col_a)) continue;
                        
                        // Determine type based on column count and content
                        if (!empty($col_a) && !empty($col_b) && empty($col_c)) {
                            // Likely category
                            $data['categories'][] = ['name' => $col_a, 'description' => $col_b];
                        } elseif (!empty($col_a) && !empty($col_b) && !empty($col_c)) {
                            // Could be ingredient or dish
                            $col_d_raw = $worksheet->getCell('D' . $row)->getValue();
                            $col_d = ensureUtf8(trim($worksheet->getCell('D' . $row)->getFormattedValue()));
                            if (empty($col_d) || (is_numeric($col_d_raw) && $col_d_raw == 0)) {
                                // Likely ingredient (Name, Category, Unit)
                                $data['ingredients'][] = ['name' => $col_a, 'category_name' => $col_b, 'unit' => $col_c];
                            } else {
                                // Likely dish (Name, Description, Category, ...)
                                $col_e = floatval($worksheet->getCell('E' . $row)->getValue() ?: 1);
                                $col_f = ensureUtf8(trim($worksheet->getCell('F' . $row)->getFormattedValue() ?: 'serving'));
                                $data['dishes'][] = [
                                    'name' => $col_a,
                                    'description' => $col_b,
                                    'category_name' => $col_c,
                                    'number_of_persons' => is_numeric($col_d_raw) ? intval($col_d_raw) : 1,
                                    'base_quantity' => $col_e,
                                    'base_unit' => $col_f
                                ];
                            }
                        }
                        
                        // Check for dish ingredients (Dish, Ingredient, Quantity, Unit)
                        $dish_col = ensureUtf8(trim($worksheet->getCell('A' . $row)->getFormattedValue()));
                        $ing_col = ensureUtf8(trim($worksheet->getCell('B' . $row)->getFormattedValue()));
                        $qty_col = floatval($worksheet->getCell('C' . $row)->getValue() ?: 0);
                        if (!empty($dish_col) && !empty($ing_col) && $qty_col > 0 && 
                            !in_array($dish_col, array_column($data['dishes'], 'name')) &&
                            !in_array($dish_col, array_column($data['categories'], 'name'))) {
                            $data['dish_ingredients'][] = [
                                'dish_name' => $dish_col,
                                'ingredient_name' => $ing_col,
                                'quantity' => $qty_col,
                                'unit' => ensureUtf8(trim($worksheet->getCell('D' . $row)->getFormattedValue() ?: ''))
                            ];
                        }
                    }
                }
                
            } catch (Exception $e) {
                throw new Exception("Error reading Excel file: " . $e->getMessage());
            }
        } else {
            throw new Exception("Excel file format requires PhpSpreadsheet library. Please install it via Composer: composer require phpoffice/phpspreadsheet");
        }
    } else {
        throw new Exception("Unsupported file format. Please upload CSV or Excel (.xls, .xlsx) file.");
    }
    
    return $data;
}

// Handle Excel/CSV file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_excel']) && isset($_FILES['excel_file'])) {
    $uploaded_file = $_FILES['excel_file'];
    
    if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error: " . $uploaded_file['error'];
    } else {
        $allowed_extensions = ['csv', 'xls', 'xlsx'];
        $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "Invalid file type. Please upload CSV or Excel file (.csv, .xls, .xlsx)";
        } else {
            if ($uploaded_file['size'] > 10 * 1024 * 1024) {
                $errors[] = "File size exceeds 10MB limit.";
            } else {
                $upload_dir = __DIR__ . '/../uploads/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_name = 'import_' . time() . '_' . basename($uploaded_file['name']);
                $target_file = $upload_dir . $file_name;
                
                if (move_uploaded_file($uploaded_file['tmp_name'], $target_file)) {
                    try {
                        $excel_data = parseExcelFile($target_file);
                        
                        if (empty($excel_data['categories']) && empty($excel_data['ingredients']) && 
                            empty($excel_data['dishes']) && empty($excel_data['dish_ingredients'])) {
                            $errors[] = "No data found in the uploaded file. Please check the file format.";
                        } else {
                            $messages[] = "Successfully parsed file: " . htmlspecialchars($uploaded_file['name']);
                            $messages[] = "Found: " . count($excel_data['categories']) . " categories, " . 
                                         count($excel_data['ingredients']) . " ingredients, " .
                                         count($excel_data['dishes']) . " dishes, " .
                                         count($excel_data['dish_ingredients']) . " dish ingredients.";
                            
                            // Start transaction
                            $conn->begin_transaction();
                            
                            try {
                                // Step 1: Import Categories
                                $category_map = []; // Map category name to ID
                                foreach ($excel_data['categories'] as $cat) {
                                    $cat_name = translateForDatabase(trim($cat['name']));
                                    $cat_desc = translateForDatabase(trim($cat['description']));
                                    
                                    if (empty($cat_name)) continue;
                                    
                                    $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
                                    $stmt->bind_param("s", $cat_name);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    
                                    if ($result->num_rows > 0) {
                                        $category_map[$cat['name']] = $result->fetch_assoc()['id'];
                                        $import_stats['categories']['skipped']++;
                                    } else {
                                        $stmt->close();
                                        $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                                        $stmt->bind_param("ss", $cat_name, $cat_desc);
                                        if ($stmt->execute()) {
                                            $category_map[$cat['name']] = $conn->insert_id;
                                            $import_stats['categories']['created']++;
                                        }
                                    }
                                    $stmt->close();
                                }
                                
                                // Step 2: Import Ingredients (requires categories)
                                $ingredient_map = []; // Map ingredient name to ID
                                foreach ($excel_data['ingredients'] as $ing) {
                                    $ing_name = translateForDatabase(trim($ing['name']));
                                    $cat_name = translateForDatabase(trim($ing['category_name']));
                                    $unit = trim($ing['unit']);
                                    
                                    if (empty($ing_name) || empty($cat_name)) continue;
                                    
                                    // Get or create category
                                    if (!isset($category_map[$ing['category_name']])) {
                                        $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
                                        $stmt->bind_param("s", $cat_name);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        if ($result->num_rows > 0) {
                                            $category_map[$ing['category_name']] = $result->fetch_assoc()['id'];
                                        } else {
                                            // Create category if it doesn't exist
                                            $stmt->close();
                                            $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                                            $desc = "Auto-created from import";
                                            $stmt->bind_param("ss", $cat_name, $desc);
                                            if ($stmt->execute()) {
                                                $category_map[$ing['category_name']] = $conn->insert_id;
                                                $import_stats['categories']['created']++;
                                            }
                                        }
                                        $stmt->close();
                                    }
                                    
                                    $category_id = $category_map[$ing['category_name']];
                                    
                                    // Check if ingredient exists
                                    $stmt = $conn->prepare("SELECT id FROM ingredients WHERE name = ?");
                                    $stmt->bind_param("s", $ing_name);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    
                                    if ($result->num_rows > 0) {
                                        $ingredient_map[$ing['name']] = $result->fetch_assoc()['id'];
                                        $import_stats['ingredients']['skipped']++;
                                    } else {
                                        $stmt->close();
                                        $stmt = $conn->prepare("INSERT INTO ingredients (name, category_id, unit) VALUES (?, ?, ?)");
                                        $stmt->bind_param("sis", $ing_name, $category_id, $unit);
                                        if ($stmt->execute()) {
                                            $ingredient_map[$ing['name']] = $conn->insert_id;
                                            $import_stats['ingredients']['created']++;
                                        }
                                    }
                                    $stmt->close();
                                }
                                
                                // Step 3: Import Dishes (requires categories)
                                $dish_map = []; // Map dish name to ID
                                foreach ($excel_data['dishes'] as $dish) {
                                    $dish_name = translateForDatabase(trim($dish['name']));
                                    $dish_desc = translateForDatabase(trim($dish['description']));
                                    $cat_name = translateForDatabase(trim($dish['category_name']));
                                    $persons = intval($dish['number_of_persons'] ?: 1);
                                    $base_qty = floatval($dish['base_quantity'] ?: 1.0);
                                    $base_unit = trim($dish['base_unit'] ?: 'serving');
                                    
                                    if (empty($dish_name) || empty($cat_name)) continue;
                                    
                                    // Get or create category
                                    if (!isset($category_map[$dish['category_name']])) {
                                        $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
                                        $stmt->bind_param("s", $cat_name);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        if ($result->num_rows > 0) {
                                            $category_map[$dish['category_name']] = $result->fetch_assoc()['id'];
                                        } else {
                                            $stmt->close();
                                            $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                                            $desc = "Auto-created from import";
                                            $stmt->bind_param("ss", $cat_name, $desc);
                                            if ($stmt->execute()) {
                                                $category_map[$dish['category_name']] = $conn->insert_id;
                                                $import_stats['categories']['created']++;
                                            }
                                        }
                                        $stmt->close();
                                    }
                                    
                                    $category_id = $category_map[$dish['category_name']];
                                    
                                    // Check if dish exists
                                    $stmt = $conn->prepare("SELECT id FROM dishes WHERE name = ?");
                                    $stmt->bind_param("s", $dish_name);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    
                                    if ($result->num_rows > 0) {
                                        $dish_map[$dish['name']] = $result->fetch_assoc()['id'];
                                        $import_stats['dishes']['skipped']++;
                                    } else {
                                        $stmt->close();
                                        // Check if columns exist
                                        $check_cols = $conn->query("SHOW COLUMNS FROM dishes LIKE 'number_of_persons'");
                                        if ($check_cols->num_rows == 0) {
                                            $conn->query("ALTER TABLE dishes ADD COLUMN number_of_persons INT DEFAULT 1");
                                            $conn->query("ALTER TABLE dishes ADD COLUMN base_quantity DECIMAL(10,2) DEFAULT 1.00");
                                            $conn->query("ALTER TABLE dishes ADD COLUMN base_unit VARCHAR(50) DEFAULT 'serving'");
                                        }
                                        
                                        $stmt = $conn->prepare("INSERT INTO dishes (name, description, category_id, number_of_persons, base_quantity, base_unit) VALUES (?, ?, ?, ?, ?, ?)");
                                        $stmt->bind_param("ssiids", $dish_name, $dish_desc, $category_id, $persons, $base_qty, $base_unit);
                                        if ($stmt->execute()) {
                                            $dish_map[$dish['name']] = $conn->insert_id;
                                            $import_stats['dishes']['created']++;
                                        }
                                    }
                                    $stmt->close();
                                }
                                
                                // Step 4: Import Dish Ingredients (requires dishes and ingredients)
                                foreach ($excel_data['dish_ingredients'] as $dish_ing) {
                                    $dish_name = translateForDatabase(trim($dish_ing['dish_name']));
                                    $ing_name = translateForDatabase(trim($dish_ing['ingredient_name']));
                                    $quantity = floatval($dish_ing['quantity']);
                                    $unit = trim($dish_ing['unit']);
                                    
                                    if (empty($dish_name) || empty($ing_name) || $quantity <= 0) continue;
                                    
                                    // Get dish ID
                                    if (!isset($dish_map[$dish_ing['dish_name']])) {
                                        $stmt = $conn->prepare("SELECT id FROM dishes WHERE name = ?");
                                        $stmt->bind_param("s", $dish_name);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        if ($result->num_rows > 0) {
                                            $dish_map[$dish_ing['dish_name']] = $result->fetch_assoc()['id'];
                                        } else {
                                            $stmt->close();
                                            continue; // Skip if dish doesn't exist
                                        }
                                        $stmt->close();
                                    }
                                    
                                    // Get ingredient ID
                                    if (!isset($ingredient_map[$dish_ing['ingredient_name']])) {
                                        $stmt = $conn->prepare("SELECT id FROM ingredients WHERE name = ?");
                                        $stmt->bind_param("s", $ing_name);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        if ($result->num_rows > 0) {
                                            $ingredient_map[$dish_ing['ingredient_name']] = $result->fetch_assoc()['id'];
                                        } else {
                                            $stmt->close();
                                            continue; // Skip if ingredient doesn't exist
                                        }
                                        $stmt->close();
                                    }
                                    
                                    $dish_id = $dish_map[$dish_ing['dish_name']];
                                    $ingredient_id = $ingredient_map[$dish_ing['ingredient_name']];
                                    
                                    // Check if dish_ingredient already exists
                                    $stmt = $conn->prepare("SELECT id FROM dish_ingredients WHERE dish_id = ? AND ingredient_id = ?");
                                    $stmt->bind_param("ii", $dish_id, $ingredient_id);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    
                                    if ($result->num_rows > 0) {
                                        $import_stats['dish_ingredients']['skipped']++;
                                    } else {
                                        $stmt->close();
                                        $stmt = $conn->prepare("INSERT INTO dish_ingredients (dish_id, ingredient_id, quantity, unit) VALUES (?, ?, ?, ?)");
                                        $stmt->bind_param("iids", $dish_id, $ingredient_id, $quantity, $unit);
                                        if ($stmt->execute()) {
                                            $import_stats['dish_ingredients']['created']++;
                                        }
                                    }
                                    $stmt->close();
                                }
                                
                                $conn->commit();
                                $messages[] = "✅ Import completed successfully!";
                                
                            } catch (Exception $e) {
                                $conn->rollback();
                                $errors[] = "Error importing data: " . $e->getMessage();
                            }
                        }
                        
                        @unlink($target_file);
                        
                    } catch (Exception $e) {
                        $errors[] = $e->getMessage();
                        @unlink($target_file);
                    }
                } else {
                    $errors[] = "Failed to upload file.";
                }
            }
        }
    }
}

$pageTitle = 'Import from Excel';
include __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0"><i class="bi bi-file-earmark-excel me-2"></i>Import from Excel/CSV</h4>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-4">
                    <h5><i class="bi bi-info-circle me-2"></i>Excel/CSV File Format:</h5>
                    <p>You can organize your data in multiple sheets (for Excel) or use section markers (for CSV):</p>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <h6><strong>Sheet 1: Categories</strong></h6>
                            <ul>
                                <li><strong>Column A:</strong> Category Name</li>
                                <li><strong>Column B:</strong> Description (optional)</li>
                            </ul>
                            
                            <h6><strong>Sheet 2: Ingredients</strong></h6>
                            <ul>
                                <li><strong>Column A:</strong> Ingredient Name</li>
                                <li><strong>Column B:</strong> Category Name</li>
                                <li><strong>Column C:</strong> Unit (optional)</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><strong>Sheet 3: Dishes</strong></h6>
                            <ul>
                                <li><strong>Column A:</strong> Dish Name</li>
                                <li><strong>Column B:</strong> Description (optional)</li>
                                <li><strong>Column C:</strong> Category Name</li>
                                <li><strong>Column D:</strong> Number of Persons (default: 1)</li>
                                <li><strong>Column E:</strong> Base Quantity (default: 1.0)</li>
                                <li><strong>Column F:</strong> Base Unit (default: serving)</li>
                            </ul>
                            
                            <h6><strong>Sheet 4: Dish Ingredients</strong></h6>
                            <ul>
                                <li><strong>Column A:</strong> Dish Name</li>
                                <li><strong>Column B:</strong> Ingredient Name</li>
                                <li><strong>Column C:</strong> Quantity</li>
                                <li><strong>Column D:</strong> Unit (optional)</li>
                            </ul>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning mt-3">
                        <strong>Note:</strong> For CSV files, you can use section markers like <code>[Categories]</code>, <code>[Ingredients]</code>, <code>[Dishes]</code>, <code>[Dish Ingredients]</code> to separate sections.
                    </div>
                </div>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <strong><i class="bi bi-exclamation-triangle me-2"></i>Errors:</strong>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($messages)): ?>
                    <div class="alert alert-success">
                        <strong><i class="bi bi-check-circle me-2"></i>Import Results:</strong>
                        <div class="mt-3">
                            <?php foreach ($messages as $msg): ?>
                                <div><?php echo nl2br(htmlspecialchars($msg)); ?></div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (!empty($import_stats)): ?>
                            <div class="mt-3 p-3 bg-light rounded">
                                <h6>Import Statistics:</h6>
                                <ul class="mb-0">
                                    <li><strong>Categories:</strong> <?php echo $import_stats['categories']['created']; ?> created, <?php echo $import_stats['categories']['skipped']; ?> skipped</li>
                                    <li><strong>Ingredients:</strong> <?php echo $import_stats['ingredients']['created']; ?> created, <?php echo $import_stats['ingredients']['skipped']; ?> skipped</li>
                                    <li><strong>Dishes:</strong> <?php echo $import_stats['dishes']['created']; ?> created, <?php echo $import_stats['dishes']['skipped']; ?> skipped</li>
                                    <li><strong>Dish Ingredients:</strong> <?php echo $import_stats['dish_ingredients']['created']; ?> created, <?php echo $import_stats['dish_ingredients']['skipped']; ?> skipped</li>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" class="mb-4">
                    <input type="hidden" name="upload_excel" value="1">
                    <div class="mb-3">
                        <label for="excel_file" class="form-label">Select Excel/CSV File</label>
                        <input type="file" class="form-control" id="excel_file" name="excel_file" 
                               accept=".csv,.xls,.xlsx" required>
                        <small class="form-text text-muted">Upload a CSV or Excel file. Maximum file size: 10MB</small>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-upload me-2"></i>Upload & Import
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-lg" onclick="downloadSampleTemplate()">
                            <i class="bi bi-download me-2"></i>Download Sample Template
                        </button>
                        <a href="dashboard.php" class="btn btn-secondary btn-lg">
                            <i class="bi bi-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </form>
                
                <script>
                function downloadSampleTemplate() {
                    // Create CSV content with all sections
                    const csvContent = 
                        "[Categories]\n" +
                        "Category Name,Description\n" +
                        "کربانه,Spices and seasonings\n" +
                        "سبزیاں,Vegetables\n" +
                        "گوشت,Meat\n\n" +
                        "[Ingredients]\n" +
                        "Ingredient Name,Category Name,Unit\n" +
                        "بادام گری,کربانه,kg\n" +
                        "کالی مرچ پاؤڈر,کربانه,g\n" +
                        "ادرک,سبزیاں,kg\n" +
                        "ہرا دھنیا,سبزیاں,kg\n" +
                        "مرغی (۱۶ ٹکڑے),گوشت,piece\n\n" +
                        "[Dishes]\n" +
                        "Dish Name,Description,Category Name,Number of Persons,Base Quantity,Base Unit\n" +
                        "چکن بریانی,Chicken Biryani,گوشت,50,10.00,kg\n" +
                        "قورمہ,Qorma,گوشت,100,10.00,piece\n\n" +
                        "[Dish Ingredients]\n" +
                        "Dish Name,Ingredient Name,Quantity,Unit\n" +
                        "چکن بریانی,مرغی (۱۶ ٹکڑے),5.00,piece\n" +
                        "چکن بریانی,بادام گری,2.00,kg\n" +
                        "چکن بریانی,ادرک,1.00,kg\n" +
                        "قورمہ,مرغی (۱۶ ٹکڑے),10.00,piece\n" +
                        "قورمہ,کالی مرچ پاؤڈر,50.00,g";
                    
                    // Create blob and download
                    const blob = new Blob(["\ufeff" + csvContent], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement('a');
                    const url = URL.createObjectURL(blob);
                    link.setAttribute('href', url);
                    link.setAttribute('download', 'import_template.csv');
                    link.style.visibility = 'hidden';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }
                </script>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>


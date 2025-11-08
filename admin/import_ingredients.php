<?php
/**
 * Import Categories and Ingredients
 * Adds categories and ingredients from the provided list
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';
require_once __DIR__ . '/../vendor/autoload.php';

requireAdmin();

$conn = getDBConnection();
$messages = [];
$errors = [];

// Define categories and their ingredients (from the provided list)
$categories_data = [
    'کربانه' => [
        'بادام گری',
        'ستارا سونف',
        'کالی مرچ پاؤڈر',
        'بونس سرف',
        'چائنا نمک',
        'دهنیا ثابت',
        'ملائی (اولپرز)',
        'کڑی پاؤڈر (نیشنل)',
        'کسٹرڈ ونیلا',
        'چھوٹی الائچی',
        'تلی پیاز (شاہ فوڈ)',
        'گرم مصالحه (ثابت)',
        'حبیب بنسبتی',
        'کشمش (سندر خوانی)',
        'جاوتری',
        'کاجو',
        'ماچس باکس',
        'دودھ (اولپرز)',
        'مخلوط پھل (۳ کلو ڈبہ)',
        'نیشنل نمک',
        'آلو بخاره',
        'لال مرچ (ثابت)',
        'پکی چاول',
        'سونف',
        'اسپنج/استری',
        'چینی',
        'بلدی پاؤڈر',
        'سرکه (سفید)',
        'سفید مرچ پاؤڈر',
        'زیره سفید'
    ],
    'تازه پهل' => [
        'سیب گولڈن'
    ],
    'گوشت' => [
        'مرغی (۱۶ ٹکڑے)'
    ],
    'کھانا پکانے کی اشیاء' => [
        'کھانا پکانے کی اشیاء ململ کپڑا',
        'کھانا پکانے کی اشیاء لکڑی'
    ],
    'سبزیاں' => [
        'ادرک',
        'ہرا دھنیا',
        'ہری مرچ',
        'لهسن',
        'پودینہ',
        'ٹماٹر'
    ],
    'بیکری' => [
        'دہی'
    ]
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
 * Parse Excel/CSV file and extract categories and ingredients
 * Expected format: Column A = Ingredient Name, Column B = Category
 * Supports Urdu/Arabic text with proper UTF-8 encoding
 */
function parseExcelFile($file_path) {
    $data = [];
    
    // Check if file is CSV
    $file_extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    
    if ($file_extension === 'csv') {
        // Parse CSV file with UTF-8 encoding support for Urdu/Arabic text
        $csv_rows = readCsvFile($file_path);
        
        if (!empty($csv_rows)) {
            // Skip header row if exists
            array_shift($csv_rows);
            
            foreach ($csv_rows as $row) {
                if (count($row) >= 2) {
                    $ingredient_name = ensureUtf8(trim($row[0]));
                    $category_name = ensureUtf8(trim($row[1]));
                    
                    if (!empty($ingredient_name) && !empty($category_name)) {
                        if (!isset($data[$category_name])) {
                            $data[$category_name] = [];
                        }
                        $data[$category_name][] = $ingredient_name;
                    }
                }
            }
        }
    } elseif (in_array($file_extension, ['xls', 'xlsx'])) {
        // Try to use PhpSpreadsheet if available
        if (class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
            try {
                $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
                $worksheet = $spreadsheet->getActiveSheet();
                $highestRow = $worksheet->getHighestRow();
                
                // Start from row 2 (skip header)
                for ($row = 2; $row <= $highestRow; $row++) {
                    $ingredient_name = ensureUtf8(trim($worksheet->getCell('A' . $row)->getFormattedValue()));
                    $category_name = ensureUtf8(trim($worksheet->getCell('B' . $row)->getFormattedValue()));
                    
                    if (!empty($ingredient_name) && !empty($category_name)) {
                        if (!isset($data[$category_name])) {
                            $data[$category_name] = [];
                        }
                        $data[$category_name][] = $ingredient_name;
                    }
                }
            } catch (Exception $e) {
                throw new Exception("Error reading Excel file: " . $e->getMessage());
            }
        } else {
            // PhpSpreadsheet not available, suggest CSV
            throw new Exception("Excel file format requires PhpSpreadsheet library. Please save your file as CSV format and try again.");
        }
    } else {
        throw new Exception("Unsupported file format. Please upload CSV or Excel (.xls, .xlsx) file.");
    }
    
    return $data;
}

// Handle Excel/CSV file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_excel']) && isset($_FILES['excel_file'])) {
    $uploaded_file = $_FILES['excel_file'];
    
    // Check for upload errors
    if ($uploaded_file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error: " . $uploaded_file['error'];
    } else {
        // Validate file type
        $allowed_extensions = ['csv', 'xls', 'xlsx'];
        $file_extension = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_extension, $allowed_extensions)) {
            $errors[] = "Invalid file type. Please upload CSV or Excel file (.csv, .xls, .xlsx)";
        } else {
            // Validate file size (max 10MB)
            if ($uploaded_file['size'] > 10 * 1024 * 1024) {
                $errors[] = "File size exceeds 10MB limit.";
            } else {
                // Create uploads directory if it doesn't exist
                $upload_dir = __DIR__ . '/../uploads/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate unique filename
                $file_name = 'import_' . time() . '_' . basename($uploaded_file['name']);
                $target_file = $upload_dir . $file_name;
                
                // Move uploaded file
                if (move_uploaded_file($uploaded_file['tmp_name'], $target_file)) {
                    try {
                        // Parse the Excel/CSV file
                        $excel_data = parseExcelFile($target_file);
                        
                        if (empty($excel_data)) {
                            $errors[] = "No data found in the uploaded file. Please check the file format.";
                        } else {
                            // Use the parsed data instead of hardcoded data
                            $categories_data = $excel_data;
                            $messages[] = "Successfully parsed Excel/CSV file: " . htmlspecialchars($uploaded_file['name']);
                            $messages[] = "Found " . count($excel_data) . " categories with ingredients.";
                            
                            // Process the imported data
                            $conn->begin_transaction();
                            
                            try {
                                foreach ($categories_data as $category_name => $ingredients_list) {
                                    // Translate category name if needed
                                    $category_name = translateForDatabase($category_name);
                                    
                                    // Check if category exists, if not create it
                                    $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
                                    $stmt->bind_param("s", $category_name);
                                    $stmt->execute();
                                    $result = $stmt->get_result();
                                    
                                    if ($result->num_rows > 0) {
                                        $category_row = $result->fetch_assoc();
                                        $category_id = $category_row['id'];
                                        $messages[] = "Category '$category_name' already exists (ID: $category_id)";
                                    } else {
                                        // Create new category
                                        $stmt->close();
                                        $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                                        $description = "Imported from Excel/CSV";
                                        $stmt->bind_param("ss", $category_name, $description);
                                        
                                        if ($stmt->execute()) {
                                            $category_id = $conn->insert_id;
                                            $messages[] = "Created category: '$category_name' (ID: $category_id)";
                                        } else {
                                            throw new Exception("Failed to create category '$category_name': " . $stmt->error);
                                        }
                                    }
                                    $stmt->close();
                                    
                                    // Add ingredients to this category
                                    $unique_ingredients = array_unique($ingredients_list);
                                    foreach ($unique_ingredients as $ingredient_name) {
                                        $ingredient_name = trim($ingredient_name);
                                        if (empty($ingredient_name)) continue;
                                        
                                        // Translate ingredient name if needed
                                        $ingredient_name = translateForDatabase($ingredient_name);
                                        
                                        // Check if ingredient already exists
                                        $stmt = $conn->prepare("SELECT id FROM ingredients WHERE name = ?");
                                        $stmt->bind_param("s", $ingredient_name);
                                        $stmt->execute();
                                        $result = $stmt->get_result();
                                        
                                        if ($result->num_rows > 0) {
                                            $ingredient_row = $result->fetch_assoc();
                                            $existing_id = $ingredient_row['id'];
                                            $messages[] = "  - Ingredient '$ingredient_name' already exists (ID: $existing_id) - skipped";
                                        } else {
                                            // Create new ingredient
                                            $stmt->close();
                                            $unit = ''; // Empty unit
                                            $stmt = $conn->prepare("INSERT INTO ingredients (name, category_id, unit) VALUES (?, ?, ?)");
                                            $stmt->bind_param("sis", $ingredient_name, $category_id, $unit);
                                            
                                            if ($stmt->execute()) {
                                                $ingredient_id = $conn->insert_id;
                                                $messages[] = "  ✓ Added ingredient: '$ingredient_name' to '$category_name' (ID: $ingredient_id)";
                                            } else {
                                                $errors[] = "Failed to add ingredient '$ingredient_name': " . $stmt->error;
                                            }
                                        }
                                        $stmt->close();
                                    }
                                }
                                
                                $conn->commit();
                                $messages[] = "\n✅ Excel/CSV import completed successfully!";
                                
                            } catch (Exception $e) {
                                $conn->rollback();
                                $errors[] = "Error importing data: " . $e->getMessage();
                            }
                        }
                        
                        // Delete uploaded file after processing
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

// Process categories and ingredients (from hardcoded data)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import']) && !isset($_POST['upload_excel'])) {
    $conn->begin_transaction();
    
    try {
        foreach ($categories_data as $category_name => $ingredients_list) {
            // Check if category exists, if not create it
            $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
            $stmt->bind_param("s", $category_name);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $category_row = $result->fetch_assoc();
                $category_id = $category_row['id'];
                $messages[] = "Category '$category_name' already exists (ID: $category_id)";
            } else {
                // Create new category
                $stmt->close();
                $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
                $description = "Category for " . strtolower($category_name);
                $stmt->bind_param("ss", $category_name, $description);
                
                if ($stmt->execute()) {
                    $category_id = $conn->insert_id;
                    $messages[] = "Created category: '$category_name' (ID: $category_id)";
                } else {
                    throw new Exception("Failed to create category '$category_name': " . $stmt->error);
                }
            }
            $stmt->close();
            
            // Add ingredients to this category (remove duplicates)
            $unique_ingredients = array_unique($ingredients_list);
            foreach ($unique_ingredients as $ingredient_name) {
                $ingredient_name = trim($ingredient_name);
                if (empty($ingredient_name)) continue;
                
                // Check if ingredient already exists (by name only, not category)
                $stmt = $conn->prepare("SELECT id FROM ingredients WHERE name = ?");
                $stmt->bind_param("s", $ingredient_name);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $ingredient_row = $result->fetch_assoc();
                    $existing_id = $ingredient_row['id'];
                    $messages[] = "  - Ingredient '$ingredient_name' already exists (ID: $existing_id) - skipped";
                } else {
                    // Create new ingredient
                    $stmt->close();
                    $unit = ''; // Empty unit as per system design
                    $stmt = $conn->prepare("INSERT INTO ingredients (name, category_id, unit) VALUES (?, ?, ?)");
                    $stmt->bind_param("sis", $ingredient_name, $category_id, $unit);
                    
                    if ($stmt->execute()) {
                        $ingredient_id = $conn->insert_id;
                        $messages[] = "  ✓ Added ingredient: '$ingredient_name' to '$category_name' (ID: $ingredient_id)";
                    } else {
                        $errors[] = "Failed to add ingredient '$ingredient_name': " . $stmt->error;
                    }
                }
                $stmt->close();
            }
        }
        
        $conn->commit();
        $messages[] = "\n✅ Import completed successfully!";
        
    } catch (Exception $e) {
        $conn->rollback();
        $errors[] = "Error: " . $e->getMessage();
    }
}

$pageTitle = 'Import Categories & Ingredients';
include __DIR__ . '/../includes/header.php';
?>

<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0"><i class="bi bi-upload me-2"></i>Import Categories & Ingredients</h4>
            </div>
            <div class="card-body">
                <!-- Excel/CSV Upload Section -->
                <div class="card mb-4 border-0 shadow-sm">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-file-earmark-excel me-2"></i>Upload Excel/CSV File</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info mb-3">
                            <strong><i class="bi bi-info-circle me-2"></i>Excel/CSV File Format:</strong>
                            <ul class="mb-0 mt-2">
                                <li><strong>Column A:</strong> Ingredient Name</li>
                                <li><strong>Column B:</strong> Category Name</li>
                                <li>First row should be headers (will be skipped)</li>
                                <li>Supported formats: CSV, Excel (.xls, .xlsx)</li>
                                <li>Maximum file size: 10MB</li>
                            </ul>
                        </div>
                        
                        <form method="POST" enctype="multipart/form-data" class="mb-3">
                            <input type="hidden" name="upload_excel" value="1">
                            <div class="mb-3">
                                <label for="excel_file" class="form-label">Select Excel/CSV File</label>
                                <input type="file" class="form-control" id="excel_file" name="excel_file" 
                                       accept=".csv,.xls,.xlsx" required>
                                <small class="form-text text-muted">Upload a CSV or Excel file with Ingredient Name (Column A) and Category (Column B)</small>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-info btn-lg">
                                    <i class="bi bi-upload me-2"></i>Upload & Import from Excel/CSV
                                </button>
                                <button type="button" class="btn btn-outline-info btn-lg" onclick="downloadSampleTemplate()">
                                    <i class="bi bi-download me-2"></i>Download Sample Template
                                </button>
                            </div>
                        </form>
                        
                        <script>
                        function downloadSampleTemplate() {
                            // Create CSV content
                            const csvContent = "Ingredient Name,Category\n" +
                                "بادام گری,کربانه\n" +
                                "ستارا سونف,کربانه\n" +
                                "کالی مرچ پاؤڈر,کربانه\n" +
                                "سیب گولڈن,تازه پهل\n" +
                                "مرغی (۱۶ ٹکڑے),گوشت\n" +
                                "ادرک,سبزیاں\n" +
                                "ہرا دھنیا,سبزیاں\n" +
                                "دہی,بیکری";
                            
                            // Create blob and download
                            const blob = new Blob(["\ufeff" + csvContent], { type: 'text/csv;charset=utf-8;' });
                            const link = document.createElement('a');
                            const url = URL.createObjectURL(blob);
                            link.setAttribute('href', url);
                            link.setAttribute('download', 'ingredients_template.csv');
                            link.style.visibility = 'hidden';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                        }
                        </script>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <!-- Manual Import Section -->
                <div class="alert alert-info">
                    <strong><i class="bi bi-info-circle me-2"></i>About Manual Import:</strong>
                    <ul class="mb-0 mt-2">
                        <li>This will create categories and add all ingredients from the provided list below</li>
                        <li>Existing categories and ingredients will be skipped (no duplicates)</li>
                        <li>All ingredients will be added with empty unit field</li>
                    </ul>
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
                        <pre class="mt-2 mb-0" style="background: #f8f9fa; padding: 15px; border-radius: 5px; max-height: 500px; overflow-y: auto;"><?php echo htmlspecialchars(implode("\n", $messages)); ?></pre>
                    </div>
                <?php endif; ?>
                
                <div class="mb-4">
                    <h5>Categories to be created:</h5>
                    <ul>
                        <?php foreach (array_keys($categories_data) as $cat_name): ?>
                            <li><strong><?php echo htmlspecialchars($cat_name); ?></strong> (<?php echo count($categories_data[$cat_name]); ?> ingredients)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <form method="POST" onsubmit="return confirm('Are you sure you want to import all categories and ingredients? This will create new entries but skip duplicates.');">
                    <input type="hidden" name="import" value="1">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-upload me-2"></i>Import Categories & Ingredients
                    </button>
                    <a href="categories.php" class="btn btn-secondary btn-lg ms-2">
                        <i class="bi bi-arrow-left me-2"></i>Back to Categories
                    </a>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>


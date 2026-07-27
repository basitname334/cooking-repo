<?php
/**
 * Printable Ingredients List
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

requireAdmin();

$conn = getDBConnection();

$ingredients_by_category = [];

$rows = db_fetch_all(
    $conn,
    'SELECT c.id as category_id, c.name as category_name, c.description,
        i.id as ingredient_id, i.name as ingredient_name
     FROM categories c
     LEFT JOIN ingredients i ON i.category_id = c.id
     ORDER BY c.name, i.name'
);

foreach ($rows as $row) {
    $cat_id = $row['category_id'];
    if (!isset($ingredients_by_category[$cat_id])) {
        $ingredients_by_category[$cat_id] = [
            'name' => $row['category_name'] ?: 'Uncategorized',
            'description' => $row['description'] ?: '',
            'ingredients' => []
        ];
    }

    if (!empty($row['ingredient_id'])) {
        $ingredients_by_category[$cat_id]['ingredients'][] = $row['ingredient_name'];
    }
}

$generated_at = date('Y-m-d H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Printable Ingredients List</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: #f8fafc;
            font-family: "Inter", "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
        }
        .print-header {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(15, 23, 42, 0.08);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }
        .category-card {
            border-radius: 18px;
            border: none;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);
            margin-bottom: 1rem;
        }
        .ingredients-list li {
            padding: 0.35rem 0;
            border-bottom: 1px dashed #e2e8f0;
        }
        .ingredients-list li:last-child {
            border-bottom: none;
        }
        @media print {
            #actionBar {
                display: none !important;
            }
            body {
                background: white;
            }
            .print-header {
                box-shadow: none;
            }
            .category-card {
                box-shadow: none;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body class="py-4">
    <div class="container">
        <div class="print-header">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                <div>
                    <h1 class="fw-bold mb-2">
                        <i class="bi bi-basket2-fill text-primary me-2"></i>
                        Ingredients Catalog
                    </h1>
                    <p class="text-muted mb-1">Generated at: <?php echo htmlspecialchars($generated_at); ?></p>
                    <p class="text-muted mb-0">Total Categories: <?php echo count($ingredients_by_category); ?></p>
                </div>
                <div id="actionBar" class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="bi bi-printer me-2"></i>Print / Save as PDF
                    </button>
                    <button class="btn btn-outline-secondary" onclick="shareIngredients()">
                        <i class="bi bi-share me-2"></i>Share
                    </button>
                    <button class="btn btn-outline-dark" onclick="copyLink()">
                        <i class="bi bi-link-45deg me-2"></i>Copy Link
                    </button>
                </div>
            </div>
        </div>

        <?php if (empty($ingredients_by_category)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle me-2"></i>No ingredients found.
            </div>
        <?php else: ?>
            <?php foreach ($ingredients_by_category as $category): ?>
                <div class="card category-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <h4 class="card-title mb-1 text-primary fw-bold">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </h4>
                                <?php if (!empty($category['description'])): ?>
                                    <p class="text-muted small mb-0">
                                        <?php echo htmlspecialchars($category['description']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <span class="badge bg-primary-subtle text-primary">
                                <?php echo count($category['ingredients']); ?> items
                            </span>
                        </div>
                        <?php if (empty($category['ingredients'])): ?>
                            <p class="text-muted fst-italic mb-0">No ingredients in this category.</p>
                        <?php else: ?>
                            <ul class="ingredients-list list-unstyled mb-0">
                                <?php foreach ($category['ingredients'] as $ingredient): ?>
                                    <li><?php echo htmlspecialchars($ingredient); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        function shareIngredients() {
            const shareData = {
                title: 'Ingredients Catalog',
                text: 'Latest ingredients catalog (<?php echo htmlspecialchars($generated_at); ?>)',
                url: window.location.href
            };

            if (navigator.share) {
                navigator.share(shareData).catch(err => {
                    if (err.name !== 'AbortError') {
                        alert('Unable to share. Please copy the link instead.');
                    }
                });
            } else {
                copyLink(true);
            }
        }

        function copyLink(showAlert) {
            navigator.clipboard.writeText(window.location.href)
                .then(() => {
                    if (showAlert) {
                        alert('Link copied to clipboard!');
                    }
                }).catch(() => {
                    alert('Unable to copy link. Please copy it manually: ' + window.location.href);
                });
        }
    </script>
</body>
</html>


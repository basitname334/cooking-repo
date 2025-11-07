<?php
/**
 * Header Template
 * Common header for all pages
 */
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/language.php';

// Get current language settings
$current_lang = getCurrentLanguage();
$lang_dir = getLanguageDirection();
$available_langs = getAvailableLanguages();
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>" dir="<?php echo $lang_dir; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?php echo isset($pageTitle) ? $pageTitle : 'Food Management System'; ?></title>
    <?php
    // Determine base path based on file location
    $basePath = '';
    $currentFile = $_SERVER['PHP_SELF'];
    if (strpos($currentFile, '/admin/') !== false || strpos($currentFile, '/user/') !== false || strpos($currentFile, '/auth/') !== false) {
        $basePath = '../';
    }
    ?>
    <link rel="icon" type="image/jpeg" href="<?php echo $basePath; ?>images/logo.jpg">
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/style.css">
    <link rel="stylesheet" href="<?php echo $basePath; ?>assets/css/user-friendly.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <?php if ($lang_dir === 'rtl'): ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <?php endif; ?>
    <style>
        body[dir="rtl"] {
            direction: rtl;
            text-align: right;
        }
        body[dir="rtl"] .navbar-nav .nav-link-modern i {
            margin-left: 0.5rem;
            margin-right: 0;
        }
        body[dir="rtl"] .me-2 {
            margin-left: 0.5rem !important;
            margin-right: 0 !important;
        }
        body[dir="rtl"] .ms-2 {
            margin-right: 0.5rem !important;
            margin-left: 0 !important;
        }
        body[dir="rtl"] .me-auto {
            margin-left: auto !important;
            margin-right: 0 !important;
        }
        body[dir="rtl"] .ms-auto {
            margin-right: auto !important;
            margin-left: 0 !important;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-modern">
        <div class="container-fluid px-3 px-md-4">
            <a class="navbar-brand d-flex align-items-center" href="<?php echo $basePath; ?>index.php">
                <img src="<?php echo $basePath; ?>images/logo.jpg" alt="Logo" class="navbar-logo me-2">
                <div class="d-flex flex-column">
                    <span class="fw-bold fs-6"><?php e('brand_name'); ?></span>
                    <span class="d-none d-sm-inline small text-muted fw-normal"><?php e('brand_tagline'); ?></span>
                </div>
            </a>
            <button class="navbar-toggler border-0 p-2" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto ms-0 ms-md-3">
                    <?php if (isLoggedIn()): ?>
                        <?php if (isAdmin()): ?>
                            <?php
                            $currentPage = basename($_SERVER['PHP_SELF']);
                            $navItems = [
                                ['url' => 'dashboard.php', 'icon' => 'speedometer2', 'text' => 'Dashboard'],
                                ['url' => 'categories.php', 'icon' => 'folder2-open', 'text' => 'Categories'],
                                ['url' => 'ingredients.php', 'icon' => 'basket', 'text' => 'Ingredients'],
                                ['url' => 'dishes.php', 'icon' => 'egg-fried', 'text' => 'Dishes'],
                                ['url' => 'orders.php', 'icon' => 'cart-check', 'text' => 'Orders'],
                                ['url' => 'customers.php', 'icon' => 'people', 'text' => 'Customers'],
                            ];
                            foreach ($navItems as $item):
                                $isActive = ($currentPage === $item['url']);
                            ?>
                            <li class="nav-item">
                                <a class="nav-link nav-link-modern <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo $basePath; ?>admin/<?php echo $item['url']; ?>">
                                    <i class="bi bi-<?php echo $item['icon']; ?> me-2"></i>
                                    <span><?php echo $item['text']; ?></span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php
                            $currentPage = basename($_SERVER['PHP_SELF']);
                            $navItems = [
                                ['url' => 'dashboard.php', 'icon' => 'speedometer2', 'text' => 'Dashboard'],
                                ['url' => 'categories.php', 'icon' => 'folder2-open', 'text' => 'Categories'],
                                ['url' => 'dishes.php', 'icon' => 'egg-fried', 'text' => 'Dishes']
                            ];
                            foreach ($navItems as $item):
                                $isActive = ($currentPage === $item['url']);
                            ?>
                            <li class="nav-item">
                                <a class="nav-link nav-link-modern <?php echo $isActive ? 'active' : ''; ?>" href="<?php echo $basePath; ?>user/<?php echo $item['url']; ?>">
                                    <i class="bi bi-<?php echo $item['icon']; ?> me-2"></i>
                                    <span><?php echo $item['text']; ?></span>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <!-- Language Switcher -->
                    <li class="nav-item dropdown">
                        <a class="nav-link nav-link-modern dropdown-toggle d-flex align-items-center" href="#" id="languageDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-globe me-2"></i>
                            <span class="d-none d-md-inline"><?php echo $available_langs[$current_lang]['flag']; ?> <?php echo $available_langs[$current_lang]['name']; ?></span>
                            <span class="d-md-none"><?php echo $available_langs[$current_lang]['flag']; ?> <span class="d-none d-sm-inline"><?php echo $available_langs[$current_lang]['name']; ?></span></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg" aria-labelledby="languageDropdown">
                            <?php foreach ($available_langs as $lang_code => $lang_info): ?>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center <?php echo $current_lang === $lang_code ? 'active' : ''; ?>" 
                                       href="?lang=<?php echo $lang_code; ?>&redirect=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>">
                                        <span class="me-2"><?php echo $lang_info['flag']; ?></span>
                                        <span><?php echo $lang_info['name']; ?></span>
                                        <?php if ($current_lang === $lang_code): ?>
                                            <i class="bi bi-check-circle-fill ms-auto text-success"></i>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php if (isLoggedIn()): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link nav-link-modern dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-person-circle me-2"></i>
                                <span class="d-none d-md-inline"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></span>
                                <span class="d-md-none"><?php echo htmlspecialchars(substr($_SESSION['user_name'] ?? 'User', 0, 12)); ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow-lg" aria-labelledby="userDropdown">
                                <li class="dropdown-header">
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-person-circle me-2 fs-4"></i>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'User'); ?></div>
                                            <small class="text-muted"><?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?></small>
                                        </div>
                                    </div>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center" href="<?php echo $basePath; ?>auth/logout.php">
                                        <i class="bi bi-box-arrow-right me-2"></i>
                                        <span>Logout</span>
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link nav-link-modern d-flex align-items-center" href="<?php echo $basePath; ?>auth/login.php">
                                <i class="bi bi-box-arrow-in-right me-2"></i>
                                <span>Login</span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container-fluid mt-4" style="overflow-x: hidden; width: 100%; max-width: 100%;">

<?php
/**
 * Language Configuration
 * Handles multilingual support (English and Urdu)
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Default language
$default_lang = 'en';

// Available languages
$available_languages = [
    'en' => [
        'name' => 'English',
        'code' => 'en',
        'dir' => 'ltr',
        'flag' => '🇬🇧'
    ],
    'ur' => [
        'name' => 'اردو',
        'code' => 'ur',
        'dir' => 'rtl',
        'flag' => '🇵🇰'
    ]
];

// Get current language from session or default
function getCurrentLanguage() {
    global $default_lang;
    return $_SESSION['lang'] ?? $default_lang;
}

// Set language
function setLanguage($lang) {
    global $available_languages;
    if (array_key_exists($lang, $available_languages)) {
        $_SESSION['lang'] = $lang;
        return true;
    }
    return false;
}

// Get language direction (LTR or RTL)
function getLanguageDirection() {
    global $available_languages;
    $lang = getCurrentLanguage();
    return $available_languages[$lang]['dir'] ?? 'ltr';
}

// Get language name
function getLanguageName($lang = null) {
    global $available_languages;
    $lang = $lang ?? getCurrentLanguage();
    return $available_languages[$lang]['name'] ?? 'English';
}

// Get available languages
function getAvailableLanguages() {
    global $available_languages;
    return $available_languages;
}

// Handle language change request
if (isset($_GET['lang']) && isset($available_languages[$_GET['lang']])) {
    setLanguage($_GET['lang']);
    // Redirect to same page without lang parameter
    $redirect_url = strtok($_SERVER['REQUEST_URI'], '?');
    if (isset($_GET['redirect'])) {
        $redirect_url = $_GET['redirect'];
    }
    header('Location: ' . $redirect_url);
    exit;
}

// Load translation file
function loadTranslations() {
    $lang = getCurrentLanguage();
    $translation_file = __DIR__ . '/../translations/' . $lang . '.php';
    
    if (file_exists($translation_file)) {
        return include $translation_file;
    }
    
    // Fallback to English
    $translation_file = __DIR__ . '/../translations/en.php';
    if (file_exists($translation_file)) {
        return include $translation_file;
    }
    
    return [];
}

// Get translation
function t($key, $default = null) {
    static $translations = null;
    
    if ($translations === null) {
        $translations = loadTranslations();
    }
    
    return $translations[$key] ?? ($default ?? $key);
}

// Echo translation (shortcut)
function e($key, $default = null) {
    echo htmlspecialchars(t($key, $default));
}

/**
 * Translate text to Urdu if current language is Urdu
 * This function automatically translates English text to Urdu when the user has selected Urdu language
 * 
 * @param string $text The text to translate
 * @param string $source_lang Source language (default: 'en')
 * @return string Translated text or original text if translation fails or language is not Urdu
 */
function translateToUrdu($text, $source_lang = 'en') {
    // If text is empty, return as is
    if (empty(trim($text))) {
        return $text;
    }
    
    // Get current language
    $current_lang = getCurrentLanguage();
    
    // If current language is not Urdu, return original text
    if ($current_lang !== 'ur') {
        return $text;
    }
    
    // If text is already in Urdu (contains Urdu characters), return as is
    if (preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
        return $text;
    }
    
    // Try to translate using Google Translate API (free tier)
    // Note: For production use, you should set up a Google Cloud Translation API key
    // You can also use other translation services like MyMemory Translation API (free)
    try {
        $translated = translateText($text, $source_lang, 'ur');
        return !empty($translated) ? $translated : $text;
    } catch (Exception $e) {
        // If translation fails, return original text
        error_log("Translation error: " . $e->getMessage());
        return $text;
    }
}

/**
 * Translate text using MyMemory Translation API (free, no API key required)
 * Alternative: You can use Google Translate API if you have an API key
 * 
 * @param string $text Text to translate
 * @param string $source Source language code
 * @param string $target Target language code
 * @return string Translated text
 */
function translateText($text, $source = 'en', $target = 'ur') {
    // Use MyMemory Translation API (free, no API key needed for small volumes)
    $url = "https://api.mymemory.translated.net/get?q=" . urlencode($text) . "&langpair=" . $source . "|" . $target;
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['responseData']['translatedText']) && !empty($data['responseData']['translatedText'])) {
            return $data['responseData']['translatedText'];
        }
    }
    
    // Fallback: Return original text if translation fails
    return $text;
}

/**
 * Helper function to translate data fields before saving to database
 * This checks the current language and translates if needed
 * 
 * @param string $text Text to translate
 * @return string Translated text
 */
function translateForDatabase($text) {
    return translateToUrdu($text);
}

?>

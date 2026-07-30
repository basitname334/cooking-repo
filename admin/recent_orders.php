<?php
/**
 * Recent / All Orders list (navbar section)
 * Create form lives on orders.php — this page only lists & manages orders.
 */
if (!defined('ORDERS_PAGE_MODE')) {
    define('ORDERS_PAGE_MODE', 'list');
}
require __DIR__ . '/orders.php';

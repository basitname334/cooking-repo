<?php
/**
 * Payment Status Configuration
 * 
 * INSTRUCTIONS:
 * - To BLOCK the site (payment not received): Set PAYMENT_REQUIRED to true
 * - To ENABLE the site (payment received): Set PAYMENT_REQUIRED to false
 * 
 * When PAYMENT_REQUIRED is true, users will see a message that the site has been 
 * stopped due to non-payment and cannot access any pages.
 * 
 * To change this setting after payment:
 * 1. Open this file (config/payment_status.php)
 * 2. Change PAYMENT_REQUIRED from true to false
 * 3. Save the file
 * 
 * You can also customize the message shown to users by editing PAYMENT_MESSAGE below.
 */

// Set to true to block site access (payment not made)
// Set to false to enable site access (payment received)
define('PAYMENT_REQUIRED', false);

// Custom message to display (optional - can be customized)
define('PAYMENT_MESSAGE', 'This site has been stopped because payment is not made. Please contact the administrator for assistance.');

?>


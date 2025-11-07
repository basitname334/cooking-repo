-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 05, 2025 at 05:48 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `database`
--
CREATE DATABASE IF NOT EXISTS `database` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `database`;

-- --------------------------------------------------------

--
-- Table structure for table `admin_users`
--

CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `role` enum('admin','super_admin') DEFAULT 'admin',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_users`
--

INSERT IGNORE INTO `admin_users` (`id`, `username`, `email`, `password`, `full_name`, `role`, `created_at`, `updated_at`) VALUES
(1, 'greatpharma', 'admin@greatpharma.com', '$2y$10$cY0Ir0M/0jquQvd.tSXKfuLStPPrT.9YT9yLgTVBdbQW5l3oHRw3y', 'Great Pharma Admin', 'super_admin', '2025-10-04 14:11:19', '2025-10-04 14:11:19');

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE IF NOT EXISTS `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE IF NOT EXISTS `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT IGNORE INTO `categories` (`id`, `name`, `description`, `image`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Pain Relief', 'Medications for pain management and relief', NULL, 'active', '2025-10-04 14:11:19', '2025-10-04 14:11:19'),
(2, 'Cold & Flu', 'Medications for cold, flu, and respiratory issues', NULL, 'active', '2025-10-04 14:11:19', '2025-10-04 14:11:19'),
(3, 'Digestive Health', 'Medications for digestive problems and stomach issues', NULL, 'active', '2025-10-04 14:11:19', '2025-10-04 14:11:19'),
(4, 'Vitamins & Supplements', 'Essential vitamins and dietary supplements', NULL, 'active', '2025-10-04 14:11:19', '2025-10-04 14:11:19'),
(5, 'Prescription Drugs', 'Prescription medications requiring doctor approval', NULL, 'active', '2025-10-04 14:11:19', '2025-10-04 14:11:19'),
(6, 'First Aid', 'First aid supplies and emergency medications', NULL, 'active', '2025-10-04 14:11:19', '2025-10-04 14:11:19');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE IF NOT EXISTS `orders` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `shipping_address` text NOT NULL,
  `billing_address` text NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `payment_status` enum('pending','paid','failed','refunded') DEFAULT 'pending',
  `order_status` enum('pending','processing','shipped','delivered','cancelled') DEFAULT 'pending',
  `prescription_uploaded` tinyint(1) DEFAULT 0,
  `prescription_path` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE IF NOT EXISTS `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE IF NOT EXISTS `products` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `discount_price` decimal(10,2) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `sku` varchar(100) NOT NULL,
  `stock_quantity` int(11) DEFAULT 0,
  `min_stock_level` int(11) DEFAULT 5,
  `manufacturer` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `prescription_required` tinyint(1) DEFAULT 0,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT IGNORE INTO `products` (`id`, `name`, `description`, `price`, `discount_price`, `category_id`, `sku`, `stock_quantity`, `min_stock_level`, `manufacturer`, `expiry_date`, `prescription_required`, `image`, `status`, `created_at`, `updated_at`) VALUES
(1, 'Paracetamol 500mg', 'Effective pain relief and fever reducer', 15.99, NULL, 1, 'PAR500', 100, 5, 'Generic Pharma', NULL, 0, NULL, 'active', '2025-10-04 14:11:19', '2025-10-04 14:11:19'),
(2, 'Ibuprofen 400mg', 'Anti-inflammatory pain relief', 18.99, NULL, 1, 'IBU400', 80, 5, 'MediCorp', NULL, 0, NULL, 'active', '2025-10-04 14:11:19', '2025-10-04 14:11:19'),
(3, 'Vitamin C 1000mg', 'Immune system support and antioxidant', 12.99, NULL, 4, 'VITC1000', 150, 5, 'HealthPlus', NULL, 0, NULL, 'active', '2025-10-04 14:11:19', '2025-10-04 14:11:19'),
(4, 'Cough Syrup', 'Relief from dry and wet cough', 22.99, NULL, 2, 'COUGH100', 60, 5, 'RespiraMed', NULL, 0, NULL, 'active', '2025-10-04 14:11:19', '2025-10-04 14:11:19'),
(5, 'Antacid Tablets', 'Fast relief from heartburn and acidity', 8.99, NULL, 3, 'ANTACID50', 200, 5, 'DigestCare', NULL, 0, NULL, 'active', '2025-10-04 14:11:19', '2025-10-04 14:11:19');

-- --------------------------------------------------------

--
-- Table structure for table `product_images`
--

CREATE TABLE IF NOT EXISTS `product_images` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip_code` varchar(10) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_users`
--
ALTER TABLE `admin_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `cart`
--
ALTER TABLE `cart`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_product` (`user_id`,`product_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `product_images`
--
ALTER TABLE `product_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_users`
--
ALTER TABLE `admin_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `cart`
--
ALTER TABLE `cart`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `product_images`
--
ALTER TABLE `product_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `cart`
--
ALTER TABLE `cart`
  ADD CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_images`
--
ALTER TABLE `product_images`
  ADD CONSTRAINT `product_images_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;
--
-- Database: `food_management_system`
--
CREATE DATABASE IF NOT EXISTS `food_management_system` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `food_management_system`;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE IF NOT EXISTS `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT IGNORE INTO `categories` (`id`, `name`, `description`, `created_at`) VALUES
(26, 'کربانه', 'Category for کربانه', '2025-11-04 18:18:07'),
(27, 'تازه پهل', 'Category for تازه پهل', '2025-11-04 18:18:07'),
(28, 'گوشت', 'Category for گوشت', '2025-11-04 18:18:07'),
(29, 'کھانا پکانے کی اشیاء', 'Category for کھانا پکانے کی اشیاء', '2025-11-04 18:18:07'),
(30, 'سبزیاں', 'Category for سبزیاں', '2025-11-04 18:18:07'),
(31, 'بیکری', 'Category for بیکری', '2025-11-04 18:18:07');

-- --------------------------------------------------------

--
-- Table structure for table `dishes`
--

CREATE TABLE IF NOT EXISTS `dishes` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `number_of_persons` int(11) DEFAULT 1,
  `base_quantity` decimal(10,2) DEFAULT 1.00,
  `base_unit` varchar(50) DEFAULT 'serving',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `dishes`
--

INSERT IGNORE INTO `dishes` (`id`, `name`, `description`, `category_id`, `number_of_persons`, `base_quantity`, `base_unit`, `created_at`) VALUES
(8, 'چکن بریانی', '', 28, 50, 10.00, 'kg', '2025-11-04 19:45:57'),
(9, 'قورمہ', '', 28, 100, 10.00, 'piece', '2025-11-05 06:53:12'),
(10, 'کسٹرڈ', '', 31, 100, 10.00, 'portion', '2025-11-05 07:07:58');

-- --------------------------------------------------------

--
-- Table structure for table `dish_ingredients`
--

CREATE TABLE IF NOT EXISTS `dish_ingredients` (
  `id` int(11) NOT NULL,
  `dish_id` int(11) NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `dish_ingredients`
--

INSERT IGNORE INTO `dish_ingredients` (`id`, `dish_id`, `ingredient_id`, `quantity`, `unit`, `created_at`) VALUES
(26, 8, 80, 5.00, 'kg', '2025-11-04 20:12:09'),
(27, 8, 71, 10.00, 'kg', '2025-11-04 20:12:09'),
(28, 8, 61, 2.00, 'kg', '2025-11-04 20:12:09'),
(29, 8, 54, 40.00, 'g', '2025-11-04 20:12:09'),
(30, 8, 72, 20.00, 'g', '2025-11-04 20:12:09'),
(31, 8, 69, 200.00, 'g', '2025-11-04 20:12:09'),
(32, 8, 78, 20.00, 'g', '2025-11-04 20:12:09'),
(33, 8, 51, 50.00, 'g', '2025-11-04 20:12:09'),
(34, 8, 77, 50.00, 'g', '2025-11-04 20:12:09'),
(35, 8, 70, 50.00, 'g', '2025-11-04 20:12:09'),
(36, 8, 76, 100.00, 'ml', '2025-11-04 20:12:09'),
(37, 8, 50, 20.00, 'g', '2025-11-04 20:12:09'),
(38, 8, 53, 100.00, 'g', '2025-11-04 20:12:09'),
(39, 8, 60, 100.00, 'g', '2025-11-04 20:12:09'),
(40, 8, 52, 100.00, 'g', '2025-11-04 20:12:09'),
(41, 8, 65, 1.00, 'piece', '2025-11-04 20:12:09'),
(42, 8, 73, 2.00, 'piece', '2025-11-04 20:12:09'),
(43, 8, 86, 100.00, 'g', '2025-11-04 20:12:09'),
(44, 8, 83, 100.00, 'g', '2025-11-04 20:12:09'),
(45, 8, 88, 500.00, 'g', '2025-11-04 20:12:09'),
(46, 8, 85, 700.00, 'g', '2025-11-04 20:12:09'),
(47, 8, 87, 4.00, 'pieces', '2025-11-04 20:12:09'),
(48, 8, 89, 2.00, 'kg', '2025-11-04 20:12:09'),
(49, 8, 81, 1.00, 'piece', '2025-11-04 20:12:09'),
(50, 8, 82, 15.00, 'kg', '2025-11-04 20:12:09'),
(51, 9, 80, 10.00, 'kg', '2025-11-05 06:53:12'),
(52, 9, 69, 50.00, 'g', '2025-11-05 06:53:12'),
(53, 9, 78, 20.00, 'g', '2025-11-05 06:53:12'),
(54, 9, 51, 50.00, 'g', '2025-11-05 06:53:12'),
(55, 9, 77, 50.00, 'g', '2025-11-05 06:53:12'),
(56, 9, 58, 30.00, 'g', '2025-11-05 06:53:12'),
(57, 9, 63, 10.00, 'g', '2025-11-05 06:53:13'),
(58, 9, 70, 70.00, 'g', '2025-11-05 06:53:13'),
(59, 9, 75, 50.00, 'g', '2025-11-05 06:53:13'),
(60, 9, 68, 100.00, 'g', '2025-11-05 06:53:13'),
(61, 9, 61, 1.50, 'kg', '2025-11-05 06:53:13'),
(62, 9, 54, 60.00, 'g', '2025-11-05 06:53:13'),
(63, 9, 76, 1.00, 'liter', '2025-11-05 06:53:13'),
(64, 9, 53, 20.00, 'g', '2025-11-05 06:53:13'),
(65, 9, 56, 50.00, 'g', '2025-11-05 06:53:13'),
(66, 9, 59, 125.00, 'g', '2025-11-05 06:53:13'),
(67, 9, 52, 200.00, 'g', '2025-11-05 06:53:13'),
(68, 9, 73, 4.00, 'pieces', '2025-11-05 06:53:13'),
(69, 9, 65, 1.00, 'piece', '2025-11-05 06:53:13'),
(70, 9, 86, 100.00, 'g', '2025-11-05 06:53:13'),
(71, 9, 83, 100.00, 'g', '2025-11-05 06:53:13'),
(72, 9, 88, 125.00, 'g', '2025-11-05 06:53:13'),
(73, 9, 85, 70.00, 'g', '2025-11-05 06:53:13'),
(74, 9, 84, 100.00, 'g', '2025-11-05 06:53:13'),
(75, 9, 89, 2.00, 'kg', '2025-11-05 06:53:13'),
(76, 9, 55, 4.00, 'pieces', '2025-11-05 06:53:13'),
(77, 9, 81, 1.00, 'piece', '2025-11-05 06:53:13'),
(78, 9, 82, 15.00, 'kg', '2025-11-05 06:53:13'),
(79, 10, 74, 5.00, 'kg', '2025-11-05 07:07:58'),
(80, 10, 50, 1.00, 'kg', '2025-11-05 07:07:58'),
(81, 10, 57, 8.00, 'pieces', '2025-11-05 07:07:58'),
(82, 10, 49, 1.00, 'kg', '2025-11-05 07:07:58'),
(83, 10, 64, 1.00, 'kg', '2025-11-05 07:07:58'),
(84, 10, 66, 25.00, 'kg', '2025-11-05 07:07:58'),
(85, 10, 55, 4.00, 'kg', '2025-11-05 07:07:58'),
(86, 10, 67, 2.00, 'pieces', '2025-11-05 07:07:58'),
(87, 10, 79, 6.00, 'kg', '2025-11-05 07:07:58'),
(88, 10, 81, 2.00, 'pieces', '2025-11-05 07:07:58'),
(89, 10, 82, 15.00, 'kg', '2025-11-05 07:07:58');

-- --------------------------------------------------------

--
-- Table structure for table `ingredients`
--

CREATE TABLE IF NOT EXISTS `ingredients` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category_id` int(11) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `ingredients`
--

INSERT IGNORE INTO `ingredients` (`id`, `name`, `category_id`, `unit`, `created_at`) VALUES
(49, 'بادام گری', 26, '', '2025-11-04 18:18:07'),
(50, 'ستارا سونف', 26, '', '2025-11-04 18:18:07'),
(51, 'کالی مرچ پاؤڈر', 26, '', '2025-11-04 18:18:07'),
(52, 'بونس سرف', 26, '', '2025-11-04 18:18:07'),
(53, 'چائنا نمک', 26, '', '2025-11-04 18:18:07'),
(54, 'دهنیا ثابت', 26, '', '2025-11-04 18:18:07'),
(55, 'ملائی (اولپرز)', 26, '', '2025-11-04 18:18:07'),
(56, 'کڑی پاؤڈر (نیشنل)', 26, '', '2025-11-04 18:18:07'),
(57, 'کسٹرڈ ونیلا', 26, '', '2025-11-04 18:18:07'),
(58, 'چھوٹی الائچی', 26, '', '2025-11-04 18:18:07'),
(59, 'تلی پیاز (شاہ فوڈ)', 26, '', '2025-11-04 18:18:07'),
(60, 'گرم مصالحه (ثابت)', 26, '', '2025-11-04 18:18:07'),
(61, 'حبیب بنسبتی', 26, '', '2025-11-04 18:18:07'),
(62, 'کشمش (سندر خوانی)', 26, '', '2025-11-04 18:18:07'),
(63, 'جاوتری', 26, '', '2025-11-04 18:18:07'),
(64, 'کاجو', 26, '', '2025-11-04 18:18:07'),
(65, 'ماچس باکس', 26, '', '2025-11-04 18:18:07'),
(66, 'دودھ (اولپرز)', 26, '', '2025-11-04 18:18:07'),
(67, 'مخلوط پھل (۳ کلو ڈبہ)', 26, '', '2025-11-04 18:18:07'),
(68, 'نیشنل نمک', 26, '', '2025-11-04 18:18:07'),
(69, 'آلو بخاره', 26, '', '2025-11-04 18:18:07'),
(70, 'لال مرچ (ثابت)', 26, '', '2025-11-04 18:18:07'),
(71, 'پکی چاول', 26, '', '2025-11-04 18:18:07'),
(72, 'سونف', 26, '', '2025-11-04 18:18:07'),
(73, 'اسپنج/استری', 26, '', '2025-11-04 18:18:07'),
(74, 'چینی', 26, '', '2025-11-04 18:18:07'),
(75, 'بلدی پاؤڈر', 26, '', '2025-11-04 18:18:07'),
(76, 'سرکه (سفید)', 26, '', '2025-11-04 18:18:07'),
(77, 'سفید مرچ پاؤڈر', 26, '', '2025-11-04 18:18:07'),
(78, 'زیره سفید', 26, '', '2025-11-04 18:18:07'),
(79, 'سیب گولڈن', 27, '', '2025-11-04 18:18:07'),
(80, 'مرغی (۱۶ ٹکڑے)', 28, '', '2025-11-04 18:18:07'),
(81, 'کھانا پکانے کی اشیاء ململ کپڑا', 29, '', '2025-11-04 18:18:07'),
(82, 'کھانا پکانے کی اشیاء لکڑی', 29, '', '2025-11-04 18:18:07'),
(83, 'ادرک', 30, '', '2025-11-04 18:18:07'),
(84, 'ہرا دھنیا', 30, '', '2025-11-04 18:18:07'),
(85, 'ہری مرچ', 30, '', '2025-11-04 18:18:07'),
(86, 'لهسن', 30, '', '2025-11-04 18:18:07'),
(87, 'پودینہ', 30, '', '2025-11-04 18:18:07'),
(88, 'ٹماٹر', 30, '', '2025-11-04 18:18:07'),
(89, 'دہی', 31, '', '2025-11-04 18:18:07');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE IF NOT EXISTS `orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `dish_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `order_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','confirmed','preparing','ready','delivered','cancelled') DEFAULT 'pending',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `orders`
--

INSERT IGNORE INTO `orders` (`id`, `order_number`, `customer_id`, `dish_id`, `quantity`, `total_amount`, `order_date`, `status`, `notes`) VALUES
(8, 'ORD-000008', 2, 8, 1.00, 2500.00, '2025-11-04 20:12:58', 'pending', ''),
(9, 'ORD-000009', 2, 9, 1.00, 0.00, '2025-11-05 07:11:44', 'pending', ''),
(10, 'ORD-000010', 2, 8, 1.00, 0.00, '2025-11-05 07:14:42', 'pending', ''),
(11, 'ORD-000011', 2, 9, 1.00, 0.00, '2025-11-05 07:14:42', 'pending', ''),
(12, 'ORD-000012', 2, 10, 1.00, 0.00, '2025-11-05 07:14:42', 'pending', ''),
(13, 'ORD-20251105-327248', 2, 10, 1.00, 0.00, '2025-11-05 07:20:48', 'pending', ''),
(14, 'ORD-20251105-327248', 2, 8, 1.00, 0.00, '2025-11-05 07:20:48', 'pending', ''),
(15, 'ORD-20251105-327248', 2, 9, 1.00, 0.00, '2025-11-05 07:20:48', 'pending', ''),
(16, 'ORD-20251105-327446', 2, 8, 3.00, 0.00, '2025-11-05 07:24:06', 'pending', ''),
(17, 'ORD-20251105-327446', 2, 9, 3.00, 0.00, '2025-11-05 07:24:06', 'pending', ''),
(18, 'ORD-20251105-327446', 2, 10, 3.00, 0.00, '2025-11-05 07:24:06', 'pending', '');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT IGNORE INTO `users` (`id`, `name`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'Admin', 'admin@example.com', '$2y$10$ttMOiyItFQoHog2Lcawi8.sBrO6RRJTUUtAm6O5PTpHq7PlNt3dB6', 'admin', '2025-11-04 13:52:22'),
(2, 'mc;ldsm', 'zainkhalid0347@gmail.com', '$2y$10$moHx42HH1CaVGBgGb/9iUegBCnzUwbktC3Qf2ikUGVr80HoyT6oPG', 'user', '2025-11-04 14:49:47');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `dishes`
--
ALTER TABLE `dishes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `dish_ingredients`
--
ALTER TABLE `dish_ingredients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_dish_ingredient` (`dish_id`,`ingredient_id`),
  ADD KEY `ingredient_id` (`ingredient_id`);

--
-- Indexes for table `ingredients`
--
ALTER TABLE `ingredients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `dish_id` (`dish_id`),
  ADD KEY `idx_order_number` (`order_number`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `dishes`
--
ALTER TABLE `dishes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `dish_ingredients`
--
ALTER TABLE `dish_ingredients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `ingredients`
--
ALTER TABLE `ingredients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=90;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `dishes`
--
ALTER TABLE `dishes`
  ADD CONSTRAINT `dishes_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dish_ingredients`
--
ALTER TABLE `dish_ingredients`
  ADD CONSTRAINT `dish_ingredients_ibfk_1` FOREIGN KEY (`dish_id`) REFERENCES `dishes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `dish_ingredients_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ingredients`
--
ALTER TABLE `ingredients`
  ADD CONSTRAINT `ingredients_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`dish_id`) REFERENCES `dishes` (`id`) ON DELETE CASCADE;
--
-- Database: `schema`
--
CREATE DATABASE IF NOT EXISTS `schema` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `schema`;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE IF NOT EXISTS `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dishes`
--

CREATE TABLE IF NOT EXISTS `dishes` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dish_ingredients`
--

CREATE TABLE IF NOT EXISTS `dish_ingredients` (
  `id` int(11) NOT NULL,
  `dish_id` int(11) NOT NULL,
  `ingredient_id` int(11) NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ingredients`
--

CREATE TABLE IF NOT EXISTS `ingredients` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category_id` int(11) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT IGNORE INTO `users` (`id`, `name`, `email`, `password`, `role`, `created_at`) VALUES
(1, 'Admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', '2025-11-04 12:23:42');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `dishes`
--
ALTER TABLE `dishes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `dish_ingredients`
--
ALTER TABLE `dish_ingredients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_dish_ingredient` (`dish_id`,`ingredient_id`),
  ADD KEY `ingredient_id` (`ingredient_id`);

--
-- Indexes for table `ingredients`
--
ALTER TABLE `ingredients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dishes`
--
ALTER TABLE `dishes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dish_ingredients`
--
ALTER TABLE `dish_ingredients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ingredients`
--
ALTER TABLE `ingredients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `dishes`
--
ALTER TABLE `dishes`
  ADD CONSTRAINT `dishes_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `dish_ingredients`
--
ALTER TABLE `dish_ingredients`
  ADD CONSTRAINT `dish_ingredients_ibfk_1` FOREIGN KEY (`dish_id`) REFERENCES `dishes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `dish_ingredients_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ingredients`
--
ALTER TABLE `ingredients`
  ADD CONSTRAINT `ingredients_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- Food Management System - Seed data (MySQL)
-- Applied automatically by ensureSeedData() when categories table is empty.
-- Manual use: mysql -u USER -p DB_NAME < database/seed.sql

SET NAMES utf8mb4;

-- Admin is created by ensureAdminUser() (admin@example.com / admin123)

INSERT IGNORE INTO `categories` (`name`, `description`) VALUES
('Spices', 'Spices and dry masala'),
('Meat', 'Chicken, mutton and other meats'),
('Vegetables', 'Fresh vegetables'),
('Dairy & Bakery', 'Milk, yogurt, custard and bakery items'),
('Staples', 'Rice, oil and cooking staples');

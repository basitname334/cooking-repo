-- Food Management System - PostgreSQL schema
-- Applied automatically by ensureDatabaseSetup() on first connect.
-- Manual: psql "$DATABASE_URL" -f database/schema.pgsql.sql

CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user' CHECK (role IN ('admin', 'user')),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ingredients (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category_id INT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    unit VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS dishes (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    category_id INT NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    number_of_persons INT DEFAULT 1,
    base_quantity DECIMAL(10,2) DEFAULT 1.00,
    base_unit VARCHAR(50) DEFAULT 'serving',
    image TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS dish_ingredients (
    id SERIAL PRIMARY KEY,
    dish_id INT NOT NULL REFERENCES dishes(id) ON DELETE CASCADE,
    ingredient_id INT NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    quantity DECIMAL(10, 2) NOT NULL,
    unit VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (dish_id, ingredient_id)
);

CREATE TABLE IF NOT EXISTS orders (
    id SERIAL PRIMARY KEY,
    order_number VARCHAR(50) DEFAULT NULL,
    customer_id INT DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
    dish_id INT NOT NULL REFERENCES dishes(id) ON DELETE CASCADE,
    quantity DECIMAL(10, 2) NOT NULL,
    unit VARCHAR(50) DEFAULT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) DEFAULT 'pending'
        CHECK (status IN ('pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled')),
    notes TEXT,
    extra_ingredients TEXT,
    customer_name VARCHAR(100) DEFAULT NULL,
    customer_cell VARCHAR(20) DEFAULT NULL,
    delivery_date DATE DEFAULT NULL,
    delivery_time TIME DEFAULT NULL,
    shift VARCHAR(20) DEFAULT NULL,
    number_of_persons INT DEFAULT NULL,
    payment_type VARCHAR(20) DEFAULT 'cash',
    paid_amount DECIMAL(10, 2) DEFAULT 0
);

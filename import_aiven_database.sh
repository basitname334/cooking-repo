#!/bin/bash

# Aiven MySQL Database Import Script
# This script imports your database schema into Aiven MySQL

# Database connection details
DB_HOST="mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com"
DB_USER="avnadmin"
DB_PASS="AVNS_tGhVB4Sijemqa7ffP4M"
DB_NAME="defaultdb"
DB_PORT="18568"

echo "=========================================="
echo "Aiven MySQL Database Import"
echo "=========================================="
echo "Host: $DB_HOST"
echo "Database: $DB_NAME"
echo "Port: $DB_PORT"
echo "=========================================="
echo ""

# Test connection first
echo "Testing connection..."
mysql --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --port="$DB_PORT" --ssl-mode=REQUIRED "$DB_NAME" -e "SELECT 1 + 2 as three;" 2>/dev/null

if [ $? -eq 0 ]; then
    echo "✓ Connection successful!"
    echo ""
    
    # Ask user if they want to continue
    read -p "Do you want to import the database schema? (y/n): " -n 1 -r
    echo ""
    
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "Importing database schema..."
        echo ""
        
        # Create a temporary SQL file with only the food_management_system database schema
        # Extract the relevant part from database.sql
        echo "Preparing SQL file..."
        
        # Import the schema
        mysql --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --port="$DB_PORT" --ssl-mode=REQUIRED "$DB_NAME" < database/database.sql
        
        if [ $? -eq 0 ]; then
            echo "✓ Database schema imported successfully!"
            echo ""
            echo "Note: If you see 'database already exists' errors, that's normal."
            echo "The important tables should be created."
        else
            echo "✗ Error importing database schema"
            echo "You may need to manually edit database/database.sql to:"
            echo "  1. Remove CREATE DATABASE statements"
            echo "  2. Change database name from food_management_system to defaultdb"
        fi
    else
        echo "Import cancelled."
    fi
else
    echo "✗ Connection failed!"
    echo "Please check your credentials and network connection."
    exit 1
fi

echo ""
echo "=========================================="
echo "Next steps:"
echo "1. Set environment variables in Render:"
echo "   DB_HOST=$DB_HOST"
echo "   DB_USER=$DB_USER"
echo "   DB_PASS=$DB_PASS"
echo "   DB_NAME=$DB_NAME"
echo "   DB_PORT=$DB_PORT"
echo "   DB_SSL_REQUIRED=true"
echo ""
echo "2. Deploy your application on Render"
echo "3. Visit: https://your-app.onrender.com"
echo "4. Login with: admin@example.com / admin123"
echo "=========================================="


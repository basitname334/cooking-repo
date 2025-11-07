# Aiven MySQL Database Setup for Render

## Your Database Connection Details

Based on your Aiven connection string, here are your database credentials:

```
DB_HOST=mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com
DB_USER=avnadmin
DB_PASS=AVNS_tGhVB4Sijemqa7ffP4M
DB_NAME=defaultdb
DB_PORT=18568
```

**Important Notes:**
- Your database name is `defaultdb`
- SSL is **REQUIRED** for Aiven connections
- Port is `18568` (not the standard 3306)

## Step 1: Set Environment Variables in Render

1. Go to your Render web service dashboard
2. Click on **"Environment"** tab
3. Add these environment variables:

```
DB_HOST=mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com
DB_USER=avnadmin
DB_PASS=AVNS_tGhVB4Sijemqa7ffP4M
DB_NAME=defaultdb
DB_PORT=18568
```

**Important:** After adding these, your service will automatically redeploy.

## Step 2: Update Database Configuration for SSL

Aiven requires SSL connections. We need to update the database connection to support SSL.

## Step 3: Import Database Schema

You have two options:

### Option A: Using MySQL Command Line (Recommended)

1. Install MySQL client if not already installed
2. Run this command (replace with your actual password):

```bash
mysql --user avnadmin --password=AVNS_tGhVB4Sijemqa7ffP4M --host mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com --port 18568 --ssl-mode=REQUIRED defaultdb < database/database.sql
```

**Note:** You may need to modify the SQL file to:
- Remove CREATE DATABASE statements (database already exists)
- Use `defaultdb` instead of `food_management_system`

### Option B: Using Import Script

1. Deploy your app on Render first
2. Visit: `https://your-app.onrender.com/import_database.php`
3. The script will import the schema
4. **DELETE** `import_database.php` after importing!

## Step 4: Verify Connection

Test the connection:
```bash
mysql --user avnadmin --password=AVNS_tGhVB4Sijemqa7ffP4M --host mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com --port 18568 --ssl-mode=REQUIRED defaultdb -e "SELECT 1 + 2 as three;"
```

Expected output:
```
+-------+
| three |
+-------+
|     3 |
+-------+
```

## Important: Database Name Decision

Your Aiven database is named `defaultdb`. You have two options:

### Option 1: Use `defaultdb` (Easier)
- Update `DB_NAME` environment variable to `defaultdb`
- Import your schema into `defaultdb`
- No need to create a new database

### Option 2: Create New Database (Better Organization)
- Connect to Aiven
- Create a new database: `food_management_system`
- Update `DB_NAME` to `food_management_system`
- Import schema into the new database

## Security Notes

⚠️ **IMPORTANT:**
- Never commit passwords to Git
- Use environment variables only
- Delete `import_database.php` after use
- Change default admin password after first login


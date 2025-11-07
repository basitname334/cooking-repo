# Deploying Food Management System on Render

This guide will walk you through deploying your PHP Food Management System on Render with a MySQL database.

## Prerequisites

- A GitHub account (or GitLab/Bitbucket)
- Your code pushed to a Git repository
- A Render account (sign up at https://render.com)

## Step-by-Step Deployment Guide

### Step 1: Push Your Code to GitHub

1. Initialize git repository (if not already done):
   ```bash
   git init
   git add .
   git commit -m "Initial commit with Dockerfile for Render"
   ```

2. Create a new repository on GitHub and push your code:
   ```bash
   git remote add origin https://github.com/yourusername/your-repo-name.git
   git branch -M main
   git push -u origin main
   ```

### Step 2: Create MySQL Database on Render

1. Log in to your Render dashboard: https://dashboard.render.com
2. Click **"New +"** button
3. Select **"PostgreSQL"** or **"MySQL"** (if available)
   - **Note**: Render's free tier offers PostgreSQL by default. For MySQL, you may need to use an external service like **PlanetScale**, **Aiven**, or **Railway**.
   - **Alternative**: Use Render's PostgreSQL and adjust your code, OR use a free MySQL hosting service.

#### Option A: Using External MySQL Service (Recommended for MySQL)

**PlanetScale (Free Tier):**
1. Sign up at https://planetscale.com
2. Create a new database
3. Get connection details (host, user, password, database name, port)

**Aiven (Free Tier):**
1. Sign up at https://aiven.io
2. Create a MySQL service
3. Get connection details from the service overview

**Railway (Free Tier):**
1. Sign up at https://railway.app
2. Create a new project
3. Add MySQL database
4. Get connection details

#### Option B: Using Render PostgreSQL (Requires Code Changes)

If you prefer to use Render's native PostgreSQL:
- You'll need to change `mysqli` to `pgsql` in your PHP code
- Update connection strings
- Adjust SQL syntax differences

### Step 3: Create Web Service on Render

1. In Render dashboard, click **"New +"**
2. Select **"Web Service"**
3. Connect your GitHub repository
4. Configure the service:
   - **Name**: `food-management-system` (or your preferred name)
   - **Environment**: `Docker`
   - **Region**: Choose closest to you
   - **Branch**: `main` (or your default branch)
   - **Root Directory**: Leave empty (root)
   - **Dockerfile Path**: `Dockerfile`
   - **Docker Context**: `.` (current directory)
   - **Instance Type**: `Free`

### Step 4: Configure Environment Variables

In the Render web service settings, go to **"Environment"** and add these variables:

#### If using External MySQL (PlanetScale, Aiven, Railway, etc.):
```
DB_HOST=your-database-host.planetscale.com
DB_USER=your-database-user
DB_PASS=your-database-password
DB_NAME=your-database-name
DB_PORT=3306
```

#### If using Render PostgreSQL (after code changes):
```
DB_HOST=your-postgres-host.onrender.com
DB_USER=your-postgres-user
DB_PASS=your-postgres-password
DB_NAME=your-postgres-database
DB_PORT=5432
```

**Important Notes:**
- Never commit these values to Git
- Render allows you to set these in the dashboard
- For external MySQL services, get these values from their dashboard

### Step 5: Import Database Schema

After your web service is deployed and database is ready:

#### Option 1: Using phpMyAdmin (if available)
1. Access your database through the MySQL provider's interface
2. Import the `database/database.sql` file
3. Make sure to select the correct database

#### Option 2: Using MySQL Command Line
1. Get your database connection string from your MySQL provider
2. Run:
   ```bash
   mysql -h [HOST] -u [USER] -p [DATABASE_NAME] < database/database.sql
   ```

#### Option 3: Using Render Shell (if using Render database)
1. Go to your database service in Render
2. Click "Connect" to get connection details
3. Use a MySQL client to connect and import the schema

#### Option 4: Using a PHP Script
Create a temporary import script (remember to delete it after):

1. Create `import_db.php` in your project root:
   ```php
   <?php
   require_once 'config/database.php';
   $sql = file_get_contents('database/database.sql');
   $conn = getDBConnection();
   
   // Execute SQL (you may need to split by semicolons)
   $statements = explode(';', $sql);
   foreach ($statements as $statement) {
       $statement = trim($statement);
       if (!empty($statement)) {
           $conn->query($statement);
       }
   }
   echo "Database imported successfully!";
   ?>
   ```

2. Access it via: `https://your-app.onrender.com/import_db.php`
3. **DELETE this file after importing!**

### Step 6: Verify Deployment

1. Visit your Render web service URL (e.g., `https://food-management-system.onrender.com`)
2. You should see the home page
3. Try logging in with default admin credentials:
   - **Email**: `admin@example.com`
   - **Password**: `admin123`

### Step 7: Update Database Connection (If Needed)

If you encounter connection errors:

1. Check environment variables are set correctly in Render dashboard
2. Verify database is accessible from Render's IP (some services require IP whitelisting)
3. Check database logs in your MySQL provider's dashboard
4. Verify the database name matches exactly

## Troubleshooting

### Database Connection Errors

**Error: "Can't connect to MySQL server"**
- Verify DB_HOST is correct (without port number in host)
- Check DB_PORT is set to 3306
- Ensure database allows connections from Render's IPs
- Verify credentials are correct

**Error: "Access denied"**
- Double-check username and password
- Ensure database user has proper permissions
- Verify database name is correct

### Application Errors

**Error: "Table doesn't exist"**
- Database schema not imported
- Run the import script or manually create tables
- Check `database/database.sql` was imported correctly

**Error: "Permission denied"**
- Check file permissions in Dockerfile
- Verify uploads directory is writable
- Check Render service logs

### Checking Logs

1. Go to your Render web service dashboard
2. Click on **"Logs"** tab
3. Look for error messages
4. Common issues will be displayed here

## Post-Deployment Checklist

- [ ] Database schema imported successfully
- [ ] Can access home page
- [ ] Can register new user
- [ ] Can login with admin account
- [ ] Admin dashboard works
- [ ] File uploads work (if applicable)
- [ ] All features tested

## Security Recommendations

1. **Change default admin password** immediately after first login
2. **Remove/secure** any database import scripts
3. **Use environment variables** for all sensitive data
4. **Enable HTTPS** (Render provides this automatically)
5. **Regular backups** of your database
6. **Update dependencies** regularly

## Updating Your Application

1. Make changes to your code
2. Commit and push to GitHub:
   ```bash
   git add .
   git commit -m "Your update message"
   git push
   ```
3. Render will automatically detect changes and redeploy
4. Check deployment logs in Render dashboard

## Cost Information

- **Render Free Tier**: 
  - Web services: Free (may spin down after inactivity)
  - Databases: PostgreSQL free tier available
  - External MySQL: Check provider's free tier limits

- **For Production**: Consider upgrading to paid plans for:
  - Always-on service (no spin-down)
  - Better performance
  - More resources

## Support Resources

- Render Documentation: https://render.com/docs
- Render Community: https://community.render.com
- PHP Docker Images: https://hub.docker.com/_/php
- MySQL Documentation: https://dev.mysql.com/doc/

## Alternative: Using Docker Compose Locally

To test the Docker setup locally before deploying:

```bash
# Build and run
docker-compose up -d

# Check logs
docker-compose logs -f

# Stop
docker-compose down
```

Create `docker-compose.yml`:
```yaml
version: '3.8'

services:
  web:
    build: .
    ports:
      - "8080:80"
    environment:
      - DB_HOST=db
      - DB_USER=root
      - DB_PASS=rootpassword
      - DB_NAME=food_management_system
      - DB_PORT=3306
    depends_on:
      - db

  db:
    image: mysql:8.0
    environment:
      - MYSQL_ROOT_PASSWORD=rootpassword
      - MYSQL_DATABASE=food_management_system
    ports:
      - "3306:3306"
    volumes:
      - db_data:/var/lib/mysql

volumes:
  db_data:
```

---

**Need Help?** Check Render's documentation or community forums for assistance.


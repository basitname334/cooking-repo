# Quick Setup Guide for Aiven MySQL on Render

## ✅ Your Database Details

You're using **Aiven MySQL** with these credentials:

```
Host: mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com
Port: 18568
User: avnadmin
Password: AVNS_tGhVB4Sijemqa7ffP4M
Database: defaultdb
SSL: REQUIRED
```

## 🚀 Step-by-Step Deployment

### Step 1: Set Environment Variables in Render

1. Go to your Render dashboard: https://dashboard.render.com
2. Navigate to your **Web Service** (or create a new one)
3. Click on **"Environment"** tab
4. Add these **6 environment variables**:

| Key | Value |
|-----|-------|
| `DB_HOST` | `mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com` |
| `DB_USER` | `avnadmin` |
| `DB_PASS` | `AVNS_tGhVB4Sijemqa7ffP4M` |
| `DB_NAME` | `defaultdb` |
| `DB_PORT` | `18568` |
| `DB_SSL_REQUIRED` | `true` |

**💡 Tip:** You can copy these from `RENDER_ENV_VARIABLES.txt` file

### Step 2: Import Database Schema

You have **3 options** to import your database:

#### Option A: Using MySQL Command Line (Easiest)

1. Install MySQL client (if not installed):
   ```bash
   # Windows (with XAMPP, you already have it)
   # Path: C:\xampp\mysql\bin\mysql.exe
   
   # Or download from: https://dev.mysql.com/downloads/mysql/
   ```

2. Navigate to your project folder and run:
   ```bash
   mysql --user=avnadmin --password=AVNS_tGhVB4Sijemqa7ffP4M --host=mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com --port=18568 --ssl-mode=REQUIRED defaultdb < database/database.sql
   ```

3. If you get errors about "database already exists", edit `database/database.sql`:
   - Remove or comment out `CREATE DATABASE` statements
   - Change `USE food_management_system;` to `USE defaultdb;` or remove it

#### Option B: Using Import Script (After Deployment)

1. Deploy your app on Render first
2. Wait for deployment to complete
3. Visit: `https://your-app.onrender.com/import_database.php`
4. The script will import tables automatically
5. **⚠️ IMPORTANT:** Delete `import_database.php` after importing!

#### Option C: Manual Import via Aiven Console

1. Go to your Aiven dashboard
2. Find your MySQL service
3. Use the web-based SQL editor or terminal
4. Copy and paste the SQL from `database/database.sql`
5. Remove `CREATE DATABASE` statements
6. Execute the SQL

### Step 3: Deploy on Render

1. **Push your code to GitHub** (if not done):
   ```bash
   git add .
   git commit -m "Add Aiven MySQL support with SSL"
   git push
   ```

2. **Create Web Service on Render**:
   - Go to https://dashboard.render.com
   - Click **"New +"** → **"Web Service"**
   - Connect your GitHub repository
   - Configure:
     - **Name**: `food-management-system`
     - **Environment**: `Docker`
     - **Dockerfile Path**: `Dockerfile`
     - **Instance Type**: `Free`

3. **Add Environment Variables** (from Step 1)

4. **Deploy** - Render will automatically deploy when you save

### Step 4: Verify Deployment

1. Wait for deployment to finish (check Render logs)
2. Visit: `https://your-app.onrender.com`
3. You should see the home page
4. Try logging in:
   - **Email**: `admin@example.com`
   - **Password**: `admin123`

### Step 5: Clean Up

1. **Delete import script** (if used):
   - Remove `import_database.php` from your repository
   - Commit and push: `git rm import_database.php && git commit -m "Remove import script" && git push`

2. **Change admin password**:
   - Login to admin dashboard
   - Change the default password immediately!

## 🔧 Troubleshooting

### Database Connection Failed

**Error:** "Can't connect to MySQL server"

**Solutions:**
1. Check environment variables are set correctly in Render
2. Verify SSL is enabled (`DB_SSL_REQUIRED=true`)
3. Check Aiven allows connections from Render IPs (should be automatic)
4. Verify database credentials are correct

### SSL Connection Error

**Error:** "SSL connection error"

**Solutions:**
1. Make sure `DB_SSL_REQUIRED=true` is set
2. Check that your code has the latest SSL connection code
3. Verify Aiven service is running

### Tables Not Found

**Error:** "Table doesn't exist"

**Solutions:**
1. Import database schema (Step 2)
2. Check if tables were created in `defaultdb` database
3. Verify `DB_NAME=defaultdb` in environment variables

### Import Script Not Working

**Solutions:**
1. Check file permissions
2. Verify database connection works first
3. Try manual import via MySQL command line (Option A)

## 📝 Important Notes

1. **Database Name**: Your Aiven database is `defaultdb`. The code will work with this name.

2. **SSL Required**: Aiven requires SSL connections. The code has been updated to handle this automatically.

3. **Security**: 
   - Never commit passwords to Git
   - Use environment variables only
   - Delete import scripts after use
   - Change default admin password

4. **Free Tier**: 
   - Render free tier may spin down after inactivity
   - First request after spin-down takes ~30 seconds
   - Aiven free tier has usage limits

## 🎉 Next Steps

After successful deployment:

1. ✅ Test all features (login, register, admin functions)
2. ✅ Change admin password
3. ✅ Add your data (categories, ingredients, dishes)
4. ✅ Test file uploads (if applicable)
5. ✅ Set up monitoring (optional)

## 📞 Need Help?

- Check Render logs: Dashboard → Your Service → Logs
- Check Aiven logs: Aiven Dashboard → Your Service → Logs
- Verify connection: Use the MySQL command from Aiven dashboard

---

**Your app should now be live on Render with Aiven MySQL! 🚀**


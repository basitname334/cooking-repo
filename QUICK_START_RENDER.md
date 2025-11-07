# Quick Start: Deploy to Render

## 🚀 Fast Deployment Steps

### 1. Push Code to GitHub
```bash
git init
git add .
git commit -m "Ready for Render deployment"
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
git push -u origin main
```

### 2. Setup MySQL Database

**Option A: PlanetScale (Free MySQL) - Recommended**
1. Go to https://planetscale.com
2. Sign up and create a database
3. Copy connection details (host, user, password, database name)

**Option B: Aiven (Free MySQL)**
1. Go to https://aiven.io
2. Create MySQL service
3. Get connection details

### 3. Deploy on Render

1. Go to https://dashboard.render.com
2. Click **"New +"** → **"Web Service"**
3. Connect your GitHub repository
4. Configure:
   - **Name**: `food-management-system`
   - **Environment**: `Docker`
   - **Dockerfile Path**: `Dockerfile`
   - **Instance Type**: `Free`

### 4. Add Environment Variables

In Render dashboard, add these environment variables:

```
DB_HOST=your-mysql-host.com
DB_USER=your-username
DB_PASS=your-password
DB_NAME=food_management_system
DB_PORT=3306
```

### 5. Import Database

After deployment, visit:
```
https://your-app.onrender.com/import_database.php
```

Then **DELETE** `import_database.php` for security!

### 6. Login

- URL: `https://your-app.onrender.com`
- Email: `admin@example.com`
- Password: `admin123`

**⚠️ Change the admin password immediately!**

---

## 📋 Files Created

- ✅ `Dockerfile` - Docker configuration
- ✅ `.dockerignore` - Files to ignore in Docker
- ✅ `.htaccess` - Apache configuration
- ✅ `render.yaml` - Render configuration (optional)
- ✅ `docker-compose.yml` - Local testing
- ✅ `import_database.php` - Database import tool
- ✅ `config/database.php` - Updated for environment variables

## 🔧 Local Testing

Test Docker setup locally:
```bash
docker-compose up -d
```

Visit: http://localhost:8080

## 📖 Full Documentation

See `RENDER_DEPLOYMENT.md` for detailed instructions.

## 🆘 Troubleshooting

**Database connection failed?**
- Check environment variables in Render dashboard
- Verify MySQL host allows connections from Render IPs
- Check database credentials are correct

**Import failed?**
- Make sure database is accessible
- Check file permissions
- Review error messages in import script

**Need help?**
- Check Render logs in dashboard
- See full guide in `RENDER_DEPLOYMENT.md`


# 🔧 Fix 503 Error on Render - Step by Step Guide

## Quick Diagnosis

**First, run the diagnostic tool:**
1. Visit: `https://cooking-repo.onrender.com/diagnose.php`
2. Review all sections
3. **DELETE diagnose.php after checking!**

---

## Most Common Causes & Fixes

### ✅ Fix 1: Missing Environment Variables (MOST COMMON)

**Problem:** Render can't connect to your database because environment variables aren't set.

**Solution:**
1. Go to [Render Dashboard](https://dashboard.render.com)
2. Click on your web service (`food-management-system` or similar)
3. Click **"Environment"** tab
4. Add these **6 environment variables** (one by one):

```
DB_HOST=mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com
DB_USER=avnadmin
DB_PASS=AVNS_tGhVB4Sijemqa7ffP4M
DB_NAME=defaultdb
DB_PORT=18568
DB_SSL_REQUIRED=true
```

5. Click **"Save Changes"**
6. Render will automatically redeploy (wait 2-3 minutes)
7. Check if the site works

**⚠️ Important:**
- Copy values EXACTLY (no spaces before/after)
- Use the exact values from `RENDER_ENV_VARIABLES.txt`
- Double-check each variable is saved correctly

---

### ✅ Fix 2: Database Connection Issues

**Problem:** Database credentials are wrong or database is not accessible.

**Check:**
1. Verify Aiven MySQL service is running
2. Go to [Aiven Dashboard](https://console.aiven.io)
3. Check your MySQL service status
4. Get fresh connection details if needed

**Test Connection:**
1. Visit: `https://cooking-repo.onrender.com/diagnose.php`
2. Check "Database Connection Test" section
3. If it fails, update environment variables with correct credentials

---

### ✅ Fix 3: Health Check Failing

**Problem:** Render can't verify your app is running.

**Solution:**
1. Test health endpoint: `https://cooking-repo.onrender.com/health.php`
2. Should return: `{"status":"ok","message":"Application is running",...}`
3. If it doesn't work, check Render logs for errors

**If health.php doesn't work:**
- Check if file exists in root directory
- Check Render logs for PHP errors
- Verify Apache is running (check logs)

---

### ✅ Fix 4: Free Tier Spin-Down

**Problem:** Render free tier services spin down after 15 minutes of inactivity.

**This is NORMAL!**
- First request after spin-down takes 30-60 seconds
- Just wait and try again
- The site will work after it spins up

**To avoid spin-down:**
- Upgrade to paid plan (always-on)
- Or use a service like UptimeRobot to ping your site every 10 minutes

---

### ✅ Fix 5: Application Startup Errors

**Problem:** PHP errors prevent the app from starting.

**Check Render Logs:**
1. Render Dashboard → Your Service → **"Logs"** tab
2. Look for red error messages
3. Common errors:
   - `Fatal error: Uncaught exception`
   - `Call to undefined function`
   - `Class not found`
   - `Permission denied`

**Common Fixes:**
- Missing PHP extensions → Check Dockerfile installs all required extensions
- File permissions → Check Dockerfile sets correct permissions
- Composer dependencies → Ensure `vendor/` folder is committed or installed during build

---

## Step-by-Step Debugging Process

### Step 1: Check Render Logs
```
Render Dashboard → Your Service → Logs Tab
```
Look for:
- ❌ Red error messages
- ⚠️ Yellow warnings
- Database connection errors
- PHP fatal errors

### Step 2: Verify Environment Variables
```
Render Dashboard → Your Service → Environment Tab
```
Compare with `RENDER_ENV_VARIABLES.txt` - must match exactly!

### Step 3: Test Health Endpoint
Visit: `https://cooking-repo.onrender.com/health.php`

Expected response:
```json
{
  "status": "ok",
  "message": "Application is running",
  "timestamp": "2024-...",
  "php_version": "8.2.x"
}
```

### Step 4: Run Diagnostic Tool
Visit: `https://cooking-repo.onrender.com/diagnose.php`

Review all sections:
- ✅ Environment Variables
- ✅ Database Connection
- ✅ File System
- ✅ Required Files
- ✅ PHP Extensions

**⚠️ DELETE diagnose.php after checking!**

### Step 5: Check Database
If database connection fails:
1. Verify Aiven service is running
2. Check credentials match exactly
3. Ensure `DB_SSL_REQUIRED=true` is set (for Aiven)
4. Test connection from Aiven dashboard

---

## Quick Checklist

Before asking for help, verify:

- [ ] All 6 environment variables are set in Render
- [ ] Environment variable values match `RENDER_ENV_VARIABLES.txt` exactly
- [ ] Aiven MySQL service is running
- [ ] Health endpoint (`/health.php`) returns OK
- [ ] Render logs show no fatal errors
- [ ] Waited 30-60 seconds after first request (spin-up time)
- [ ] Diagnostic tool shows no critical issues

---

## Still Not Working?

### Check These:

1. **Render Status:** https://status.render.com
2. **Aiven Status:** https://status.aiven.io
3. **Build Logs:** Render Dashboard → Your Service → Events → Check build logs
4. **Runtime Logs:** Render Dashboard → Your Service → Logs → Check for errors

### Common Error Messages:

**"Connection refused"**
- Database host/port is wrong
- Database service is down
- Firewall blocking connection

**"Access denied"**
- Wrong username/password
- Database name is incorrect

**"Table doesn't exist"**
- Database schema not imported
- Visit `/import_database.php` to import

**"Permission denied"**
- File permissions issue
- Check Dockerfile sets correct permissions

---

## After Fixing

Once your site is working:

1. ✅ Test login: `https://cooking-repo.onrender.com/auth/login.php`
2. ✅ Import database if needed: `/import_database.php` (then DELETE it!)
3. ✅ Delete diagnostic files: `diagnose.php`
4. ✅ Change admin password immediately!
5. ✅ Test all features

---

## Prevention

To avoid future 503 errors:

- ✅ Keep environment variables up to date
- ✅ Monitor Render logs regularly
- ✅ Use health check endpoint (already configured)
- ✅ Don't delete database accidentally
- ✅ Keep Aiven service active
- ✅ Consider upgrading from free tier for production

---

## Need More Help?

1. Check `TROUBLESHOOTING_503.md` for detailed troubleshooting
2. Review Render documentation: https://render.com/docs
3. Check Render community: https://community.render.com


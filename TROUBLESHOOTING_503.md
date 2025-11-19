# Troubleshooting HTTP 503 Error on Render

## What is a 503 Error?

HTTP 503 means "Service Unavailable" - Render can't reach your application or the health check is failing.

## Common Causes & Solutions

### 1. Check Render Logs

**First step - always check logs!**

1. Go to your Render Dashboard
2. Click on your web service
3. Click the **"Logs"** tab
4. Look for error messages (red text)

Common errors you might see:
- `Connection refused`
- `Connection timeout`
- `Fatal error: Uncaught exception`
- `MySQL connection failed`

---

### 2. Missing or Incorrect Environment Variables

**Symptoms:**
- 503 error immediately after deployment
- Database connection errors in logs

**Solution:**

Go to Render Dashboard → Your Web Service → **Environment** tab and verify these variables are set:

```
DB_HOST=your-database-host.com
DB_USER=your-database-user
DB_PASS=your-database-password
DB_NAME=your-database-name
DB_PORT=3306
DB_SSL_REQUIRED=true    (if using Aiven, PlanetScale, or other cloud MySQL)
```

**Important:**
- Use exact values (no spaces before/after)
- Copy-paste from your database provider's dashboard
- Double-check each value

---

### 3. Database Connection Issues

**If using Aiven:**
1. Check your Aiven service is running
2. Verify `DB_SSL_REQUIRED=true` is set
3. Get connection details from Aiven dashboard → Service Overview → Connection Information
4. Use the **"Host"** and **"Port"** values exactly as shown

**If using PlanetScale:**
1. Check branch is active
2. Get connection string from PlanetScale dashboard
3. Set `DB_SSL_REQUIRED=true`
4. Use the hostname from connection string (usually ends in `.planetscale.com`)

**If using Render's MySQL:**
1. Ensure database is created
2. Check database service is running
3. Use internal hostname from Render dashboard

---

### 4. Health Check Failing

**What we fixed:**
- Changed health check from `/` to `/health.php`
- New health check doesn't require database connection
- This prevents 503 errors if DB is temporarily slow

**After deploying the fix:**
- Health check now uses `/health.php` endpoint
- App should start even if database has issues
- You can verify: visit `https://your-app.onrender.com/health.php`

---

### 5. Application Startup Errors

**Check for:**
- PHP fatal errors in logs
- Missing PHP extensions
- File permission issues
- Composer dependencies not installed

**Solution:**
- Review Render build logs (not just runtime logs)
- Ensure `composer.json` is committed
- Check Dockerfile installs all required PHP extensions

---

### 6. Free Tier Limitations

**Render Free Tier:**
- Services spin down after 15 minutes of inactivity
- First request after spin-down takes longer (30-60 seconds)
- Might show 503 temporarily during spin-up

**This is normal!** Just wait 30-60 seconds and try again.

---

## Step-by-Step Debugging

### Step 1: Check Logs
```
Render Dashboard → Your Service → Logs Tab
```

### Step 2: Verify Environment Variables
```
Render Dashboard → Your Service → Environment Tab
```

Compare with your database provider's connection details.

### Step 3: Test Database Connection

Create a temporary file `test-db.php`:

```php
<?php
$host = getenv('DB_HOST');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');
$name = getenv('DB_NAME');
$port = getenv('DB_PORT') ?: '3306';

echo "Host: $host<br>";
echo "User: $user<br>";
echo "Database: $name<br>";
echo "Port: $port<br>";

$conn = @new mysqli($host, $user, $pass, $name, (int)$port);
if ($conn->connect_error) {
    echo "Error: " . $conn->connect_error;
} else {
    echo "Success: Connected!";
    $conn->close();
}
```

Upload to Render, visit `https://your-app.onrender.com/test-db.php`, then **DELETE IT**.

### Step 4: Test Health Endpoint

Visit: `https://your-app.onrender.com/health.php`

Should return:
```json
{"status":"ok","message":"Application is running","timestamp":"2024-...","php_version":"8.2.x"}
```

If this works but main site doesn't, the issue is with database connection, not the app itself.

---

## Quick Fixes

### Fix 1: Update Health Check Path
✅ **Already done in this update** - Changed to `/health.php`

### Fix 2: Add DB_SSL_REQUIRED Variable
If using Aiven or PlanetScale, make sure `DB_SSL_REQUIRED=true` is set.

### Fix 3: Verify All Environment Variables
Double-check each variable matches your database provider exactly.

### Fix 4: Redeploy Service
Sometimes Render needs a fresh deployment:
1. Render Dashboard → Your Service → Manual Deploy → Clear build cache & deploy

---

## Still Not Working?

1. **Check Render Status**: https://status.render.com
2. **Check Database Provider Status**: 
   - Aiven: https://status.aiven.io
   - PlanetScale: https://status.planetscale.com
3. **Review Full Logs**: Look for any PHP errors or warnings
4. **Test Locally**: Use Docker Compose to test setup locally first

---

## After Fixing

Once your site is working:
1. ✅ Verify `health.php` returns OK
2. ✅ Test login at `/auth/login.php`
3. ✅ Import database if needed at `/import_database.php`
4. ✅ **DELETE** any test files (`test-db.php`, etc.)

---

## Prevention

To avoid future 503 errors:
- ✅ Keep environment variables up to date
- ✅ Monitor Render logs regularly
- ✅ Use health check endpoint (already configured)
- ✅ Don't delete database accidentally
- ✅ Keep Render and database services active

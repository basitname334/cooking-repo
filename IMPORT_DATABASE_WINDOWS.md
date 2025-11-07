# Import Database to Aiven MySQL (Windows/PowerShell)

## Problem

PowerShell doesn't support the `<` redirection operator like CMD or Bash. Here are several solutions:

## Solution 1: Use PowerShell Script (Easiest) ⭐

1. Run the provided PowerShell script:
   ```powershell
   .\import_database_powershell.ps1
   ```

2. Follow the prompts

## Solution 2: Use CMD Instead of PowerShell

1. Open **Command Prompt** (CMD) instead of PowerShell
2. Navigate to your project folder:
   ```cmd
   cd "C:\xampp\htdocs\final php"
   ```
3. Run the MySQL command:
   ```cmd
   C:\xampp\mysql\bin\mysql.exe --user=avnadmin --password=AVNS_tGhVB4Sijemqa7ffP4M --host=mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com --port=18568 --ssl-mode=REQUIRED defaultdb < database\database.sql
   ```

## Solution 3: Use Get-Content in PowerShell

Run this command in PowerShell:

```powershell
Get-Content database\database.sql | C:\xampp\mysql\bin\mysql.exe --user=avnadmin --password=AVNS_tGhVB4Sijemqa7ffP4M --host=mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com --port=18568 --ssl-mode=REQUIRED defaultdb
```

**Note:** Replace `C:\xampp\mysql\bin\mysql.exe` with your actual MySQL path if different.

## Solution 4: Use MySQL Workbench or phpMyAdmin

1. **Download MySQL Workbench**: https://dev.mysql.com/downloads/workbench/
2. Create a new connection:
   - Host: `mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com`
   - Port: `18568`
   - Username: `avnadmin`
   - Password: `AVNS_tGhVB4Sijemqa7ffP4M`
   - Enable SSL
3. Connect and open `database/database.sql`
4. Execute the SQL script

## Solution 5: Use Import Script After Deployment

1. Deploy your app on Render first
2. Visit: `https://your-app.onrender.com/import_database.php`
3. The script will import automatically
4. **Delete** `import_database.php` after importing!

## Quick CMD Command (Copy-Paste Ready)

Open **CMD** (not PowerShell) and run:

```cmd
cd "C:\xampp\htdocs\final php" && C:\xampp\mysql\bin\mysql.exe --user=avnadmin --password=AVNS_tGhVB4Sijemqa7ffP4M --host=mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com --port=18568 --ssl-mode=REQUIRED defaultdb < database\database.sql
```

## Troubleshooting

### MySQL not found
- Make sure XAMPP MySQL is installed
- Use full path: `C:\xampp\mysql\bin\mysql.exe`
- Or add MySQL to PATH

### SSL connection error
- Make sure you're using `--ssl-mode=REQUIRED`
- Check your Aiven service is running
- Verify credentials are correct

### Permission denied
- Run CMD/PowerShell as Administrator
- Check file permissions on `database.sql`

## Recommended: Use CMD

For Windows, **Command Prompt (CMD)** is the easiest:
1. Press `Win + R`
2. Type `cmd` and press Enter
3. Navigate to your project
4. Run the MySQL import command

---

**After importing**, proceed with Render deployment!


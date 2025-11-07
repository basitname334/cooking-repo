# PowerShell Script to Import Database to Aiven MySQL
# Run this script in PowerShell: .\import_database_powershell.ps1

$DB_HOST = "mysql-36bf6fed-zainkhalid0347-58f4.i.aivencloud.com"
$DB_USER = "avnadmin"
$DB_PASS = "AVNS_tGhVB4Sijemqa7ffP4M"
$DB_NAME = "defaultdb"
$DB_PORT = "18568"
$SQL_FILE = "database\database.sql"

Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "Aiven MySQL Database Import" -ForegroundColor Cyan
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "Host: $DB_HOST"
Write-Host "Database: $DB_NAME"
Write-Host "Port: $DB_PORT"
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host ""

# Find MySQL executable
$mysqlPath = $null

# Check common locations
$possiblePaths = @(
    "C:\xampp\mysql\bin\mysql.exe",
    "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe",
    "C:\Program Files\MySQL\MySQL Server 8.4\bin\mysql.exe",
    "C:\wamp64\bin\mysql\mysql8.0.xx\bin\mysql.exe",
    "mysql.exe"  # If in PATH
)

foreach ($path in $possiblePaths) {
    if (Test-Path $path) {
        $mysqlPath = $path
        break
    }
}

# Try to find mysql in PATH
if ($null -eq $mysqlPath) {
    $mysqlPath = (Get-Command mysql -ErrorAction SilentlyContinue).Source
}

if ($null -eq $mysqlPath) {
    Write-Host "Error: MySQL client not found!" -ForegroundColor Red
    Write-Host "Please install MySQL client or add it to PATH" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "For XAMPP, MySQL is usually at: C:\xampp\mysql\bin\mysql.exe" -ForegroundColor Yellow
    exit 1
}

Write-Host "Found MySQL at: $mysqlPath" -ForegroundColor Green
Write-Host ""

# Test connection first
Write-Host "Testing connection..." -ForegroundColor Yellow
$testQuery = "SELECT 1 + 2 as three;"
$testResult = & $mysqlPath --user=$DB_USER --password=$DB_PASS --host=$DB_HOST --port=$DB_PORT --ssl-mode=REQUIRED $DB_NAME -e $testQuery 2>&1

if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ Connection successful!" -ForegroundColor Green
    Write-Host ""
} else {
    Write-Host "✗ Connection failed!" -ForegroundColor Red
    Write-Host $testResult -ForegroundColor Red
    exit 1
}

# Check if SQL file exists
if (-not (Test-Path $SQL_FILE)) {
    Write-Host "Error: SQL file not found: $SQL_FILE" -ForegroundColor Red
    exit 1
}

Write-Host "SQL file found: $SQL_FILE" -ForegroundColor Green
Write-Host ""

# Ask for confirmation
$confirmation = Read-Host "Do you want to import the database schema? (y/n)"
if ($confirmation -ne 'y' -and $confirmation -ne 'Y') {
    Write-Host "Import cancelled." -ForegroundColor Yellow
    exit 0
}

Write-Host "Importing database schema..." -ForegroundColor Yellow
Write-Host "This may take a few moments..." -ForegroundColor Yellow
Write-Host ""

# Import using Get-Content and pipe
$sqlContent = Get-Content $SQL_FILE -Raw

# Split by semicolons and execute (PowerShell way)
$statements = $sqlContent -split ';' | Where-Object { $_.Trim() -ne '' }

$successCount = 0
$errorCount = 0

foreach ($statement in $statements) {
    $statement = $statement.Trim()
    if ($statement -eq '' -or $statement -match '^--' -or $statement -match '^/\*') {
        continue
    }
    
    # Skip CREATE DATABASE and USE statements (database already exists)
    if ($statement -match 'CREATE DATABASE' -or $statement -match 'USE `') {
        continue
    }
    
    $result = $sqlContent | & $mysqlPath --user=$DB_USER --password=$DB_PASS --host=$DB_HOST --port=$DB_PORT --ssl-mode=REQUIRED $DB_NAME 2>&1
    
    if ($LASTEXITCODE -eq 0) {
        $successCount++
    } else {
        $errorCount++
        # Ignore "already exists" errors
        if ($result -notmatch 'already exists') {
            Write-Host "Error: $result" -ForegroundColor Red
        }
    }
}

# Better approach: Import entire file at once
Write-Host "Importing database schema (better method)..." -ForegroundColor Yellow
$importResult = Get-Content $SQL_FILE -Raw | & $mysqlPath --user=$DB_USER --password=$DB_PASS --host=$DB_HOST --port=$DB_PORT --ssl-mode=REQUIRED $DB_NAME 2>&1

if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ Database schema imported successfully!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Note: Some 'table already exists' warnings are normal." -ForegroundColor Yellow
} else {
    # Filter out "already exists" warnings
    $errors = $importResult | Where-Object { $_ -notmatch 'already exists' -and $_ -notmatch 'Warning' }
    if ($errors) {
        Write-Host "⚠ Some errors occurred:" -ForegroundColor Yellow
        Write-Host $errors -ForegroundColor Red
    } else {
        Write-Host "✓ Import completed (some warnings are normal)" -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "==========================================" -ForegroundColor Cyan
Write-Host "Next steps:" -ForegroundColor Cyan
Write-Host "1. Set environment variables in Render"
Write-Host "2. Deploy your application"
Write-Host "3. Visit: https://your-app.onrender.com"
Write-Host "4. Login with: admin@example.com / admin123"
Write-Host "==========================================" -ForegroundColor Cyan


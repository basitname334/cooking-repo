# Database deployment – best practices

## Root cause of "Table 'defaultdb.users' doesn't exist"

1. **Production setup was skipped**  
   `getDBConnection()` only ran `ensureDatabaseSetup()` when **not** in production (`DB_HOST` was not localhost). On Render/Aiven, `DB_HOST` is the Aiven host, so the code treated the app as production and **never** created tables. The `defaultdb` database existed but had no tables.

2. **ensureAdminUser() assumed the table existed**  
   Login calls `ensureAdminUser($conn)`, which runs `SELECT ... FROM users`. If the `users` table was never created, that query throws "Table 'defaultdb.users' doesn't exist".

3. **Fix applied**
   - Schema setup now runs in **all** environments (local and production) on first use.
   - `ensureAdminUser()` first checks for the `users` table; if it is missing, it creates it and inserts the default admin, then continues.

---

## What was changed

| Area | Change |
|------|--------|
| **Connection** | Retry logic (env: `DB_CONNECT_RETRIES`, `DB_CONNECT_RETRY_DELAY_MS`), proper handling of `mysqli_sql_exception`. |
| **Schema** | `db_ensure_schema_once()` runs once per request and calls `ensureDatabaseSetup($conn)` for both local and production. |
| **ensureAdminUser()** | Checks for `users` table (e.g. `SHOW TABLES LIKE 'users'`); if missing, creates `users` via `db_create_users_table($conn)` then inserts default admin. Uses prepared statements and `password_hash(PASSWORD_DEFAULT)`. |
| **Config** | All settings from env: `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `DB_PORT`, `DB_SSL_REQUIRED`, optional `DB_CONNECT_RETRIES`, `DB_CONNECT_RETRY_DELAY_MS`. |

---

## Deployment checklist

1. **Set environment variables** on the server (e.g. Render → Environment):
   - `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `DB_PORT`
   - `DB_SSL_REQUIRED=true` for Aiven/PlanetScale
   - Optional: `DB_CONNECT_RETRIES=3`, `DB_CONNECT_RETRY_DELAY_MS=500`

2. **No manual migration required**  
   The first request that uses the DB will run the automatic migration (create missing tables and default admin). No need to run a separate SQL file or script for a fresh deploy.

3. **Optional: run schema manually**  
   For a one-off or backup, you can import `database/schema.sql` into your MySQL client or phpMyAdmin. The app will still run migrations and will not duplicate tables (all use `CREATE TABLE IF NOT EXISTS`).

4. **Security**
   - Never commit real `DB_PASS` (or other secrets) to the repo; use env only.
   - Default admin: `admin@example.com` / `admin123` — change password after first login in production.

5. **PHP version**  
   Code uses PHP 8+ (e.g. `str_contains`, typed returns). Ensure the server runs PHP 8.0+.

---

## SQL schema summary (reference)

Tables (in dependency order):  
`users` → `categories` → `ingredients`, `dishes` → `dish_ingredients`, `orders`  
(orders and dish_ingredients reference users/dishes/ingredients.)

Full DDL is in `database/schema.sql` and is applied automatically in `config/database.php` via `ensureDatabaseSetup()`.

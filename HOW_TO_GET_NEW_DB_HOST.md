# How to Get a New Database Host

Your app needs these env vars: `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `DB_PORT`, and for Aiven `DB_SSL_REQUIRED=true`.

---

## Option 1: Get a new host from Aiven (MySQL)

1. **Log in:** [https://console.aiven.io](https://console.aiven.io)

2. **Create a new MySQL service** (or use an existing one):
   - Click **+ Create service**
   - Choose **MySQL**
   - Pick a **cloud** and **region** (e.g. same as Render for lower latency)
   - Set a **service name** → Create

3. **Get the connection details:**
   - Open your MySQL service
   - Go to **Overview** or **Connection information**
   - Copy:
     - **Host** (e.g. `mysql-xxxxx-xxxxx-xxxx.i.aivencloud.com`)
     - **Port** (e.g. `18568` or `12345`)
     - **User** (often `avnadmin`)
     - **Password** (click “Show” if hidden)
     - **Database name** (often `defaultdb`)

4. **Update your env vars** (in Render and in `RENDER_ENV_VARIABLES.txt`):

   ```
   DB_HOST=<paste the new Host from Aiven>
   DB_PORT=<paste the Port>
   DB_USER=avnadmin
   DB_PASS=<paste the Password>hjgcfggh
   DB_NAME=defaultdb
   DB_SSL_REQUIRED=true
   ```

5. In **Render**: Dashboard → your Web Service → **Environment** → edit each variable with the new values → **Save**. Render will redeploy.

---

## Option 2: Use Render PostgreSQL (no Aiven)

Render can create a PostgreSQL database for you. Your app is written for **MySQL**, so you’d need to either:

- Add a MySQL add-on if Render offers one in your plan, or  
- Use another MySQL host (e.g. Aiven, PlanetScale, or a free MySQL host).

So “get a new DB host” here usually means: get a new **MySQL** host from Aiven (Option 1) or another MySQL provider.

---

## Option 3: Use local MySQL (development only)

For local testing only:

- Install MySQL (or use XAMPP and start MySQL).
- Set in your local env or `.env`:

  ```
  DB_HOST=localhost
  DB_PORT=3306
  DB_USER=root
  DB_PASS=
  DB_NAME=food_management_system
  DB_SSL_REQUIRED=false
  ```

Do **not** use these values in Render production; use Aiven or another cloud MySQL host.

---

## Checklist after you have a new host

- [ ] Update `DB_HOST` and `DB_PORT` (and user/pass/DB name if they changed) in Render Environment.
- [ ] Update `RENDER_ENV_VARIABLES.txt` with the same values (for your reference).
- [ ] Save in Render so the service redeploys.
- [ ] If the old Aiven service is unused, you can delete it in Aiven console to avoid charges.

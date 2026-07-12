# PalliCare — Namecheap cPanel Deployment Guide

Step-by-step instructions to deploy the PalliCare Telemedicine app to a **Namecheap shared hosting** account (cPanel + MySQL). No terminal or SSH required.

---

## 0. Get the upload package (the "zip")

You don't need anyone to build a zip for you — GitHub makes one on demand:

1. Open the repo: <https://github.com/jonyatoniyars/BPDA-Telemedicine-App-prd>
2. Click the green **`< > Code`** button → **Download ZIP**.
3. Unzip it on your computer. You'll get a folder like `BPDA-Telemedicine-App-prd-main/`.

> The contents of that folder (NOT the folder itself) are what you upload into `public_html`.

**Before uploading**, delete these dev-only items — they are not needed on the server:

- `.github/` (CI workflow, harmless but unused on shared hosting)
- `.gitignore`
- `SETUP.md` and `README.md` (optional; keep if you like)

---

## 1. Create the MySQL database in cPanel

1. Log in to cPanel (usually `https://yourdomain.com/cpanel` or via your Namecheap dashboard).
2. Go to **Databases → MySQL® Databases**.
3. **Create a New Database** — e.g. `cpaneluser_pallicare`. Note the full name (cPanel prefixes it with your account name).
4. Scroll to **MySQL Users → Add New User**. Create a user (e.g. `cpaneluser_pcadmin`) with a **strong password**. Save these.
5. Under **Add User To Database**, select the user + database → **Add** → grant **ALL PRIVILEGES** → **Make Changes**.

You now have three values you'll need next:

| Setting | Example value |
|-----------|------------------------------|
| DB_HOST | `localhost` |
| DB_NAME | `cpaneluser_pallicare` |
| DB_USER | `cpaneluser_pcadmin` |
| DB_PASS | `the strong password you set`|

> On Namecheap shared hosting, **DB_HOST is almost always `localhost`.**

---

## 2. Configure `config.php`

The repo ships a template called **`config.example.php`**.

1. **Copy** `config.example.php` to a new file named **`config.php`**.
2. Open `config.php` and fill in the values from Step 1:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'cpaneluser_pallicare');
   define('DB_USER', 'cpaneluser_pcadmin');
   define('DB_PASS', 'your_strong_password');
   define('DB_CHARSET', 'utf8mb4');
   ```
3. Set **`BASE_URL`** to your public URL (no trailing slash), e.g.
   ```php
   define('BASE_URL', 'https://yourdomain.com');
   ```
   If you deploy into a subfolder, include it: `https://yourdomain.com/pallicare`.
4. Replace any **secret / JWT** placeholder values with a long random string (30+ characters).

> If you downloaded before the config template existed and only see `config.php`, just edit it directly with the values above.

---

## 3. Set the installer token

The installer (`admin/install.php`) is protected by a secret token. **Never leave it at a default.**

- Preferred: set an environment variable `INSTALLER_TOKEN` to your own secret (cPanel → *Setup Python/Env* or via `.htaccess` `SetEnv INSTALLER_TOKEN yoursecret`).
- Or edit the token value at the top of `admin/install.php` to a private string only you know.

Write this token down — you need it in Step 5.

---

## 4. Upload files to `public_html`

**Option A — cPanel File Manager (easiest):**

1. cPanel → **Files → File Manager → `public_html`**.
2. Click **Upload**, then upload the **zip** you downloaded (or a re-zipped copy of the edited folder contents).
3. Back in File Manager, select the uploaded zip → **Extract**.
4. Move the extracted files so they sit **directly inside `public_html`** (i.e. `public_html/index.php`, `public_html/api/`, etc.), not inside a nested subfolder.
5. Confirm `.htaccess` is present (enable *Show Hidden Files* in File Manager settings if you don't see it).

**Option B — FTP (FileZilla):** connect with your cPanel FTP credentials and drag the folder contents into `public_html`.

---

## 5. Run the installer

1. In your browser, visit:
   ```
   https://yourdomain.com/admin/install.php?token=YOUR_INSTALLER_TOKEN
   ```
2. Review the **Environment Checks** (PHP ≥ 7.4, PDO, PDO MySQL, DB connection all green).
3. Click **🚀 Run Installation**. This creates all tables and seeds the default accounts.
   - Only tick **Force Reinstall** if you want to wipe and recreate everything (**destroys all data**).
4. When it finishes, it shows the seeded logins.

---

## 6. Lock it down (do this immediately)

1. **Delete or rename `admin/install.php`** so nobody can re-run it. (The wizard offers a self-rename button — use it.)
2. **Log in and change every default password.** The seed accounts all use the same default password defined in the installer; rotate them right away, starting with the admin.
3. Verify `.htaccess` is active (it blocks direct access to `config.php` and other sensitive files).
4. Make sure your site is served over **HTTPS** (Namecheap → cPanel → *SSL/TLS Status* → run AutoSSL).

---

## 7. Default accounts (change these on first login)

After install, five accounts are seeded (admin, 2 doctors, 2 health workers). Their default password is set inside `admin/install.php`. **Change all of them immediately** via the admin panel. The admin account email is `admin@pallicare.dev` unless you edited the installer.

---

## Troubleshooting

| Symptom | Fix |
|--------------------------------------|-----|
| "Database connection failed" | Re-check `config.php` values; on Namecheap `DB_HOST` = `localhost`; confirm the user is added to the DB with ALL PRIVILEGES. |
| Installer shows **403 Forbidden** | Token missing/wrong. Append `?token=YOUR_TOKEN` and make sure it matches what you set in Step 3. |
| Blank white page | Enable errors temporarily, or check cPanel → *Errors* / `error_log`. Usually a config typo or wrong PHP version. |
| CSS/JS not loading | `BASE_URL` is wrong — it must match your real URL (and subfolder, if any) with no trailing slash. |
| 500 error after upload | `.htaccess` rule unsupported by your PHP handler; try switching PHP version in cPanel → *Select PHP Version* (use 8.0–8.2). |
| Links point to wrong path | Same as above — fix `BASE_URL`. |

---

## Recommended PHP version

In cPanel → **Select PHP Version**, choose **PHP 8.0, 8.1, or 8.2**. Ensure the `pdo_mysql` extension is enabled (it is by default on Namecheap).

---

That's it. Database created → `config.php` filled → files in `public_html` → installer run → installer deleted → passwords changed. You're live.

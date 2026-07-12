# PalliCare — cPanel PHP/MySQL Package

Complete cPanel-ready version of the PalliCare Community Health Platform.

## What's included

- `index.php` — entry point
- `config.php` — MySQL credentials (edit before upload)
- `install.sql` — database schema + seed medicines
- `admin/install.php` — web setup wizard that creates tables and seeds users securely
- `.htaccess` — routing, security headers, PHP tweaks
- `api/` — REST API endpoints
- `pages/` — frontend pages for all roles
- `admin/` — standalone admin control panel
- `assets/` — CSS and JavaScript

## cPanel deployment steps

1. **Create MySQL database & user** in cPanel.
2. **Edit `config.php`** with your database credentials.
3. **Upload all files** to your public_html (or subdomain folder) using cPanel File Manager or FTP.
4. **Run installer:** visit `https://yourdomain.com/admin/install.php?token=install-pallicare-2024`
   - The installer is protected by a token defined at the top of `admin/install.php`.
   - **Change `$installerSecret`** in `admin/install.php` before uploading for real deployments.
   - Creates tables automatically.
   - Seeds admin, doctor, and health worker accounts.
   - Default admin login: `admin@pallicare.dev` / `password123`
5. **Delete `admin/install.php`** after successful installation.
6. **Log in** at `/pages/login.php` or `/admin/login.php`.

## Default accounts (after install)

| Role          | Email                       | Password    | Notes              |
|---------------|-----------------------------|-------------|--------------------|
| Admin         | admin@pallicare.dev         | password123 |                    |
| Doctor        | doctor1@pallicare.dev       | password123 |                    |
| Doctor        | doctor2@pallicare.dev       | password123 |                    |
| Health Worker | hw1@pallicare.dev           | password123 | Rx permission ON   |
| Health Worker | hw2@pallicare.dev           | password123 | Rx permission OFF  |

## Features

- Role-based access: Admin, Doctor, Health Worker
- Prescription creation, review, and printing
- Medicine management
- Doctor-health worker assignments
- Video call request workflow
- User approvals and permission toggles
- Responsive UI with no external dependencies

## Security notes

- Change default passwords immediately after install.
- Change `$installerSecret` in `admin/install.php` before deploying.
- Replace JWT secrets in `config.php` with long random strings.
- Keep `.htaccess` rules active to protect sensitive files.
- Always delete or rename `admin/install.php` after setup.
- Session cookies use `SameSite=Strict` + `HttpOnly`, providing strong CSRF mitigation.

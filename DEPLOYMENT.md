# MoneyLend — Production Deployment & Operations Guide

## Overview

MoneyLend is a personal money lending management web application built with PHP, MySQL/MariaDB (PDO), Apache, and modern vanilla frontend standards. This document provides complete, step-by-step instructions for deploying, configuring, and operating MoneyLend in both local development environments and production servers.

---

## 1. Local Development (XAMPP Environment)

### Prerequisites
- **Operating System:** macOS, Linux, or Windows
- **Server Bundle:** XAMPP with PHP 8.2+ and MySQL / MariaDB
- **Browser:** Modern web browser (Chrome, Firefox, Safari, Edge)

### Setup Steps
1. **Clone or Copy Repository:**
   Place the project inside the web server document root:
   - macOS XAMPP: `/Applications/XAMPP/xamppfiles/htdocs/moneylend/`
   - Windows XAMPP: `C:\xampp\htdocs\moneylend\`
   - Linux XAMPP: `/opt/lampp/htdocs/moneylend/`

2. **Start Services:**
   Launch the XAMPP Control Panel and start **Apache** and **MySQL**.

3. **Import Database:**
   Using phpMyAdmin (`http://localhost/phpmyadmin`) or MySQL CLI:
   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root -e "CREATE DATABASE IF NOT EXISTS moneylend CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   /Applications/XAMPP/xamppfiles/bin/mysql -u root moneylend < database/schema.sql
   ```
   *(Optional for local testing)* Populate demonstration records:
   ```bash
   /Applications/XAMPP/xamppfiles/bin/mysql -u root moneylend < database/seed.sql
   ```

4. **Access Application:**
   Navigate to:
   ```text
   http://localhost/moneylend/
   ```
   Default Administrator credentials (seeded in local/demo environment):
   - **Email:** `admin@moneylend.local`
   - **Password:** `admin123`

---

## 2. Production System Requirements

| Component | Minimum Requirement | Recommended |
| :--- | :--- | :--- |
| **Operating System** | Linux (Ubuntu 22.04 LTS / Debian 12 / RHEL 9) | Linux (Ubuntu LTS) |
| **Web Server** | Apache 2.4+ with `mod_rewrite` & `mod_headers` | Apache 2.4+ or Nginx (reverse proxy) |
| **PHP Runtime** | PHP 8.2+ | PHP 8.2 or 8.3 |
| **PHP Extensions** | `pdo`, `pdo_mysql`, `mbstring`, `session`, `curl`, `json`, `openssl` | All listed extensions enabled |
| **Database** | MySQL 5.7+ / MariaDB 10.3+ (InnoDB engine) | MySQL 8.0+ / MariaDB 10.6+ |
| **SSL/TLS** | Valid SSL/TLS Certificate (e.g. Let's Encrypt) | Enforced HTTPS with HTTP/2 |

---

## 3. Step-by-Step Production Installation

### Step 1: Upload Project Files
Deploy codebase to the designated server web directory (e.g., `/var/www/moneylend`):
```bash
git clone https://github.com/<your-username>/moneylend.git /var/www/moneylend
cd /var/www/moneylend
```

### Step 2: Environment Configuration
Create a production `.env` file from the provided template:
```bash
cp .env.example .env
chmod 600 .env
```
Edit `.env` with production parameters:
```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://moneylend.example.com/

DB_HOST=localhost
DB_PORT=3306
DB_NAME=moneylend_prod
DB_USER=moneylend_user
DB_PASS=YourStrongSecureRandomPasswordHere
DB_CHARSET=utf8mb4

SESSION_TIMEOUT_MINUTES=30
LOGIN_MAX_ATTEMPTS=5
LOGIN_LOCKOUT_MINUTES=15
MIN_PASSWORD_LENGTH=8
AUDIT_RETENTION_DAYS=90

MAIL_MODE=log
```

### Step 3: Create Dedicated Database & User
Connect to MySQL as administrative root:
```sql
CREATE DATABASE `moneylend_prod` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'moneylend_user'@'localhost' IDENTIFIED BY 'YourStrongSecureRandomPasswordHere';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, INDEX, ALTER ON `moneylend_prod`.* TO 'moneylend_user'@'localhost';

FLUSH PRIVILEGES;
```

### Step 4: Import Database Schema
Import the production schema definition:
```bash
mysql -u moneylend_user -p moneylend_prod < database/schema.sql
```
*(Do NOT import `seed.sql` on a live production instance)*

### Step 5: Database Migrations Execution Order
For existing deployments upgrading across versions, execute database migrations sequentially:
1. `001_add_activity_log.sql` (Phase 10 — Activity & Audit Trail)
2. `002_add_notifications.sql` (Phase 11 — Internal Notification System)
3. `003_add_security_admin.sql` (Phase 12 — Security Hardening & Rate Limiting)

Command example:
```bash
mysql -u moneylend_user -p moneylend_prod < database/migrations/001_add_activity_log.sql
mysql -u moneylend_user -p moneylend_prod < database/migrations/002_add_notifications.sql
mysql -u moneylend_user -p moneylend_prod < database/migrations/003_add_security_admin.sql
```

### Step 6: Configure Web Server (Apache)

#### Virtual Host Example (`/etc/apache2/sites-available/moneylend.conf`):
```apache
<VirtualHost *:80>
    ServerName moneylend.example.com
    DocumentRoot /var/www/moneylend

    # Redirect all plain HTTP traffic to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
    ServerName moneylend.example.com
    DocumentRoot /var/www/moneylend

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/moneylend.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/moneylend.example.com/privkey.pem

    <Directory /var/www/moneylend>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/moneylend_error.log
    CustomLog ${APACHE_LOG_DIR}/moneylend_access.log combined
</VirtualHost>
```
Enable modules and site configuration:
```bash
sudo a2enmod rewrite headers ssl
sudo a2ensite moneylend.conf
sudo systemctl restart apache2
```

### Step 7: Configure File Permissions
Ensure the web server has restricted, read-only permissions across PHP source files and write access strictly to `logs/`:
```bash
sudo chown -R www-data:www-data /var/www/moneylend
sudo find /var/www/moneylend -type d -exec chmod 755 {} \;
sudo find /var/www/moneylend -type f -exec chmod 644 {} \;

# Restrict sensitive environment configuration file
chmod 600 /var/www/moneylend/.env

# Grant write access to logging folder
chmod 775 /var/www/moneylend/logs
```

### Step 8: Update Default Administrator Password
Upon first login, immediately navigate to **Settings > Security** or **User Management** and change the initial default password to a strong, production-grade password.

### Step 9: Configure Automated Notification Runner
To enable daily due-date reminder scans and delinquency tracking, configure a system cron job:
```bash
# Run every day at 08:00 AM server time
0 8 * * * /usr/bin/php /var/www/moneylend/scripts/generate_notifications.php >> /var/www/moneylend/logs/cron.log 2>&1
```

---

## 4. Backup & Disaster Recovery Strategy

### Generating Database Dumps
Execute compressed backups outside the web document root:
```bash
# Recommended command:
mysqldump -u <DB_USER> -p<DB_PASS> --single-transaction --quick --routines --triggers <DB_NAME> | gzip > /var/backups/moneylend/moneylend_$(date +\%Y\%m\%d_\%H\%M\%S).sql.gz
```

### Automated Backup Cron Example:
```bash
# Daily backup at 02:00 AM
0 2 * * * mysqldump -u moneylend_user -p"StrongPassword" --single-transaction moneylend_prod | gzip > /var/backups/moneylend/backup_$(date +\%Y\%m\%d).sql.gz
```

### Safe Restore Procedure
> [!CAUTION]
> **Never restore a backup over a live production database directly.** Always verify database restoration on a separate staging or test database first.

1. Uncompress the backup archive:
   ```bash
   gunzip -k /var/backups/moneylend/backup_20261009.sql.gz
   ```
2. Test restoration on an isolated verification database:
   ```bash
   mysql -u <DB_USER> -p -e "CREATE DATABASE moneylend_restore_test;"
   mysql -u <DB_USER> -p moneylend_restore_test < /var/backups/moneylend/backup_20261009.sql
   ```
3. Verify table counts, row numbers, and financial balances:
   ```sql
   USE moneylend_restore_test;
   SELECT COUNT(*) FROM borrowers;
   SELECT COUNT(*), SUM(principal_amount), SUM(remaining_balance) FROM loans;
   SELECT COUNT(*), SUM(amount) FROM repayments;
   ```

---

## 5. Production Security Verification Checklist

Before opening the application to production traffic, verify the following security controls:

- [ ] **HTTPS Enforced:** All plain HTTP connections automatically redirect to HTTPS.
- [ ] **Secure Cookies Active:** In `includes/auth.php`, `session.cookie_secure` is verified active over HTTPS.
- [ ] **Technical Error Suppression:** `APP_DEBUG=false` in `.env`; `display_errors` is disabled in `php.ini`.
- [ ] **Database Credentials:** Default MySQL `root` without password replaced with dedicated, restricted user.
- [ ] **Environment Protection:** `.env` file exists with `600` permissions and is verified excluded from Git.
- [ ] **Direct Access Blocking:** Apache `.htaccess` verified blocking HTTP access to `.env`, `database/`, `logs/`, and `*.sql`.
- [ ] **Directory Listing:** Directory browsing (`Options -Indexes`) is disabled.
- [ ] **Default Password Changed:** Seed administrator password (`admin123`) has been replaced.
- [ ] **Audit Trail Active:** System actions recorded in `activity_logs`.
- [ ] **Brute-Force Rate Limiting:** Throttling active in `login_attempts`.
- [ ] **Scheduled Automated Backups:** Cron backup job verified running and saving to a secure off-web directory.

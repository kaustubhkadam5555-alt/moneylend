# MoneyLend — Security Policy & Administration Guide

## Overview

MoneyLend is built with defense-in-depth principles across authentication, authorization, session lifecycle, financial calculations, audit logging, and data persistence. This document details the security posture, operational guidelines, and backup strategies implemented across the application.

---

## 1. Authentication Security

- **Password Hashing:** Passwords are hashed using PHP's native `password_hash()` implementing salted `bcrypt` (`PASSWORD_DEFAULT`). Plaintext passwords are never stored in the database or written to disk.
- **Verification:** Credential validation relies strictly on constant-time verification via `password_verify()`.
- **Account State Verification:** Inactive or suspended accounts are immediately rejected during the authentication process.
- **Generic Error Responses:** Login failures return generic notices (`"Invalid email or password."`) to eliminate account enumeration risks.
- **Last Login Tracking:** Successful authentications record the exact timestamp (`last_login_at`) on the user record.

---

## 2. Brute-Force & Rate-Limiting Protection

- **Tracking Mechanism:** Failed login attempts are recorded in the `login_attempts` table, indexing client IP address and attempted email.
- **Throttling Policy:** If failed attempts reach the threshold (default: 5 attempts within a 15-minute window), further attempts are throttled.
- **Lockout Safety:** Throttling uses a sliding time window rather than permanently locking user accounts, preventing denial-of-service against legitimate users.
- **Auto-Clearing:** Upon successful authentication, prior failed attempts for the specific user/IP are cleared.

---

## 3. Session & Account Security

- **Strict Mode:** `session.use_strict_mode` is enabled to reject uninitialized session identifiers.
- **Cookie-Only Sessions:** `session.use_only_cookies` is enforced to prevent session passing via URLs.
- **HttpOnly Flag:** Session cookies are flagged with `HttpOnly = true` to protect against JavaScript-based cookie theft (XSS).
- **SameSite Protection:** Configured with `SameSite = 'Lax'` to protect against Cross-Site Request Forgery (CSRF).
- **HTTPS Readiness:** The `Secure` cookie flag is dynamically enabled whenever HTTPS is active (`$_SERVER['HTTPS'] === 'on'` or port 443). Production deployments must run behind TLS/HTTPS.
- **Session Fixation Defense:** `session_regenerate_id(true)` is invoked upon successful authentication to invalidate old session IDs.
- **Inactivity Timeout:** Inactive sessions expire after a configurable duration (default: 30 minutes). When expired, users are cleanly logged out with a notice.
- **Clean Logout:** Logging out wipes `$_SESSION`, destroys the server session record, and expires the client cookie.

---

## 4. Role-Based Access Control (RBAC)

MoneyLend distinguishes between two primary roles:

| Role | Privileges |
| :--- | :--- |
| **admin** | Full access: borrowers, loans, repayments, reports, notifications, user administration, security policies, and system settings. |
| **staff** | Operational access: borrowers, loans, repayments, receipts, reports, and notification preferences. Restricted from administrative user management and security policy modifications. |

### Authorization Guards
- Centralized verification via `has_role('admin')`, `is_admin()`, and `require_role('admin')`.
- Unauthorized requests to administrative resources trigger clean "Access denied" notices and safe redirection.
- Administrative Self-Protection:
  - Administrators cannot deactivate, demote, or delete their own active account.
  - The system prohibits deactivating, demoting, or deleting the last active administrator, preventing administrative lockout.

---

## 5. Input Validation & Defense-in-Depth

- **SQL Injection Immunity:** All database queries utilize PDO native prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`) with typed parameter binding.
- **Cross-Site Scripting (XSS):** All dynamic browser output is sanitized using `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- **Cross-Site Request Forgery (CSRF):** Every state-altering HTTP `POST` action requires a 256-bit cryptographic CSRF token verified via `hash_equals()`.
- **Safe Redirects:** Destination URLs are sanitized against CRLF header injection and protocol-relative schemes (`//`).
- **CSV Formula Injection:** CSV export sanitizes fields starting with `=`, `+`, `-`, or `@` to neutralize spreadsheet macro execution.
- **Security Headers:**
  - `X-Content-Type-Options: nosniff`
  - `X-Frame-Options: SAMEORIGIN`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `Permissions-Policy: geolocation=(), microphone=(), camera=()`
  - `X-XSS-Protection: 1; mode=block`

---

## 6. Audit Trail & Logging

- **Immutable Audit History:** State changes (logins, logouts, loans, repayments, user modifications, settings changes) are appended to the `activity_logs` table.
- **Safe Metadata:** Logs capture user IDs, entity types, entity IDs, timestamps, and descriptive summaries.
- **Zero-Credential Logging:** Passwords, password hashes, CSRF tokens, session IDs, and database credentials are strictly prohibited from logs.

---

## 7. Secrets Management

- **No Hardcoded Secrets:** Credentials and environment settings are kept in local configuration or `.env`.
- **Git Exclusions:** `.env`, `*.log`, `scratch/*_cookie.txt`, and database backup archives (`*.sql.gz`, `*.dump`) are explicitly excluded via `.gitignore`.
- **Template Config:** `.env.example` provides safe configuration placeholders for deployment setup without containing real secrets.

---

## 8. Backup Strategy & Disaster Recovery

MoneyLend stores vital financial ledgers, borrower records, and transaction histories. A structured backup strategy ensures business continuity.

### Database Backup (MySQL / MariaDB)

To generate an encrypted or compressed database dump using `mysqldump` in a local or server environment:

```bash
# Recommended command (run outside publicly accessible web roots):
mysqldump -u <db_user> -p<db_password> --single-transaction --quick --routines --triggers <db_name> | gzip > /path/to/secure/backups/moneylend_backup_$(date +\%Y\%m\%d_\%H\%M\%S).sql.gz
```

*Example for XAMPP macOS:*
```bash
/Applications/XAMPP/xamppfiles/bin/mysqldump -u root moneylend | gzip > ~/moneylend_backups/moneylend_backup_$(date +%Y%m%d_%H%M%S).sql.gz
```

### Critical Backup Safety Rules
1. **Never store database dumps inside `htdocs`** or any publicly web-accessible folder.
2. **Never test restore commands directly against a live database.** Always test restoration on an isolated test environment or development staging instance.
3. **Backup before running migrations:** Always create a snapshot prior to applying database schema updates.
4. **Retention:** Maintain a 30-to-90 day rotating backup schedule with offsite copies for disaster recovery.

---

## 9. Production Deployment Recommendations

When deploying MoneyLend to a production environment:

1. **Enforce HTTPS (TLS):** Obtain and configure an SSL/TLS certificate (e.g. Let's Encrypt). This enables the `Secure` cookie flag and encrypts all network transit.
2. **Disable Display Errors:** Set `display_errors = Off` and `log_errors = On` in `php.ini` to prevent server path leakage.
3. **Restrict File Permissions:** Web server user should only have read access to codebase files and write access only to `logs/` and safe asset directories.
4. **Strong Database Credentials:** Replace default `root` without password with a dedicated non-root MySQL user with strong credentials and limited host binding.

---

## 10. Reporting a Vulnerability

If you discover a security vulnerability within MoneyLend, please report it responsibly by contacting the administrator or repository owner directly rather than opening public GitHub issues. All verified security reports will be investigated and addressed promptly.

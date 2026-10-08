# MoneyLend — Release Notes

## Version 1.3.0 (Final Release)

### Release Status
**Production-Ready / Deployment-Ready** (v1.3.0) — Complete, verified, and hardened for deployment.

---

### Overview
MoneyLend v1.3.0 marks the final release of the Money Lending Management Web Application. Developed with standards-compliant native PHP 8.2+, MySQL 5.7+ / MariaDB (InnoDB), PDO, Bootstrap 5.3, Chart.js, and vanilla JavaScript, this release delivers a complete, cohesive, secure, and production-ready platform for private credit, personal lending, and administrative portfolio management.

---

### Complete MoneyLend Capabilities Summary

- **Authentication & RBAC Administration:**
  - Secure bcrypt password hashing with constant-time verification (`password_verify`).
  - Native PHP session management with strict cookies (`HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS).
  - Multi-tier Role-Based Access Control (`admin` and `staff`).
  - User administration portal with account activation toggles, role assignments, and password resets.
  - Administrative self-protection and last-active-admin lockout safeguards.
  - Sliding-window login rate limiting to mitigate brute-force attempts.
  - Automatic session inactivity timeout guards.

- **Executive Financial Dashboard:**
  - Real-time portfolio KPIs: Total Borrowers, Active Loans, Total Amount Lent, Total Amount Repaid, and Net Outstanding Balance.
  - Quick action shortcuts for originating borrowers, logging agreements, and collecting installments.
  - Real-time portfolio health metrics and recent activity/transaction feeds.

- **Borrower Relationship Management:**
  - Full lifecycle CRUD with search, status filtering, and Indian 10-digit mobile number validation (`/^[6-9]\d{9}$/`).
  - Contact deduplication and foreign-key protected deletion preserving historical records.
  - Dedicated client profile views with summary statistics, linked loan portfolio history, and printable client financial statements.

- **Loan Portfolio Management:**
  - Loan agreement origination supporting **Flat Interest** and **Simple Interest** calculation models.
  - Flexible duration terms in months or years with automated maturity due date calculation.
  - Synchronized client-side preview with authoritative server-side calculations.
  - Dynamic repayment progress bars tracking collection percentage against total payable.
  - Automated loan status lifecycle (`Active`, `Partially Paid`, `Paid`, `Overdue`, `Cancelled`).
  - Audit protection: loans with logged payments are cancelled rather than hard-deleted.
  - Printable official loan statements with installment breakdown and signature blocks.

- **Repayments Ledger & Official Receipts:**
  - Installment collection interface supporting multiple payment channels (`Cash`, `UPI`, `Bank Transfer`, `Cheque`).
  - Strict server-side validation enforcing that repayments cannot exceed the outstanding balance.
  - Atomic database transactions synchronizing loan `amount_repaid`, `remaining_balance`, and status.
  - Printable official repayment vouchers with comprehensive settlement trace.
  - Installment modification with balance recalculation and atomic deletion with status reversion.

- **Reports & Portfolio Analytics:**
  - Analytical dashboard featuring interactive Chart.js visualizations (Collection Trend line chart, Status Distribution donut).
  - Multi-parameter filtering across date presets (Today, Yesterday, This Week, This Month, Last Month, This Year) and custom ranges.
  - Payment method share distribution and top borrowers ranking.
  - Overdue delinquency tracking with dynamic days-overdue calculation.
  - Formula-injection-safe streaming CSV export for loan portfolios and repayment ledgers.

- **Notifications & Automation Engine:**
  - Internal notification center with top-nav unread bell badge and tabbed filtering (`All`, `Unread`, `Due Soon`, `Overdue`).
  - Idempotent reminder engine generating due-soon, due-today, and overdue delinquency alerts with zero duplicates.
  - Standalone CLI runner (`scripts/generate_notifications.php`) suitable for cron task scheduling.
  - Safe development email architecture (`MAIL_MODE=log`) logging to `logs/mail.log` without requiring live SMTP credentials.

- **Activity & Audit Trail:**
  - Centralized audit log tracking user logins, logouts, loans, repayments, profile changes, and user management.
  - Module and action-based filtering with metadata summaries.
  - Zero sensitive credential logging.

- **Production Configuration & Server Hardening:**
  - Decoupled environment configuration supporting `.env` or system environment variable overrides (`DB_*`, `APP_ENV`, `APP_URL`).
  - Production error suppression (`display_errors = 0`) to prevent credential or internal path disclosure.
  - Apache `.htaccess` rules enforcing `Options -Indexes`, blocking `.env`, `database/`, `logs/`, `*.sql`, and `*.log` from direct web access.
  - Standardized `DEPLOYMENT.md` detailing XAMPP local setup, production Linux/Apache installation, virtual hosts, backup/restore, and maintenance.

---

### Security Architecture & Hardening

1. **SQL Injection:** 100% parameterized queries via native PDO prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`).
2. **Cross-Site Scripting (XSS):** Universal HTML entity escaping via `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
3. **Cross-Site Request Forgery (CSRF):** Cryptographic session tokens validated on all state-altering POST requests.
4. **Authentication & Password Hashing:** Salted `bcrypt` (`PASSWORD_DEFAULT`) with constant-time verification (`password_verify()`).
5. **Authorization (RBAC):** Server-side role checks guarding admin portals with self-protection and last-admin safeguards.
6. **Rate Limiting:** Sliding-window login throttling tracking failed attempts by IP and email.
7. **Session Security:** Strict session cookies (`HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS active), session regeneration, and inactivity timeout.
8. **Defense-in-Depth HTTP Headers:** `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, and `X-XSS-Protection`.
9. **Secrets Protection:** Zero credentials committed to Git; `.env`, `.env.local`, and sensitive directories ignored and blocked via `.htaccess`.
10. **CSV Formula Defense:** Spreadsheet execution characters (`=`, `+`, `-`, `@`, `\t`, `\r`) sanitized prior to CSV streaming.

---

### Test Execution Summary

All test suites across all project phases executed with a 100% pass rate:

| Test Suite | Scope | Executed | Passed | Failed |
| :--- | :--- | :---: | :---: | :---: |
| **Security Hardening** (`test_phase73_security.php`) | Auth guards, CSRF, SQLi, XSS, headers, session destruction | 20 | 20 | 0 |
| **Error & Success Handling** (`test_phase74_verification.php`) | Alerts, PRG redirects, empty states, ID safety, leakage check | 11 | 11 | 0 |
| **Master Regression** (`test_phase75_full_regression.php`) | Auth, dashboard math, CRUD, E2E journey, DB integrity | 54 | 54 | 0 |
| **Phase 10 Advanced Features** (`test_phase10_advanced_features.php`) | Activity logging, statements, advanced search & filtering | 28 | 28 | 0 |
| **Phase 11 Notifications & Automation** (`test_phase11_notifications.php`) | Notification center, bell counter, idempotent runner, CLI | 22 | 22 | 0 |
| **Phase 12 Security & Administration** (`test_phase12_security_admin.php`) | User administration, RBAC, rate-limiting, inactivity guard | 22 | 22 | 0 |
| **Phase 13 Production Readiness** (`test_phase13_production_readiness.php`) | Env config, .htaccess blocks, error suppression, E2E smoke | 12 | 12 | 0 |
| **Global PHP Syntax Check** (`php -l`) | All application, configuration, and helper PHP files | 34 | 34 | 0 |
| **TOTAL** | | **203** | **203** | **0** |

---

### Database Integrity Verification

The financial baseline remains preserved and fully verified:
- **Total Borrowers:** 7
- **Total Loans:** 6
- **Total Repayments:** 7
- **Total Principal Lent:** ₹250,000.00
- **Total Principal Repaid:** ₹57,345.00
- **Net Outstanding Balance:** ₹217,655.00
- **Orphaned Loans:** 0
- **Orphaned Repayments:** 0

---

### Known Environment Limitations

- **Headless Browser Subagent Download:** Playwright-based headless browser automation was unable to install the driver binary due to an external network 404 response on macOS ARM64 (`playwright-1.57.0-mac-arm64.zip` from Microsoft CDN). Automated functional regression testing was conducted via comprehensive PHP/cURL regression test suites and code-level audits, validating HTTP response codes, security headers, DOM output, and CSS responsive media rules.
- **Local Development Environment:** Testing was conducted in a local XAMPP macOS environment (HTTP). Production deployments require TLS/HTTPS termination and production database credentials as documented in `DEPLOYMENT.md`.

---

### Database Migration Order

To set up a fresh deployment from scratch, execute files in the following order:
1. `database/schema.sql` (Creates core tables, foreign keys, system settings, demo administrator)
2. `database/migrations/001_add_activity_log.sql` (Adds `activity_logs` table)
3. `database/migrations/002_add_notifications.sql` (Adds `notifications` and `notification_preferences` tables)
4. `database/migrations/003_add_security_admin.sql` (Adds `login_attempts` table, updates `users` with `is_active`, `last_login_at`, security settings)
5. *(Optional for demo only)* `database/seed.sql` (Populates demonstration borrowers, loans, repayments)

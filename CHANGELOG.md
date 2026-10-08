# Changelog

All notable changes to the **MoneyLend** project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.3.0] — 2026-10-09 (Phase 13: Production Readiness & Final Release)

### Added
- **Production Configuration:** Dynamic environment variable loading (`.env`, `getenv()`, `$_ENV`) across database connections, application URLs, and error reporting.
- **Apache Server Hardening:** Production `.htaccess` enforcing directory browsing restrictions (`Options -Indexes`), character encoding (`UTF-8`), and direct HTTP access blocking to sensitive files (`.env`, `database/`, `logs/`, `*.sql`).
- **Comprehensive Operations Documentation:** Created `DEPLOYMENT.md` providing step-by-step local XAMPP and production server setup, Apache virtual host configuration, permission models, and disaster recovery procedures.
- **Production Smoke & Readiness Test Suite:** Automated verification suite (`scratch/test_phase13_production_readiness.php`) ensuring complete health, error suppression, and security posture.
- **Clean Git Tracking:** Added `logs/.gitkeep` and excluded temporary test artifacts, database backups, and logs from version control.

### Changed
- Promoted application version constant `APP_VERSION` to `1.3.0` across application headers, footers, and metadata.
- Configured production error suppression disabling technical error displays (`display_errors = 0`) when `APP_ENV=production` or `APP_DEBUG=false`.

---

## [1.2.0] — 2026-10-09 (Phase 12: Advanced Security & Administration)

### Added
- **User Administration Portal:** Dedicated management interface (`settings/users.php`) for inspecting registered users, viewing last login timestamps, activating/deactivating accounts, and resetting passwords.
- **Role-Based Access Control (RBAC):** Strict operational separation between `admin` (full system control, user management, security settings) and `staff` (operational access to borrowers, loans, repayments, and reports).
- **Administrative Self-Protection:** Server-side rules preventing administrators from deactivating, demoting, or deleting their own active accounts.
- **Last Active Admin Safeguard:** Strict validation prohibiting deactivating or demoting the system's last remaining active administrator.
- **Brute-Force Rate Limiting:** Login attempt auditing table `login_attempts` with progressive sliding-window throttling (default: 5 failed attempts within 15 minutes).
- **Session Inactivity Guard:** Configurable inactivity timeout (default: 30 minutes) expiring dormant sessions with clear user notification.
- **Dynamic Security Configuration:** Centralized panel in `settings/index.php` for calibrating session timeouts, lockout windows, failed attempt limits, and password length policies.
- **Security Posture Widget:** Real-time system integrity card on the executive dashboard for administrators.
- **Security Documentation:** Comprehensive `SECURITY.md` establishing vulnerability policies, threat models, and safe backup guidelines.

---

## [1.1.0] — 2026-10-09 (Phase 11: Notifications & Automation)

### Added
- **Internal Notification Center:** Dedicated notifications hub (`/notifications/`) with filtering by state (`All`, `Unread`, `Due Soon`, `Overdue`).
- **Navbar Unread Counter:** Real-time top navigation bell icon displaying live unread counter badge and dropdown previews.
- **Automated Due-Date Engine:** Automated generation of `loan_due_soon` (advance notice), `loan_due_today` (maturity date), and `loan_overdue` (delinquency escalation) reminders.
- **Transactional Hooks:** Event-driven notifications generated upon installment confirmation and zero-balance loan settlement.
- **CLI Automation Runner:** Command-line runner (`scripts/generate_notifications.php`) with cron support, dry-run simulation, and log output.
- **Safe Development Email Mode:** Decoupled `MAIL_MODE=log` logging rendered email notifications to `logs/mail.log` without requiring live SMTP credentials.

---

## [1.0.2] — 2026-10-09 (Phase 10: Advanced Management Features)

### Added
- **Centralized Activity & Audit Log:** Append-only audit trail (`/activity/`) capturing system actions across loans, repayments, borrowers, settings, and logins.
- **Official Financial Statements:** Dedicated borrower statements (`borrowers/statement.php`) and loan installment ledgers (`loans/statement.php`) with print-ready `@media print` layouts.
- **Dashboard Intelligence Metrics:** Collection recovery rate progress bar, overdue delinquency monitors, and upcoming due loan widgets.
- **Cross-Module Deep Filtering:** Quick borrower filter presets on loans and repayments directories.

---

## [1.0.1] — 2026-10-08 (Phase 9: Professional UI/UX Enhancement)

### Added
- **Professional Slate Design System:** Refined color tokens, elevated card components, subtle shadows, and animated micro-interactions.
- **Responsive Drawer Navigation:** Mobile drawer sidebar with backdrop overlay and keyboard-accessible trap.
- **Interactive Visual Feedback:** Contextual badges with high-contrast accessibility across all module tables.

---

## [1.0.0] — 2026-10-07 (Phases 1–8: Initial Stable Release)

### Added
- Core lending platform with borrower CRUD, loan origination (Flat and Simple Interest models), and installment repayment tracking.
- Automated loan lifecycle status management (`active`, `partially_paid`, `paid`, `overdue`, `cancelled`).
- Official printable repayment receipts and streaming CSV export engine.
- Defense-in-depth security hardening: PDO prepared queries, CSRF token validation, XSS escaping, secure session flags, and security headers.
- Comprehensive 54-test automated regression suite with complete end-to-end user journey validation.

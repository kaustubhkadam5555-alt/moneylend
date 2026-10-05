# MoneyLend

> **"Simple Lending. Smarter Tracking."**

A simple, lightweight, and modern money-lending management web application built for small lenders to manage borrowers, track loan agreements, log repayments, and monitor portfolio performance from a centralized dashboard.

Developed with a clean, beginner-friendly architecture suitable for college projects and small-scale operations.

---

## 🚀 Tech Stack

- **Backend:** PHP 8.2+ (Native, Object-Oriented PDO database abstraction)
- **Database:** MySQL / MariaDB (via XAMPP)
- **Frontend UI:** HTML5, CSS3, Vanilla JavaScript
- **CSS Framework:** Bootstrap 5.3 (via CDN)
- **Icons:** Font Awesome 6 Free (via CDN)
- **Fonts:** Inter (via Google Fonts)
- **Web Server:** Apache (via XAMPP on macOS)
- **Database Administration:** phpMyAdmin

---

## 📂 Project Directory Structure

```text
moneylend/
│
├── index.php               # Application entry point & auth router
├── login.php               # Authentication login interface
├── logout.php              # Session destruction and logout handler
├── dashboard.php           # Primary administrative financial dashboard
│
├── config/
│   ├── config.php          # App constants, timezone, BASE_URL, currency
│   └── database.php        # Secure PDO MySQL connection handler
│
├── includes/
│   ├── header.php          # Reusable HTML head, meta, CDN styles
│   ├── navbar.php          # Reusable top navigation bar
│   ├── sidebar.php         # Reusable left sidebar navigation
│   ├── footer.php          # Reusable layout footer and script loader
│   └── auth.php            # Session management and auth helper functions
│
├── assets/
│   ├── css/
│   │   └── style.css       # Custom branding and responsive dashboard styles
│   │
│   ├── js/
│   │   └── script.js       # Mobile navigation toggle & UI interactions
│   │
│   └── images/             # Static logos, avatars, and graphical assets
│
├── borrowers/              # Borrower management module (Phase 2)
│
├── loans/                  # Loan portfolio and schedule module (Phase 2)
│
├── repayments/             # Installment and payment tracking module (Phase 2)
│
├── reports/                # Financial reports & analytics module (Phase 3)
│
├── database/
│   └── schema.sql          # MySQL database tables, constraints, & seed
│
├── README.md               # Project documentation & setup instructions
└── .gitignore              # Git ignore rules for macOS and editor files
```

---

## 🛠️ Requirements & Prerequisites

1. **XAMPP for macOS** (PHP 8.2+ and MariaDB/MySQL).
2. Modern Web Browser (Google Chrome, Safari, Firefox, Edge).

---

## 💻 Installation & Setup Guide

### 1. Place the Project in XAMPP `htdocs`
Ensure the repository is placed directly in your XAMPP web root:
```bash
/Applications/XAMPP/xamppfiles/htdocs/moneylend
```

### 2. Start Apache and MySQL
Open the **XAMPP Application Manager** (or terminal) and start:
- **Apache Web Server**
- **MySQL Database Server**

You can verify their status in terminal:
```bash
sudo /Applications/XAMPP/xamppfiles/xampp status
```

### 3. Import the Database Schema
You can set up the database using either **phpMyAdmin** or the **MySQL Command Line**.

#### Option A: Via phpMyAdmin (Browser)
1. Open your browser and go to `http://localhost/phpmyadmin/`.
2. Click **New** in the left sidebar and create a database named `moneylend` with collation `utf8mb4_unicode_ci`.
3. Select the `moneylend` database and click the **Import** tab.
4. Choose the file `database/schema.sql` from your project folder and click **Import**.

#### Option B: Via Terminal (Quick)
Run the following command in terminal:
```bash
/Applications/XAMPP/xamppfiles/bin/mysql -u root < /Applications/XAMPP/xamppfiles/htdocs/moneylend/database/schema.sql
```

### 4. Configure Database Credentials (If Needed)
Default database credentials are set in `config/database.php`:
- **Host:** `localhost`
- **Database:** `moneylend`
- **User:** `root`
- **Password:** `""` (empty by default in XAMPP)
- **Port:** `3306`

### 5. Open the Application
Launch your browser and visit:
```text
http://localhost/moneylend/
```
You will be directed to the **Login** screen (`login.php`). In Phase 1, you can click **Sign In** to preview the **Dashboard** (`dashboard.php`).

---

## 📊 Database Schema Overview

The database `moneylend` includes four core tables:

1. **`users`**: Administrative credentials, names, emails, roles (`admin`, `staff`), and creation timestamps.
2. **`borrowers`**: Client records including full name, phone number, email, and address.
3. **`loans`**: Loan portfolio records linked to borrowers with principal amount, interest rate, loan date, due date, status (`active`, `paid`, `overdue`, `defaulted`), and notes.
4. **`repayments`**: Repayment records linked to loans with payment amount, payment date, method (`Cash`, `UPI`, `Bank Transfer`, `Cheque`), and transaction notes.

---

## 🔒 Security Practices

- **Prepared Statements:** Database layer uses PDO configured with `PDO::ATTR_EMULATE_PREPARES => false` to mitigate SQL injection.
- **Output Sanitization:** User-rendered content is escaped using `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- **Session Protection:** Session cookies are secured with `httponly` and `samesite=Lax` flags.
- **Credential Protection:** Database connection details and error traces are suppressed from client responses and logged to server error logs.
- **Password Hashing:** Prepared to use `password_hash()` and `password_verify()` using standard bcrypt algorithms.

---

## 🗺️ Development Roadmap

- [x] **Phase 1: Project Foundation & UI Shell** *(Current)*
  - Directory structure and configuration files.
  - Initial database schema with foreign keys and indexes.
  - Reusable template parts (`header`, `navbar`, `sidebar`, `footer`, `auth`).
  - Responsive financial dashboard UI shell with statistic cards and activity placeholders.
  - Branded login and session logout handlers.
- [ ] **Phase 2: Authentication & CRUD Operations**
  - Secure login verification against `users` table with password hashing.
  - Borrower management (Add, List, View, Edit, Delete).
  - Loan creation and management (Borrower assignment, interest calculator).
  - Repayment tracking (Record installments, auto-calculate balances).
- [ ] **Phase 3: Financial Reports & Enhancements**
  - Summary reports, overdue loans filter, print receipts, and export capabilities.

---

## 📄 License
Educational project developed for small money-lending administration.

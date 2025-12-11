# 🏥 Hospital Les Corts - Clinical & Pharmacy Management System

![Project Status](https://img.shields.io/badge/Status-Active-success)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)
![MySQL](https://img.shields.io/badge/Database-MySQL-4479A1)
![HTML5](https://img.shields.io/badge/Frontend-HTML5%2FCS3-orange)

A robust, **full-stack web application** designed to digitize hospital workflows. This system bridges the gap between Clinical Management (EHR) and Pharmacy Inventory Control (ERP), featuring role-based access for Doctors, Pharmacists, Administrators, and Patients.

---

## 📖 Table of Contents
- [About the Project](#-about-the-project)
- [Key Features](#-key-features)
- [Technical Highlights](#-technical-highlights)
- [Database Architecture](#-database-architecture)
- [Installation & Setup](#-installation--setup)
- [Usage Guide](#-usage-guide)
- [Future Improvements](#-future-improvements)

---

## 📝 About the Project

This project was built to solve the challenge of fragmented medical data. It integrates patient history with real-time inventory management. Unlike standard CRUD applications, this system implements complex business logic, such as inferring active medical conditions based on prescription history and managing medication stock down to specific lot numbers and expiration dates.

**Core Functionalities:**
* Electronic Health Records (EHR)
* Electronic Prescribing (eRx)
* Advanced Pharmacy Inventory Management
* Patient Self-Service Portal

---

## ✨ Key Features

### 🩺 For Healthcare Professionals (Doctors/Nurses)
* **Patient Timeline:** Visual history of all medical encounters, sorted chronologically.
* **Smart Diagnosis Tracking:** Algorithms that aggregate active diagnoses from consultation notes *and* infer chronic conditions based on active prescriptions.
* **Electronic Prescribing:** Integrated drug search with autocomplete to issue prescriptions linked to pharmacy stock.
* **Allergy Alerts:** Prominent warnings for patient allergens during workflows.

### 💊 For Pharmacists
* **Inventory Control:** Real-time tracking of medication stock levels.
* **Batch Management:** Logic to handle specific **Lot Numbers** and **Expiration Dates** (FIFO compliance).
* **Stock Alerts:** Visual indicators for 'Low Stock' and 'Out of Stock' items.
* **Interoperability:** CSV/JSON Import and Export tools for external auditing.

### 👤 For Patients
* **Personal Dashboard:** Secure access to their own medical record.
* **Active Treatments:** View current prescriptions, dosage instructions, and prescriber details.
* **Medical History:** Transparency regarding their diagnoses and hospital visits.

### 🛡️ Security & Administration
* **RBAC (Role-Based Access Control):** Strict session management ensuring Doctors cannot edit stock prices, and Pharmacists cannot modify medical history.
* **Secure Authentication:** Session-based login with role validation.

---

## ⚙️ Technical Highlights

This project demonstrates several advanced backend concepts:

1.  **Complex SQL Queries (UNION & JOINs):**
    * The "Active Diagnoses" feature uses a `UNION` strategy to merge explicitly diagnosed conditions from the `ENCOUNTER` table with implicitly inferred conditions from the `PRESCRIPTION` table, removing duplicates and sorting by relevance.
    
2.  **ACID Transactions:**
    * Inventory updates (Adding/Removing stock) use SQL transactions (`$conn->beginTransaction()`, `commit()`, `rollback()`) to ensure data integrity. If a lot number insertion fails, the total stock update is rolled back.

3.  **Dynamic Frontend:**
    * Vanilla JavaScript is used for modal management, dynamic form toggling (hiding/showing lot number fields), and asynchronous search suggestions without relying on heavy frameworks.

4.  **Database Normalization:**
    * Data is structured to reduce redundancy (e.g., `DRUGS` table is separate from `LOT_NUMBERS` to allow multiple expiration dates for a single product).

---

## 🗄️ Database Architecture

The system is built on a relational MySQL database containing the following core entities:

* **`PATIENT`**: Core demographics and medical IDs.
* **`PROFESSIONAL`**: Staff details and roles (Admin, Doctor, Nurse, Pharmacist).
* **`DRUGS`**: The catalog of medications (ATC Code, Commercial Name, Price, Min/Max Stock).
* **`LOT_NUMBERS`**: Extension of the drugs table handling specific batch inventory.
* **`ENCOUNTER`**: Represents a doctor-patient visit (Diagnostic ID, Reason, Timestamp).
* **`PRESCRIPTION`**: Junction table linking Patients, Professionals, and Drugs.
* **`DIAGNOSTICS`**: ICD-10 reference table.

---

## 🚀 Installation & Setup

### Prerequisites
* **XAMPP/WAMP/MAMP** (Apache & MySQL)
* **PHP 7.4** or higher

### Steps
1.  **Clone the Repository**
    ```bash
    git clone [https://github.com/yourusername/hospital-les-corts.git](https://github.com/yourusername/hospital-les-corts.git)
    ```

2.  **Setup the Database**
    * Open phpMyAdmin (`http://localhost/phpmyadmin`).
    * Create a database named `clinic_db`.
    * Import the `database_schema.sql` file provided in the root folder.

3.  **Configure Connection**
    * Navigate to `includes/functions.php`.
    * Update the credentials if necessary:
        ```php
        $host = 'localhost';
        $db   = 'clinic_db';
        $user = 'root';
        $pass = '';
        ```

4.  **Launch**
    * Place the project folder in your local server directory (`htdocs` or `www`).
    * Visit `http://localhost/hospital-les-corts/index.php`.

---

## 📂 Folder Structure

```text
/hospital-les-corts
│
├── includes/            # DB Connections & Authentication Logic
├── modules/             # Specific CRUD Modules (e.g., Drug Editing)
├── css/                 # Custom Stylesheets
├── uploads/             # Patient documents (secure)
│
├── index.php            # Login Portal
├── dashboard.php        # Main Role-Based Dashboard
├── patient_dashboard.php# Medical Record View
├── edit_general_meds.php# Pharmacy Stock Management
├── import_interop.php   # Data Import Tool
└── README.md            # Documentation

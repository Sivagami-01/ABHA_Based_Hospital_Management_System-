# MediCore Hospital Portal

MediCore is an ABHA-inspired, role-based hospital management portal built with PHP and MySQL. It brings patient identity, appointments, consultations, prescriptions, and consent workflows into one application.

## Features

- Patient registration, profiles, and health identity workflows
- Role-based portals for patients, front-desk officers, doctors, consent managers, and administrators
- Appointment scheduling and management
- Consultation records and prescription generation
- Patient consent requests and access management for health information
- OTP and optional Google sign-in workflows
- Audit logs, notifications, and optional teleconsultation workflows

Identity verification and payment features include demo/sandbox workflows. They are not a substitute for production identity verification or payment-provider integrations.

## Requirements

- Windows with XAMPP, or another Apache/PHP/MySQL environment
- PHP 8.0 or later with PDO MySQL enabled
- MySQL
- Google OAuth credentials and SMTP credentials only if you want to enable those integrations

## Local setup with XAMPP

1. Put the project folder under XAMPP's `htdocs` directory. For example:

   ```text
   C:\xampp\htdocs\abha_hospital_system
   ```

2. Start Apache and MySQL from the XAMPP Control Panel.

3. Create and initialize the database by importing [`database/schema.sql`](database/schema.sql) in phpMyAdmin or the MySQL command line. The schema creates the `abha_hospital_db` database and the core tables.

4. Configure local environment values. If you do not already have a `.env` file, copy the example:

   ```powershell
   Copy-Item .env.example .env
   ```

   Set `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USERNAME`, and `DB_PASSWORD` in `.env` to match your local MySQL configuration. The example uses port `3307`; change it if MySQL is listening on another port.

5. To use appointment payments or teleconsultations, also import the relevant SQL files from [`database/migrations/`](database/migrations/) after the base schema. Apply each migration once.

6. Open `http://localhost/abha_hospital_system/setup_admin.php` and create the initial administrator account. Once an administrator exists, sign in at `http://localhost/abha_hospital_system/login.php`.

7. Open the portal at `http://localhost/abha_hospital_system/`.

## Optional integrations

- **Google sign-in:** Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI` in `.env`. Register the redirect URI with your Google OAuth application.
- **Email OTP:** Set `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, and `EMAIL_FROM`. OTP expiry, attempt limits, and resend cooldown can also be adjusted using the `OTP_*` settings in `.env`.
- **Identity verification:** The included identity/eKYC workflows support demo or sandbox use. Configure and validate an approved provider separately before any real-world use.

Keep `.env` private and never commit real credentials. Use synthetic data for local development; this project is not configured by default as a production healthcare system.

## Project layout

| Path | Purpose |
| --- | --- |
| `patient/` | Patient dashboard, appointments, records, prescriptions, and consent |
| `doctor/` | Doctor dashboard, appointments, consultations, and prescriptions |
| `receptionist/` | Patient lookup, registration, and appointment operations |
| `admin/` | Hospital and user administration |
| `consent/` | Consent manager workflows |
| `database/schema.sql` | Core database schema and initial role data |
| `database/migrations/` | Optional payment and teleconsultation schema |
| `includes/` | Shared authentication, database, consent, OTP, and application helpers |

## Authors

- Sivagami G & Madhumitha S

SCSVMV 

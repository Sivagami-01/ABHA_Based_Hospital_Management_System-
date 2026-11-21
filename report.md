# ABHA-Inspired Hospital System — Project Report

## Scope and evidence

- **Project folder:** `C:\xampp\htdocs\abha_hospital_system`
- This report describes the PHP source, checked-in SQL, configuration template, tests, and workspace configuration that were inspected.
- The project’s real `.env` file and the contents of stored PDFs, signatures, Aadhaar files, and SQLite data were not read or copied into this report.
- No live database query, migration, form submission, email, OAuth login, Aadhaar verification, or Jitsi call was performed for this report.
- The VS Code workspace also includes a separate sibling folder, `C:\xampp\htdocs\emsign`. It is not part of this hospital application; see [Workspace boundary](#workspace-boundary).

## 1. Project overview

The project presents itself as the **ABHA-Inspired Hospital System** or **MediCore Hospital Portal**. It is an academic/demo web application for coordinating patient identities, hospital registration, appointments, doctor consultations, medical records, prescriptions, and patient consent.

It is intended for five application roles:

1. Patients
2. Front Desk Officers (the `receptionist` role in code)
3. Doctors
4. Consent Managers
5. Administrators

**Important distinction:** this is not an official ABHA or ABDM service. The application creates its own `demo_health_identities` records and `DHI-...` identifiers. No official ABHA/ABDM API integration was found in the inspected project source.

## 2. What the project does

### Main features

| Feature | What it does |
|---|---|
| Demo health identity and patient registration | Collects identity and demographic details, creates a demo health identity, and then allows an account to be created after the project’s eKYC flow. See [create_health_identity.php](./create_health_identity.php) and [create_patient_account.php](./create_patient_account.php). |
| Aadhaar/eKYC demonstration | Accepts Aadhaar-related documents, extracts or parses fields, records consent, and uses an OTP challenge. Offline XML signature verification is implemented locally, but the code also contains demo/sandbox fallback paths; this is not an official UIDAI service. See [includes/aadhaar.php](./includes/aadhaar.php), [includes/ekyc.php](./includes/ekyc.php), and [ekyc.php](./ekyc.php). |
| Local authentication and roles | Supports username/email plus password login, password changes, and role-specific pages. See [login.php](./login.php), [includes/auth.php](./includes/auth.php), and [includes/functions.php](./includes/functions.php). |
| Google sign-in | Optional Google OAuth sign-in, followed by email OTP verification for an existing active application account. See [google_login.php](./google_login.php), [google_callback.php](./google_callback.php), and [google_otp.php](./google_otp.php). |
| Hospital administration | Administrators can manage hospitals, departments, doctors, Front Desk Officers, and consultation fee settings. See [admin/hospitals.php](./admin/hospitals.php), [admin/departments.php](./admin/departments.php), [admin/doctors.php](./admin/doctors.php), and [admin/front_desk_officers.php](./admin/front_desk_officers.php). |
| Hospital patient registration | A receptionist can associate an existing verified patient with their assigned hospital and department. See [receptionist/register_patient.php](./receptionist/register_patient.php). |
| Appointment workflow | Patients request in-person or online appointments; receptionists route requests to a doctor; doctors approve, reject, or request rescheduling using the existing appointment status field. See [patient/book_appointment.php](./patient/book_appointment.php), [receptionist/appointments.php](./receptionist/appointments.php), and [doctor/appointments.php](./doctor/appointments.php). |
| Teleconsultation | Online appointments receive a random room name and can open a Jitsi Meet iframe after server-side participant, appointment, assignment, approval, and payment checks. See [teleconsultation/room.php](./teleconsultation/room.php). |
| Demo consultation payments | Stores a local payment status and generated demo reference. It is not a real payment gateway and does not transfer money. See [includes/consultation_payments.php](./includes/consultation_payments.php) and [patient/demo_payment.php](./patient/demo_payment.php). |
| Consultation notes and diagnosis | An assigned doctor records symptoms, diagnosis, and notes for an approved, paid appointment. See [doctor/consultation.php](./doctor/consultation.php). |
| Medical records | Doctors can create records; patients can see their records. See [doctor/medical_records.php](./doctor/medical_records.php) and [patient/medical_records.php](./patient/medical_records.php). |
| Prescriptions and PDFs | Doctors create prescription medicine rows, draw or upload a visual signature, and generate a PDF using the bundled FPDF library. The signature is saved as a per-prescription image snapshot. Patients and doctors have ownership-checked PDF serving pages. See [doctor/prescriptions.php](./doctor/prescriptions.php), [includes/doctor_signature.php](./includes/doctor_signature.php), and [includes/generate_prescription_pdf.php](./includes/generate_prescription_pdf.php). |
| Consent management | Doctors can request consent; patients can approve, deny, or revoke; consent managers and administrators can monitor requests. See [doctor/request_consent.php](./doctor/request_consent.php), [patient/consent.php](./patient/consent.php), [patient/process_consent.php](./patient/process_consent.php), and [consent/requests.php](./consent/requests.php). |
| Notifications and audit trail | The application records notifications and audit events for many account, appointment, consent, and document actions. See [includes/notifications.php](./includes/notifications.php) and [includes/audit.php](./includes/audit.php). |
| Account maintenance | Users can change their password; administrators can update their profile or reset user passwords. See [change_password.php](./change_password.php), [update_admin.php](./update_admin.php), [admin/reset_user_password.php](./admin/reset_user_password.php), and [admin/reset_doctor_password.php](./admin/reset_doctor_password.php). |
| Browser navigation | The shared header now uses an explicit Back link target rather than relying on JavaScript history. The recent eKYC and test-user POST changes use redirects for Post/Redirect/Get. See [includes/header.php](./includes/header.php), [ekyc.php](./ekyc.php), and [setup_test_users.php](./setup_test_users.php). |

### Typical user flows

#### Patient identity and account

1. A person enters demographic and contact details on `create_health_identity.php`.
2. The application creates a pending demo health identity and starts the eKYC flow.
3. The person provides the requested consent and one-time code. Offline XML/ZIP handling can validate an XML signature and Aadhaar-linked mobile hash when configured. Other paths may use local OCR or a clearly identified sandbox fixture.
4. The project creates a patient login account only after the identity status reaches `verified`.
5. The patient signs in and uses their dashboard.

#### Appointment and consultation

1. A patient chooses a hospital, preferred time, reason, and in-person or online type.
2. A request is stored with status `requested`. Online requests also receive a `teleconsultations` row.
3. A receptionist assigns the request to a doctor in the requested department at the assigned hospital. The appointment becomes `scheduled`.
4. The assigned doctor approves, rejects, or requests rescheduling. Approval creates a pending demo payment using the hospital’s configured fee.
5. The patient completes the demo payment. The doctor can then start the consultation; for an online appointment, both authorized participants can open the same Jitsi room.
6. The doctor saves consultation notes and diagnosis, and can create a prescription and PDF.

#### Consent

1. A doctor creates a consent request for a patient and specifies purpose, requested data, and optional expiry.
2. The patient reviews the request and approves, denies, or later revokes it.
3. The system writes the decision to `consent_records`, updates the request, and creates notifications/audit entries.
4. A Consent Manager can monitor request status. The actual enforcement limitations are described in [Current status and limitations](#10-current-status-and-limitations).

## 3. Technology stack

| Technology | Version/evidence | Why it is used |
|---|---|---|
| PHP | The local XAMPP CLI reports **PHP 8.2.12**. The project does not declare a minimum version in a dependency manifest. | Server-side page logic, form handling, authentication, and database access. |
| MySQL-compatible database | MySQL PDO DSN and MySQL-specific SQL are used. Server version was not checked. | Stores accounts, identities, appointments, clinical records, consent, and related data. |
| PDO | Built into the PHP code; MySQL PDO driver is required. | Prepared statements and database transactions. |
| HTML, CSS, JavaScript | No separate frontend build system or package manifest found. | PHP-rendered pages, styling, and small browser interactions. |
| Bootstrap | 5.3.3 CDN reference in [includes/header.php](./includes/header.php) and [includes/footer.php](./includes/footer.php). | Responsive layout, forms, navigation, and common UI components. |
| Bootstrap Icons | 1.11.3 CDN reference in [includes/header.php](./includes/header.php). | Icons in the interface. |
| Google Fonts | DM Sans and Manrope are imported in [assets/css/style.css](./assets/css/style.css). | Typography. |
| FPDF | Bundled local copy identifies itself as **1.86** in [lib/fpdf/fpdf.php](./lib/fpdf/fpdf.php). | Builds prescription PDFs without Composer. |
| OpenSSL and XML/DOM | Used by [includes/aadhaar.php](./includes/aadhaar.php). | Local verification of XML signatures for the Offline eKYC path. |
| ZipArchive | Used by [includes/aadhaar.php](./includes/aadhaar.php). | Reads Offline eKYC ZIP packages. |
| Tesseract OCR and `pdftoppm` | Optional local command-line tools; paths can be configured through environment variables. Versions were not found. | Optional text extraction from document images/PDFs. |
| Google OAuth 2.0 | Google authorization and token endpoints are called by the sign-in flow. | Optional authentication for an existing account, followed by OTP. |
| Jitsi Meet IFrame API | Public `meet.jit.si`; no pinned API version found. | Embeds online audio/video rooms. |
| SMTP | Custom PHP socket-based mail code in [includes/otp.php](./includes/otp.php). | Sends OTP emails when SMTP is configured. |

No root `composer.json`, `package.json`, `requirements.txt`, or `pom.xml` was found. There is no project-defined AI/ML model. Tesseract is an optional OCR utility, not a model authored by this project.

## 4. Architecture and design

The application is a collection of server-rendered PHP pages rather than a separate frontend and REST API. Each page commonly handles its own request, checks a role, uses shared `includes/` helpers, queries MySQL through PDO, and renders HTML.

```text
Browser
  │  HTTP form requests / page loads
  ▼
Apache (XAMPP) ── PHP page/controller
                    ├── includes/auth.php, functions.php, audit.php, ...
                    ├── PDO ── MySQL database
                    ├── local storage (prescription PDFs, signatures, temporary uploads)
                    ├── Google OAuth + OTP email (optional)
                    ├── local OpenSSL / XML / OCR processing
                    └── Jitsi Meet iframe for online consultations
```

### Structure and patterns

- **Server-side page/controller pattern:** business logic and page markup are often in the same PHP file.
- **Shared include helpers:** authentication, routing, CSRF, flashes, audit logging, notifications, consent, OCR/eKYC, signatures, and payments live under `includes/`.
- **Role checks:** pages call `requireRole(...)`; role dashboard destinations are mapped in `dashboardForRole()`.
- **Database access:** PDO prepared statements are used in many request handlers; transactions protect multi-step operations such as account creation and appointment assignment.
- **Post/Redirect/Get:** successful or terminal actions commonly redirect after POST. This is not uniformly applied to all invalid form states.
- **No central router or formal MVC framework** was found.

## 5. Folder and file structure

The tree below lists the main source and configuration files. Storage contents are summarized rather than enumerated because they may contain personal or clinical data.

```text
abha_hospital_system/
├── .env                         # Local settings; contents deliberately not reviewed
├── .env.example                 # Names/defaults for configuration
├── abha_hospital_system.code-workspace
├── index.php                    # Public landing page
├── create_health_identity.php   # Demo identity registration
├── create_patient_account.php   # Patient account creation
├── ekyc.php                     # Identity verification and OTP workflow
├── login.php / logout.php       # Local login/logout
├── google_login.php             # Starts Google OAuth
├── google_callback.php          # OAuth callback and account lookup
├── google_otp.php               # OTP step after Google sign-in
├── sso_login.php / sso_callback.php
├── setup_admin.php              # First administrator setup
├── setup_test_users.php         # Development account creation
├── change_password.php
├── update_admin.php
├── admin/
│   ├── dashboard.php
│   ├── hospitals.php
│   ├── departments.php
│   ├── doctors.php
│   ├── front_desk_officers.php
│   ├── audit_logs.php
│   ├── reset_user_password.php
│   ├── reset_doctor_password.php
│   └── clear_all_data.php
├── patient/
│   ├── dashboard.php
│   ├── book_appointment.php
│   ├── appointments.php
│   ├── demo_payment.php
│   ├── medical_records.php
│   ├── prescriptions.php
│   ├── serve_prescription_pdf.php
│   ├── consent.php
│   ├── process_consent.php
│   └── notifications.php
├── receptionist/
│   ├── dashboard.php
│   ├── appointments.php
│   ├── register_patient.php
│   ├── patients.php
│   └── patient_lookup.php
├── doctor/
│   ├── dashboard.php
│   ├── appointments.php
│   ├── consultation.php
│   ├── medical_records.php
│   ├── patient_data.php
│   ├── request_consent.php
│   ├── prescriptions.php
│   ├── view_prescription.php
│   └── serve_prescription_pdf.php
├── consent/
│   ├── dashboard.php
│   └── requests.php
├── teleconsultation/
│   └── room.php
├── includes/
│   ├── auth.php, sso.php
│   ├── functions.php, env.php
│   ├── header.php, navbar.php, footer.php
│   ├── audit.php, notifications.php
│   ├── aadhaar.php, ekyc.php, otp.php
│   ├── consent.php, consultation_payments.php
│   ├── doctor_signature.php
│   └── generate_prescription_pdf.php
├── config/
│   ├── database.php
│   └── uidai/uidai_offline_publickey_2026.cer
├── database/
│   ├── schema.sql
│   ├── migrate_patient_profile.php
│   ├── migrate_prescription_documents.php
│   └── migrations/
│       ├── create_teleconsultation_tables.sql
│       └── create_consultation_payment_tables.sql
├── assets/
│   ├── css/style.css
│   └── js/password-toggle.js
├── lib/fpdf/fpdf.php
├── tests/
│   ├── test_consultation_payments.php
│   ├── test_doctor_signature.php
│   └── test_prescription_pdf_paths.php
├── documents/                   # Includes a legacy eSigner API PDF
├── storage/
│   ├── prescriptions/           # Generated PDFs
│   ├── private_signatures/      # Reusable signatures and snapshots
│   └── temp/                    # Temporary/test uploads and diagnostics
├── test_db.php, test_pdf_*.php  # Ad-hoc root diagnostics/tests
└── tmp_*.php                    # Temporary diagnostic scripts
```

The tree is a practical summary; it does not list generated file names or contents in `storage/`.

### Folder purposes

- `admin/`, `patient/`, `receptionist/`, `doctor/`, and `consent/`: role-oriented application pages.
- `teleconsultation/`: online consultation room page.
- `includes/`: shared application logic and page chrome.
- `config/`: database setup and the Offline eKYC public certificate.
- `database/`: base schema, migrations, and migration scripts.
- `assets/`: local CSS and JavaScript.
- `lib/fpdf/`: bundled PDF library.
- `tests/`: standalone PHP tests.
- `documents/`: supporting documents, including a legacy eSigner API PDF.
- `storage/`: generated PDFs, private signature images, and temporary uploads/diagnostics; treat contents as sensitive.

### Important file map

- [config/database.php](./config/database.php): reads database settings, opens PDO, and performs runtime schema checks.
- [includes/auth.php](./includes/auth.php): role enforcement, current-user lookup, and login checks.
- [includes/functions.php](./includes/functions.php): escaping, CSRF, flash messages, URL helpers, role dashboards, and PDF path validation.
- [includes/aadhaar.php](./includes/aadhaar.php): Offline eKYC parsing/signature verification and optional OCR.
- [includes/ekyc.php](./includes/ekyc.php): OTP challenge and eKYC support functions.
- [includes/consent.php](./includes/consent.php): consent expiry and `hasValidConsent()`.
- [includes/consultation_payments.php](./includes/consultation_payments.php): demo fee/payment state helpers.
- [includes/doctor_signature.php](./includes/doctor_signature.php): validates, stores, loads, and snapshots visual doctor signatures.
- [includes/generate_prescription_pdf.php](./includes/generate_prescription_pdf.php): PDF layout, medicine rows, and signature embedding.
- [teleconsultation/room.php](./teleconsultation/room.php): server authorization and public Jitsi iframe.
- [database/schema.sql](./database/schema.sql): base relational schema.

## 6. Database and data

The checked-in base schema is [database/schema.sql](./database/schema.sql). Separate SQL migrations add teleconsultation/availability and demo-payment structures. The database was not queried directly for this report, so the list below describes checked-in definitions and runtime-created structures, not a live database inspection.

### Base tables

| Table | Main purpose and key fields |
|---|---|
| `roles` | Role identifiers and unique role names: patient, receptionist, doctor, consent manager, admin. |
| `users` | Login account, role, optional hospital, username/email, password hash, and account status. |
| `demo_health_identities` | Demo health ID, identity/contact fields, government ID metadata, health details, and verification status. |
| `mock_ekyc_verifications` | Verification history linked to a demo identity; stores document reference/type, status, and verification time. Runtime checks may add provider/document metadata columns. |
| `patients` | Patient account and unique link to a health identity; patient ID and patient profile fields. |
| `hospitals` | Hospital name, address/contact information, and active/inactive status. |
| `departments` | Department name linked to a hospital. |
| `doctors` | Doctor profile linked to a user and department, including specialization and license number. |
| `hospital_patient_registrations` | Links patients to hospitals/departments, with registration status and registering user. |
| `appointments` | Patient, hospital, optional assigned doctor, requester/forwarder, date/reason, doctor response, and status. Status values include `requested`, `scheduled`, `approved`, `rejected`, `reschedule_requested`, `completed`, and `cancelled`. |
| `medical_records` | Patient, doctor, optional hospital, record type/title/description, and creation time. |
| `consultations` | Appointment, patient, doctor, symptoms, diagnosis, notes, and consultation date. |
| `prescriptions` | Consultation, patient, doctor, and prescription date. |
| `prescription_items` | Medicine name, dosage, frequency, duration, and instructions per prescription. |
| `consent_requests` | Patient, requesting user, purpose, requested data, status, request time, and optional expiry. |
| `consent_records` | Patient/request link and decision history. |
| `audit_logs` | User/role, action, entity, description, IP address, and timestamp. |
| `notifications` | User, title, message, read flag, and timestamp. |

### Additional tables

| Table | Defined in | Purpose |
|---|---|---|
| `doctor_availability` | [database/migrations/create_teleconsultation_tables.sql](./database/migrations/create_teleconsultation_tables.sql) | Day-of-week and time-window availability rows. A corresponding management workflow was not found in application pages. |
| `teleconsultations` | Same migration | One online-consultation record per appointment, with patient, requested department, assigned doctor, and unique room name. |
| `consultation_fee_settings` | [database/migrations/create_consultation_payment_tables.sql](./database/migrations/create_consultation_payment_tables.sql) | Per-hospital online and in-person demo fees. |
| `appointment_payments` | Same migration | One payment record per appointment; records patient/doctor, type, amount, `demo` payment mode, status, reference, and time. |
| `prescription_documents` | Created by [config/database.php](./config/database.php) when absent; also described by [database/migrate_prescription_documents.php](./database/migrate_prescription_documents.php) | Prescription-to-PDF paths and historical signing/workflow columns. The schema contains legacy eSigner-related fields, but the main app’s current prescription flow uses the PDF paths rather than an eSigner API. |

Doctor signatures are stored as private files under `storage/private_signatures/`, not in a new signature table. A separate image file is kept for each prescription snapshot so later changes to a doctor’s reusable signature do not replace older snapshots.

### Main relationships

- A user has a role and may be linked to a hospital.
- A patient profile links one user to one demo health identity.
- A doctor profile links one user to a department; departments belong to hospitals.
- An appointment links a patient and hospital and may be assigned to a doctor.
- A teleconsultation extends an appointment; its doctor assignment is updated with appointment routing.
- A consultation belongs to an appointment, patient, and doctor; prescriptions and prescription items extend that record.
- Consent requests link a patient and requesting user; decision records preserve decisions.
- Notifications belong to users; audit entries can reference a user and application entity.
- Demo payment rows link an appointment, patient, and assigned doctor.

### Identifiers and sample data

- Demo health identities are generated in a `DHI-<year>-<six digits>` style; patient IDs use an `HMS-<year>-<six digits>` style.
- The base schema seeds the five role names. No external patient dataset was found in the inspected source.
- The Aadhaar extraction code contains synthetic/demo fallback fixtures. Test scripts also use small image fixtures and in-memory SQLite tables.
- Files under `storage/` may contain local test or application data; their contents were not read for this report.

## 7. APIs and integrations

| Integration | Purpose and data flow | What is not provided |
|---|---|---|
| Google OAuth 2.0 | [google_login.php](./google_login.php) redirects to `accounts.google.com`; [google_callback.php](./google_callback.php) exchanges the authorization code at `oauth2.googleapis.com/token` and checks the returned identity token using Google’s token-info endpoint. The email must match an active local user; [google_otp.php](./google_otp.php) then performs an application OTP step. | It does not create an application account for an unknown Google user. |
| SMTP email | [includes/otp.php](./includes/otp.php) sends OTP email using configured SMTP host/port/account settings. | No email provider credentials are included in this report. |
| Aadhaar Offline eKYC | [includes/aadhaar.php](./includes/aadhaar.php) parses XML/ZIP locally, can verify XML signatures with OpenSSL and a configured public certificate, and may run local OCR tools for other document formats. | No official live UIDAI API call was found. The code has demo/sandbox handling, so application “verified” status should not be equated with an official UIDAI verification without validating the configured environment and data path. |
| Jitsi Meet | [teleconsultation/room.php](./teleconsultation/room.php) loads the public Jitsi IFrame API and opens a room after application-side checks. | A random room name on the public service is not a privacy or authentication guarantee. |
| Bootstrap and Google Fonts CDNs | CSS/JS and font assets load from external CDN hosts. | The UI requires internet access for those hosted assets unless local copies are introduced. |
| Consultation payments | Local database helper records demo payment completion and a `DEMO-...` reference. | No card processor, bank transfer, or real-money payment integration was found. |
| ABHA/ABDM | The project uses demo health IDs and project-owned tables. | No official ABHA/ABDM API endpoint or SDK integration was found. |

There is no general REST API documented in the project; the application is primarily page-based.

## 8. Configuration and local setup

### Configuration files

- [`.env.example`](./.env.example) lists settings for `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USERNAME`, `DB_PASSWORD`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `EMAIL_PROVIDER`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION`, `EMAIL_FROM`, `OTP_EXPIRY_MINUTES`, `OTP_MAX_ATTEMPTS`, `OTP_RESEND_COOLDOWN_SECONDS`, and `AADHAAR_UPLOAD_MAX_BYTES`.
- Code also reads `OFFLINE_EKYC_PUBLIC_CERT_PATH`, `AADHAAR_TESSERACT_PATH`, and `AADHAAR_PDFTOPPM_PATH`. The default certificate file is under [config/uidai/](./config/uidai).
- [config/database.php](./config/database.php) loads `.env`, opens the PDO connection, and checks/changes schema on application startup.
- [includes/functions.php](./includes/functions.php) defines `BASE_URL` as `/abha_hospital_system`; deployments under a different URL path need configuration/code review.
- The `.env.example` database port is an example, not a statement of the live server configuration. The actual `.env` was intentionally not read.

### Safe local setup outline

1. Install XAMPP with a PHP version compatible with the code (the inspected local CLI is PHP 8.2.12) and a MySQL/MariaDB server.
2. Place the project at `C:\xampp\htdocs\abha_hospital_system`, matching the current `BASE_URL`.
3. For a new environment, copy `.env.example` to `.env` and set database values. Configure Google and SMTP settings only if those optional flows are needed. Never publish `.env` or its secrets.
4. Create a **fresh, empty** database and import [database/schema.sql](./database/schema.sql). This SQL creates the database and base tables and inserts role rows; it is not an idempotent reset script and should not be casually run over an existing database.
5. For the features that need them, review and apply the additive SQL files under [database/migrations/](./database/migrations/) to a test database first. Do not execute migrations against an existing/production database without a backup and explicit review.
6. Note that `config/database.php` can create `prescription_documents` and add missing eKYC columns automatically. [database/migrate_patient_profile.php](./database/migrate_patient_profile.php) also performs schema and existing-record updates; inspect it carefully before using it on existing data.
7. Start Apache and MySQL in XAMPP and open `http://localhost/abha_hospital_system/`.
8. Use [setup_admin.php](./setup_admin.php) only for initial administrator setup. Then configure hospitals, departments, doctors, receptionist accounts, and consultation fees from the admin pages.
9. Create a patient identity/account and configure eKYC/OTP dependencies if that demonstration flow is required.
10. Google sign-in needs OAuth client configuration and a matching callback URL. Email OTP needs working SMTP configuration; the project also has a development/log provider that can display debug codes and should not be treated as production delivery.
11. Jitsi demonstrations require internet access. Payments are demo-only.

The project has no README-based install guide or dependency-lock file. Required PHP extensions and external command-line tools should be checked on the actual XAMPP installation; they are not declared in a package manifest.

## 9. Testing and verification

### Tests in the project

| Test | Coverage | Safe-run note |
|---|---|---|
| [tests/test_prescription_pdf_paths.php](./tests/test_prescription_pdf_paths.php) | Resolves a PDF inside prescription storage and rejects traversal, outside paths, and missing files. | Ran for this report; all four checks passed. Command from the project root: `C:\xampp\php\php.exe C:\xampp\htdocs\abha_hospital_system\tests\test_prescription_pdf_paths.php`. |
| [tests/test_doctor_signature.php](./tests/test_doctor_signature.php) | Image validation, PNG/JPEG, size/type rejection, doctor isolation, replacement, per-prescription snapshots, and PDF image embedding. | Not run here: it loads the PDF generator, which loads the application database config and may perform runtime schema changes. Use only after isolating it from the real database. |
| [tests/test_consultation_payments.php](./tests/test_consultation_payments.php) | Fee lookup, pending/completed payment transitions, appointment backfill, duplicate handling, and transaction reference behavior. | Not run here: it loads the database-backed helper/config before switching to an in-memory SQLite fixture. |
| Root `test_db.php`, `test_pdf_generate.php`, `test_pdf_verify.php`, and `tmp_*.php` | Ad-hoc diagnostics or temporary scripts; not a unified test suite. | Not run; inspect each before use because some load the real database or process local documents. |

There is no PHPUnit, Composer test script, or package-based test runner. Tests are standalone PHP scripts.

### Checks actually run for this report

- PHP syntax check: **82 PHP files checked; all passed `php -l`.** Syntax checking does not execute application code or prove runtime/database behavior.
- Prescription PDF path test: **4 checks passed.** Command: `C:\xampp\php\php.exe C:\xampp\htdocs\abha_hospital_system\tests\test_prescription_pdf_paths.php`.
- No database, migration, OAuth, email, OCR, eKYC, or video-call workflow was executed.
- The earlier Back-navigation task also reported passing PHP syntax checks and browser navigation checks for its three modified files; a database-changing eKYC/test-user submission was deliberately not performed.

## 10. Current status and limitations

“Implemented in source” below means code is present; it does not mean every external service or complete browser workflow was independently tested for this report.

### Present in source

- Role-specific pages for patient, receptionist, doctor, consent manager, and administrator.
- Patient identity/account, appointment routing, consultation, medical record, consent, prescription, and PDF workflows.
- Jitsi teleconsultation integration and server-side room authorization checks.
- Visual doctor signature upload/drawing and PDF embedding.
- Local and optional Google-based login; OTP/email helpers.
- SQL definitions and standalone tests for the documented flows.

### Needs attention or verification

1. **Not an official ABHA product:** health IDs are project-generated demo IDs; no official ABHA/ABDM service integration was found.
2. **eKYC status is environment-dependent:** the code contains local XML signature verification but also sandbox/demo fallback behavior. Verify the certificate, PHP extensions, and exact processing path before treating a status as authoritative.
3. **Consent enforcement mismatch:** `includes/consent.php` defines `hasValidConsent()`, but no call site was found. `doctor/patient_data.php` checks that the doctor has an approved/completed appointment, displays a consent record if one exists, and then loads records even when no consent record is found. The page’s consent wording therefore overstates what the route enforces.
4. **Receptionist lookup scope:** `receptionist/patient_lookup.php` searches by demo health ID without an assigned-hospital restriction. The separate `receptionist/patients.php` search is hospital-scoped. Review the lookup route before using real patient records.
5. **Doctor availability:** `doctor_availability` is defined in a migration, but no doctor availability management or appointment-slot enforcement flow was found in the application pages.
6. **Rescheduling:** the existing appointment status supports `reschedule_requested`; an advanced rescheduling/schedule-change workflow was not found.
7. **Public video service:** the Jitsi room name is random, but the page itself warns that public room names are not an authentication or privacy guarantee.
8. **Payment is simulated:** the fee and payment records are demo-only, not financial settlement.
9. **Consent and medical-record scope:** doctor medical-record creation presents all verified patients in its selector, and the protected-data page does not enforce the consent helper. Review scope and consent rules before real-data use.
10. **Prescription file serving:** PDF endpoints check patient/doctor ownership and confine paths to `storage/prescriptions`. However, PDFs are stored below the web root and the inventory contains a deny rule only under `storage/private_signatures/`, not a `storage/`-level access rule. Depending on Apache configuration, a direct static URL may bypass the PHP ownership check; verify/deny direct access before using real clinical PDFs.
11. **Runtime schema changes:** including `config/database.php` can create a table or add eKYC columns. A normal page load is therefore not guaranteed to be schema-read-only.
12. **Data-clear page:** `admin/clear_all_data.php` contains a permanent delete operation. It deletes many core tables but does not list newer payment/teleconsultation/fee/document tables in its delete set, so its “clear all” label may not match the full current schema. Do not use it casually.
13. **Development accounts:** `setup_test_users.php` contains fixed development-account credentials in source and can create test users. The values are intentionally not reproduced here. Disable or restrict this page before any production deployment.
14. **Email TLS:** the custom SMTP socket setup in `includes/otp.php` disables peer and peer-name certificate verification. This needs security review before production email use.
15. **Demo SSO route:** `includes/sso.php` provides session helpers, and Google OTP uses them. `sso_callback.php` consumes `sso_pending_user`, but no setter for that session value was found in the main project scan; verify whether this route is still intended.
16. **eSigner leftovers:** no active eSigner signing endpoint/helper was found in the main app’s current PHP workflow scan, but legacy columns remain in the prescription-document schema, and [documents/emSigner API Documentation.2025 (1).pdf](./documents/emSigner%20API%20Documentation.2025%20%281%29.pdf) remains in the project. These are not evidence of an active main-app signing flow.
17. **Sensitive local artifacts:** storage contains generated PDFs, signature images, temporary Aadhaar-related files, and diagnostic scripts. Their contents were not inspected for this report; review access, retention, and cleanup policy before sharing or deploying the folder.
18. **No full end-to-end test was run:** browser/database/external-service functionality remains to be verified in a safe, isolated environment.

## 11. Key code explanations

| File/function | Plain-language explanation |
|---|---|
| [includes/auth.php](./includes/auth.php) — `requireLogin()`, `requireRole()`, `currentUser()` | Stops unauthenticated users and restricts pages to the role stored in the session. |
| [includes/functions.php](./includes/functions.php) — `verifyCsrfToken()`, `flash()`, `dashboardForRole()`, `prescriptionPdfStoragePath()` | Shared form token checking, one-time UI messages, role dashboard routing, and path confinement for PDF-serving code. |
| [includes/aadhaar.php](./includes/aadhaar.php) — `verifyOfflineEkycSignature()`, `processOfflineEkycUpload()` | Parses Offline eKYC XML/ZIP and attempts local signature/digest verification; separate branches support demo/OCR behavior. |
| [includes/ekyc.php](./includes/ekyc.php) — OTP challenge helpers | Creates an expiring OTP challenge, hashes the code in session, enforces resend/attempt limits, and verifies submitted codes. |
| [includes/consent.php](./includes/consent.php) — `expireConsentRequests()`, `hasValidConsent()` | Expires pending consent requests and can check whether a requester has approved, unexpired consent; the helper currently has no detected call site. |
| [includes/consultation_payments.php](./includes/consultation_payments.php) | Reads configured demo fees, creates pending payment rows, completes eligible demo payments, and creates references/notifications. |
| [includes/doctor_signature.php](./includes/doctor_signature.php) | Checks image bytes, detected MIME, dimensions, and allowed PNG/JPEG formats; stores doctor-specific images and prescription-specific snapshots in private storage. |
| [includes/generate_prescription_pdf.php](./includes/generate_prescription_pdf.php) — `generatePrescriptionPdf()` | Loads prescription/doctor/patient data and medicine items, builds the PDF using FPDF, embeds a saved signature snapshot when available, stores the file, and updates `prescription_documents`. |
| [teleconsultation/room.php](./teleconsultation/room.php) | Looks up the appointment using the URL ID, checks the active logged-in patient or assigned doctor, then requires approved status and completed demo payment before rendering Jitsi. |
| [config/database.php](./config/database.php) | Loads configuration and opens PDO; it also performs runtime schema checks/creation, which is important when reasoning about test or setup side effects. |

## 12. Glossary

| Term | Simple meaning |
|---|---|
| ABHA | A national digital health identifier concept; this project uses its own demo IDs and is not an official ABHA service. |
| ABDM | Ayushman Bharat Digital Mission; no official API connection was found in this project. |
| API | A defined way for two software systems to communicate. |
| Apache | The web server commonly included with XAMPP. |
| Authentication | Checking who a user is, such as by login. |
| Authorization / RBAC | Deciding which pages/actions a signed-in role is allowed to use. |
| CSRF token | A per-session form value used to help reject forged form submissions. |
| eKYC | Electronic identity verification. |
| FPDF | A PHP library for constructing PDF files. |
| IFrame | A web page embedded inside another page; used here for Jitsi. |
| Migration | A SQL or PHP change that alters a database schema or data. |
| MySQL / MariaDB | Relational database systems that store the application’s structured records. |
| OCR | Optical Character Recognition: converting text in images/PDF pages into machine-readable text. |
| PDO | PHP’s database access layer, used here to connect to MySQL and run prepared queries. |
| Post/Redirect/Get (PRG) | A web pattern where a POST is followed by a redirect to a GET page, reducing accidental resubmission. |
| SMTP | A protocol used to send email. |
| SSO | Single Sign-On; in this codebase the label is used for a session helper/demo flow, while Google OAuth is a separate external login path. |
| UIDAI | The Indian authority associated with Aadhaar; the project’s local file processing is not itself an official UIDAI API integration. |
| Webhook | An HTTP callback sent by one service to another; no active eSigner webhook was found in the main hospital application workflow. |

## Workspace boundary

[abha_hospital_system.code-workspace](./abha_hospital_system.code-workspace) opens both the hospital project and `../emsign`. The sibling [emsign README](../emsign/README.md) describes a separate teaching/demo leave-approval app using eMudhra emSigner, its own PHP pages, SQLite storage, and signing callbacks. It is not the hospital application’s active eSigner module. Its local `.env`, SQLite database, and credential-like configuration values were not inspected or copied into this report.

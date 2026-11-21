<?php

require_once __DIR__ . '/../includes/env.php';
loadEnvironment(dirname(__DIR__));

$host = env('DB_HOST', 'localhost');
$port = env('DB_PORT', '3306');
$dbname = env('DB_NAME', 'abha_hospital_db');
$username = env('DB_USERNAME', 'root');
$password = env('DB_PASSWORD', '');

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    ensureMockEkycSchema($pdo);
    ensurePrescriptionDocumentsSchema($pdo);

} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

function ensurePrescriptionDocumentsSchema(PDO $pdo): void
{
    $tableExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'prescription_documents'"
    )->fetchColumn();

    if (!$tableExists) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS prescription_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                prescription_id INT NOT NULL,
                consultation_id INT NOT NULL,
                doctor_id INT NOT NULL,
                patient_id INT NOT NULL,
                unsigned_pdf_path VARCHAR(500) NULL,
                signed_pdf_path VARCHAR(500) NULL,
                signing_status ENUM('generated','awaiting_signature','signed','failed') DEFAULT 'generated',
                emsigner_transaction_id VARCHAR(100) NULL,
                emsigner_reference_number VARCHAR(100) NULL,
                emsigner_workflow_id VARCHAR(100) NULL,
                emsigner_document_id VARCHAR(100) NULL,
                emsigner_status VARCHAR(50) NULL,
                emsigner_response_code VARCHAR(50) NULL,
                signed_at TIMESTAMP NULL,
                doctor_signed_pdf_path VARCHAR(500) NULL,
                approver_token_hash CHAR(64) NULL,
                approver_token_expires DATETIME NULL,
                approver_token_used_at DATETIME NULL,
                approver_workflow_id VARCHAR(100) NULL,
                approver_reference_number VARCHAR(100) NULL,
                approver_document_id VARCHAR(100) NULL,
                approver_signing_status VARCHAR(50) NULL,
                approver_signed_at TIMESTAMP NULL,
                patient_mail_claimed TINYINT(1) NOT NULL DEFAULT 0,
                patient_mail_sent_at TIMESTAMP NULL,
                final_pdf_path VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CONSTRAINT fk_presc_doc_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE,
                CONSTRAINT fk_presc_doc_consultation FOREIGN KEY (consultation_id) REFERENCES consultations(id) ON DELETE CASCADE,
                CONSTRAINT fk_presc_doc_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id),
                CONSTRAINT fk_presc_doc_patient FOREIGN KEY (patient_id) REFERENCES patients(id),

                UNIQUE KEY uk_prescription_doc (prescription_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        return;
    }
}

function ensureMockEkycSchema(PDO $pdo): void
{
    $checkColumns = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mock_ekyc_verifications'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $existing = array_flip($checkColumns);

    $columnDefinitions = [
        'provider' => "VARCHAR(50) NOT NULL DEFAULT 'mock'",
        'document_file_name' => 'VARCHAR(255) NULL',
        'parsed_details' => 'TEXT NULL',
    ];

    foreach ($columnDefinitions as $column => $definition) {
        if (!isset($existing[$column])) {
            $pdo->exec("ALTER TABLE mock_ekyc_verifications ADD COLUMN {$column} {$definition}");
        }
    }
}

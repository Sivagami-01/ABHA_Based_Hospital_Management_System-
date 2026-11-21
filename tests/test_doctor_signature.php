<?php

require_once __DIR__ . '/../includes/doctor_signature.php';
require_once __DIR__ . '/../includes/generate_prescription_pdf.php';

$temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'abha-signature-' . bin2hex(random_bytes(8));
$firstPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR42mPgEpEDAABoAD1q9XBbAAAAAElFTkSuQmCC', true);
$secondPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mPgEpFrAAABJQC9MRgrDgAAAABJRU5ErkJggg==', true);
$jpegBytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAFA3PEY8MlBGQUZaVVBfeMiCeG5uePWvuZHI////////////////////////////////////////////////////2wBDAVVaWnhpeOuCguv/////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAT/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AIgAf//Z', true);
$generatedFiles = [];
$productionSignaturePath = null;

if ($firstPng === false || $secondPng === false || $jpegBytes === false) {
    throw new RuntimeException('Could not decode PNG test fixtures.');
}

$assert = static function (string $message, bool $condition): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
};
$assertThrows = static function (string $message, callable $callback): void {
    try {
        $callback();
    } catch (RuntimeException $exception) {
        echo "PASS: {$message}\n";
        return;
    }
    throw new RuntimeException($message);
};

try {
    $assert(
        'PNG data URLs decode to validated PNG bytes',
        decodeDoctorSignatureDataUrl('data:image/png;base64,' . base64_encode($firstPng)) === $firstPng
    );
    $assertThrows('Non-PNG signature data is rejected', static function (): void {
        decodeDoctorSignatureDataUrl('data:image/svg+xml;base64,PHN2Zy8+');
    });
    $assertThrows('Malformed PNG signature data is rejected', static function (): void {
        decodeDoctorSignatureDataUrl('data:image/png;base64,' . base64_encode('not a PNG'));
    });
    $assertThrows('Oversized signature images are rejected', static function (): void {
        validateDoctorSignatureImage(str_repeat('x', 262145));
    });
    $assertThrows('Invalid image uploads are rejected', static function (): void {
        validateDoctorSignatureImage('not an image');
    });
    $assertThrows('Valid image content outside PNG/JPG is rejected', static function (): void {
        validateDoctorSignatureImage(base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=', true));
    });
    $validatedJpeg = validateDoctorSignatureImage($jpegBytes);
    $assert(
        'A valid uploaded JPEG is recognized from its actual content',
        $validatedJpeg['mime'] === 'image/jpeg' && $validatedJpeg['extension'] === 'jpg'
    );

    writeDoctorSignaturePng(doctorSignatureFilePath(7, $temporaryDirectory), $firstPng);
    $assert('Reusable signature is stored under its doctor-specific filename', loadDoctorSignaturePng(7, $temporaryDirectory) === $firstPng);

    $snapshotPath = prescriptionSignatureFilePath(41, 7, $temporaryDirectory);
    writeDoctorSignaturePng($snapshotPath, $firstPng);
    writeDoctorSignatureImage(doctorSignatureFilePath(7, $temporaryDirectory, 'jpg'), $jpegBytes);
    removeAlternateDoctorSignature(7, 'jpg', $temporaryDirectory);
    $assert('Updating the reusable signature does not change the prescription snapshot', file_get_contents($snapshotPath) === $firstPng);
    $assert('A replaced signature is reused from the authenticated doctor’s storage', loadDoctorSignatureImage(7, $temporaryDirectory)['bytes'] === $jpegBytes);
    $assert('A different doctor cannot resolve this doctor’s reusable signature', loadDoctorSignatureImage(8, $temporaryDirectory) === null);

    $pdf = new PrescriptionPDF();
    $pdf->AddPage();
    $pdf->Image($snapshotPath, 20, 20, 42, 0, 'PNG');
    $pdfPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'signature-test.pdf';
    $pdf->Output('F', $pdfPath);
    $generatedFiles[] = $pdfPath;
    $pdfContents = file_get_contents($pdfPath);
    $assert('FPDF embeds the signature as a PDF image', is_string($pdfContents) && strpos($pdfContents, '/Subtype /Image') !== false);

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('
        CREATE TABLE prescriptions (id INTEGER PRIMARY KEY, consultation_id INTEGER, doctor_id INTEGER, patient_id INTEGER, prescription_date TEXT);
        CREATE TABLE consultations (id INTEGER PRIMARY KEY, symptoms TEXT, diagnosis TEXT, notes TEXT, consultation_date TEXT);
        CREATE TABLE patients (id INTEGER PRIMARY KEY, patient_id TEXT, full_name TEXT, date_of_birth TEXT, gender TEXT, mobile TEXT, blood_group TEXT, allergies TEXT, existing_conditions TEXT, health_identity_id INTEGER);
        CREATE TABLE demo_health_identities (id INTEGER PRIMARY KEY, demo_health_id TEXT);
        CREATE TABLE doctors (id INTEGER PRIMARY KEY, user_id INTEGER, doctor_name TEXT, specialization TEXT, license_number TEXT, department_id INTEGER);
        CREATE TABLE departments (id INTEGER PRIMARY KEY, department_name TEXT);
        CREATE TABLE users (id INTEGER PRIMARY KEY, hospital_id INTEGER);
        CREATE TABLE hospitals (id INTEGER PRIMARY KEY, hospital_name TEXT, address TEXT, phone TEXT, email TEXT);
        CREATE TABLE prescription_items (id INTEGER PRIMARY KEY AUTOINCREMENT, prescription_id INTEGER, medicine_name TEXT, dosage TEXT, frequency TEXT, duration TEXT, instructions TEXT);
        CREATE TABLE prescription_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, prescription_id INTEGER UNIQUE, consultation_id INTEGER, doctor_id INTEGER, patient_id INTEGER, unsigned_pdf_path TEXT);
    ');

    $fixtureDoctorId = random_int(100000000, 900000000);
    $signedPrescriptionId = random_int(100000000, 900000000);
    $unsignedPrescriptionId = $signedPrescriptionId + 1;
    $pdo->exec("
        INSERT INTO prescriptions VALUES
            ({$signedPrescriptionId}, 1, {$fixtureDoctorId}, 1, '2026-11-14 08:00:00'),
            ({$unsignedPrescriptionId}, 2, {$fixtureDoctorId}, 1, '2026-11-14 08:05:00');
        INSERT INTO consultations VALUES
            (1, 'Cough', 'Seasonal illness', 'Rest', '2026-11-14 07:55:00'),
            (2, 'Headache', 'Tension headache', 'Hydrate', '2026-11-14 08:00:00');
        INSERT INTO patients VALUES (1, 'TEST-001', 'Demo Patient', '1990-01-01', 'female', '0000000000', 'Unknown', 'None', 'None', 1);
        INSERT INTO demo_health_identities VALUES (1, 'TEST-HEALTH-ID');
        INSERT INTO doctors VALUES ({$fixtureDoctorId}, 1, 'Demo Doctor', 'General Medicine', 'TEST-LICENSE', 1);
        INSERT INTO departments VALUES (1, 'Medicine');
        INSERT INTO users VALUES (1, 1);
        INSERT INTO hospitals VALUES (1, 'Demo Hospital', 'Test Address', '0000000000', 'demo@example.invalid');
        INSERT INTO prescription_items VALUES
            (1, {$signedPrescriptionId}, 'Medicine One', '10 mg', 'Daily', '5 days', 'After food'),
            (2, {$signedPrescriptionId}, 'Medicine Two', '5 mg', 'Twice daily', '3 days', 'With water'),
            (3, {$unsignedPrescriptionId}, 'Older Medicine', '1 tablet', 'Daily', '2 days', 'As directed');
    ");

    $productionSignaturePath = prescriptionSignatureFilePath($signedPrescriptionId, $fixtureDoctorId, null, 'jpg');
    writeDoctorSignatureImage($productionSignaturePath, $jpegBytes);
    $signedPdfResult = generatePrescriptionPdf($pdo, $signedPrescriptionId);
    $generatedFiles[] = $signedPdfResult['file_path'];
    $signedPdfContents = file_get_contents($signedPdfResult['file_path']);
    $assert(
        'Prescription PDF generation embeds the saved signature for a multi-medicine prescription',
        is_string($signedPdfContents)
            && strpos($signedPdfContents, '/Subtype /Image') !== false
            && strpos($signedPdfContents, '/DCTDecode') !== false
            && str_ends_with($signedPdfContents, '%%EOF' . "\n")
    );

    $unsignedPdfResult = generatePrescriptionPdf($pdo, $unsignedPrescriptionId);
    $generatedFiles[] = $unsignedPdfResult['file_path'];
    $unsignedPdfContents = file_get_contents($unsignedPdfResult['file_path']);
    $assert(
        'An older prescription without a signature still generates a normal PDF',
        is_string($unsignedPdfContents)
            && strpos($unsignedPdfContents, '/Subtype /Image') === false
            && str_ends_with($unsignedPdfContents, '%%EOF' . "\n")
    );
} finally {
    foreach ($generatedFiles as $generatedFile) {
        if (is_file($generatedFile)) {
            unlink($generatedFile);
        }
    }
    if ($productionSignaturePath !== null && is_file($productionSignaturePath)) {
        unlink($productionSignaturePath);
    }
    if (is_dir($temporaryDirectory)) {
        foreach (array_diff(scandir($temporaryDirectory) ?: [], ['.', '..']) as $fileName) {
            $filePath = $temporaryDirectory . DIRECTORY_SEPARATOR . $fileName;
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }
        rmdir($temporaryDirectory);
    }
}

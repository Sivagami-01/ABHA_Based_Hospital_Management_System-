<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
requireRole('doctor');

$doctorQuery = $pdo->prepare('SELECT id FROM doctors WHERE user_id = :user_id LIMIT 1');
$doctorQuery->execute([':user_id' => $_SESSION['user_id']]);
$doctorId = $doctorQuery->fetchColumn();

if (!$doctorId) {
    http_response_code(403);
    exit('Doctor profile not found.');
}

$prescriptionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$mode = filter_input(INPUT_GET, 'mode', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: 'view';

if (!$prescriptionId) {
    http_response_code(400);
    exit('Missing prescription ID.');
}

$query = $pdo->prepare("
    SELECT 
        pr.id AS prescription_id,
        doc.unsigned_pdf_path,
        doc.signed_pdf_path
    FROM prescriptions pr
    LEFT JOIN prescription_documents doc ON doc.prescription_id = pr.id
    WHERE pr.id = :id AND pr.doctor_id = :doctor_id
    LIMIT 1
");
$query->execute([':id' => $prescriptionId, ':doctor_id' => $doctorId]);
$doc = $query->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);
    exit('Prescription not found or unauthorized.');
}

$absolutePath = null;
foreach ([$doc['unsigned_pdf_path'] ?? '', $doc['signed_pdf_path'] ?? ''] as $relativePath) {
    $absolutePath = prescriptionPdfStoragePath((string)$relativePath);
    if ($absolutePath !== null) {
        break;
    }
}
if ($absolutePath === null) {
    http_response_code(404);
    exit('Prescription PDF has not been generated or is missing from storage.');
}

logAudit(
    'PRESCRIPTION_PDF_DOWNLOADED',
    'prescription',
    $prescriptionId,
    'Prescription PDF accessed (' . $mode . ') by doctor.'
);

$filename = basename($absolutePath);
$disposition = ($mode === 'download') ? 'attachment' : 'inline';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('Content-Length: ' . filesize($absolutePath));
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($absolutePath);
exit;


<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
requireRole('patient');

$prescriptionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$mode = filter_input(INPUT_GET, 'mode', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: 'view';

if (!$prescriptionId) {
    http_response_code(400);
    exit('Missing prescription ID.');
}

// Find prescription and check ownership for this patient
$query = $pdo->prepare("
    SELECT 
        pr.id AS prescription_id,
        doc.final_pdf_path,
        doc.unsigned_pdf_path
    FROM prescriptions pr
    JOIN patients p ON p.id = pr.patient_id
    LEFT JOIN prescription_documents doc ON doc.prescription_id = pr.id
    WHERE pr.id = :id AND p.user_id = :user_id
    LIMIT 1
");
$query->execute([':id' => $prescriptionId, ':user_id' => $_SESSION['user_id']]);
$doc = $query->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);
    exit('Prescription not found or unauthorized.');
}

$absolutePath = null;
foreach ([$doc['final_pdf_path'] ?? '', $doc['unsigned_pdf_path'] ?? ''] as $relativePath) {
    $absolutePath = prescriptionPdfStoragePath((string)$relativePath);
    if ($absolutePath !== null) {
        break;
    }
}
if ($absolutePath === null) {
    http_response_code(404);
    exit('Prescription PDF is not available on server storage.');
}

logAudit(
    'PRESCRIPTION_PDF_DOWNLOADED',
    'prescription',
    $prescriptionId,
    'Prescription PDF accessed (' . $mode . ') by patient.'
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

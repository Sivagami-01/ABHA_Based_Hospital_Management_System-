<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/generate_prescription_pdf.php';

requireRole('doctor');

$doctorQuery = $pdo->prepare('SELECT id FROM doctors WHERE user_id = :user_id LIMIT 1');
$doctorQuery->execute([':user_id' => $_SESSION['user_id']]);
$doctor = $doctorQuery->fetch(PDO::FETCH_ASSOC);

if (!$doctor) {
    http_response_code(403);
    exit('Doctor profile not found.');
}

$prescriptionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'prescription_id', FILTER_VALIDATE_INT);

if (!$prescriptionId) {
    flash('danger', 'Invalid prescription ID.');
    redirect(appUrl('doctor/appointments.php'));
}

// Fetch Prescription Details
$prescQuery = $pdo->prepare("
    SELECT 
        pr.id AS prescription_id,
        pr.prescription_date,
        pr.consultation_id,
        pr.patient_id,
        pr.doctor_id,
        p.patient_id AS patient_code,
        p.full_name AS patient_name,
        p.gender,
        p.date_of_birth,
        p.mobile,
        i.demo_health_id,
        c.symptoms,
        c.diagnosis,
        c.notes,
        c.consultation_date,
        doc.unsigned_pdf_path,
        doc.signed_pdf_path
    FROM prescriptions pr
    JOIN patients p ON p.id = pr.patient_id
    LEFT JOIN demo_health_identities i ON i.id = p.health_identity_id
    JOIN consultations c ON c.id = pr.consultation_id
    LEFT JOIN prescription_documents doc ON doc.prescription_id = pr.id
    WHERE pr.id = :id AND pr.doctor_id = :doctor_id
    LIMIT 1
");
$prescQuery->execute([':id' => $prescriptionId, ':doctor_id' => $doctor['id']]);
$prescription = $prescQuery->fetch(PDO::FETCH_ASSOC);

if (!$prescription) {
    http_response_code(404);
    exit('Prescription not found or unauthorized.');
}
$pdfAvailable = prescriptionPdfStoragePath((string)($prescription['unsigned_pdf_path'] ?? '')) !== null
    || prescriptionPdfStoragePath((string)($prescription['signed_pdf_path'] ?? '')) !== null;

// Fetch Medication Items
$itemsQuery = $pdo->prepare("SELECT * FROM prescription_items WHERE prescription_id = :id ORDER BY id ASC");
$itemsQuery->execute([':id' => $prescriptionId]);
$medicationItems = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'Form session expired. Please retry.');
        redirect(appUrl('doctor/view_prescription.php?id=' . $prescriptionId));
    }

    if ($action === 'generate_pdf') {
        try {
            generatePrescriptionPdf($pdo, $prescriptionId);
            logAudit('PRESCRIPTION_PDF_GENERATED', 'prescription', $prescriptionId, 'Prescription PDF generated.');
            flash('success', 'Prescription PDF generated successfully.');
            redirect(appUrl('doctor/view_prescription.php?id=' . $prescriptionId));
        } catch (Throwable $e) {
            flash('danger', 'Error generating PDF: ' . $e->getMessage());
            redirect(appUrl('doctor/view_prescription.php?id=' . $prescriptionId));
        }
    }
}

$pageTitle = 'Prescription Details';
require __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <p class="eyebrow mb-1">Clinical Documentation & Compliance</p>
        <h1 class="h2 mb-0">Prescription #RX-<?= date('Y', strtotime($prescription['prescription_date'])) ?>-<?= str_pad((string)$prescriptionId, 6, '0', STR_PAD_LEFT) ?></h1>
    </div>
    <a href="<?= e(appUrl('doctor/appointments.php')) ?>" class="btn btn-outline-secondary btn-sm">&larr; Back to Appointments</a>
</div>

<!-- Prescription PDF Actions -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <span class="text-muted me-2">Prescription PDF:</span>
            <span class="badge bg-light text-dark border"><?= $pdfAvailable ? 'PDF available' : 'Not generated' ?></span>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <?php if ($pdfAvailable): ?>
                <a href="<?= e(appUrl('doctor/serve_prescription_pdf.php?id=' . $prescriptionId . '&mode=view')) ?>" target="_blank" class="btn btn-outline-primary">
                    <i class="bi bi-file-earmark-pdf"></i> View PDF
                </a>
                <a href="<?= e(appUrl('doctor/serve_prescription_pdf.php?id=' . $prescriptionId . '&mode=download')) ?>" class="btn btn-outline-primary">
                    <i class="bi bi-download"></i> Download PDF
                </a>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="prescription_id" value="<?= (int)$prescriptionId ?>">
                    <input type="hidden" name="action" value="generate_pdf">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-arrow-clockwise"></i> Regenerate PDF
                    </button>
                </form>
            <?php else: ?>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="prescription_id" value="<?= (int)$prescriptionId ?>">
                    <input type="hidden" name="action" value="generate_pdf">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-file-earmark-plus"></i> Generate Prescription PDF
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Clinical Information Summary -->
<div class="row g-4 mb-4">
    <!-- Patient Card -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="card-title text-primary mb-0"><i class="bi bi-person me-2"></i>Patient Details</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width: 40%;">Full Name:</td><td class="fw-bold"><?= e($prescription['patient_name']) ?></td></tr>
                    <tr><td class="text-muted">Patient ID:</td><td><?= e($prescription['patient_code'] ?? 'N/A') ?></td></tr>
                    <tr><td class="text-muted">Health ID (ABHA):</td><td><?= e($prescription['demo_health_id'] ?? 'N/A') ?></td></tr>
                    <tr><td class="text-muted">Gender / DOB:</td><td><?= e(ucfirst($prescription['gender'] ?? '')) ?> / <?= e($prescription['date_of_birth'] ?? 'N/A') ?></td></tr>
                    <tr><td class="text-muted">Mobile:</td><td><?= e($prescription['mobile'] ?? 'N/A') ?></td></tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Consultation & Diagnosis Card -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="card-title text-primary mb-0"><i class="bi bi-clipboard2-pulse me-2"></i>Consultation Assessment</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width: 35%;">Consultation Date:</td><td><?= e(date('d-M-Y H:i', strtotime($prescription['consultation_date'] ?? $prescription['prescription_date']))) ?></td></tr>
                    <tr><td class="text-muted">Symptoms:</td><td><?= e($prescription['symptoms'] ?: 'None recorded') ?></td></tr>
                    <tr><td class="text-muted">Diagnosis:</td><td class="fw-bold text-danger"><?= e($prescription['diagnosis'] ?: 'N/A') ?></td></tr>
                    <tr><td class="text-muted">Clinical Notes:</td><td><?= e($prescription['notes'] ?: 'None recorded') ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Prescription Items Table -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-0 pt-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title text-primary mb-0"><i class="bi bi-capsule me-2"></i>Prescribed Medications</h5>
        <span class="badge bg-light text-dark"><?= count($medicationItems) ?> item(s)</span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 30%;">Medicine Name</th>
                        <th style="width: 15%;">Dosage</th>
                        <th style="width: 15%;">Frequency</th>
                        <th style="width: 15%;">Duration</th>
                        <th style="width: 20%;">Instructions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($medicationItems): ?>
                        <?php foreach ($medicationItems as $idx => $item): ?>
                            <tr>
                                <td><?= $idx + 1 ?></td>
                                <td class="fw-semibold text-dark"><?= e($item['medicine_name']) ?></td>
                                <td><?= e($item['dosage'] ?: '-') ?></td>
                                <td><?= e($item['frequency'] ?: '-') ?></td>
                                <td><?= e($item['duration'] ?: '-') ?></td>
                                <td><span class="text-muted"><?= e($item['instructions'] ?: 'As directed') ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-muted text-center py-3">No medications recorded for this prescription.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

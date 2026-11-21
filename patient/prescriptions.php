<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
requireRole('patient');

$query = $pdo->prepare('
    SELECT 
        pr.id AS prescription_id,
        pr.prescription_date,
        d.doctor_name,
        pi.medicine_name,
        pi.dosage,
        pi.frequency,
        pi.duration,
        pi.instructions,
        doc.final_pdf_path,
        doc.unsigned_pdf_path
    FROM prescriptions pr
    JOIN patients p ON p.id = pr.patient_id
    JOIN doctors d ON d.id = pr.doctor_id
    JOIN prescription_items pi ON pi.prescription_id = pr.id
    LEFT JOIN prescription_documents doc ON doc.prescription_id = pr.id
    WHERE p.user_id = :user_id
    ORDER BY pr.prescription_date DESC, pi.id ASC
');
$query->execute([':user_id' => $_SESSION['user_id']]);
$prescriptions = $query->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'My Prescriptions';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <p class="eyebrow">Patient space</p>
        <h1 class="h2">My Prescriptions</h1>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Doctor</th>
                        <th>Medicine</th>
                        <th>Dosage</th>
                        <th>Frequency</th>
                        <th>Duration</th>
                        <th>Instructions</th>
                        <th>Prescription PDF</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($prescriptions as $prescription): ?>
                        <tr>
                            <td><?= e(date('d-M-Y', strtotime($prescription['prescription_date']))) ?></td>
                            <td><?= e($prescription['doctor_name']) ?></td>
                            <td class="fw-semibold"><?= e($prescription['medicine_name']) ?></td>
                            <td><?= e($prescription['dosage'] ?: '-') ?></td>
                            <td><?= e($prescription['frequency'] ?: '-') ?></td>
                            <td><?= e($prescription['duration'] ?: '-') ?></td>
                            <td><?= e($prescription['instructions'] ?: 'As directed') ?></td>
                            <td>
                                <?php
                                $hasPrescriptionPdf = prescriptionPdfStoragePath((string)($prescription['final_pdf_path'] ?? '')) !== null
                                    || prescriptionPdfStoragePath((string)($prescription['unsigned_pdf_path'] ?? '')) !== null;
                                ?>
                                <?php if ($hasPrescriptionPdf): ?>
                                    <div class="d-flex flex-column gap-1">
                                        <a href="<?= e(appUrl('patient/serve_prescription_pdf.php?id=' . $prescription['prescription_id'] . '&mode=view')) ?>" target="_blank" class="btn btn-sm btn-outline-primary text-nowrap">
                                            <i class="bi bi-file-earmark-pdf"></i> View PDF
                                        </a>
                                        <a href="<?= e(appUrl('patient/serve_prescription_pdf.php?id=' . $prescription['prescription_id'] . '&mode=download')) ?>" class="btn btn-sm btn-outline-primary text-nowrap">
                                            <i class="bi bi-download"></i> Download
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border text-nowrap">
                                        <i class="bi bi-hourglass-split"></i> PDF not generated
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$prescriptions): ?>
                        <tr>
                            <td colspan="8" class="text-muted text-center py-4">No prescriptions found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
requireRole('patient');

$query = $pdo->prepare(
    'SELECT a.id, a.appointment_date, a.reason, a.status, d.doctor_name, dep.department_name,
            h.hospital_name, tc.id AS teleconsultation_id,
            CASE WHEN tc.id IS NULL THEN \'in_person\' ELSE \'online\' END AS consultation_type,
            ap.status AS payment_status, ap.amount AS consultation_fee
     FROM appointments a
     JOIN patients p ON p.id = a.patient_id
     JOIN hospitals h ON h.id = a.hospital_id
     LEFT JOIN doctors d ON d.id = a.doctor_id
     LEFT JOIN departments dep ON dep.id = d.department_id
     LEFT JOIN teleconsultations tc ON tc.appointment_id = a.id
     LEFT JOIN appointment_payments ap ON ap.appointment_id = a.id
     WHERE p.user_id = :user_id
     ORDER BY a.appointment_date DESC'
);
$query->execute([':user_id' => $_SESSION['user_id']]);
$appointments = $query->fetchAll(PDO::FETCH_ASSOC);
$pageTitle = 'My Appointments';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div><p class="eyebrow">Patient space</p><h1 class="h2">My Appointments</h1></div>
    <a class="btn btn-primary" href="<?= e(appUrl('patient/book_appointment.php')) ?>">Request appointment</a>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Hospital</th><th>Date</th><th>Doctor</th><th>Department</th><th>Type</th><th>Reason</th><th>Status</th><th>Fee</th><th>Payment</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($appointments as $appointment): ?>
                        <tr>
                            <td><?= e($appointment['hospital_name']) ?></td>
                            <td><?= e($appointment['appointment_date']) ?></td>
                            <td><?= e($appointment['doctor_name'] ?? 'Awaiting Front Desk assignment') ?></td>
                            <td><?= e($appointment['department_name'] ?? 'Pending routing') ?></td>
                            <td><?= e(ucfirst($appointment['consultation_type'])) ?></td>
                            <td><?= e($appointment['reason']) ?></td>
                            <td>
                                <?php if ($appointment['status'] === 'approved' && $appointment['payment_status'] === 'pending'): ?>
                                    Awaiting Payment
                                <?php else: ?>
                                    <?= e(ucfirst(str_replace('_', ' ', $appointment['status']))) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= $appointment['consultation_fee'] !== null ? e(number_format((float) $appointment['consultation_fee'], 2)) : '—' ?></td>
                            <td><?= e(ucfirst($appointment['payment_status'] ?? 'Not available')) ?></td>
                            <td>
                                <?php if (
                                    $appointment['status'] === 'approved'
                                    && in_array($appointment['payment_status'], ['pending', 'failed', 'cancelled'], true)
                                ): ?>
                                    <a class="btn btn-sm btn-success text-nowrap" href="<?= e(appUrl('patient/demo_payment.php?appointment_id=' . (int) $appointment['id'])) ?>">Pay Now</a>
                                <?php elseif ($appointment['payment_status'] === 'completed'): ?>
                                    <a class="btn btn-sm btn-outline-primary text-nowrap" href="<?= e(appUrl('patient/demo_payment.php?appointment_id=' . (int) $appointment['id'])) ?>">View Receipt</a>
                                    <?php if ($appointment['teleconsultation_id'] && $appointment['status'] === 'approved'): ?>
                                        <a class="btn btn-sm btn-primary text-nowrap mt-1" href="<?= e(appUrl('teleconsultation/room.php?appointment_id=' . (int) $appointment['id'])) ?>">Join video consultation</a>
                                    <?php endif; ?>
                                <?php elseif ($appointment['teleconsultation_id'] && $appointment['status'] === 'approved'): ?>
                                    <a class="btn btn-sm btn-primary text-nowrap" href="<?= e(appUrl('teleconsultation/room.php?appointment_id=' . (int) $appointment['id'])) ?>">Join video consultation</a>
                                <?php elseif (in_array($appointment['status'], ['requested', 'scheduled'], true)): ?>
                                    <span class="small text-muted">Awaiting Doctor Approval</span>
                                <?php elseif ($appointment['status'] === 'rejected'): ?>
                                    <span class="small text-muted">Appointment Rejected</span>
                                <?php elseif ($appointment['teleconsultation_id']): ?>
                                    <span class="small text-muted">Join when approved</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$appointments): ?><tr><td colspan="10" class="text-muted">No appointments found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

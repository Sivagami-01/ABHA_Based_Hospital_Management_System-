<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
requireRole('patient');

$patientQuery = $pdo->prepare(
    'SELECT p.patient_id, p.hospital_patient_id, p.full_name, p.date_of_birth, p.gender, p.mobile, p.email,
            p.address, p.city, p.state, p.pincode, p.emergency_contact_name, p.emergency_contact_relationship,
            p.emergency_contact_number, p.blood_group, d.demo_health_id, d.verification_status
     FROM patients p
     JOIN demo_health_identities d ON d.id = p.health_identity_id
     WHERE p.user_id = :user_id
     LIMIT 1'
);
$patientQuery->execute([':user_id' => $_SESSION['user_id']]);
$patient = $patientQuery->fetch(PDO::FETCH_ASSOC);
$onlineQuery = $pdo->prepare(
    'SELECT a.id, a.appointment_date, a.status, d.doctor_name, h.hospital_name,
            ap.status AS payment_status
     FROM appointments a
     JOIN patients p ON p.id = a.patient_id
     JOIN hospitals h ON h.id = a.hospital_id
     LEFT JOIN doctors d ON d.id = a.doctor_id
     JOIN teleconsultations tc ON tc.appointment_id = a.id
     LEFT JOIN appointment_payments ap ON ap.appointment_id = a.id
     WHERE p.user_id = :user_id
     ORDER BY a.appointment_date DESC
     LIMIT 5'
);
$onlineQuery->execute([':user_id' => $_SESSION['user_id']]);
$onlineAppointments = $onlineQuery->fetchAll(PDO::FETCH_ASSOC);
$paymentQuery = $pdo->prepare(
    'SELECT a.id, a.appointment_date, a.status, d.doctor_name, h.hospital_name,
            CASE WHEN tc.id IS NULL THEN \'in_person\' ELSE \'online\' END AS consultation_type,
            ap.status AS payment_status, ap.amount AS consultation_fee
     FROM appointments a
     JOIN patients p ON p.id = a.patient_id
     JOIN hospitals h ON h.id = a.hospital_id
     LEFT JOIN doctors d ON d.id = a.doctor_id
     LEFT JOIN teleconsultations tc ON tc.appointment_id = a.id
     LEFT JOIN appointment_payments ap ON ap.appointment_id = a.id
     WHERE p.user_id = :user_id
     ORDER BY a.appointment_date DESC
     LIMIT 5'
);
$paymentQuery->execute([':user_id' => $_SESSION['user_id']]);
$paymentAppointments = $paymentQuery->fetchAll(PDO::FETCH_ASSOC);
$displayPatientId = $patient['patient_id'] ?? $patient['hospital_patient_id'] ?? null;
$addressParts = array_filter([
    $patient['address'] ?? null,
    $patient['city'] ?? null,
    $patient['state'] ?? null,
    $patient['pincode'] ?? null,
]);
$pageTitle = 'Patient Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="hero-panel p-4 rounded-3 mb-4"><p class="text-uppercase text-secondary small fw-semibold mb-1">Patient space</p><h1 class="h2">Welcome, <?= e($patient['full_name'] ?? $_SESSION['username']) ?></h1><p class="mb-0">Your Health Identity connects your care across participating hospitals.</p><div class="d-flex flex-wrap gap-2 mt-3"><a class="btn btn-primary" href="<?= e(appUrl('patient/book_appointment.php')) ?>">Request appointment</a><a class="btn btn-outline-dark" href="<?= e(appUrl('patient/appointments.php')) ?>">My appointments</a><a class="btn btn-outline-dark" href="<?= e(appUrl('patient/medical_records.php')) ?>">Medical records</a><a class="btn btn-outline-dark" href="<?= e(appUrl('patient/prescriptions.php')) ?>">Prescriptions</a></div></div>
<section class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Online consultations</h2>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(appUrl('patient/appointments.php')) ?>">All appointments</a>
        </div>
        <?php if ($onlineAppointments): ?>
            <?php foreach ($onlineAppointments as $onlineAppointment): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-top py-2">
                    <div>
                        <strong><?= e($onlineAppointment['hospital_name']) ?></strong>
                        <div class="small text-muted"><?= e($onlineAppointment['appointment_date']) ?> · <?= e($onlineAppointment['doctor_name'] ?? 'Awaiting doctor assignment') ?> · <?= e($onlineAppointment['status']) ?></div>
                    </div>
                    <?php if ($onlineAppointment['status'] === 'approved' && $onlineAppointment['payment_status'] === 'completed'): ?>
                        <a class="btn btn-sm btn-primary" href="<?= e(appUrl('teleconsultation/room.php?appointment_id=' . (int) $onlineAppointment['id'])) ?>">Join video consultation</a>
                    <?php elseif ($onlineAppointment['status'] === 'approved' && $onlineAppointment['payment_status'] === 'pending'): ?>
                        <span class="badge text-bg-warning">Payment Pending</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-muted mb-0">No online consultations yet.</p>
        <?php endif; ?>
    </div>
</section>
<section class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Consultation payments</h2>
        <?php if ($paymentAppointments): ?>
            <?php foreach ($paymentAppointments as $paymentAppointment): ?>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border-top py-2">
                    <div>
                        <strong>Appointment #<?= (int) $paymentAppointment['id'] ?> · <?= e($paymentAppointment['hospital_name']) ?></strong>
                        <div class="small text-muted">
                            <?= e($paymentAppointment['doctor_name'] ?? 'Awaiting doctor assignment') ?>
                            · <?= e(ucfirst($paymentAppointment['consultation_type'])) ?>
                            · <?= e($paymentAppointment['appointment_date']) ?>
                            <?php if ($paymentAppointment['consultation_fee'] !== null): ?>
                                · Fee <?= e(number_format((float) $paymentAppointment['consultation_fee'], 2)) ?>
                            <?php endif; ?>
                            · Payment <?= e(ucfirst($paymentAppointment['payment_status'] ?? 'not set')) ?>
                        </div>
                    </div>
                    <?php if (
                        $paymentAppointment['status'] === 'approved'
                        && in_array($paymentAppointment['payment_status'], ['pending', 'failed', 'cancelled'], true)
                    ): ?>
                        <a class="btn btn-sm btn-success" href="<?= e(appUrl('patient/demo_payment.php?appointment_id=' . (int) $paymentAppointment['id'])) ?>">Pay Now</a>
                    <?php elseif ($paymentAppointment['payment_status'] === 'completed'): ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(appUrl('patient/demo_payment.php?appointment_id=' . (int) $paymentAppointment['id'])) ?>">View Receipt</a>
                    <?php elseif ($paymentAppointment['status'] === 'approved'): ?>
                        <span class="badge text-bg-warning">Payment setup pending</span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary"><?= e(ucfirst(str_replace('_', ' ', $paymentAppointment['status']))) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-muted mb-0">No appointments to display.</p>
        <?php endif; ?>
    </div>
</section>
<div class="row g-3 mb-4"><div class="col-md-4"><div class="card stat-card p-3 h-100"><h2 class="h5">Patient Unique ID</h2><p class="mb-0"><strong><?= e($displayPatientId ?? 'Created after registration') ?></strong></p></div></div><div class="col-md-4"><div class="card stat-card p-3 h-100"><h2 class="h5">Health Identity</h2><?php if ($patient): ?><p class="mb-1"><strong><?= e($patient['demo_health_id']) ?></strong></p><span class="badge text-bg-success">Verified</span><?php else: ?><p class="text-muted mb-0">Identity details are not linked yet.</p><?php endif; ?></div></div></div>
<?php if ($patient): ?>
<section class="card border-0 shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3">Patient Profile</h2>
        <div class="row g-3">
            <div class="col-md-6"><div class="text-muted small">Name</div><div><?= e($patient['full_name']) ?></div></div>
            <div class="col-md-3"><div class="text-muted small">Date of Birth</div><div><?= e($patient['date_of_birth']) ?></div></div>
            <div class="col-md-3"><div class="text-muted small">Gender</div><div><?= e(ucfirst($patient['gender'])) ?></div></div>
            <div class="col-md-4"><div class="text-muted small">Mobile Number</div><div><?= e($patient['mobile']) ?></div></div>
            <div class="col-md-4"><div class="text-muted small">Email</div><div><?= e($patient['email']) ?></div></div>
            <div class="col-md-4"><div class="text-muted small">Blood Group</div><div><?= e($patient['blood_group'] ?: 'Unknown') ?></div></div>
            <div class="col-md-6"><div class="text-muted small">Address</div><div><?= e(implode(', ', $addressParts)) ?></div></div>
            <div class="col-md-6"><div class="text-muted small">Emergency Contact</div><div><?= e(trim(($patient['emergency_contact_name'] ?? '') . ' - ' . ($patient['emergency_contact_relationship'] ?? '') . ' - ' . ($patient['emergency_contact_number'] ?? ''), ' -')) ?></div></div>
        </div>
    </div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>

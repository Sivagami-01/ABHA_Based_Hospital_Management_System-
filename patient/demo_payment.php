<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/consultation_payments.php';
requireRole('patient');

$patientQuery = $pdo->prepare('SELECT id FROM patients WHERE user_id = :user_id LIMIT 1');
$patientQuery->execute([':user_id' => $_SESSION['user_id']]);
$patientId = (int) $patientQuery->fetchColumn();
$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);

$loadPayment = static function (bool $lock = false) use ($pdo, $appointmentId, $patientId): ?array {
    $query = $pdo->prepare(
        'SELECT a.id, a.patient_id, a.doctor_id, a.hospital_id, a.appointment_date, a.status AS appointment_status,
                p.user_id AS patient_user_id, i.full_name AS patient_name,
                d.user_id AS doctor_user_id, d.doctor_name, dep.department_name,
                h.hospital_name, tc.id AS teleconsultation_id,
                ap.id AS payment_id, ap.patient_id AS payment_patient_id, ap.doctor_id AS payment_doctor_id,
                ap.consultation_type, ap.amount, ap.payment_mode, ap.status AS payment_status,
                ap.transaction_reference, ap.created_at, ap.paid_at
         FROM appointments a
         JOIN patients p ON p.id = a.patient_id
         JOIN demo_health_identities i ON i.id = p.health_identity_id
         JOIN doctors d ON d.id = a.doctor_id
         JOIN departments dep ON dep.id = d.department_id
         JOIN hospitals h ON h.id = a.hospital_id
         LEFT JOIN teleconsultations tc ON tc.appointment_id = a.id
         JOIN appointment_payments ap ON ap.appointment_id = a.id
         WHERE a.id = :appointment_id
           AND p.id = :patient_id
           AND p.user_id = :user_id
         LIMIT 1' . ($lock ? ' FOR UPDATE' : '')
    );
    $query->execute([
        ':appointment_id' => $appointmentId,
        ':patient_id' => $patientId,
        ':user_id' => $_SESSION['user_id'],
    ]);
    $payment = $query->fetch(PDO::FETCH_ASSOC);
    if (!$payment
        || (int) $payment['payment_patient_id'] !== (int) $payment['patient_id']
        || (int) $payment['payment_doctor_id'] !== (int) $payment['doctor_id']
    ) {
        return null;
    }
    return $payment;
};

$payment = null;
$pageNotice = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $pageNotice = 'The form expired. Reload the page and try again.';
    } elseif (!$patientId || !$appointmentId) {
        http_response_code(404);
        exit('Payment not found.');
    } else {
        try {
            $pdo->beginTransaction();
            $payment = $loadPayment(true);
            if (!$payment) {
                throw new RuntimeException('Payment not found for this patient.');
            }
            if ($payment['payment_status'] === 'completed') {
                $pdo->commit();
                redirect('demo_payment.php?appointment_id=' . (int) $appointmentId);
            }
            if ($payment['appointment_status'] !== 'approved'
                || !in_array($payment['payment_status'], ['pending', 'failed', 'cancelled'], true)
            ) {
                throw new RuntimeException('This appointment is not eligible for demo payment.');
            }

            $paidAt = date('Y-m-d H:i:s');
            $transactionReference = completeDemoAppointmentPayment(
                $pdo,
                (int) $payment['payment_id'],
                $patientId,
                (int) $payment['doctor_id'],
                (int) $payment['doctor_user_id'],
                (int) $payment['id'],
                $payment['consultation_type'],
                $payment['patient_name'],
                $paidAt
            );
            if ($transactionReference === null) {
                throw new RuntimeException('Payment state changed. Reload the page to see the current status.');
            }

            logAudit(
                'DEMO_PAYMENT_COMPLETED',
                'appointment_payment',
                (int) $payment['payment_id'],
                'Demo payment completed for appointment #' . (int) $payment['id']
                    . '; reference ' . $transactionReference . '.'
            );
            $pdo->commit();
            flash('success', 'Demo payment recorded. No real money was transferred.');
            redirect('demo_payment.php?appointment_id=' . (int) $appointmentId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                $pageNotice = $exception->getMessage();
            } else {
                error_log('Demo consultation payment failed: ' . $exception->getMessage());
                $pageNotice = 'The demo payment could not be recorded. Please retry.';
            }
        }
    }
}

if (!$patientId || !$appointmentId || !($payment = $loadPayment())) {
    http_response_code(404);
    exit('Payment not found.');
}

$canPay = $payment['appointment_status'] === 'approved'
    && in_array($payment['payment_status'], ['pending', 'failed', 'cancelled'], true);
$pageTitle = $payment['payment_status'] === 'completed' ? 'Demo Payment Receipt' : 'Confirm Demo Payment';
require __DIR__ . '/../includes/header.php';
?>
<div class="setup-shell">
    <div class="setup-card">
        <p class="eyebrow">Internal demo transaction</p>
        <h1 class="h3"><?= $payment['payment_status'] === 'completed' ? 'Demo Payment Receipt' : 'Confirm Demo Payment' ?></h1>
        <div class="alert alert-warning" role="alert"><strong>DEMO PAYMENT – NO REAL MONEY IS TRANSFERRED.</strong></div>
        <?php if ($pageNotice !== null): ?><div class="alert alert-danger" role="alert"><?= e($pageNotice) ?></div><?php endif; ?>
        <?php if ($payment['payment_status'] === 'completed'): ?>
            <div class="border rounded p-3 mb-3">
                <p><strong>Hospital:</strong> <?= e($payment['hospital_name']) ?></p>
                <p><strong>Patient:</strong> <?= e($payment['patient_name']) ?></p>
                <p><strong>Doctor:</strong> <?= e($payment['doctor_name']) ?></p>
                <p><strong>Department:</strong> <?= e($payment['department_name']) ?></p>
                <p><strong>Appointment ID:</strong> <?= (int) $payment['id'] ?></p>
                <p><strong>Consultation type:</strong> <?= e(ucfirst($payment['consultation_type'])) ?></p>
                <p><strong>Appointment date:</strong> <?= e($payment['appointment_date']) ?></p>
                <p><strong>Consultation fee:</strong> <?= e(number_format((float) $payment['amount'], 2)) ?></p>
                <p><strong>Payment mode:</strong> Demo Payment</p>
                <p><strong>Payment status:</strong> Completed</p>
                <p><strong>Transaction reference:</strong> <?= e($payment['transaction_reference']) ?></p>
                <p class="mb-0"><strong>Payment date:</strong> <?= e($payment['paid_at']) ?></p>
            </div>
            <button type="button" class="btn btn-outline-primary" onclick="window.print()">Print demo receipt</button>
        <?php else: ?>
            <div class="border rounded p-3 mb-3">
                <p><strong>Appointment ID:</strong> <?= (int) $payment['id'] ?></p>
                <p><strong>Doctor:</strong> <?= e($payment['doctor_name']) ?></p>
                <p><strong>Department:</strong> <?= e($payment['department_name']) ?></p>
                <p><strong>Consultation type:</strong> <?= e(ucfirst($payment['consultation_type'])) ?></p>
                <p><strong>Appointment date:</strong> <?= e($payment['appointment_date']) ?></p>
                <p><strong>Payment status:</strong> <?= e(ucfirst($payment['payment_status'])) ?></p>
                <p class="h4 mb-0"><strong>Demo fee:</strong> <?= e(number_format((float) $payment['amount'], 2)) ?></p>
            </div>
            <?php if ($canPay): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="appointment_id" value="<?= (int) $payment['id'] ?>">
                    <button class="btn btn-primary" type="submit">Confirm Demo Payment</button>
                </form>
            <?php else: ?>
                <div class="alert alert-secondary">Payment is unavailable until the appointment is approved. No payment has been made.</div>
            <?php endif; ?>
        <?php endif; ?>
        <a class="btn btn-link mt-2" href="<?= e(appUrl('patient/appointments.php')) ?>">Back to appointments</a>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

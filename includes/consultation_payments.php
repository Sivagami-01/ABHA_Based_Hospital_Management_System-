<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/notifications.php';

function consultationFee(PDO $pdo, int $hospitalId, string $consultationType): ?string
{
    $feeColumn = match ($consultationType) {
        'online' => 'online_fee',
        'in_person' => 'in_person_fee',
        default => throw new InvalidArgumentException('Unsupported consultation type.'),
    };
    $statement = $pdo->prepare(
        'SELECT ' . $feeColumn . ' FROM consultation_fee_settings WHERE hospital_id = :hospital_id LIMIT 1'
    );
    $statement->execute([':hospital_id' => $hospitalId]);
    $fee = $statement->fetchColumn();
    return $fee === false ? null : (string) $fee;
}

function createPendingAppointmentPayment(
    PDO $pdo,
    int $appointmentId,
    int $patientId,
    int $doctorId,
    int $hospitalId,
    string $consultationType
): int {
    $amount = consultationFee($pdo, $hospitalId, $consultationType);
    if ($amount === null) {
        throw new RuntimeException('An administrator must configure both consultation fees before this appointment can be approved.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO appointment_payments
            (appointment_id, patient_id, doctor_id, consultation_type, amount, status)
         VALUES (:appointment_id, :patient_id, :doctor_id, :consultation_type, :amount, \'pending\')'
    );
    $statement->execute([
        ':appointment_id' => $appointmentId,
        ':patient_id' => $patientId,
        ':doctor_id' => $doctorId,
        ':consultation_type' => $consultationType,
        ':amount' => $amount,
    ]);
    return (int) $pdo->lastInsertId();
}

function backfillApprovedAppointmentPayments(PDO $pdo, int $hospitalId): int
{
    $statement = $pdo->prepare(
        "INSERT INTO appointment_payments
            (appointment_id, patient_id, doctor_id, consultation_type, amount, status)
         SELECT a.id, a.patient_id, a.doctor_id,
                CASE WHEN tc.id IS NULL THEN 'in_person' ELSE 'online' END,
                CASE WHEN tc.id IS NULL THEN fees.in_person_fee ELSE fees.online_fee END,
                'pending'
         FROM appointments a
         JOIN consultation_fee_settings fees ON fees.hospital_id = a.hospital_id
         LEFT JOIN teleconsultations tc ON tc.appointment_id = a.id
         WHERE a.hospital_id = :hospital_id
           AND a.status = 'approved'
           AND a.doctor_id IS NOT NULL
           AND NOT EXISTS (
                SELECT 1 FROM appointment_payments existing
                WHERE existing.appointment_id = a.id
           )"
    );
    $statement->execute([':hospital_id' => $hospitalId]);
    return $statement->rowCount();
}

function appointmentPayment(PDO $pdo, int $appointmentId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, appointment_id, patient_id, doctor_id, consultation_type, amount,
                payment_mode, status, transaction_reference, created_at, paid_at
         FROM appointment_payments
         WHERE appointment_id = :appointment_id
         LIMIT 1'
    );
    $statement->execute([':appointment_id' => $appointmentId]);
    $payment = $statement->fetch(PDO::FETCH_ASSOC);
    return $payment ?: null;
}

function appointmentHasCompletedPayment(PDO $pdo, int $appointmentId): bool
{
    $statement = $pdo->prepare(
        'SELECT 1
         FROM appointment_payments ap
         JOIN appointments a ON a.id = ap.appointment_id
         WHERE a.id = :appointment_id
           AND ap.status = \'completed\'
           AND ap.patient_id = a.patient_id
           AND ap.doctor_id = a.doctor_id
         LIMIT 1'
    );
    $statement->execute([':appointment_id' => $appointmentId]);
    return (bool) $statement->fetchColumn();
}

function completeDemoAppointmentPayment(
    PDO $pdo,
    int $paymentId,
    int $patientId,
    int $doctorId,
    int $doctorUserId,
    int $appointmentId,
    string $consultationType,
    string $patientName,
    string $paidAt
): ?string {
    $transactionReference = generateDemoTransactionReference();
    $statement = $pdo->prepare(
        "UPDATE appointment_payments
         SET status = 'completed', transaction_reference = :reference, paid_at = :paid_at
         WHERE id = :payment_id
           AND patient_id = :patient_id
           AND doctor_id = :doctor_id
           AND status IN ('pending', 'failed', 'cancelled')"
    );
    $statement->execute([
        ':reference' => $transactionReference,
        ':paid_at' => $paidAt,
        ':payment_id' => $paymentId,
        ':patient_id' => $patientId,
        ':doctor_id' => $doctorId,
    ]);
    if ($statement->rowCount() !== 1) {
        return null;
    }

    $amountQuery = $pdo->prepare('SELECT amount FROM appointment_payments WHERE id = :payment_id LIMIT 1');
    $amountQuery->execute([':payment_id' => $paymentId]);
    $amount = $amountQuery->fetchColumn();
    if ($amount === false) {
        throw new RuntimeException('Completed payment could not be reloaded for notification.');
    }

    createNotification(
        $pdo,
        $doctorUserId,
        'Demo consultation payment completed',
        $patientName . ' - demo payment completed for appointment #' . $appointmentId
            . ' (' . $consultationType . '), amount ' . number_format((float) $amount, 2)
            . ', reference ' . $transactionReference . ', paid at ' . $paidAt . '.'
    );
    return $transactionReference;
}

function generateDemoTransactionReference(): string
{
    return 'DEMO-' . strtoupper(bin2hex(random_bytes(12)));
}

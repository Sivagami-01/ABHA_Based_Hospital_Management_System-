<?php

require_once __DIR__ . '/../includes/consultation_payments.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('
    CREATE TABLE consultation_fee_settings (
        hospital_id INTEGER PRIMARY KEY,
        online_fee NUMERIC NOT NULL,
        in_person_fee NUMERIC NOT NULL
    );
    CREATE TABLE appointment_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        appointment_id INTEGER NOT NULL UNIQUE,
        patient_id INTEGER NOT NULL,
        doctor_id INTEGER NOT NULL,
        consultation_type TEXT NOT NULL,
        amount NUMERIC NOT NULL,
        payment_mode TEXT NOT NULL DEFAULT "demo",
        status TEXT NOT NULL DEFAULT "pending",
        transaction_reference TEXT UNIQUE,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        paid_at TEXT
    );
    CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        message TEXT NOT NULL
    );
    CREATE TABLE appointments (
        id INTEGER PRIMARY KEY,
        patient_id INTEGER NOT NULL,
        doctor_id INTEGER,
        hospital_id INTEGER,
        status TEXT NOT NULL
    );
    CREATE TABLE teleconsultations (id INTEGER PRIMARY KEY, appointment_id INTEGER UNIQUE);
');
$pdo->exec("INSERT INTO consultation_fee_settings VALUES (1, 125.00, 300.00)");

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

$assert('Online fee is read from the configured hospital rate', consultationFee($pdo, 1, 'online') === '125');
$assert('In-person fee is read from the configured hospital rate', consultationFee($pdo, 1, 'in_person') === '300');
$assertThrows('An unconfigured hospital cannot approve an appointment', static function () use ($pdo): void {
    createPendingAppointmentPayment($pdo, 90, 8, 12, 9, 'online');
});

$onlinePaymentId = createPendingAppointmentPayment($pdo, 101, 8, 12, 1, 'online');
$onlinePayment = appointmentPayment($pdo, 101);
$assert(
    'Approval creates one pending online payment with the configured amount',
    $onlinePayment !== null
        && (int) $onlinePayment['id'] === $onlinePaymentId
        && $onlinePayment['status'] === 'pending'
        && $onlinePayment['consultation_type'] === 'online'
        && (float) $onlinePayment['amount'] === 125.0
);
$pdo->exec("
    INSERT INTO appointments VALUES
        (101, 8, 12, 1, 'approved'),
        (102, 9, 13, 1, 'scheduled'),
        (201, 10, 14, 1, 'approved'),
        (202, 11, 15, 1, 'approved'),
        (203, 12, 16, 1, 'scheduled');
    INSERT INTO teleconsultations VALUES (1, 202);
");
$backfilled = backfillApprovedAppointmentPayments($pdo, 1);
$assert(
    'Fee configuration backfills approved appointments with the matching consultation type',
    $backfilled === 2
        && appointmentPayment($pdo, 201)['consultation_type'] === 'in_person'
        && (float) appointmentPayment($pdo, 201)['amount'] === 300.0
        && appointmentPayment($pdo, 202)['consultation_type'] === 'online'
        && (float) appointmentPayment($pdo, 202)['amount'] === 125.0
        && appointmentPayment($pdo, 203) === null
);
$assert('Existing approved payments are not duplicated by another fee save', backfillApprovedAppointmentPayments($pdo, 1) === 0);
$pdo->exec('UPDATE consultation_fee_settings SET online_fee = 180 WHERE hospital_id = 1');
$assert('Changing the configured fee does not change an approved appointment snapshot', (float) appointmentPayment($pdo, 101)['amount'] === 125.0);

$pdo->beginTransaction();
$wrongPatientReference = completeDemoAppointmentPayment($pdo, $onlinePaymentId, 99, 12, 22, 101, 'online', 'Patient One', '2026-11-14 10:00:00');
$wrongDoctorReference = completeDemoAppointmentPayment($pdo, $onlinePaymentId, 8, 99, 22, 101, 'online', 'Patient One', '2026-11-14 10:00:00');
$pdo->rollBack();
$assert('A different patient or doctor cannot complete the payment', $wrongPatientReference === null && $wrongDoctorReference === null);
$assert('Unauthorized payment attempts do not create a doctor notification', (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 0);

$pdo->beginTransaction();
$reference = completeDemoAppointmentPayment(
    $pdo,
    $onlinePaymentId,
    8,
    12,
    22,
    101,
    'online',
    'Patient One',
    '2026-11-14 10:05:00'
);
$pdo->commit();
$assert('Successful demo payment generates a server-side transaction reference', is_string($reference) && preg_match('/^DEMO-[A-F0-9]{24}$/', $reference) === 1);
$assert('Payment completion updates its status and timestamp', appointmentHasCompletedPayment($pdo, 101) && appointmentPayment($pdo, 101)['paid_at'] === '2026-11-14 10:05:00');
$notification = $pdo->query('SELECT user_id, title, message FROM notifications')->fetch(PDO::FETCH_ASSOC);
$assert(
    'Payment completion creates one detailed notification for the assigned doctor',
    $notification !== false
        && (int) $notification['user_id'] === 22
        && strpos($notification['message'], 'Patient One') !== false
        && strpos($notification['message'], 'appointment #101') !== false
        && strpos($notification['message'], 'online') !== false
        && strpos($notification['message'], '125.00') !== false
        && strpos($notification['message'], (string) $reference) !== false
        && strpos($notification['message'], '2026-11-14 10:05:00') !== false
);

$pdo->beginTransaction();
$duplicateReference = completeDemoAppointmentPayment($pdo, $onlinePaymentId, 8, 12, 22, 101, 'online', 'Patient One', '2026-11-14 10:10:00');
$pdo->rollBack();
$assert('A repeated submission cannot alter a completed payment or its amount', $duplicateReference === null && (float) appointmentPayment($pdo, 101)['amount'] === 125.0);
$assert('A repeated submission does not duplicate the doctor notification', (int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 1);

$inPersonPaymentId = createPendingAppointmentPayment($pdo, 102, 9, 13, 1, 'in_person');
$assert(
    'In-person approval snapshots the in-person rate',
    (float) appointmentPayment($pdo, 102)['amount'] === 300.0
        && appointmentPayment($pdo, 102)['consultation_type'] === 'in_person'
);
$assertThrows('A second payment record for one appointment is rejected', static function () use ($pdo): void {
    createPendingAppointmentPayment($pdo, 101, 8, 12, 1, 'online');
});

$pdo->exec("UPDATE appointment_payments SET status = 'failed' WHERE id = " . $inPersonPaymentId);
$pdo->beginTransaction();
$retryReference = completeDemoAppointmentPayment(
    $pdo,
    $inPersonPaymentId,
    9,
    13,
    23,
    102,
    'in_person',
    'Patient Two',
    '2026-11-14 10:15:00'
);
$pdo->commit();
$assert('A failed demo payment can be retried once the appointment remains eligible', is_string($retryReference) && appointmentHasCompletedPayment($pdo, 102));
$duplicateReferenceRejected = false;
try {
    $duplicate = $pdo->prepare(
        'UPDATE appointment_payments SET transaction_reference = :reference WHERE appointment_id = :appointment_id'
    );
    $duplicate->execute([':reference' => $reference, ':appointment_id' => 102]);
} catch (PDOException $exception) {
    $duplicateReferenceRejected = true;
}
$assert('A transaction reference cannot be reused for another appointment', $duplicateReferenceRejected);

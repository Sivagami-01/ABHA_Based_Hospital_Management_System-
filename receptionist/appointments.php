<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/audit.php';
requireRole('receptionist');

$hospitalId = currentHospitalId();
$hospital = $hospitalId ? configuredHospital($pdo) : null;
if ($hospitalId) { $hospitalQuery = $pdo->prepare('SELECT id, hospital_name FROM hospitals WHERE id = :id LIMIT 1'); $hospitalQuery->execute([':id' => $hospitalId]); $hospital = $hospitalQuery->fetch(PDO::FETCH_ASSOC); }
$departments = $hospital ? $pdo->prepare('SELECT id, department_name FROM departments WHERE hospital_id = :hospital_id ORDER BY department_name') : null;
if ($departments) { $departments->execute([':hospital_id' => $hospitalId]); $departments = $departments->fetchAll(PDO::FETCH_ASSOC); } else { $departments = []; }
$doctors = $hospital ? $pdo->prepare('SELECT d.id, d.doctor_name, d.department_id, dep.department_name FROM doctors d JOIN users u ON u.id = d.user_id JOIN departments dep ON dep.id = d.department_id WHERE u.hospital_id = :hospital_id AND u.status = \'active\' ORDER BY dep.department_name, d.doctor_name') : null;
if ($doctors) { $doctors->execute([':hospital_id' => $hospitalId]); $doctors = $doctors->fetchAll(PDO::FETCH_ASSOC); } else { $doctors = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = filter_input(INPUT_POST, 'appointment_id', FILTER_VALIDATE_INT);
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) { flash('danger', 'The form expired.'); }
    elseif (!$hospitalId || !$appointmentId) { flash('danger', 'Hospital assignment or appointment is missing.'); }
    elseif (isset($_POST['forward_request'])) {
        $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT); $doctorId = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
        $check = $pdo->prepare("SELECT a.id, tc.id AS teleconsultation_id FROM appointments a JOIN doctors d ON d.id = :doctor_id JOIN users du ON du.id = d.user_id LEFT JOIN teleconsultations tc ON tc.appointment_id = a.id WHERE a.id = :appointment_id AND a.hospital_id = :hospital_id AND a.status = 'requested' AND d.department_id = :department_id AND du.hospital_id = :hospital_id AND (tc.id IS NULL OR tc.requested_department_id = :requested_department_id) LIMIT 1");
        $check->execute([
            ':appointment_id' => $appointmentId,
            ':hospital_id' => $hospitalId,
            ':doctor_id' => $doctorId,
            ':department_id' => $departmentId,
            ':requested_department_id' => $departmentId,
        ]);
        $assignment = $check->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) {
            flash('danger', 'Select a doctor from the assigned hospital and requested department.');
        } else {
            try {
                $pdo->beginTransaction();
                $update = $pdo->prepare("UPDATE appointments SET doctor_id = :doctor_id, forwarded_by = :forwarded_by, status = 'scheduled' WHERE id = :appointment_id AND hospital_id = :hospital_id AND status = 'requested'");
                $update->execute([
                    ':doctor_id' => $doctorId,
                    ':forwarded_by' => $_SESSION['user_id'],
                    ':appointment_id' => $appointmentId,
                    ':hospital_id' => $hospitalId,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Appointment assignment changed before it could be saved.');
                }
                if (!empty($assignment['teleconsultation_id'])) {
                    $teleconsultationUpdate = $pdo->prepare('UPDATE teleconsultations SET doctor_id = :doctor_id WHERE appointment_id = :appointment_id');
                    $teleconsultationUpdate->execute([':doctor_id' => $doctorId, ':appointment_id' => $appointmentId]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Appointment assignment failed: ' . $exception->getMessage());
                flash('danger', 'The appointment could not be assigned. Please try again.');
                redirect('appointments.php');
            }
            $doctorUser = $pdo->prepare('SELECT user_id FROM doctors WHERE id = :doctor_id LIMIT 1');
            $doctorUser->execute([':doctor_id' => $doctorId]);
            createNotification($pdo, (int) $doctorUser->fetchColumn(), 'Appointment request forwarded', 'A Front Desk Officer forwarded an appointment request for your review.');
            logAudit('APPOINTMENT_FORWARDED', 'appointment', $appointmentId, 'Appointment request forwarded to doctor.');
            flash('success', 'Appointment forwarded to doctor.');
            redirect('appointments.php');
        }
    } elseif (isset($_POST['cancel_appointment'])) {
        try {
            $pdo->beginTransaction();
            $check = $pdo->prepare(
                "SELECT a.id, p.user_id AS patient_user_id, ap.status AS payment_status
                 FROM appointments a
                 JOIN patients p ON p.id = a.patient_id
                 LEFT JOIN appointment_payments ap ON ap.appointment_id = a.id
                 WHERE a.id = :appointment_id
                   AND a.hospital_id = :hospital_id
                   AND a.status IN ('requested','scheduled','approved')
                 FOR UPDATE"
            );
            $check->execute([':appointment_id' => $appointmentId, ':hospital_id' => $hospitalId]);
            $cancelTarget = $check->fetch(PDO::FETCH_ASSOC);
            if (!$cancelTarget) {
                throw new RuntimeException('Appointment cannot be cancelled.');
            }
            if ($cancelTarget['payment_status'] === 'completed') {
                throw new RuntimeException('A completed demo payment cannot be cancelled or overwritten.');
            }

            $paymentCancel = $pdo->prepare(
                "UPDATE appointment_payments SET status = 'cancelled'
                 WHERE appointment_id = :appointment_id AND status IN ('pending','failed')"
            );
            $paymentCancel->execute([':appointment_id' => $appointmentId]);
            $appointmentCancel = $pdo->prepare(
                "UPDATE appointments SET status = 'cancelled'
                 WHERE id = :appointment_id AND hospital_id = :hospital_id
                   AND status IN ('requested','scheduled','approved')"
            );
            $appointmentCancel->execute([':appointment_id' => $appointmentId, ':hospital_id' => $hospitalId]);
            if ($appointmentCancel->rowCount() !== 1) {
                throw new RuntimeException('Appointment changed before cancellation could be saved.');
            }
            $pdo->commit();
            createNotification($pdo, (int) $cancelTarget['patient_user_id'], 'Appointment cancelled', 'Your hospital appointment request was cancelled.');
            logAudit('APPOINTMENT_CANCELLED', 'appointment', $appointmentId, 'Appointment cancelled by Front Desk Officer.');
            flash('success', 'Appointment cancelled.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof RuntimeException) {
                flash('warning', $exception->getMessage());
            } else {
                error_log('Appointment cancellation failed: ' . $exception->getMessage());
                flash('danger', 'Appointment could not be cancelled.');
            }
        }
        redirect('appointments.php');
    }
}
$pending = $hospital ? $pdo->prepare("SELECT a.id, a.appointment_date, a.reason, i.full_name, i.demo_health_id, tc.id AS teleconsultation_id, tc.requested_department_id FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN demo_health_identities i ON i.id = p.health_identity_id LEFT JOIN teleconsultations tc ON tc.appointment_id = a.id WHERE a.hospital_id = :hospital_id AND a.status = 'requested' ORDER BY a.appointment_date") : null; if ($pending) { $pending->execute([':hospital_id' => $hospitalId]); $pending = $pending->fetchAll(PDO::FETCH_ASSOC); } else { $pending = []; }
$current = $hospital ? $pdo->prepare("SELECT a.id, a.appointment_date, a.status, i.full_name, d.doctor_name, tc.id AS teleconsultation_id FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN demo_health_identities i ON i.id = p.health_identity_id LEFT JOIN doctors d ON d.id = a.doctor_id LEFT JOIN teleconsultations tc ON tc.appointment_id = a.id WHERE a.hospital_id = :hospital_id AND a.status IN ('scheduled','approved','completed') ORDER BY a.appointment_date DESC LIMIT 30") : null; if ($current) { $current->execute([':hospital_id' => $hospitalId]); $current = $current->fetchAll(PDO::FETCH_ASSOC); } else { $current = []; }
$pageTitle = 'Appointment Requests'; require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><p class="eyebrow">Front Desk Officer</p><h1 class="h2">Appointment Requests</h1></div><div class="d-flex gap-2"><a class="btn btn-outline-primary btn-sm" href="<?= e(appUrl('receptionist/patient_lookup.php')) ?>">Patient Lookup</a><span class="badge text-bg-success"><?= e($hospital['hospital_name'] ?? 'Hospital not assigned') ?></span></div></div>
<?php if (!$hospital): ?><div class="alert alert-warning">Ask an administrator to assign your account to a hospital.</div><?php endif; ?>
<section class="card border-0 shadow-sm mb-4"><div class="card-body"><h2 class="h5">New requests</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Patient</th><th>Preferred time</th><th>Reason</th><th>Route</th></tr></thead><tbody><?php foreach ($pending as $request): ?><tr><td><?= e($request['full_name']) ?><br><small class="text-muted"><?= e($request['demo_health_id']) ?></small><?php if ($request['teleconsultation_id']): ?><br><span class="badge text-bg-info">Online</span><?php endif; ?></td><td><?= e($request['appointment_date']) ?></td><td><?= e($request['reason']) ?></td><td><form method="post" class="d-flex gap-2 flex-wrap"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="appointment_id" value="<?= (int) $request['id'] ?>"><select class="form-select form-select-sm" id="department_<?= (int) $request['id'] ?>" name="department_id" onchange="filterDoctors(<?= (int) $request['id'] ?>)" required><option value="">Department</option><?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= (int) $request['requested_department_id'] === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['department_name']) ?></option><?php endforeach; ?></select><select class="form-select form-select-sm" id="doctor_<?= (int) $request['id'] ?>" name="doctor_id" required><option value="">Doctor</option><?php foreach ($doctors as $doctor): ?><option value="<?= (int) $doctor['id'] ?>" data-department="<?= (int) $doctor['department_id'] ?>"><?= e($doctor['doctor_name']) ?> - <?= e($doctor['department_name']) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-primary" name="forward_request" value="1">Forward</button></form></td></tr><?php endforeach; ?><?php if (!$pending): ?><tr><td colspan="4" class="text-muted">No new requests for this hospital.</td></tr><?php endif; ?></tbody></table></div></div></section>
<section class="card border-0 shadow-sm"><div class="card-body"><h2 class="h5">Hospital appointments</h2><div class="table-responsive"><table class="table"><thead><tr><th>Patient</th><th>Doctor</th><th>Date</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($current as $appointment): ?><tr><td><?= e($appointment['full_name']) ?><?php if ($appointment['teleconsultation_id']): ?><br><span class="badge text-bg-info">Online</span><?php endif; ?></td><td><?= e($appointment['doctor_name'] ?? 'Awaiting assignment') ?></td><td><?= e($appointment['appointment_date']) ?></td><td><?= e($appointment['status']) ?></td><td><?php if (in_array($appointment['status'], ['scheduled','approved'], true)): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="appointment_id" value="<?= (int) $appointment['id'] ?>"><button class="btn btn-sm btn-outline-danger" name="cancel_appointment" value="1">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$current): ?><tr><td colspan="5" class="text-muted">No appointments for this hospital.</td></tr><?php endif; ?></tbody></table></div></div></section><?php require __DIR__ . '/../includes/footer.php'; ?><script>
function filterDoctors(requestId) {
    const deptSelect = document.getElementById('department_' + requestId);
    const docSelect = document.getElementById('doctor_' + requestId);
    const selectedDept = deptSelect.value;
    
    Array.from(docSelect.options).forEach(option => {
        if (option.value === '') {
            option.style.display = 'block';
        } else {
            option.style.display = option.dataset.department === selectedDept ? 'block' : 'none';
        }
    });
}
</script>

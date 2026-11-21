<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/audit.php';
requireRole('patient');

$patientQuery = $pdo->prepare('SELECT id FROM patients WHERE user_id = :user_id LIMIT 1');
$patientQuery->execute([':user_id' => $_SESSION['user_id']]);
$patientId = (int) $patientQuery->fetchColumn();
$hospitals = $pdo->query("SELECT id, hospital_name FROM hospitals WHERE status = 'active' ORDER BY hospital_name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $pdo->query('SELECT id, hospital_id, department_name FROM departments ORDER BY department_name')->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hospitalId = filter_input(INPUT_POST, 'hospital_id', FILTER_VALIDATE_INT);
    $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    $date = $_POST['appointment_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    $symptoms = trim($_POST['symptoms'] ?? '');
    $consultationType = $_POST['consultation_type'] ?? 'in_person';
    $appointmentTime = $date !== '' ? strtotime($date) : false;

    if (
        !verifyCsrfToken($_POST['csrf_token'] ?? null)
        || !$patientId
        || !$hospitalId
        || $appointmentTime === false
        || $appointmentTime <= time()
        || $reason === ''
        || !in_array($consultationType, ['in_person', 'online'], true)
        || ($consultationType === 'online' && !$departmentId)
    ) {
        flash('danger', 'Select a hospital, future preferred time, consultation type, and reason for the visit.');
    } else {
        $hospitalQuery = $pdo->prepare("SELECT id, hospital_name FROM hospitals WHERE id = :id AND status = 'active' LIMIT 1");
        $hospitalQuery->execute([':id' => $hospitalId]);
        $hospital = $hospitalQuery->fetch(PDO::FETCH_ASSOC);
        $department = null;
        if ($consultationType === 'online') {
            $departmentQuery = $pdo->prepare('SELECT id FROM departments WHERE id = :id AND hospital_id = :hospital_id LIMIT 1');
            $departmentQuery->execute([':id' => $departmentId, ':hospital_id' => $hospitalId]);
            $department = $departmentQuery->fetchColumn();
        }

        if (!$hospital || ($consultationType === 'online' && !$department)) {
            flash('danger', 'Select a valid hospital and department for the requested consultation.');
        } else {
            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare(
                    'INSERT INTO appointments
                        (patient_id, hospital_id, doctor_id, requested_by, appointment_date, reason, doctor_response, status)
                     VALUES
                        (:patient_id, :hospital_id, NULL, :requested_by, :appointment_date, :reason, NULL, \'requested\')'
                );
                $insert->execute([
                    ':patient_id' => $patientId,
                    ':hospital_id' => $hospitalId,
                    ':requested_by' => $_SESSION['user_id'],
                    ':appointment_date' => date('Y-m-d H:i:s', $appointmentTime),
                    ':reason' => $reason . ($symptoms !== '' ? "\nSymptoms: " . $symptoms : ''),
                ]);
                $appointmentId = (int) $pdo->lastInsertId();

                if ($consultationType === 'online') {
                    $teleconsultation = $pdo->prepare(
                        'INSERT INTO teleconsultations
                            (appointment_id, patient_id, requested_department_id, doctor_id, room_name)
                         VALUES (:appointment_id, :patient_id, :department_id, NULL, :room_name)'
                    );
                    $teleconsultation->execute([
                        ':appointment_id' => $appointmentId,
                        ':patient_id' => $patientId,
                        ':department_id' => $departmentId,
                        ':room_name' => 'abha-' . bin2hex(random_bytes(24)),
                    ]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Appointment request failed: ' . $exception->getMessage());
                flash('danger', 'The appointment request could not be saved. Please try again.');
                redirect('book_appointment.php');
            }

            $officers = $pdo->prepare("SELECT id FROM users WHERE hospital_id = :hospital_id AND role_id = (SELECT id FROM roles WHERE role_name = 'receptionist' LIMIT 1) AND status = 'active'");
            $officers->execute([':hospital_id' => $hospitalId]);
            foreach ($officers as $officer) {
                createNotification($pdo, (int) $officer['id'], 'Appointment request received', 'A patient submitted an appointment request for ' . $hospital['hospital_name'] . '.');
            }
            logAudit(
                'APPOINTMENT_REQUESTED',
                'appointment',
                $appointmentId,
                $consultationType === 'online'
                    ? 'Patient submitted an online consultation appointment request.'
                    : 'Patient submitted a hospital appointment request.'
            );
            flash('success', 'Appointment request submitted to ' . $hospital['hospital_name'] . '.');
            redirect('appointments.php');
        }
    }
}

$pageTitle = 'Book Appointment';
require __DIR__ . '/../includes/header.php';
?>
<div class="setup-shell">
    <div class="setup-card">
        <p class="eyebrow">Patient space</p>
        <h1 class="h3">Request an Appointment</h1>
        <p class="text-muted">Choose a hospital and preferred time. The Front Desk Officer will route your request to the appropriate department and doctor.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <label class="form-label" for="hospital_id">Hospital</label>
            <select class="form-select mb-3" id="hospital_id" name="hospital_id" required>
                <option value="">Select hospital</option>
                <?php foreach ($hospitals as $hospital): ?>
                    <option value="<?= (int) $hospital['id'] ?>"><?= e($hospital['hospital_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="form-label" for="consultation_type">Consultation type</label>
            <select class="form-select mb-3" id="consultation_type" name="consultation_type">
                <option value="in_person">In-person consultation</option>
                <option value="online">Online consultation (video)</option>
            </select>
            <div id="online_department_field" hidden>
                <label class="form-label" for="department_id">Requested department</label>
                <select class="form-select mb-3" id="department_id" name="department_id">
                    <option value="">Select department</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) $department['id'] ?>" data-hospital="<?= (int) $department['hospital_id'] ?>"><?= e($department['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <label class="form-label" for="appointment_date">Preferred date and time</label>
            <input class="form-control mb-3" id="appointment_date" type="datetime-local" name="appointment_date" required>
            <label class="form-label" for="reason">Reason for visit</label>
            <input class="form-control mb-3" id="reason" name="reason" required>
            <label class="form-label" for="symptoms">Symptoms <span class="text-muted">(optional)</span></label>
            <textarea class="form-control mb-3" id="symptoms" name="symptoms" rows="3"></textarea>
            <button class="btn btn-primary">Submit appointment request</button>
        </form>
    </div>
</div>
<script>
    const consultationType = document.getElementById('consultation_type');
    const hospitalSelect = document.getElementById('hospital_id');
    const departmentField = document.getElementById('online_department_field');
    const departmentSelect = document.getElementById('department_id');
    function updateOnlineDepartmentOptions() {
        const online = consultationType.value === 'online';
        departmentField.hidden = !online;
        departmentSelect.required = online;
        Array.from(departmentSelect.options).forEach(function (option) {
            if (option.value) {
                option.hidden = option.dataset.hospital !== hospitalSelect.value;
            }
        });
        if (departmentSelect.selectedOptions.length && departmentSelect.selectedOptions[0].hidden) {
            departmentSelect.value = '';
        }
    }
    consultationType.addEventListener('change', updateOnlineDepartmentOptions);
    hospitalSelect.addEventListener('change', updateOnlineDepartmentOptions);
    updateOnlineDepartmentOptions();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>

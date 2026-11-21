<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
requireRole(['patient', 'doctor']);

$user = currentUser();
$appointmentId = filter_input(INPUT_GET, 'appointment_id', FILTER_VALIDATE_INT);
if (!$user || ($user['status'] ?? '') !== 'active' || !$appointmentId) {
    http_response_code(403);
    exit('A valid signed-in account and appointment are required.');
}

$query = $pdo->prepare(
    'SELECT a.id, a.patient_id, a.doctor_id, a.appointment_date, a.status, tc.room_name,
            tc.patient_id AS teleconsultation_patient_id,
            tc.doctor_id AS teleconsultation_doctor_id,
            ap.status AS payment_status,
            p.user_id AS patient_user_id, p.full_name AS patient_name,
            d.user_id AS doctor_user_id, d.doctor_name
     FROM appointments a
     JOIN teleconsultations tc ON tc.appointment_id = a.id
     JOIN patients p ON p.id = a.patient_id
     JOIN doctors d ON d.id = a.doctor_id
     LEFT JOIN appointment_payments ap
        ON ap.appointment_id = a.id
       AND ap.patient_id = a.patient_id
       AND ap.doctor_id = a.doctor_id
     WHERE a.id = :appointment_id
     LIMIT 1'
);
$query->execute([':appointment_id' => $appointmentId]);
$appointment = $query->fetch(PDO::FETCH_ASSOC);

$role = currentRole();
$isPatient = $role === 'patient'
    && $appointment
    && (int) $appointment['patient_user_id'] === (int) $user['id']
    && (int) $appointment['patient_id'] === (int) $appointment['teleconsultation_patient_id'];
$isDoctor = $role === 'doctor'
    && $appointment
    && (int) $appointment['doctor_user_id'] === (int) $user['id']
    && (int) $appointment['doctor_id'] === (int) $appointment['teleconsultation_doctor_id'];

if (!$appointment || (!$isPatient && !$isDoctor)) {
    http_response_code(403);
    exit('You are not authorized to join this consultation.');
}

if (
    $appointment['status'] !== 'approved'
    || $appointment['room_name'] === ''
    || $appointment['payment_status'] !== 'completed'
) {
    http_response_code(403);
    $pageTitle = 'Consultation unavailable';
    require __DIR__ . '/../includes/header.php';
    ?>
    <div class="alert alert-warning">
        This online consultation requires an approved appointment and a completed demo payment.
        Current appointment status: <?= e($appointment['status']) ?>;
        payment status: <?= e($appointment['payment_status'] ?? 'not set') ?>.
    </div>
    <?php
    require __DIR__ . '/../includes/footer.php';
    exit;
}

logAudit('TELECONSULTATION_JOINED', 'appointment', (int) $appointment['id'], 'Authorized participant opened the online consultation room.');
$displayName = $isDoctor
    ? ($appointment['doctor_name'] ?: ($user['full_name'] ?: $user['username']))
    : ($appointment['patient_name'] ?: ($user['full_name'] ?: $user['username']));
$pageTitle = 'Video Consultation';
require __DIR__ . '/../includes/header.php';
?>
<section class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <p class="eyebrow mb-1">Approved online appointment</p>
                <h1 class="h3 mb-1">Video Consultation</h1>
                <p class="text-muted mb-0"><?= e($appointment['appointment_date']) ?> · <?= e($isDoctor ? $appointment['patient_name'] : $appointment['doctor_name']) ?></p>
            </div>
            <button type="button" class="btn btn-outline-danger" id="leave-meeting" disabled>Leave meeting</button>
        </div>
        <p id="meeting-status" class="alert alert-info" role="status">Connecting to the video service…</p>
        <div id="jitsi-container" style="min-height: min(68vh, 680px); background: #111827; border-radius: .5rem; overflow: hidden;"></div>
        <p id="meeting-error" class="alert alert-danger mt-3 d-none" role="alert"></p>
        <p class="small text-muted mt-3 mb-0">This demonstration uses the public Jitsi service. The room name is random, but public room names are not authentication or a guarantee of privacy.</p>
    </div>
</section>
<script>
    (function () {
        const status = document.getElementById('meeting-status');
        const error = document.getElementById('meeting-error');
        const leaveButton = document.getElementById('leave-meeting');
        const roomName = <?= json_encode($appointment['room_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const displayName = <?= json_encode($displayName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let meeting = null;
        let joinTimeout = null;
        const loadTimeout = window.setTimeout(function () {
            if (!meeting) {
                error.textContent = 'The video service did not load. Check your internet connection and reload this page.';
                error.classList.remove('d-none');
                status.classList.add('d-none');
            }
        }, 15000);

        function startMeeting() {
            try {
                meeting = new JitsiMeetExternalAPI('meet.jit.si', {
                    roomName: roomName,
                    parentNode: document.getElementById('jitsi-container'),
                    width: '100%',
                    height: 'min(68vh, 680px)',
                    userInfo: { displayName: displayName },
                    configOverwrite: { prejoinPageEnabled: false },
                    interfaceConfigOverwrite: { MOBILE_APP_PROMO: false }
                });
                leaveButton.disabled = false;
                joinTimeout = window.setTimeout(function () {
                    error.textContent = 'The video room did not connect. Check your internet connection and try again.';
                    error.classList.remove('d-none');
                    status.classList.add('d-none');
                }, 30000);
                meeting.addListener('videoConferenceJoined', function () {
                    window.clearTimeout(joinTimeout);
                    status.textContent = 'Connected to the consultation.';
                    status.className = 'alert alert-success';
                });
                meeting.addListener('participantJoined', function () {
                    status.textContent = 'A participant joined the consultation.';
                    status.className = 'alert alert-success';
                });
                meeting.addListener('videoConferenceLeft', function () {
                    status.textContent = 'You left the consultation.';
                    status.className = 'alert alert-secondary';
                    leaveButton.disabled = true;
                });
                meeting.addListener('readyToClose', function () {
                    status.textContent = 'The consultation has ended.';
                    status.className = 'alert alert-secondary';
                    leaveButton.disabled = true;
                });
                meeting.addListener('errorOccurred', function () {
                    error.textContent = 'The video service reported a connection error. Please reload and try again.';
                    error.classList.remove('d-none');
                    status.classList.add('d-none');
                });
                meeting.addListener('cameraError', function () {
                    error.textContent = 'Camera access failed. Check browser permissions or continue with camera off.';
                    error.classList.remove('d-none');
                });
                meeting.addListener('micError', function () {
                    error.textContent = 'Microphone access failed. Check browser permissions or continue muted.';
                    error.classList.remove('d-none');
                });
                window.clearTimeout(loadTimeout);
            } catch (exception) {
                error.textContent = 'The video room could not be opened. Please reload and try again.';
                error.classList.remove('d-none');
                status.classList.add('d-none');
                window.clearTimeout(loadTimeout);
                window.clearTimeout(joinTimeout);
            }
        }

        const apiScript = document.createElement('script');
        apiScript.src = 'https://meet.jit.si/external_api.js';
        apiScript.async = true;
        apiScript.onload = startMeeting;
        apiScript.onerror = function () {
            error.textContent = 'Could not reach Jitsi Meet. Check your internet connection and reload this page.';
            error.classList.remove('d-none');
            status.classList.add('d-none');
            window.clearTimeout(loadTimeout);
        };
        document.head.appendChild(apiScript);
        leaveButton.addEventListener('click', function () {
            if (meeting) {
                meeting.executeCommand('hangup');
            }
        });
    }());
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>

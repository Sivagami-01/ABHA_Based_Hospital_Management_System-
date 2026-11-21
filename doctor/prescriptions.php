<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/doctor_signature.php';
require_once __DIR__ . '/../includes/generate_prescription_pdf.php';
requireRole('doctor');

$doctorQuery = $pdo->prepare('SELECT id FROM doctors WHERE user_id = :user_id LIMIT 1');
$doctorQuery->execute([':user_id' => $_SESSION['user_id']]);
$doctorId = (int) $doctorQuery->fetchColumn();
if (!$doctorId) {
    http_response_code(403);
    exit('Doctor profile not found.');
}

$consultationId = filter_input(INPUT_GET, 'consultation_id', FILTER_VALIDATE_INT)
    ?: filter_input(INPUT_POST, 'consultation_id', FILTER_VALIDATE_INT);
$consultationQuery = $pdo->prepare(
    'SELECT c.id, c.patient_id, p.user_id AS patient_user_id, i.full_name,
            a.status AS appointment_status, ap.status AS payment_status,
            ap.patient_id AS payment_patient_id, ap.doctor_id AS payment_doctor_id
     FROM consultations c
     JOIN appointments a ON a.id = c.appointment_id
     JOIN patients p ON p.id = c.patient_id
     JOIN demo_health_identities i ON i.id = p.health_identity_id
     LEFT JOIN appointment_payments ap ON ap.appointment_id = a.id
     WHERE c.id = :id AND c.doctor_id = :doctor_id
     LIMIT 1'
);
$consultationQuery->execute([':id' => $consultationId, ':doctor_id' => $doctorId]);
$consultation = $consultationQuery->fetch(PDO::FETCH_ASSOC);

if (!$consultation) {
    http_response_code(404);
    exit('Consultation not found or not created by this doctor.');
}
if (
    ($consultation['payment_status'] !== null && $consultation['payment_status'] !== 'completed')
    || ($consultation['payment_status'] === 'completed'
        && ((int) $consultation['payment_patient_id'] !== (int) $consultation['patient_id']
            || (int) $consultation['payment_doctor_id'] !== $doctorId))
    || ($consultation['payment_status'] === null && $consultation['appointment_status'] !== 'completed')
) {
    http_response_code(403);
    exit('A completed demo payment is required before creating a prescription.');
}

$savedSignatureDataUrl = doctorSignatureDataUrl($doctorId);
$signatureNotice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        flash('danger', 'The form expired. Please try again.');
    } elseif (($_POST['action'] ?? '') === 'upload_signature') {
        $upload = $_FILES['signature_file'] ?? null;
        try {
            if (
                !is_array($upload)
                || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($upload['tmp_name'] ?? null)
                || !is_uploaded_file($upload['tmp_name'])
                || (int) ($upload['size'] ?? 0) < 1
                || (int) $upload['size'] > 262144
            ) {
                throw new RuntimeException('Choose a PNG or JPG image smaller than 256 KB.');
            }

            $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
            if (!in_array($extension, ['png', 'jpg', 'jpeg'], true)) {
                throw new RuntimeException('Upload a PNG or JPG/JPEG image.');
            }

            $imageBytes = file_get_contents($upload['tmp_name']);
            if ($imageBytes === false || strlen($imageBytes) !== (int) $upload['size']) {
                throw new RuntimeException('The uploaded signature image could not be read.');
            }
            $image = validateDoctorSignatureImage($imageBytes);
            $extensionMime = $extension === 'png' ? 'image/png' : 'image/jpeg';
            if ($image['mime'] !== $extensionMime) {
                throw new RuntimeException('The file extension does not match the image content.');
            }

            writeDoctorSignatureImage(
                doctorSignatureFilePath($doctorId, null, $image['extension']),
                $imageBytes
            );
            removeAlternateDoctorSignature($doctorId, $image['extension']);
            logAudit('DOCTOR_SIGNATURE_UPDATED', 'doctor', $doctorId, 'Doctor saved a reusable prescription signature.');
            flash('success', 'Signature uploaded and saved for your prescriptions.');
        } catch (RuntimeException $exception) {
            flash('danger', $exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Doctor signature upload failed: ' . $exception->getMessage());
            flash('danger', 'The signature could not be saved. Please try again.');
        }
        redirect('prescriptions.php?consultation_id=' . (int) $consultation['id']);
    } elseif (($_POST['action'] ?? '') === 'sign_prescription') {
        $medicineNames = $_POST['medicine_name'] ?? [];
        $items = [];
        if (is_array($medicineNames)) {
            foreach ($medicineNames as $index => $medicine) {
                $medicine = trim((string) $medicine);
                if ($medicine !== '') {
                    $items[] = [
                        $medicine,
                        trim((string) ($_POST['dosage'][$index] ?? '')),
                        trim((string) ($_POST['frequency'][$index] ?? '')),
                        trim((string) ($_POST['duration'][$index] ?? '')),
                        trim((string) ($_POST['instructions'][$index] ?? '')),
                    ];
                }
            }
        }

        if (!$items) {
            $signatureNotice = 'Add at least one medicine before signing the prescription.';
        } else {
            $signatureChanged = ($_POST['signature_changed'] ?? '') === '1';
            try {
                $signature = $signatureChanged
                    ? validateDoctorSignatureImage(decodeDoctorSignatureDataUrl((string) ($_POST['doctor_signature'] ?? '')))
                    : loadDoctorSignatureImage($doctorId);

                if ($signature === null) {
                    $signatureNotice = 'Draw or upload and save your signature before signing this prescription.';
                } else {
                    $snapshotPath = null;
                    $prescriptionCreated = false;
                    try {
                        $pdo->beginTransaction();
                        $insert = $pdo->prepare(
                            'INSERT INTO prescriptions (consultation_id, patient_id, doctor_id)
                             VALUES (:consultation_id, :patient_id, :doctor_id)'
                        );
                        $insert->execute([
                            ':consultation_id' => $consultation['id'],
                            ':patient_id' => $consultation['patient_id'],
                            ':doctor_id' => $doctorId,
                        ]);
                        $prescriptionId = (int) $pdo->lastInsertId();

                        $itemInsert = $pdo->prepare(
                            'INSERT INTO prescription_items
                                (prescription_id, medicine_name, dosage, frequency, duration, instructions)
                             VALUES (:prescription_id, :medicine, :dosage, :frequency, :duration, :instructions)'
                        );
                        foreach ($items as $item) {
                            $itemInsert->execute([
                                ':prescription_id' => $prescriptionId,
                                ':medicine' => $item[0],
                                ':dosage' => $item[1] !== '' ? $item[1] : null,
                                ':frequency' => $item[2] !== '' ? $item[2] : null,
                                ':duration' => $item[3] !== '' ? $item[3] : null,
                                ':instructions' => $item[4] !== '' ? $item[4] : null,
                            ]);
                        }

                        $snapshotPath = prescriptionSignatureFilePath(
                            $prescriptionId,
                            $doctorId,
                            null,
                            $signature['extension']
                        );
                        writeDoctorSignatureImage($snapshotPath, $signature['bytes']);
                        $pdo->commit();
                        $prescriptionCreated = true;
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        if ($snapshotPath !== null && is_file($snapshotPath)) {
                            unlink($snapshotPath);
                        }
                        error_log('Signed prescription creation failed: ' . $exception->getMessage());
                        $signatureNotice = 'The prescription could not be saved. Please try again.';
                    }

                    if ($prescriptionCreated) {
                        $signatureSaveFailed = false;
                        if ($signatureChanged && isset($_POST['save_signature'])) {
                            try {
                                writeDoctorSignatureImage(
                                    doctorSignatureFilePath($doctorId, null, $signature['extension']),
                                    $signature['bytes']
                                );
                                removeAlternateDoctorSignature($doctorId, $signature['extension']);
                            } catch (Throwable $exception) {
                                error_log('Could not update reusable doctor signature: ' . $exception->getMessage());
                                $signatureSaveFailed = true;
                            }
                        }

                        if (!empty($consultation['patient_user_id'])) {
                            createNotification($pdo, (int) $consultation['patient_user_id'], 'Prescription created', 'A new prescription is available in your patient portal.');
                        }
                        logAudit('PRESCRIPTION_CREATED', 'prescription', $prescriptionId, 'Prescription created and signed by doctor.');

                        $pdfGenerated = false;
                        try {
                            generatePrescriptionPdf($pdo, $prescriptionId);
                            logAudit('PRESCRIPTION_PDF_GENERATED', 'prescription', $prescriptionId, 'Signed prescription PDF generated.');
                            $pdfGenerated = true;
                        } catch (Throwable $exception) {
                            error_log('Signed prescription PDF generation failed: ' . $exception->getMessage());
                        }

                        if ($pdfGenerated && !$signatureSaveFailed) {
                            flash('success', 'Prescription signed and PDF generated.');
                        } elseif ($pdfGenerated) {
                            flash('warning', 'Prescription PDF was generated, but your reusable signature could not be updated.');
                        } else {
                            flash('warning', 'Prescription was signed and saved, but PDF generation failed. You can retry from the prescription details page.');
                        }
                        redirect('view_prescription.php?id=' . $prescriptionId);
                    }
                }
            } catch (RuntimeException $exception) {
                $signatureNotice = $exception->getMessage();
            }
        }
    }
}

$pageTitle = 'Create Prescription';
require __DIR__ . '/../includes/header.php';
?>
<div class="setup-shell">
    <div class="setup-card">
        <p class="eyebrow">Clinical documentation</p>
        <h1 class="h3">Create Prescription</h1>
        <p class="text-muted">Patient: <strong><?= e($consultation['full_name']) ?></strong></p>
        <?php if ($signatureNotice !== null): ?>
            <div class="alert alert-warning" role="alert"><?= e($signatureNotice) ?></div>
        <?php endif; ?>
        <?php if ($savedSignatureDataUrl === null): ?>
            <div class="alert alert-info">Draw a signature below or upload and save one before signing a prescription.</div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" id="signature-upload-form" class="border rounded p-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="consultation_id" value="<?= (int) $consultation['id'] ?>">
            <input type="hidden" name="action" value="upload_signature">
            <label class="form-label fw-semibold" for="signature-file">Upload or replace saved signature</label>
            <input class="form-control" type="file" id="signature-file" name="signature_file" accept="image/png,image/jpeg,.png,.jpg,.jpeg" required>
            <img id="upload-preview" class="img-fluid border rounded mt-3 d-none" alt="Selected signature preview" style="max-height:120px;background:#fff">
            <p id="upload-error" class="small text-danger mt-2 d-none" role="alert"></p>
            <button type="submit" class="btn btn-outline-primary btn-sm mt-3">Save uploaded signature</button>
        </form>

        <form method="post" id="prescription-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="consultation_id" value="<?= (int) $consultation['id'] ?>">
            <input type="hidden" name="action" value="sign_prescription">
            <input type="hidden" name="signature_changed" id="signature-changed" value="0">
            <div id="medicine-list">
                <div class="medicine-entry border-top pt-3 mt-3">
                    <label class="form-label">Medicine 1</label>
                    <input class="form-control mb-2" name="medicine_name[]" aria-label="Medicine name">
                    <input class="form-control mb-2" name="dosage[]" placeholder="Dosage">
                    <input class="form-control mb-2" name="frequency[]" placeholder="Frequency">
                    <input class="form-control mb-2" name="duration[]" placeholder="Duration">
                    <input class="form-control mb-2" name="instructions[]" placeholder="Instructions">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-medicine">Remove</button>
                </div>
            </div>
            <button type="button" id="add-medicine" class="btn btn-outline-primary mt-3">+ Add Medicine</button>

            <section class="border-top mt-4 pt-3" aria-labelledby="signature-heading">
                <h2 class="h5" id="signature-heading">Draw or preview signature</h2>
                <p class="small text-muted">Your saved signature is preloaded. Draw here to replace it for this prescription and optionally save the replacement for future use.</p>
                <div class="border rounded bg-white p-2">
                    <canvas id="signature-pad" width="720" height="220" style="display:block;width:100%;height:auto;touch-action:none"></canvas>
                </div>
                <input type="hidden" name="doctor_signature" id="doctor-signature" value="<?= e($savedSignatureDataUrl ?? '') ?>">
                <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="clear-signature">Clear signature</button>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="save_signature" id="save-signature" checked>
                        <label class="form-check-label" for="save-signature">Save drawn replacement for future prescriptions</label>
                    </div>
                </div>
            </section>
            <button class="btn btn-primary mt-3" type="submit">Sign Prescription</button>
        </form>
    </div>
</div>
<script>
    let medicineIndex = 1;
    const medicineList = document.getElementById('medicine-list');
    const signatureCanvas = document.getElementById('signature-pad');
    const signatureContext = signatureCanvas.getContext('2d');
    const signatureInput = document.getElementById('doctor-signature');
    const signatureChangedInput = document.getElementById('signature-changed');
    let hasSignatureInk = Boolean(signatureInput.value);
    let signatureImageReady = !signatureInput.value;
    let drawing = false;

    function paintSignatureBackground() {
        signatureContext.fillStyle = '#ffffff';
        signatureContext.fillRect(0, 0, signatureCanvas.width, signatureCanvas.height);
    }
    paintSignatureBackground();
    if (signatureInput.value) {
        const savedSignature = new Image();
        savedSignature.onload = function () {
            signatureContext.drawImage(savedSignature, 0, 0, signatureCanvas.width, signatureCanvas.height);
            signatureImageReady = true;
        };
        savedSignature.onerror = function () { signatureImageReady = true; };
        savedSignature.src = signatureInput.value;
    }

    function canvasPoint(event) {
        const bounds = signatureCanvas.getBoundingClientRect();
        return {
            x: (event.clientX - bounds.left) * signatureCanvas.width / bounds.width,
            y: (event.clientY - bounds.top) * signatureCanvas.height / bounds.height
        };
    }
    signatureCanvas.addEventListener('pointerdown', function (event) {
        if (!signatureImageReady) { return; }
        drawing = true;
        signatureCanvas.setPointerCapture(event.pointerId);
        const point = canvasPoint(event);
        signatureContext.beginPath();
        signatureContext.moveTo(point.x, point.y);
    });
    signatureCanvas.addEventListener('pointermove', function (event) {
        if (!drawing) { return; }
        const point = canvasPoint(event);
        signatureContext.strokeStyle = '#111827';
        signatureContext.lineWidth = 4;
        signatureContext.lineCap = 'round';
        signatureContext.lineJoin = 'round';
        signatureContext.lineTo(point.x, point.y);
        signatureContext.stroke();
        hasSignatureInk = true;
        signatureChangedInput.value = '1';
        signatureInput.value = signatureCanvas.toDataURL('image/png');
    });
    function stopDrawing() { drawing = false; }
    signatureCanvas.addEventListener('pointerup', stopDrawing);
    signatureCanvas.addEventListener('pointercancel', stopDrawing);
    document.getElementById('clear-signature').addEventListener('click', function () {
        paintSignatureBackground();
        signatureInput.value = '';
        signatureChangedInput.value = '1';
        hasSignatureInk = false;
    });
    document.getElementById('prescription-form').addEventListener('submit', function (event) {
        if (!signatureImageReady) {
            event.preventDefault();
            alert('The saved signature preview is still loading. Please wait and try again.');
            return;
        }
        if (signatureChangedInput.value === '1') {
            signatureInput.value = hasSignatureInk ? signatureCanvas.toDataURL('image/png') : '';
        }
    });

    const signatureFile = document.getElementById('signature-file');
    const uploadPreview = document.getElementById('upload-preview');
    const uploadError = document.getElementById('upload-error');
    let previewUrl = null;
    signatureFile.addEventListener('change', function () {
        if (previewUrl) { URL.revokeObjectURL(previewUrl); }
        previewUrl = null;
        uploadPreview.classList.add('d-none');
        uploadError.classList.add('d-none');
        const file = this.files && this.files[0];
        if (!file) { return; }
        if (!['image/png', 'image/jpeg'].includes(file.type) || file.size > 262144) {
            uploadError.textContent = 'Choose a PNG or JPG image smaller than 256 KB.';
            uploadError.classList.remove('d-none');
            this.value = '';
            return;
        }
        previewUrl = URL.createObjectURL(file);
        uploadPreview.src = previewUrl;
        uploadPreview.classList.remove('d-none');
    });
    window.addEventListener('beforeunload', function () {
        if (previewUrl) { URL.revokeObjectURL(previewUrl); }
    });

    document.getElementById('add-medicine').addEventListener('click', function () {
        const wrapper = document.createElement('div');
        wrapper.className = 'medicine-entry border-top pt-3 mt-3';
        wrapper.innerHTML = `
            <label class="form-label">Medicine ${medicineIndex + 1}</label>
            <input class="form-control mb-2" name="medicine_name[]" aria-label="Medicine name">
            <input class="form-control mb-2" name="dosage[]" placeholder="Dosage">
            <input class="form-control mb-2" name="frequency[]" placeholder="Frequency">
            <input class="form-control mb-2" name="duration[]" placeholder="Duration">
            <input class="form-control mb-2" name="instructions[]" placeholder="Instructions">
            <button type="button" class="btn btn-outline-danger btn-sm remove-medicine">Remove</button>
        `;
        medicineList.appendChild(wrapper);
        medicineIndex += 1;
    });
    document.addEventListener('click', function (event) {
        if (event.target && event.target.classList.contains('remove-medicine')) {
            const entry = event.target.closest('.medicine-entry');
            if (entry && medicineList.children.length > 1) {
                entry.remove();
            }
        }
    });
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>

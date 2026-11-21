<?php

function doctorSignatureStorageDirectory(?string $storageDirectory = null): string
{
    return $storageDirectory ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'private_signatures');
}

function validateDoctorSignatureImage(string $imageBytes): array
{
    if ($imageBytes === '' || strlen($imageBytes) > 262144) {
        throw new RuntimeException('Signature image must be smaller than 256 KB.');
    }

    $image = @getimagesizefromstring($imageBytes);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($imageBytes);
    if (
        $image === false
        || !in_array($mime, ['image/png', 'image/jpeg'], true)
        || ($image['mime'] ?? '') !== $mime
        || ($image[0] ?? 0) < 1
        || ($image[1] ?? 0) < 1
        || ($image[0] ?? 0) > 1600
        || ($image[1] ?? 0) > 600
    ) {
        throw new RuntimeException('Signature must be a valid PNG or JPG image.');
    }

    if ($mime === 'image/png') {
        $pngColorType = strlen($imageBytes) > 25 ? ord($imageBytes[25]) : -1;
        if (($image['bits'] ?? 0) !== 8 || !in_array($pngColorType, [2, 6], true)) {
            throw new RuntimeException('Signature PNG must use 8-bit RGB or RGBA color.');
        }
    }
    if ($mime === 'image/jpeg' && !in_array($image['channels'] ?? 0, [1, 3, 4], true)) {
        throw new RuntimeException('Signature JPG color format is not supported.');
    }

    return [
        'bytes' => $imageBytes,
        'mime' => $mime,
        'extension' => $mime === 'image/png' ? 'png' : 'jpg',
    ];
}

function validateDoctorSignaturePng(string $pngBytes): void
{
    if (validateDoctorSignatureImage($pngBytes)['mime'] !== 'image/png') {
        throw new RuntimeException('Signature must be a PNG image.');
    }
}

function decodeDoctorSignatureDataUrl(string $dataUrl): string
{
    if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/D', $dataUrl, $matches)) {
        throw new RuntimeException('Drawn signature data must be a PNG image.');
    }

    $pngBytes = base64_decode($matches[1], true);
    if ($pngBytes === false) {
        throw new RuntimeException('Signature image data is invalid.');
    }

    validateDoctorSignaturePng($pngBytes);
    return $pngBytes;
}

function writeDoctorSignatureImage(string $filePath, string $imageBytes): array
{
    $image = validateDoctorSignatureImage($imageBytes);
    if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== $image['extension']) {
        throw new RuntimeException('Signature image extension does not match its content.');
    }

    $directory = dirname($filePath);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create private signature storage.');
    }

    $accessFile = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($accessFile) && file_put_contents($accessFile, "Require all denied\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not protect signature storage from direct web access.');
    }

    $temporaryPath = tempnam($directory, '.signature-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Could not prepare signature storage.');
    }

    try {
        if (file_put_contents($temporaryPath, $imageBytes, LOCK_EX) !== strlen($imageBytes)) {
            throw new RuntimeException('Could not save signature image.');
        }
        @chmod($temporaryPath, 0600);
        if (!rename($temporaryPath, $filePath)) {
            throw new RuntimeException('Could not finalize signature image.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }

    return $image;
}

function writeDoctorSignaturePng(string $filePath, string $pngBytes): void
{
    validateDoctorSignaturePng($pngBytes);
    writeDoctorSignatureImage($filePath, $pngBytes);
}

function doctorSignatureFilePath(int $doctorId, ?string $storageDirectory = null, string $extension = 'png'): string
{
    if (!in_array($extension, ['png', 'jpg'], true)) {
        throw new InvalidArgumentException('Unsupported signature file extension.');
    }
    return doctorSignatureStorageDirectory($storageDirectory)
        . DIRECTORY_SEPARATOR . 'doctor_' . $doctorId . '.' . $extension;
}

function prescriptionSignatureFilePath(
    int $prescriptionId,
    int $doctorId,
    ?string $storageDirectory = null,
    string $extension = 'png'
): string {
    if (!in_array($extension, ['png', 'jpg'], true)) {
        throw new InvalidArgumentException('Unsupported signature file extension.');
    }
    return doctorSignatureStorageDirectory($storageDirectory)
        . DIRECTORY_SEPARATOR . 'prescription_' . $prescriptionId . '_doctor_' . $doctorId . '.' . $extension;
}

function removeAlternateDoctorSignature(int $doctorId, string $currentExtension, ?string $storageDirectory = null): void
{
    foreach (['png', 'jpg'] as $extension) {
        if ($extension === $currentExtension) {
            continue;
        }
        $alternatePath = doctorSignatureFilePath($doctorId, $storageDirectory, $extension);
        if (is_file($alternatePath) && !unlink($alternatePath)) {
            throw new RuntimeException('Could not replace the previous saved doctor signature.');
        }
    }
}

function loadDoctorSignatureImage(int $doctorId, ?string $storageDirectory = null): ?array
{
    foreach (['png', 'jpg'] as $extension) {
        $filePath = doctorSignatureFilePath($doctorId, $storageDirectory, $extension);
        if (!is_file($filePath)) {
            continue;
        }

        $imageBytes = file_get_contents($filePath);
        if ($imageBytes === false) {
            throw new RuntimeException('Could not read the saved doctor signature.');
        }
        $image = validateDoctorSignatureImage($imageBytes);
        if ($image['extension'] !== $extension) {
            throw new RuntimeException('Saved signature file extension does not match its content.');
        }
        return $image;
    }

    return null;
}

function loadDoctorSignaturePng(int $doctorId, ?string $storageDirectory = null): ?string
{
    $image = loadDoctorSignatureImage($doctorId, $storageDirectory);
    return $image !== null && $image['mime'] === 'image/png' ? $image['bytes'] : null;
}

function doctorSignatureDataUrl(int $doctorId, ?string $storageDirectory = null): ?string
{
    $image = loadDoctorSignatureImage($doctorId, $storageDirectory);
    return $image === null
        ? null
        : 'data:' . $image['mime'] . ';base64,' . base64_encode($image['bytes']);
}

function loadPrescriptionSignaturePath(
    int $prescriptionId,
    int $doctorId,
    ?string $storageDirectory = null
): ?string {
    foreach (['png', 'jpg'] as $extension) {
        $filePath = prescriptionSignatureFilePath($prescriptionId, $doctorId, $storageDirectory, $extension);
        if (!is_file($filePath)) {
            continue;
        }

        $imageBytes = file_get_contents($filePath);
        if ($imageBytes === false) {
            throw new RuntimeException('Could not read the prescription signature snapshot.');
        }
        $image = validateDoctorSignatureImage($imageBytes);
        if ($image['extension'] !== $extension) {
            throw new RuntimeException('Prescription signature file extension does not match its content.');
        }
        return $filePath;
    }

    return null;
}

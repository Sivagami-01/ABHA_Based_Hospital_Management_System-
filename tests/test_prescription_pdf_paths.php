<?php

require_once __DIR__ . '/../includes/functions.php';

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'abha-prescription-pdf-' . bin2hex(random_bytes(8));
$storageRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'prescriptions';
if (!mkdir($storageRoot, 0700, true)) {
    throw new RuntimeException('Could not create temporary prescription PDF storage.');
}

$pdfPath = $storageRoot . DIRECTORY_SEPARATOR . 'prescription.pdf';
if (file_put_contents($pdfPath, 'test pdf content') === false) {
    rmdir($storageRoot);
    rmdir($temporaryRoot);
    throw new RuntimeException('Could not create temporary prescription PDF.');
}

$assert = static function (string $message, bool $condition): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
};

try {
    $assert(
        'A PDF within prescription storage resolves',
        prescriptionPdfStoragePath('storage/prescriptions/prescription.pdf', $storageRoot) === realpath($pdfPath)
    );
    $assert(
        'Parent-directory traversal is rejected',
        prescriptionPdfStoragePath('storage/prescriptions/../outside.pdf', $storageRoot) === null
    );
    $assert(
        'Paths outside prescription storage are rejected',
        prescriptionPdfStoragePath('storage/other/document.pdf', $storageRoot) === null
    );
    $assert(
        'Missing prescription PDFs are rejected',
        prescriptionPdfStoragePath('storage/prescriptions/missing.pdf', $storageRoot) === null
    );
} finally {
    unlink($pdfPath);
    rmdir($storageRoot);
    rmdir($temporaryRoot);
}

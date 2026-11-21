<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../lib/fpdf/fpdf.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/doctor_signature.php';

class PrescriptionPDF extends FPDF
{
    private array $hospitalInfo = [];
    private array $prescriptionImages = [];

    public function setHospitalInfo(array $hospital): void
    {
        $this->hospitalInfo = $hospital;
    }

    public function Image($file, $x=null, $y=null, $w=0, $h=0, $type='', $link='')
    {
        $imagePath = realpath($file);
        if ($imagePath === false || !is_file($imagePath)) {
            $this->Error('Prescription image file not found: ' . $file);
        }

        if (!isset($this->prescriptionImages[$imagePath])) {
            $imageInfo = @getimagesize($imagePath);
            if (($imageInfo['mime'] ?? '') === 'image/jpeg') {
                $this->prescriptionImages[$imagePath] = $this->parseSignatureJpeg($imagePath, $imageInfo);
            } else {
                $this->prescriptionImages[$imagePath] = $this->parseSignaturePng($imagePath);
            }
            $this->prescriptionImages[$imagePath]['i'] = count($this->prescriptionImages);
        }
        $image = $this->prescriptionImages[$imagePath];

        if ($x === null) {
            $x = $this->x;
        }
        if ($y === null) {
            $y = $this->y;
        }
        if ($w == 0 && $h == 0) {
            $w = $image['width'] * 25.4 / 96;
            $h = $image['height'] * 25.4 / 96;
        } elseif ($w == 0) {
            $w = $h * $image['width'] / $image['height'];
        } elseif ($h == 0) {
            $h = $w * $image['height'] / $image['width'];
        }

        $this->_out(sprintf(
            'q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q',
            $w * $this->k,
            $h * $this->k,
            $x * $this->k,
            ($this->h - ($y + $h)) * $this->k,
            $image['i']
        ));
        if ($link) {
            $this->Link($x, $y, $w, $h, $link);
        }
    }

    private function parseSignatureJpeg(string $filePath, array $imageInfo): array
    {
        $imageBytes = file_get_contents($filePath);
        $channels = (int) ($imageInfo['channels'] ?? 0);
        if (
            $imageBytes === false
            || !in_array($channels, [1, 3, 4], true)
            || (int) ($imageInfo['bits'] ?? 0) !== 8
        ) {
            $this->Error('Prescription signature JPG format is not supported.');
        }

        return [
            'width' => (int) $imageInfo[0],
            'height' => (int) $imageInfo[1],
            'mime' => 'image/jpeg',
            'channels' => $channels,
            'data' => $imageBytes,
            'alpha' => null,
        ];
    }

    private function parseSignaturePng(string $filePath): array
    {
        $png = file_get_contents($filePath);
        if ($png === false || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            $this->Error('Invalid prescription PNG image.');
        }

        $offset = 8;
        $compressed = '';
        $header = null;
        $pngLength = strlen($png);
        while ($offset + 8 <= $pngLength) {
            $chunkLength = unpack('Nlength', substr($png, $offset, 4))['length'];
            $chunkType = substr($png, $offset + 4, 4);
            $chunkStart = $offset + 8;
            if ($chunkStart + $chunkLength + 4 > $pngLength) {
                $this->Error('Invalid prescription PNG chunk.');
            }
            $chunkData = substr($png, $chunkStart, $chunkLength);
            if ($chunkType === 'IHDR') {
                $header = unpack(
                    'Nwidth/Nheight/Cdepth/Ctype/Ccompression/Cfilter/Cinterlace',
                    substr($chunkData, 0, 13)
                );
            } elseif ($chunkType === 'IDAT') {
                $compressed .= $chunkData;
            } elseif ($chunkType === 'IEND') {
                break;
            }
            $offset = $chunkStart + $chunkLength + 4;
        }

        if (
            !$header
            || $header['depth'] !== 8
            || !in_array($header['type'], [2, 6], true)
            || $header['compression'] !== 0
            || $header['filter'] !== 0
            || $header['interlace'] !== 0
        ) {
            $this->Error('Prescription signature PNG format is not supported.');
        }

        $width = (int) $header['width'];
        $height = (int) $header['height'];
        $channels = $header['type'] === 6 ? 4 : 3;
        $rowBytes = $width * $channels;
        $decoded = @gzuncompress($compressed);
        if ($decoded === false || strlen($decoded) !== ($rowBytes + 1) * $height) {
            $this->Error('Could not decode prescription signature PNG.');
        }

        $rgbRows = '';
        $alphaRows = '';
        $hasTransparency = false;
        $previousRow = str_repeat("\0", $rowBytes);
        for ($rowIndex = 0; $rowIndex < $height; $rowIndex++) {
            $rowOffset = $rowIndex * ($rowBytes + 1);
            $filter = ord($decoded[$rowOffset]);
            $filteredRow = substr($decoded, $rowOffset + 1, $rowBytes);
            $row = '';
            for ($byteIndex = 0; $byteIndex < $rowBytes; $byteIndex++) {
                $left = $byteIndex >= $channels ? ord($row[$byteIndex - $channels]) : 0;
                $above = ord($previousRow[$byteIndex]);
                $upperLeft = $byteIndex >= $channels ? ord($previousRow[$byteIndex - $channels]) : 0;
                $value = ord($filteredRow[$byteIndex]);
                if ($filter === 1) {
                    $value += $left;
                } elseif ($filter === 2) {
                    $value += $above;
                } elseif ($filter === 3) {
                    $value += (int) floor(($left + $above) / 2);
                } elseif ($filter === 4) {
                    $estimate = $left + $above - $upperLeft;
                    $leftDistance = abs($estimate - $left);
                    $aboveDistance = abs($estimate - $above);
                    $upperLeftDistance = abs($estimate - $upperLeft);
                    $predictor = $leftDistance <= $aboveDistance && $leftDistance <= $upperLeftDistance
                        ? $left
                        : ($aboveDistance <= $upperLeftDistance ? $above : $upperLeft);
                    $value += $predictor;
                } elseif ($filter !== 0) {
                    $this->Error('Unsupported prescription PNG filter.');
                }
                $row .= chr($value & 255);
            }

            $rgbRow = "\0";
            $alphaRow = "\0";
            for ($pixelOffset = 0; $pixelOffset < $rowBytes; $pixelOffset += $channels) {
                $rgbRow .= substr($row, $pixelOffset, 3);
                if ($channels === 4) {
                    $alpha = ord($row[$pixelOffset + 3]);
                    $alphaRow .= chr($alpha);
                    $hasTransparency = $hasTransparency || $alpha !== 255;
                }
            }
            $rgbRows .= $rgbRow;
            if ($channels === 4) {
                $alphaRows .= $alphaRow;
            }
            $previousRow = $row;
        }

        return [
            'width' => $width,
            'height' => $height,
            'mime' => 'image/png',
            'data' => gzcompress($rgbRows),
            'alpha' => $hasTransparency ? gzcompress($alphaRows) : null,
        ];
    }

    protected function _putresources()
    {
        $this->_putfonts();
        $this->_putPrescriptionImages();
        $this->offsets[2] = strlen($this->buffer);
        $this->_put('2 0 obj');
        $this->_put('<<');
        $this->_put('/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
        $this->_put('/Font <<');
        foreach ($this->fonts as $font) {
            $this->_put('/F' . $font['i'] . ' ' . $font['n'] . ' 0 R');
        }
        $this->_put('>>');
        if ($this->prescriptionImages) {
            $this->_put('/XObject <<');
            foreach ($this->prescriptionImages as $image) {
                $this->_put('/I' . $image['i'] . ' ' . $image['n'] . ' 0 R');
            }
            $this->_put('>>');
        }
        $this->_put('>>');
        $this->_put('endobj');
    }

    private function _putPrescriptionImages(): void
    {
        foreach ($this->prescriptionImages as &$image) {
            if ($image['mime'] === 'image/jpeg') {
                $this->_newobj();
                $image['n'] = $this->n;
                $colorSpace = [1 => 'DeviceGray', 3 => 'DeviceRGB', 4 => 'DeviceCMYK'][$image['channels']];
                $this->_put('<<');
                $this->_put('/Type /XObject');
                $this->_put('/Subtype /Image');
                $this->_put('/Width ' . $image['width']);
                $this->_put('/Height ' . $image['height']);
                $this->_put('/ColorSpace /' . $colorSpace);
                $this->_put('/BitsPerComponent 8');
                $this->_put('/Filter /DCTDecode');
                $this->_put('/Length ' . strlen($image['data']));
                $this->_put('>>');
                $this->_putstream($image['data']);
                $this->_put('endobj');
                continue;
            }

            if ($image['alpha'] !== null) {
                $this->_newobj();
                $image['smask'] = $this->n;
                $this->_put('<<');
                $this->_put('/Type /XObject');
                $this->_put('/Subtype /Image');
                $this->_put('/Width ' . $image['width']);
                $this->_put('/Height ' . $image['height']);
                $this->_put('/ColorSpace /DeviceGray');
                $this->_put('/BitsPerComponent 8');
                $this->_put('/Filter /FlateDecode');
                $this->_put('/DecodeParms << /Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns ' . $image['width'] . ' >>');
                $this->_put('/Length ' . strlen($image['alpha']));
                $this->_put('>>');
                $this->_putstream($image['alpha']);
                $this->_put('endobj');
            }

            $this->_newobj();
            $image['n'] = $this->n;
            $this->_put('<<');
            $this->_put('/Type /XObject');
            $this->_put('/Subtype /Image');
            $this->_put('/Width ' . $image['width']);
            $this->_put('/Height ' . $image['height']);
            $this->_put('/ColorSpace /DeviceRGB');
            $this->_put('/BitsPerComponent 8');
            $this->_put('/Filter /FlateDecode');
            $this->_put('/DecodeParms << /Predictor 15 /Colors 3 /BitsPerComponent 8 /Columns ' . $image['width'] . ' >>');
            if (isset($image['smask'])) {
                $this->_put('/SMask ' . $image['smask'] . ' 0 R');
            }
            $this->_put('/Length ' . strlen($image['data']));
            $this->_put('>>');
            $this->_putstream($image['data']);
            $this->_put('endobj');
        }
        unset($image);
    }

    public function Header(): void
    {
        $hospitalName = $this->hospitalInfo['hospital_name'] ?? 'MediCore Multispeciality Hospital';
        $hospitalAddress = $this->hospitalInfo['address'] ?? 'Healthcare Avenue, Medical City';
        $hospitalPhone = $this->hospitalInfo['phone'] ?? '+91 1800-123-4567';
        $hospitalEmail = $this->hospitalInfo['email'] ?? 'care@medicorehospital.local';

        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(30, 64, 175); // Royal Blue
        $this->Cell(0, 8, $hospitalName, 0, 1, 'C');

        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(100, 116, 139); // Slate Grey
        $this->Cell(0, 5, $hospitalAddress . ' | Tel: ' . $hospitalPhone . ' | Email: ' . $hospitalEmail, 0, 1, 'C');

        $this->SetDrawColor(203, 213, 225);
        $this->SetLineWidth(0.4);
        $this->Line(10, 24, 200, 24);

        $this->Ln(4);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb} - MediCore Healthcare Digital Records System', 0, 0, 'C');
    }
}

function generatePrescriptionPdf(PDO $pdo, int $prescriptionId): array
{
    $prescQuery = $pdo->prepare("
        SELECT 
            pr.id AS prescription_id,
            pr.prescription_date,
            pr.consultation_id,
            pr.doctor_id,
            pr.patient_id,
            c.symptoms,
            c.diagnosis,
            c.notes,
            c.consultation_date,
            p.patient_id AS patient_code,
            p.full_name AS patient_name,
            p.date_of_birth,
            p.gender,
            p.mobile AS patient_mobile,
            p.blood_group,
            p.allergies,
            p.existing_conditions,
            i.demo_health_id,
            d.doctor_name,
            d.specialization,
            d.license_number,
            dept.department_name,
            h.id AS hospital_id,
            h.hospital_name,
            h.address AS hospital_address,
            h.phone AS hospital_phone,
            h.email AS hospital_email
        FROM prescriptions pr
        JOIN consultations c ON c.id = pr.consultation_id
        JOIN patients p ON p.id = pr.patient_id
        LEFT JOIN demo_health_identities i ON i.id = p.health_identity_id
        JOIN doctors d ON d.id = pr.doctor_id
        LEFT JOIN departments dept ON dept.id = d.department_id
        LEFT JOIN users u ON u.id = d.user_id
        LEFT JOIN hospitals h ON h.id = u.hospital_id
        WHERE pr.id = :prescription_id
        LIMIT 1
    ");
    $prescQuery->execute([':prescription_id' => $prescriptionId]);
    $data = $prescQuery->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        throw new RuntimeException("Prescription not found with ID: " . $prescriptionId);
    }
    $signaturePath = loadPrescriptionSignaturePath($prescriptionId, (int) $data['doctor_id']);

    $itemsQuery = $pdo->prepare("
        SELECT medicine_name, dosage, frequency, duration, instructions
        FROM prescription_items
        WHERE prescription_id = :prescription_id
        ORDER BY id ASC
    ");
    $itemsQuery->execute([':prescription_id' => $prescriptionId]);
    $items = $itemsQuery->fetchAll(PDO::FETCH_ASSOC);

    $hospitalInfo = [
        'hospital_name' => $data['hospital_name'] ?: 'MediCore General Hospital',
        'address' => $data['hospital_address'] ?: 'ABHA Integrated Health Facility',
        'phone' => $data['hospital_phone'] ?: '+91 1800-456-7890',
        'email' => $data['hospital_email'] ?: 'support@medicore.local'
    ];

    $pdf = new PrescriptionPDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->setHospitalInfo($hospitalInfo);
    $pdf->AddPage();
    $pdf->SetAutoPageBreak(true, 25);

    // Prescription Title Banner
    $pdf->SetFillColor(238, 242, 255); // Light Indigo
    $pdf->SetDrawColor(199, 210, 254);
    $pdf->RoundedRect = function($x, $y, $w, $h) use ($pdf) {
        $pdf->Rect($x, $y, $w, $h, 'DF');
    };
    $pdf->Rect(10, 27, 190, 8, 'DF');
    $pdf->SetXY(10, 27);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->SetTextColor(49, 46, 129);
    $pdf->Cell(95, 8, '  MEDICAL PRESCRIPTION & CLINICAL SUMMARY', 0, 0, 'L');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(95, 8, 'Prescription #: RX-' . date('Y', strtotime($data['prescription_date'])) . '-' . str_pad((string)$prescriptionId, 6, '0', STR_PAD_LEFT) . '  ', 0, 1, 'R');

    $pdf->Ln(3);

    // Two Column Layout: Patient Details (Left) & Doctor / Visit Details (Right)
    $yStart = $pdf->GetY();

    // --- LEFT COLUMN: Patient Info ---
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->Rect(10, $yStart, 93, 38, 'DF');

    $pdf->SetXY(12, $yStart + 2);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(89, 4.5, 'PATIENT INFORMATION', 0, 1, 'L');

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetX(12);
    $pdf->Cell(25, 4.5, 'Name:', 0, 0);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(64, 4.5, $data['patient_name'], 0, 1);

    $pdf->SetX(12);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(25, 4.5, 'Patient ID:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(64, 4.5, $data['patient_code'] ?? 'N/A', 0, 1);

    $pdf->SetX(12);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(25, 4.5, 'Health ID (ABHA):', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(64, 4.5, $data['demo_health_id'] ?? 'N/A', 0, 1);

    $pdf->SetX(12);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(25, 4.5, 'Gender / DOB:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $genderStr = ucfirst($data['gender'] ?? 'Unknown');
    $dobStr = $data['date_of_birth'] ? date('d-M-Y', strtotime($data['date_of_birth'])) : 'N/A';
    $pdf->Cell(64, 4.5, $genderStr . ' / ' . $dobStr, 0, 1);

    $pdf->SetX(12);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(25, 4.5, 'Mobile / Blood:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(64, 4.5, ($data['patient_mobile'] ?? 'N/A') . ' | ' . ($data['blood_group'] ?? 'Unknown'), 0, 1);

    $pdf->SetX(12);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(25, 4.5, 'Known Allergies:', 0, 0);
    $pdf->SetTextColor(185, 28, 28);
    $pdf->Cell(64, 4.5, $data['allergies'] ?: 'None Reported', 0, 1);

    // --- RIGHT COLUMN: Doctor & Consultation Info ---
    $pdf->SetFillColor(248, 250, 252);
    $pdf->Rect(107, $yStart, 93, 38, 'DF');

    $pdf->SetXY(109, $yStart + 2);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(89, 4.5, 'DOCTOR & VISIT INFORMATION', 0, 1, 'L');

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetX(109);
    $pdf->Cell(28, 4.5, 'Doctor Name:', 0, 0);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(61, 4.5, $data['doctor_name'], 0, 1);

    $pdf->SetX(109);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(28, 4.5, 'Specialization:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(61, 4.5, ($data['specialization'] ?: 'General Physician') . ' (' . ($data['department_name'] ?: 'Clinical') . ')', 0, 1);

    $pdf->SetX(109);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(28, 4.5, 'License Number:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(61, 4.5, $data['license_number'] ?: 'REG-MCI-VALID', 0, 1);

    $pdf->SetX(109);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(28, 4.5, 'Consultation Date:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(61, 4.5, date('d-M-Y H:i A', strtotime($data['consultation_date'] ?? $data['prescription_date'])), 0, 1);

    $pdf->SetX(109);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(28, 4.5, 'Prescription Date:', 0, 0);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(61, 4.5, date('d-M-Y', strtotime($data['prescription_date'])), 0, 1);

    $pdf->SetY($yStart + 41);

    // --- CLINICAL FINDINGS SECTION (Symptoms, Diagnosis, Notes) ---
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(190, 6, '  CLINICAL FINDINGS & DIAGNOSIS', 1, 1, 'L', true);

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(51, 65, 85);

    $pdf->Cell(35, 6, ' Symptoms Recorded:', 'L', 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->Cell(155, 6, $data['symptoms'] ?: 'None recorded during visit', 'R', 1, 'L');

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell(35, 6, ' Primary Diagnosis:', 'L', 0, 'L');
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(185, 28, 28);
    $pdf->Cell(155, 6, $data['diagnosis'] ?: 'Clinical Assessment Completed', 'R', 1, 'L');

    if (!empty($data['notes'])) {
        $pdf->SetTextColor(51, 65, 85);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->Cell(35, 6, ' Clinical Notes:', 'L', 0, 'L');
        $pdf->Cell(155, 6, $data['notes'], 'R', 1, 'L');
    }

    $pdf->Cell(190, 0, '', 'T', 1);
    $pdf->Ln(3);

    // --- RX MEDICATION TABLE ---
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(30, 64, 175);
    $pdf->Cell(190, 6, 'Rx - PRESCRIBED MEDICATIONS', 0, 1, 'L');

    // Table Header
    $pdf->SetFillColor(30, 64, 175);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(30, 64, 175);
    $pdf->SetFont('Helvetica', 'B', 8.5);

    $pdf->Cell(10, 7, '#', 1, 0, 'C', true);
    $pdf->Cell(60, 7, 'Medicine Name', 1, 0, 'L', true);
    $pdf->Cell(30, 7, 'Dosage', 1, 0, 'L', true);
    $pdf->Cell(30, 7, 'Frequency', 1, 0, 'L', true);
    $pdf->Cell(25, 7, 'Duration', 1, 0, 'L', true);
    $pdf->Cell(35, 7, 'Instructions', 1, 1, 'L', true);

    // Table Body
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetDrawColor(226, 232, 240);
    $fill = false;
    $itemIndex = 1;

    foreach ($items as $item) {
        $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell(10, 6.5, (string)$itemIndex, 1, 0, 'C', true);
        $pdf->Cell(60, 6.5, $item['medicine_name'], 1, 0, 'L', true);

        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->Cell(30, 6.5, $item['dosage'] ?: '-', 1, 0, 'L', true);
        $pdf->Cell(30, 6.5, $item['frequency'] ?: '-', 1, 0, 'L', true);
        $pdf->Cell(25, 6.5, $item['duration'] ?: '-', 1, 0, 'L', true);
        $pdf->Cell(35, 6.5, $item['instructions'] ?: 'As directed', 1, 1, 'L', true);

        $fill = !$fill;
        $itemIndex++;
    }

    if (empty($items)) {
        $pdf->Cell(190, 7, 'No medication items recorded.', 1, 1, 'C');
    }

    $pdf->Ln(6);

    // --- DIGITAL SIGNATURE & VERIFICATION SECTION ---
    $sigBoxY = $pdf->GetY();
    if ($sigBoxY > 205) {
        $pdf->AddPage();
        $sigBoxY = $pdf->GetY();
    }

    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Rect(10, $sigBoxY, 190, 64, 'DF');

    $pdf->SetXY(13, $sigBoxY + 3);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(100, 4.5, 'PRESCRIPTION COMPLIANCE', 0, 0, 'L');
    $pdf->Cell(80, 4.5, 'ATTENDING PHYSICIAN', 0, 1, 'R');

    $pdf->SetXY(13, $sigBoxY + 8);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->MultiCell(100, 3.8, 'This electronic medical prescription is prepared in accordance with the Information Technology Act, 2000 and National Digital Health Mission guidelines.', 0, 'L');

    // Right Side: Physician Signature Box & Coordinate Region
    if ($signaturePath !== null) {
        $pdf->SetXY(118, $sigBoxY + 8);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell(78, 3.5, 'Doctor signature', 0, 1, 'R');
        $pdf->Image($signaturePath, 154, $sigBoxY + 13, 42, 0, 'PNG');

        $pdf->SetXY(118, $sigBoxY + 28);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(78, 4.5, $data['doctor_name'], 0, 1, 'R');

        $pdf->SetXY(118, $sigBoxY + 32.5);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell(78, 3.8, ($data['specialization'] ?: 'Medical Practitioner') . ' | Reg: ' . ($data['license_number'] ?: 'MCI-VALID'), 0, 1, 'R');

        $pdf->SetXY(118, $sigBoxY + 40);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(148, 163, 184);
        $pdf->Cell(78, 3.5, 'Generated: ' . date('d-M-Y H:i:s T'), 0, 1, 'R');
    } else {
        $pdf->SetXY(118, $sigBoxY + 8);
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(78, 4.5, $data['doctor_name'], 0, 1, 'R');

        $pdf->SetXY(118, $sigBoxY + 12.5);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell(78, 3.8, ($data['specialization'] ?: 'Medical Practitioner') . ' | Reg: ' . ($data['license_number'] ?: 'MCI-VALID'), 0, 1, 'R');

        $pdf->SetXY(118, $sigBoxY + 28);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(148, 163, 184);
        $pdf->Cell(78, 3.5, 'Generated: ' . date('d-M-Y H:i:s T'), 0, 1, 'R');
    }

    // Save File
    $storageDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'prescriptions';
    if (!is_dir($storageDir)) {
        mkdir($storageDir, 0755, true);
    }

    $fileName = 'prescription_' . $prescriptionId . '_unsigned.pdf';
    $filePath = $storageDir . DIRECTORY_SEPARATOR . $fileName;
    $relativePdfPath = 'storage/prescriptions/' . $fileName;

    $pdf->Output('F', $filePath);

    // Update or Insert into prescription_documents
    $docCheck = $pdo->prepare("SELECT id FROM prescription_documents WHERE prescription_id = :prescription_id LIMIT 1");
    $docCheck->execute([':prescription_id' => $prescriptionId]);
    $existingDoc = $docCheck->fetch(PDO::FETCH_ASSOC);

    if ($existingDoc) {
        $updateDoc = $pdo->prepare("
            UPDATE prescription_documents
            SET unsigned_pdf_path = :unsigned_path,
                consultation_id = :consultation_id,
                doctor_id = :doctor_id,
                patient_id = :patient_id
            WHERE id = :id
        ");
        $updateDoc->execute([
            ':unsigned_path' => $relativePdfPath,
            ':consultation_id' => $data['consultation_id'],
            ':doctor_id' => $data['doctor_id'],
            ':patient_id' => $data['patient_id'],
            ':id' => $existingDoc['id']
        ]);
        $documentId = (int)$existingDoc['id'];
    } else {
        $insertDoc = $pdo->prepare("
            INSERT INTO prescription_documents (
                prescription_id, consultation_id, doctor_id, patient_id, unsigned_pdf_path
            ) VALUES (
                :prescription_id, :consultation_id, :doctor_id, :patient_id, :unsigned_path
            )
        ");
        $insertDoc->execute([
            ':prescription_id' => $prescriptionId,
            ':consultation_id' => $data['consultation_id'],
            ':doctor_id' => $data['doctor_id'],
            ':patient_id' => $data['patient_id'],
            ':unsigned_path' => $relativePdfPath
        ]);
        $documentId = (int)$pdo->lastInsertId();
    }

    return [
        'document_id' => $documentId,
        'prescription_id' => $prescriptionId,
        'file_path' => $filePath,
        'relative_path' => $relativePdfPath,
        'patient_name' => $data['patient_name'],
        'doctor_name' => $data['doctor_name'],
        'prescription_date' => $data['prescription_date']
    ];
}

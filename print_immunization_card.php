<?php
// FILE: print_immunization_card.php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Access Control
if (!hasAnyRole(['admin', 'nurse', 'doctor', 'pharmacist', 'patient'])) {
    die("Access Denied");
}

$conn = getDBConnection();

if (!isset($_GET['id'])) {
    die("Invalid Request");
}

$ref_immunization_id = $_GET['id'];

// 1. First, find the Patient ID associated with this immunization record
$stmt = $conn->prepare("SELECT patient_id FROM IMMUNIZATIONS WHERE immunization_id = :id");
$stmt->execute(['id' => $ref_immunization_id]);
$patient_ref = $stmt->fetch();

if (!$patient_ref) {
    die("Record not found.");
}

$patient_id = $patient_ref['patient_id'];

// 2. Get Full Patient Details
$stmt = $conn->prepare("SELECT * FROM PATIENT WHERE patient_id = :id");
$stmt->execute(['id' => $patient_id]);
$patient = $stmt->fetch();

// 3. Get ALL Immunization History for this patient
$stmt = $conn->prepare("
    SELECT 
        i.*, 
        p.name as doc_name, 
        p.surname as doc_surname
    FROM IMMUNIZATIONS i
    LEFT JOIN PROFESSIONAL p ON i.administered_by = p.professional_id
    WHERE i.patient_id = :pid
    ORDER BY i.administered_date ASC
");
$stmt->execute(['pid' => $patient_id]);
$history = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vaccination Card - <?= htmlspecialchars($patient['name']) ?></title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #fff;
            color: #333;
            padding: 40px;
            max-width: 900px;
            margin: 0 auto;
        }

        /* HEADER */
        .header {
            text-align: center;
            border-bottom: 2px solid #2c3e50;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            margin: 0;
            color: #2c3e50;
            font-size: 24px;
            text-transform: uppercase;
        }
        .header h2 {
            margin: 5px 0 0 0;
            font-size: 16px;
            color: #7f8c8d;
            font-weight: 400;
        }
        .logo {
            font-size: 40px;
            margin-bottom: 10px;
        }

        /* PATIENT INFO BOX */
        .patient-box {
            background: #f8f9fa;
            border: 1px solid #e1e8ed;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
        }
        .info-group {
            flex: 1;
        }
        .label {
            font-size: 11px;
            color: #7f8c8d;
            text-transform: uppercase;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .value {
            font-size: 16px;
            font-weight: 600;
            color: #2c3e50;
        }

        /* TABLE */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 40px;
        }
        th {
            background: #2c3e50;
            color: white;
            padding: 12px;
            text-align: left;
            font-size: 12px;
            text-transform: uppercase;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        /* FOOTER */
        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 11px;
            color: #95a5a6;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }

        /* PRINT BUTTON (Hidden when printing) */
        .no-print {
            text-align: center;
            margin-bottom: 30px;
        }
        .btn-print {
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-print:hover { background: #2980b9; }

        @media print {
            .no-print { display: none; }
            body { padding: 0; }
            .patient-box { border: 1px solid #000; background: none; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()" class="btn-print">🖨️ Print / Save as PDF</button>
        <button onclick="window.close()" class="btn-print" style="background:#95a5a6; margin-left:10px;">Close</button>
    </div>

    <div class="header">
        <div class="logo">🏥</div>
        <h1>Hospital les Corts</h1>
        <h2>Official Immunization Record</h2>
    </div>

    <div class="patient-box">
        <div class="info-group">
            <div class="label">Patient Name</div>
            <div class="value"><?= htmlspecialchars($patient['name'] . ' ' . $patient['surname']) ?></div>
        </div>
        <div class="info-group">
            <div class="label">DNI / Passport</div>
            <div class="value"><?= htmlspecialchars($patient['DNI']) ?></div>
        </div>
        <div class="info-group">
            <div class="label">Date of Birth</div>
            <div class="value"><?= date('d/m/Y', strtotime($patient['birth_date'])) ?></div>
        </div>
        <div class="info-group">
            <div class="label">Medical Record #</div>
            <div class="value"><?= htmlspecialchars($patient['record_number']) ?></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date Administered</th>
                <th>Vaccine / Product</th>
                <th>Manufacturer</th>
                <th>Dose</th>
                <th>Lot Number</th>
                <th>Site/Route</th>
                <th>Administered By</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $row): ?>
            <tr>
                <td style="font-weight:600;"><?= date('Y-m-d', strtotime($row['administered_date'])) ?></td>
                <td><?= htmlspecialchars($row['vaccine_name']) ?></td>
                <td><?= htmlspecialchars($row['manufacturer']) ?></td>
                <td><?= htmlspecialchars($row['dose_number']) ?></td>
                <td style="font-family: monospace;"><?= htmlspecialchars($row['lot_number']) ?></td>
                <td><?= htmlspecialchars($row['site']) ?> (<?= htmlspecialchars($row['route']) ?>)</td>
                <td>
                    <?php if($row['doc_name']): ?>
                        <?= htmlspecialchars(substr($row['doc_name'],0,1) . '. ' . $row['doc_surname']) ?>
                    <?php else: ?>
                        System Record
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer">
        <p>This document is a certified extract from the Hospital les Corts Electronic Health Record System.</p>
        <p>Generated on <?= date('d/m/Y H:i') ?></p>
        <p>Hospital les Corts • Barcelona, Spain • +34 93 456 78 90</p>
    </div>

    <script>
        // Automatically open print dialog when page loads
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        }
    </script>
</body>
</html>
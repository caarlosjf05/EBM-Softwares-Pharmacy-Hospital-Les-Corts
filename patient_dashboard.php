<?php
// FILE: patient_dashboard.php
// ADAPTED: Now accessible by Professionals (with ID) AND Patients (auto)

session_start();

// 1. DATABASE CONNECTION
require_once 'includes/functions.php'; // Ensure database connection logic is here
$conn = getDBConnection();

// 2. AUTHENTICATION LOGIC
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$patient_id = null;
$viewer_role = $_SESSION['type_user'];

// CASE A: Professional Viewing a Patient
if (in_array($viewer_role, ['admin', 'doctor', 'nurse', 'pharmacist'])) {
    if (isset($_GET['patient_id'])) {
        $patient_id = intval($_GET['patient_id']);
    } else {
        die("Error: No patient ID provided for professional view.");
    }
} 
// CASE B: Patient Viewing Themselves
elseif ($viewer_role === 'patient') {
    // Get ID from session email
    $stmt = $conn->prepare("SELECT patient_id FROM PATIENT WHERE email = :email");
    $stmt->execute(['email' => $_SESSION['email']]);
    $p_data = $stmt->fetch();
    
    if ($p_data) {
        $patient_id = $p_data['patient_id'];
    } else {
        die("Error: Patient record not found.");
    }
} else {
    header('Location: index.php');
    exit();
}

// --- DATA FETCHING ---

// 1. Patient Info
$stmt = $conn->prepare("SELECT * FROM PATIENT WHERE patient_id = :id");
$stmt->execute(['id' => $patient_id]);
$patient = $stmt->fetch();

if (!$patient) die("Patient not found.");

// 2. Allergies
$stmt = $conn->prepare("
    SELECT pa.*, a.name_alergen as allergen_name 
    FROM PATIENT_ALLERGY pa
    LEFT JOIN ALLERGENS a ON pa.allergen_id = a.allergen_id
    WHERE pa.patient_id = :id ORDER BY pa.severity DESC
");
$stmt->execute(['id' => $patient_id]);
$allergies = $stmt->fetchAll();

// 3. Primary Physician
$primary_physician = null;
if ($patient['primary_physician']) {
    $stmt = $conn->prepare("SELECT name, surname, speciality, telephone FROM PROFESSIONAL WHERE professional_id = :id");
    $stmt->execute(['id' => $patient['primary_physician']]);
    $primary_physician = $stmt->fetch();
}

// 4. Encounters
$stmt = $conn->prepare("
    SELECT e.*, p.name as doc_name, p.surname as doc_surname, d.disease_name, d.code_ICD_10
    FROM ENCOUNTER e
    JOIN PROFESSIONAL p ON e.professional_id = p.professional_id
    LEFT JOIN DIAGNOSTICS d ON e.diagnostic_id = d.diagnostic_id
    WHERE e.patient_id = :id ORDER BY e.start_datetime DESC LIMIT 10
");
$stmt->execute(['id' => $patient_id]);
$encounters = $stmt->fetchAll();

// 5. Active Diagnostics (FIXED)
// LOGIC: Selects unique diagnoses linked to the patient's PRESCRIPTIONS
$stmt = $conn->prepare("
    SELECT DISTINCT 
        d.code_ICD_10, 
        d.disease_name, 
        d.type, 
        MAX(pr.date) as last_date
    FROM PRESCRIPTION pr
    JOIN DIAGNOSTICS d ON pr.diagnostic_id = d.diagnostic_id
    WHERE pr.patient_id = :id
    GROUP BY d.diagnostic_id 
    ORDER BY last_date DESC
");
$stmt->execute(['id' => $patient_id]);
$active_diagnostics = $stmt->fetchAll();

// 6. Recent Prescriptions
$stmt = $conn->prepare("
    SELECT pr.date, d.comercial_name, d.active_principle, pi.dose, pi.frequency, pi.duration, prof.surname as doc_surname
    FROM PRESCRIPTION pr
    JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
    JOIN DRUGS d ON pi.drug_id = d.drug_id
    JOIN PROFESSIONAL prof ON pr.professional_id = prof.professional_id
    WHERE pr.patient_id = :id 
    ORDER BY pr.date DESC LIMIT 15
");
$stmt->execute(['id' => $patient_id]);
$recent_prescriptions = $stmt->fetchAll();

// Calculate Age
$age = date_diff(date_create($patient['birth_date']), date_create('today'))->y;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Record: <?= htmlspecialchars($patient['surname']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .hospital-name { font-size: 14px; font-weight: 600; color: #2c3e50; text-transform: uppercase; }
        .btn { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 500; text-transform: uppercase; transition: all 0.2s; }
        .btn:hover { border-color: #95a5a6; color: #2c3e50; }
        .container { max-width: 1400px; margin: 0 auto; padding: 32px; }
        
        /* Patient Header with Gradient */
        .patient-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px; border-radius: 8px; margin-bottom: 32px; display: grid; grid-template-columns: auto 1fr auto; gap: 32px; align-items: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .patient-avatar { width: 80px; height: 80px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 36px; border: 3px solid rgba(255,255,255,0.3); }
        .patient-main-info h1 { margin: 0 0 10px 0; font-weight: 300; font-size: 28px; }
        .patient-meta { display: flex; gap: 20px; font-size: 14px; opacity: 0.9; }
        
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px; margin-bottom: 32px; }
        .info-card { background: white; border: 1px solid #e1e8ed; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .info-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #f0f0f0; }
        .info-card-title { font-weight: 600; color: #2c3e50; }
        
        .info-item { margin-bottom: 15px; }
        .info-label { font-size: 11px; color: #95a5a6; text-transform: uppercase; display: block; margin-bottom: 4px; }
        .info-value { font-weight: 500; color: #2c3e50; }
        
        /* Allergies */
        .allergy-item { background: #fff5f5; border-left: 4px solid #e74c3c; padding: 10px; margin-bottom: 10px; border-radius: 4px; }
        .allergy-name { font-weight: 700; color: #c0392b; }
        
        /* Timeline */
        .timeline { position: relative; padding-left: 30px; border-left: 2px solid #e1e8ed; margin-left: 10px; }
        .timeline-item { position: relative; margin-bottom: 25px; }
        .timeline-item::before { content: ''; position: absolute; left: -36px; top: 5px; width: 10px; height: 10px; background: #3498db; border-radius: 50%; border: 2px solid white; }
        .timeline-date { font-size: 12px; color: #95a5a6; margin-bottom: 5px; }
        .timeline-title { font-weight: 600; color: #2c3e50; }
        
        /* Tables */
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th { text-align: left; padding: 12px; background: #f8f9fa; color: #7f8c8d; font-size: 11px; text-transform: uppercase; }
        td { padding: 12px; border-bottom: 1px solid #eee; font-size: 13px; }
        
        .section-title { font-size: 18px; color: #2c3e50; margin: 40px 0 20px 0; border-bottom: 2px solid #eee; padding-bottom: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <div class="hospital-name">Hospital les Corts</div>
        </div>
        <div class="header-right">
            <?php if (in_array($viewer_role, ['admin', 'doctor', 'nurse', 'pharmacist'])): ?>
                <a href="edit_patient_meds.php?patient_id=<?= $patient_id ?>" class="btn">← Back to Edit</a>
                <a href="dashboard.php" class="btn">Dashboard</a>
            <?php else: ?>
                <a href="dashboard.php" class="btn">← Back to Dashboard</a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn">🖨️ Print</button>
        </div>
    </div>

    <div class="container">
        <div class="patient-header">
            <div class="patient-avatar">
                <?= strtoupper(substr($patient['name'], 0, 1)) ?>
            </div>
            <div class="patient-main-info">
                <h1><?= htmlspecialchars($patient['name'] . ' ' . $patient['surname']) ?></h1>
                <div class="patient-meta">
                    <div>📋 Record: <?= htmlspecialchars($patient['record_number']) ?></div>
                    <div>🆔 DNI: <?= htmlspecialchars($patient['DNI']) ?></div>
                    <div>🎂 <?= $age ?> years</div>
                    <div><?= $patient['sex'] === 'M' ? '👨 Male' : '👩 Female' ?></div>
                </div>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-card">
                <div class="info-card-header"><span class="info-card-title">👤 Personal Info</span></div>
                <div class="info-item"><span class="info-label">DOB</span><span class="info-value"><?= date('d/m/Y', strtotime($patient['birth_date'])) ?></span></div>
                <div class="info-item"><span class="info-label">Blood Type</span><span class="info-value"><?= htmlspecialchars($patient['blood_type'] ?? '-') ?></span></div>
                <div class="info-item"><span class="info-label">Email</span><span class="info-value"><?= htmlspecialchars($patient['email']) ?></span></div>
                <div class="info-item"><span class="info-label">Phone</span><span class="info-value"><?= htmlspecialchars($patient['phone_number'] ?? '-') ?></span></div>
            </div>

            <div class="info-card">
                <div class="info-card-header"><span class="info-card-title">👨‍⚕️ Primary Care</span></div>
                <?php if ($primary_physician): ?>
                    <div class="info-item"><span class="info-label">Doctor</span><span class="info-value">Dr. <?= htmlspecialchars($primary_physician['surname']) ?></span></div>
                    <div class="info-item"><span class="info-label">Specialty</span><span class="info-value"><?= htmlspecialchars($primary_physician['speciality'] ?? 'General') ?></span></div>
                    <div class="info-item"><span class="info-label">Contact</span><span class="info-value"><?= htmlspecialchars($primary_physician['telephone'] ?? '-') ?></span></div>
                <?php else: ?>
                    <p style="color:#95a5a6;">No primary physician assigned.</p>
                <?php endif; ?>
            </div>

            <div class="info-card">
                <div class="info-card-header"><span class="info-card-title">⚠️ Allergies</span></div>
                <?php if (empty($allergies)): ?>
                    <p style="color:#27ae60;">✅ No known allergies.</p>
                <?php else: ?>
                    <?php foreach ($allergies as $alg): ?>
                        <div class="allergy-item">
                            <div class="allergy-name"><?= htmlspecialchars($alg['allergen_name']) ?></div>
                            <small><?= htmlspecialchars($alg['reaction'] ?? '') ?> (<?= htmlspecialchars($alg['severity']) ?>)</small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <h2 class="section-title">📊 Active Diagnoses (From Rx)</h2>
        <?php if (!empty($active_diagnostics)): ?>
            <table>
                <thead><tr><th>ICD-10</th><th>Condition</th><th>Type</th><th>Rx Date</th></tr></thead>
                <tbody>
                    <?php foreach ($active_diagnostics as $d): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($d['code_ICD_10']) ?></strong></td>
                            <td><?= htmlspecialchars($d['disease_name']) ?></td>
                            <td><?= htmlspecialchars($d['type'] ?? 'Chronic') ?></td>
                            <td><?= date('d/m/Y', strtotime($d['last_date'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:#95a5a6; font-style:italic;">No diagnoses found in prescription history.</p>
        <?php endif; ?>

        <h2 class="section-title">🏥 Consultation History</h2>
        <div class="timeline">
            <?php foreach ($encounters as $enc): ?>
                <div class="timeline-item">
                    <div class="timeline-date"><?= date('d/m/Y H:i', strtotime($enc['start_datetime'])) ?></div>
                    <div class="timeline-title"><?= htmlspecialchars($enc['main_reason']) ?></div>
                    <div style="font-size:13px;">Dr. <?= htmlspecialchars($enc['doc_surname']) ?></div>
                    <?php if ($enc['disease_name']): ?>
                        <div style="font-size:12px; color:#7f8c8d; margin-top:4px;">Dx: <?= htmlspecialchars($enc['disease_name']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <h2 class="section-title">💊 Medications (All History)</h2>
        <?php if (!empty($recent_prescriptions)): ?>
            <table>
                <thead><tr><th>Date</th><th>Medication</th><th>Dose</th><th>Prescriber</th></tr></thead>
                <tbody>
                    <?php foreach ($recent_prescriptions as $rx): ?>
                        <tr>
                            <td><?= date('d/m/Y', strtotime($rx['date'])) ?></td>
                            <td>
                                <strong><?= htmlspecialchars($rx['comercial_name']) ?></strong><br>
                                <small><?= htmlspecialchars($rx['active_principle']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($rx['dose']) ?> (<?= htmlspecialchars($rx['frequency']) ?>)</td>
                            <td>Dr. <?= htmlspecialchars($rx['doc_surname']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:#95a5a6;">No prescription history found.</p>
        <?php endif; ?>

        <div style="text-align:center; margin-top:50px; color:#b2bec3; font-size:12px;">
            Generated by Hospital les Corts System | <?= date('d/m/Y H:i:s') ?>
        </div>
    </div>
</body>
</html>
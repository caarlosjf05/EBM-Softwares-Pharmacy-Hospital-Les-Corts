<?php
/**
 * PATIENT ALLERGY VERIFICATION MODULE
 * Allows pharmacists to check patient allergies before dispensation
 * Critical safety feature to prevent adverse reactions
 */

require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Authorization: Pharmacist + Clinical Staff
if (!hasAnyRole(['pharmacist', 'doctor', 'nurse'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$patient = null;
$allergies = [];
$active_prescriptions = [];
$allergy_conflicts = [];
$error = '';
$search_term = '';

// Search patient by DNI
if (isset($_POST['search_patient']) || isset($_GET['patient_dni'])) {
    $search_term = trim($_POST['patient_dni'] ?? $_GET['patient_dni'] ?? '');
    
    $stmt = $conn->prepare("SELECT * FROM PATIENT WHERE DNI = :dni");
    $stmt->execute(['dni' => $search_term]);
    $patient = $stmt->fetch();
    
    if (!$patient) {
        $error = 'Patient not found. Please verify the DNI.';
    } else {
        // Get patient allergies
        $stmt = $conn->prepare("
            SELECT 
                pa.patient_allergy_id,
                pa.allergen_id,
                pa.severity,
                pa.reaction,
                pa.notes,
                a.name_alergen as allergen_name,
                a.category as allergen_category
            FROM PATIENT_ALLERGY pa
            JOIN ALLERGENS a ON pa.allergen_id = a.allergen_id
            WHERE pa.patient_id = :patient_id
            ORDER BY 
                CASE pa.severity 
                    WHEN 'Severe' THEN 1 
                    WHEN 'Moderate' THEN 2 
                    WHEN 'Mild' THEN 3 
                    ELSE 4 
                END
        ");
        $stmt->execute(['patient_id' => $patient['patient_id']]);
        $allergies = $stmt->fetchAll();
        
        // Get active prescriptions (last 6 months, with or without dispensation)
        $stmt = $conn->prepare("
            SELECT DISTINCT
                d.drug_id,
                d.comercial_name,
                d.active_principle,
                d.code_ATC,
                pr.prescription_id,
                pr.date as prescription_date,
                pi.dose,
                pi.frequency,
                prof.name as doctor_name,
                prof.surname as doctor_surname,
                COALESCE(SUM(disp.quantity), 0) as dispensed_quantity
            FROM PRESCRIPTION pr
            JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
            JOIN DRUGS d ON pi.drug_id = d.drug_id
            JOIN PROFESSIONAL prof ON pr.professional_id = prof.professional_id
            LEFT JOIN DISPENSING disp ON pi.prescription_item_id = disp.prescription_item_id
            WHERE pr.patient_id = :patient_id
            AND pr.date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY pi.prescription_item_id
            ORDER BY pr.date DESC
        ");
        $stmt->execute(['patient_id' => $patient['patient_id']]);
        $active_prescriptions = $stmt->fetchAll();
        
        // Check for allergy conflicts with active medications
        if (!empty($allergies) && !empty($active_prescriptions)) {
            foreach ($active_prescriptions as $prescription) {
                foreach ($allergies as $allergy) {
                    // Check if drug contains allergen (simplified check by name matching)
                    // In a real system, this would use MEDICAMENT_ALLERGEN table
                    $allergen_name_lower = strtolower($allergy['allergen_name']);
                    $drug_name_lower = strtolower($prescription['comercial_name']);
                    $active_principle_lower = strtolower($prescription['active_principle']);
                    
                    // Check for matches in drug name or active principle
                    if (strpos($drug_name_lower, $allergen_name_lower) !== false || 
                        strpos($active_principle_lower, $allergen_name_lower) !== false) {
                        
                        $allergy_conflicts[] = [
                            'prescription' => $prescription,
                            'allergy' => $allergy
                        ];
                    }
                    
                    // Also check by category if it's a drug class allergy
                    if ($allergy['allergen_category'] === 'Drug' && 
                        (strpos($drug_name_lower, $allergen_name_lower) !== false ||
                         strpos($active_principle_lower, $allergen_name_lower) !== false)) {
                        
                        $allergy_conflicts[] = [
                            'prescription' => $prescription,
                            'allergy' => $allergy
                        ];
                    }
                }
            }
        }
    }
}

function calculateAge($birthdate) {
    if (!$birthdate) return 'N/A';
    $today = new DateTime();
    $birth = new DateTime($birthdate);
    return $today->diff($birth)->y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allergy Verification - Hospital les Corts</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
        }
        
        .header-module {
            background: white;
            border-bottom: 1px solid #e1e8ed;
            padding: 0 32px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .header-module h1 {
            font-size: 18px;
            font-weight: 400;
            color: #2c3e50;
        }
        
        .btn-back {
            background: transparent;
            color: #7f8c8d;
            border: 1px solid #dce1e6;
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.2s;
        }
        
        .btn-back:hover {
            border-color: #95a5a6;
            color: #2c3e50;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px;
        }
        
        .box {
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 4px;
            padding: 32px;
            margin-bottom: 24px;
        }
        
        .box h2 {
            font-size: 16px;
            font-weight: 500;
            color: #2c3e50;
            margin-bottom: 24px;
        }
        
        .alert {
            padding: 14px 20px;
            border-radius: 4px;
            margin-bottom: 24px;
            font-size: 13px;
        }
        
        .alert-error {
            background: #fee;
            border-left: 3px solid #e74c3c;
            color: #c0392b;
        }
        
        .alert-warning {
            background: #fff3cd;
            border-left: 3px solid #f39c12;
            color: #856404;
        }
        
        .alert-success {
            background: #d4edda;
            border-left: 3px solid #27ae60;
            color: #155724;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #2c3e50;
            font-weight: 500;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #dce1e6;
            border-radius: 4px;
            font-size: 14px;
            background: #fafbfc;
        }
        
        input:focus {
            outline: none;
            border-color: #3498db;
            background: white;
        }
        
        .btn-primary {
            padding: 12px 24px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        /* Patient Info Card */
        .patient-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 32px;
            border-radius: 8px;
            margin-bottom: 32px;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 24px;
            align-items: center;
        }
        
        .patient-avatar {
            width: 80px;
            height: 80px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            font-weight: 300;
            border: 3px solid rgba(255,255,255,0.3);
        }
        
        .patient-info h3 {
            font-size: 24px;
            font-weight: 300;
            margin-bottom: 8px;
        }
        
        .patient-meta {
            display: flex;
            gap: 20px;
            font-size: 13px;
            opacity: 0.9;
        }
        
        /* Allergy Cards */
        .allergy-grid {
            display: grid;
            gap: 16px;
            margin-bottom: 32px;
        }
        
        .allergy-card {
            border: 2px solid;
            border-radius: 8px;
            padding: 20px;
            position: relative;
        }
        
        .allergy-card.severe {
            background: #fdeaea;
            border-color: #e74c3c;
        }
        
        .allergy-card.moderate {
            background: #fff3cd;
            border-color: #f39c12;
        }
        
        .allergy-card.mild {
            background: #e8f4f8;
            border-color: #3498db;
        }
        
        .allergy-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 12px;
        }
        
        .allergy-name {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .severity-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .severity-badge.severe {
            background: #e74c3c;
            color: white;
        }
        
        .severity-badge.moderate {
            background: #f39c12;
            color: white;
        }
        
        .severity-badge.mild {
            background: #3498db;
            color: white;
        }
        
        .allergy-details {
            font-size: 13px;
            color: #34495e;
            line-height: 1.6;
        }
        
        .allergy-details strong {
            display: block;
            margin-top: 8px;
            color: #2c3e50;
        }
        
        .category-badge {
            display: inline-block;
            padding: 4px 10px;
            background: rgba(0,0,0,0.1);
            border-radius: 12px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            margin-top: 8px;
        }
        
        /* Conflict Alert */
        .conflict-section {
            background: #fff5f5;
            border: 2px solid #e74c3c;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 32px;
        }
        
        .conflict-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .conflict-icon {
            font-size: 32px;
        }
        
        .conflict-title {
            font-size: 18px;
            font-weight: 600;
            color: #e74c3c;
        }
        
        .conflict-item {
            background: white;
            border-left: 4px solid #e74c3c;
            padding: 16px;
            margin-bottom: 12px;
            border-radius: 4px;
        }
        
        .conflict-drug {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 4px;
        }
        
        .conflict-allergen {
            color: #e74c3c;
            font-size: 13px;
        }
        
        /* Prescription Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        th {
            background: #f8f9fa;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: #7f8c8d;
            font-size: 11px;
            text-transform: uppercase;
            border-bottom: 1px solid #e1e8ed;
        }
        
        td {
            padding: 14px 16px;
            border-bottom: 1px solid #f5f7fa;
            color: #2c3e50;
            font-size: 13px;
            vertical-align: top;
        }
        
        tr:hover {
            background: #fcfdff;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #95a5a6;
            font-style: italic;
        }
        
        .safe-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            background: #d4edda;
            color: #155724;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="header-module">
        <h1>🤧 Patient Allergy Verification</h1>
        <a href="../dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>

    <div class="container">
        <div class="box">
            <h2>Patient Identification</h2>
            <p style="color: #7f8c8d; margin-bottom: 24px;">
                Enter patient DNI to view their allergy profile and check for conflicts with active prescriptions.
            </p>
            <form method="POST">
                <div class="form-group">
                    <label for="patient_dni">Patient DNI (Identity Document Number)</label>
                    <input type="text" id="patient_dni" name="patient_dni" 
                           value="<?= htmlspecialchars($search_term) ?>" 
                           placeholder="Enter DNI to search..." required autofocus>
                </div>
                <button type="submit" name="search_patient" class="btn-primary">🔍 Search Patient</button>
            </form>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($patient): ?>
            <!-- Patient Card -->
            <div class="patient-card">
                <div class="patient-avatar">
                    <?= strtoupper(substr($patient['name'], 0, 1)) ?>
                </div>
                <div class="patient-info">
                    <h3><?= htmlspecialchars($patient['name'] . ' ' . $patient['surname']) ?></h3>
                    <div class="patient-meta">
                        <span>📋 Record: <?= htmlspecialchars($patient['record_number']) ?></span>
                        <span>🆔 DNI: <?= htmlspecialchars($patient['DNI']) ?></span>
                        <span>🎂 Age: <?= calculateAge($patient['birth_date']) ?> years</span>
                        <span><?= $patient['sex'] === 'M' ? '👨 Male' : '👩 Female' ?></span>
                    </div>
                </div>
            </div>

            <!-- Conflict Alerts -->
            <?php if (!empty($allergy_conflicts)): ?>
                <div class="conflict-section">
                    <div class="conflict-header">
                        <div class="conflict-icon">🚨</div>
                        <div>
                            <div class="conflict-title">CRITICAL: Allergy Conflicts Detected</div>
                            <p style="margin: 0; font-size: 13px; color: #c0392b;">
                                <?= count($allergy_conflicts) ?> potential conflict(s) found with active prescriptions
                            </p>
                        </div>
                    </div>
                    
                    <?php foreach ($allergy_conflicts as $conflict): ?>
                        <div class="conflict-item">
                            <div class="conflict-drug">
                                💊 <?= htmlspecialchars($conflict['prescription']['comercial_name']) ?>
                                <small style="color: #7f8c8d;">
                                    (<?= htmlspecialchars($conflict['prescription']['active_principle']) ?>)
                                </small>
                            </div>
                            <div class="conflict-allergen">
                                ⚠️ Conflicts with known allergy: <strong><?= htmlspecialchars($conflict['allergy']['allergen_name']) ?></strong>
                                (Severity: <?= htmlspecialchars($conflict['allergy']['severity']) ?>)
                            </div>
                            <?php if ($conflict['allergy']['reaction']): ?>
                                <div style="margin-top: 8px; font-size: 12px; color: #e74c3c;">
                                    <strong>Known Reaction:</strong> <?= htmlspecialchars($conflict['allergy']['reaction']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <div style="margin-top: 16px; padding: 12px; background: white; border-radius: 4px; font-size: 13px; color: #c0392b;">
                        <strong>⚠️ ACTION REQUIRED:</strong> Do not dispense these medications without physician consultation. 
                        Contact prescribing doctor immediately to discuss alternative treatment options.
                    </div>
                </div>
            <?php elseif (!empty($allergies) && !empty($active_prescriptions)): ?>
                <div class="alert alert-success">
                    ✅ <strong>No conflicts detected</strong> - Patient has allergies but no conflicts with current prescriptions
                </div>
            <?php endif; ?>

            <!-- Registered Allergies -->
            <div class="box">
                <h2>⚠️ Registered Allergies (<?= count($allergies) ?>)</h2>
                
                <?php if (empty($allergies)): ?>
                    <div class="alert alert-success">
                        ✅ <strong>No allergies registered</strong> for this patient
                    </div>
                <?php else: ?>
                    <div class="allergy-grid">
                        <?php foreach ($allergies as $allergy): ?>
                            <div class="allergy-card <?= strtolower($allergy['severity']) ?>">
                                <div class="allergy-header">
                                    <div class="allergy-name">
                                        <?= htmlspecialchars($allergy['allergen_name']) ?>
                                        <span class="category-badge">
                                            <?= htmlspecialchars($allergy['allergen_category']) ?>
                                        </span>
                                    </div>
                                    <span class="severity-badge <?= strtolower($allergy['severity']) ?>">
                                        <?= htmlspecialchars($allergy['severity']) ?>
                                    </span>
                                </div>
                                
                                <div class="allergy-details">
                                    <?php if ($allergy['reaction']): ?>
                                        <strong>Reaction:</strong>
                                        <?= htmlspecialchars($allergy['reaction']) ?>
                                    <?php endif; ?>
                                    
                                    <?php if ($allergy['notes']): ?>
                                        <strong>Additional Notes:</strong>
                                        <?= htmlspecialchars($allergy['notes']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Active Prescriptions -->
            <div class="box">
                <h2>💊 Active Prescriptions (Last 6 Months)</h2>
                
                <?php if (empty($active_prescriptions)): ?>
                    <div class="no-data">No active prescriptions found for this patient</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Medication</th>
                                <th>Active Principle</th>
                                <th>Dosage</th>
                                <th>Prescriber</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_prescriptions as $rx): ?>
                                <tr>
                                    <td><?= date('m/d/Y', strtotime($rx['prescription_date'])) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($rx['comercial_name']) ?></strong>
                                        <br>
                                        <small style="color: #7f8c8d;"><?= htmlspecialchars($rx['code_ATC']) ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($rx['active_principle']) ?></td>
                                    <td>
                                        <?= htmlspecialchars($rx['dose']) ?>
                                        <br>
                                        <small style="color: #7f8c8d;"><?= htmlspecialchars($rx['frequency']) ?></small>
                                    </td>
                                    <td>
                                        Dr. <?= htmlspecialchars($rx['doctor_name'] . ' ' . $rx['doctor_surname']) ?>
                                    </td>
                                    <td>
                                        <?php if ($rx['dispensed_quantity'] > 0): ?>
                                            <span class="safe-badge">
                                                ✓ Dispensed (<?= $rx['dispensed_quantity'] ?>)
                                            </span>
                                        <?php else: ?>
                                            <span style="color: #f39c12; font-weight: 600;">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

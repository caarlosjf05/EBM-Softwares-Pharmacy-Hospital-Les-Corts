<?php
/**
 * DRUG INTERACTIONS CHECKER BY PATIENT
 * File: modules/drug_interactions.php
 */

require_once '../includes/auth.php';

// Access Control
if (!hasAnyRole(['doctor', 'nurse', 'admin', 'pharmacist'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();
$interactions = [];
$error = '';
$patient = null;
$patient_drugs = [];

// Get list of patients with active medications
$stmt = $conn->query("
    SELECT DISTINCT 
        p.patient_id,
        p.name,
        p.surname,
        p.DNI,
        p.record_number
    FROM PATIENT p
    INNER JOIN PRESCRIPTION pr ON p.patient_id = pr.patient_id
    INNER JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
    WHERE pr.date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    ORDER BY p.surname, p.name
");
$all_patients = $stmt->fetchAll();

// Process interaction check by patient
if (isset($_POST['check_patient_interactions'])) {
    $patient_id = $_POST['patient_id'] ?? null;
    
    if ($patient_id) {
        try {
            // Get patient info
            $stmt = $conn->prepare("SELECT * FROM PATIENT WHERE patient_id = :patient_id");
            $stmt->execute(['patient_id' => $patient_id]);
            $patient = $stmt->fetch();
            
            if ($patient) {
                // Get active medications (last 6 months)
                $stmt = $conn->prepare("
                    SELECT DISTINCT 
                        d.drug_id,
                        d.comercial_name,
                        d.active_principle,
                        d.code_ATC
                    FROM PRESCRIPTION pr
                    JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
                    JOIN DRUGS d ON pi.drug_id = d.drug_id
                    WHERE pr.patient_id = :patient_id
                    AND pr.date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                    GROUP BY d.drug_id
                    ORDER BY d.comercial_name
                ");
                $stmt->execute(['patient_id' => $patient_id]);
                $patient_drugs = $stmt->fetchAll();
                
                // Check all combinations
                for ($i = 0; $i < count($patient_drugs); $i++) {
                    for ($j = $i + 1; $j < count($patient_drugs); $j++) {
                        $drug1 = $patient_drugs[$i];
                        $drug2 = $patient_drugs[$j];
                        
                        // Search interaction in DB
                        $stmt = $conn->prepare("
                            SELECT severity, description, recommendation
                            FROM DRUG_INTERACTIONS
                            WHERE 
                                (drug_id_a = :drug1 AND drug_id_b = :drug2)
                                OR
                                (drug_id_a = :drug2 AND drug_id_b = :drug1)
                            LIMIT 1
                        ");
                        $stmt->execute([
                            'drug1' => $drug1['drug_id'],
                            'drug2' => $drug2['drug_id']
                        ]);
                        
                        $interaction = $stmt->fetch();
                        
                        if ($interaction && strtolower($interaction['severity']) !== 'none') {
                            $interactions[] = [
                                'drug1' => $drug1,
                                'drug2' => $drug2,
                                'severity' => $interaction['severity'],
                                'description' => $interaction['description'],
                                'recommendation' => $interaction['recommendation']
                            ];
                        }
                    }
                }
                
                if (empty($interactions)) {
                    // Success message handled in HTML
                }
            } else {
                $error = 'Patient not found';
            }
        } catch (Exception $e) {
            $error = 'Error: ' . $e->getMessage();
        }
    } else {
        $error = 'Please select a patient';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drug Interaction Checker - Hospital les Corts</title>
    <style>
        /* [Keeping your original CSS structure for consistency] */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .header h1 { font-size: 18px; font-weight: 400; color: #2c3e50; }
        .back-btn { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 12px; text-transform: uppercase; }
        .container { max-width: 1200px; margin: 0 auto; padding: 32px; }
        .box { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 32px; margin-bottom: 24px; }
        .box h2 { font-size: 16px; font-weight: 500; color: #2c3e50; margin-bottom: 24px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #2c3e50; font-weight: 500; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        select { width: 100%; padding: 12px 16px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; background: #fafbfc; }
        select:focus { outline: none; border-color: #3498db; background: white; }
        button { padding: 12px 24px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; }
        button:hover { background: #2980b9; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; }
        .alert-error { background: #fee; border-left: 3px solid #e74c3c; color: #c0392b; }
        .alert-success { background: #d4edda; border-left: 3px solid #27ae60; color: #155724; }
        .patient-info { background: #fafbfc; border: 1px solid #e1e8ed; padding: 20px; border-radius: 4px; margin-bottom: 24px; }
        .patient-info-title { font-size: 12px; font-weight: 500; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }
        .patient-info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .patient-info-item { font-size: 13px; }
        .patient-info-label { color: #7f8c8d; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .patient-info-value { color: #2c3e50; font-weight: 500; }
        .drugs-list { background: #fafbfc; border: 1px solid #e1e8ed; padding: 20px; border-radius: 4px; margin-bottom: 24px; }
        .drugs-list h3 { font-size: 14px; color: #2c3e50; margin-bottom: 16px; }
        .drug-item { padding: 12px; background: white; border: 1px solid #e1e8ed; border-radius: 4px; margin-bottom: 8px; font-size: 13px; }
        .drug-name { font-weight: 600; color: #2c3e50; }
        .drug-principle { color: #7f8c8d; font-size: 12px; }
        .interaction-card { border-left: 4px solid #e74c3c; padding: 20px; margin-bottom: 16px; border-radius: 4px; background: white; }
        .interaction-card.severe { border-left-color: #e74c3c; background: #fdeaea; }
        .interaction-card.moderate { border-left-color: #f39c12; background: #fff3cd; }
        .interaction-card.minor { border-left-color: #3498db; background: #e8f4f8; }
        .severity-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }
        .severity-severe { background: #e74c3c; color: white; }
        .severity-moderate { background: #f39c12; color: white; }
        .severity-minor { background: #3498db; color: white; }
        .drug-names { font-size: 14px; font-weight: 600; color: #2c3e50; margin: 8px 0; }
        .interaction-description { font-size: 13px; color: #34495e; line-height: 1.6; margin-top: 8px; }
        .recommendation { font-size: 13px; color: #e74c3c; font-weight: 600; margin-top: 8px; padding: 8px; background: rgba(231, 76, 60, 0.1); border-radius: 4px; }
        .stats-box { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 20px; text-align: center; }
        .stat-label { color: #7f8c8d; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-number { font-size: 32px; font-weight: 300; color: #2c3e50; }
        .stat-card.warning .stat-number { color: #f39c12; }
        .stat-card.danger .stat-number { color: #e74c3c; }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <h1>🔍 Drug Interaction Checker</h1>
        </div>
        <a href="../dashboard.php" class="back-btn">Back to Dashboard</a>
    </div>

    <div class="container">
        <div class="box">
            <h2>Check Interactions by Patient</h2>
            <p style="color: #7f8c8d; margin-bottom: 24px; font-size: 13px;">
                Select a patient to check for potential interactions between their currently active medications.
            </p>
            
            <form method="POST">
                <div class="form-group">
                    <label>Patient</label>
                    <select name="patient_id" required>
                        <option value="">Select a patient...</option>
                        <?php foreach ($all_patients as $p): ?>
                            <option value="<?= $p['patient_id'] ?>" <?= ($patient && $patient['patient_id'] == $p['patient_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['surname'] . ', ' . $p['name'] . ' - DNI: ' . $p['DNI']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" name="check_patient_interactions">Check Interactions</button>
            </form>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($patient): ?>
            <div class="patient-info">
                <div class="patient-info-title">Patient Information</div>
                <div class="patient-info-grid">
                    <div class="patient-info-item">
                        <div class="patient-info-label">Full Name</div>
                        <div class="patient-info-value"><?= htmlspecialchars($patient['name'] . ' ' . $patient['surname']) ?></div>
                    </div>
                    <div class="patient-info-item">
                        <div class="patient-info-label">DNI</div>
                        <div class="patient-info-value"><?= htmlspecialchars($patient['DNI']) ?></div>
                    </div>
                    <div class="patient-info-item">
                        <div class="patient-info-label">Record Number</div>
                        <div class="patient-info-value"><?= htmlspecialchars($patient['record_number']) ?></div>
                    </div>
                    <div class="patient-info-item">
                        <div class="patient-info-label">Date of Birth</div>
                        <div class="patient-info-value"><?= htmlspecialchars($patient['birth_date']) ?></div>
                    </div>
                </div>
            </div>

            <div class="stats-box">
                <div class="stat-card">
                    <div class="stat-label">Active Medications</div>
                    <div class="stat-number"><?= count($patient_drugs) ?></div>
                </div>
                <div class="stat-card <?= !empty($interactions) ? 'danger' : '' ?>">
                    <div class="stat-label">Interactions Found</div>
                    <div class="stat-number"><?= count($interactions) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Status</div>
                    <div class="stat-number" style="font-size: 24px;">
                        <?= empty($interactions) ? '✅' : '⚠️' ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($patient_drugs)): ?>
                <div class="drugs-list">
                    <h3>💊 Active Medications (Last 6 Months)</h3>
                    <?php foreach ($patient_drugs as $drug): ?>
                        <div class="drug-item">
                            <div class="drug-name"><?= htmlspecialchars($drug['comercial_name']) ?></div>
                            <div class="drug-principle">
                                <?= htmlspecialchars($drug['active_principle']) ?>
                                <?php if ($drug['code_ATC']): ?>
                                    • ATC Code: <?= htmlspecialchars($drug['code_ATC']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($interactions)): ?>
                <div class="box">
                    <h2>⚠️ Detected Interactions</h2>
                    
                    <?php foreach ($interactions as $interaction): ?>
                        <div class="interaction-card <?= strtolower($interaction['severity']) ?>">
                            <span class="severity-badge severity-<?= strtolower($interaction['severity']) ?>">
                                <?= strtoupper($interaction['severity']) ?>
                            </span>
                            
                            <div class="drug-names">
                                🔴 <?= htmlspecialchars($interaction['drug1']['comercial_name']) ?>
                                <br>
                                <small style="color: #7f8c8d; font-weight: normal;">
                                    (<?= htmlspecialchars($interaction['drug1']['active_principle']) ?>)
                                </small>
                                <br><br>
                                ⚡ INTERACTION WITH
                                <br><br>
                                🔴 <?= htmlspecialchars($interaction['drug2']['comercial_name']) ?>
                                <br>
                                <small style="color: #7f8c8d; font-weight: normal;">
                                    (<?= htmlspecialchars($interaction['drug2']['active_principle']) ?>)
                                </small>
                            </div>

                            <div class="interaction-description">
                                <?= htmlspecialchars($interaction['description']) ?>
                            </div>

                            <?php if (!empty($interaction['recommendation'])): ?>
                                <div class="recommendation">
                                    📋 Recommendation: <?= htmlspecialchars($interaction['recommendation']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($patient): ?>
                <div class="alert alert-success">
                    ✅ No harmful interactions detected for this patient's medications.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>

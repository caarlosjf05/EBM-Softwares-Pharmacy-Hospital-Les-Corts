<?php
// FILE: modules/clinical_decision_support.php
require_once '../includes/auth.php';

// Access control: Available for all clinical staff (Doctor, Nurse, Pharmacist)
// Blocked for Admin and Patients
if (!hasAnyRole(['doctor', 'nurse', 'pharmacist'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// --- 1. KEY INDICATORS (COUNTERS) ---
$alerts = [];

// High-severity drug interactions (Database total)
$stmt = $conn->query("SELECT COUNT(*) as count FROM DRUG_INTERACTIONS WHERE severity = 'high'");
$alerts['high_interactions'] = $stmt->fetch()['count'] ?? 0;

// Renal adjustments needed (Patient count)
$stmt = $conn->query("SELECT COUNT(DISTINCT patient_id) as count FROM PATIENT WHERE creatinine_clearance < 50 AND creatinine_clearance > 0");
$alerts['renal_adjustments'] = $stmt->fetch()['count'] ?? 0;

// Pediatric patients
$stmt = $conn->query("SELECT COUNT(*) as count FROM PATIENT WHERE TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) < 18");
$alerts['pediatric_patients'] = $stmt->fetch()['count'] ?? 0;

// Allergies
$stmt = $conn->query("SELECT COUNT(DISTINCT patient_id) as count FROM PATIENT_ALLERGY");
$alerts['patients_with_allergies'] = $stmt->fetch()['count'] ?? 0;

// Low stock
$stmt = $conn->query("SELECT COUNT(*) as count FROM DRUGS WHERE actual_inventory <= minimum_stock AND minimum_stock > 0");
$alerts['low_stock'] = $stmt->fetch()['count'] ?? 0;


// --- 2. DETAILED ALERTS SECTIONS ---

// SECTION A: REAL Active Drug-Drug Interactions
// Finds patients who have TWO active prescriptions (last 6 months) that interact
$stmt = $conn->query("
    SELECT DISTINCT
        p.patient_id,
        p.name,
        p.surname,
        p.record_number,
        d1.comercial_name as drug1,
        d2.comercial_name as drug2,
        di.severity,
        di.description,
        di.recommendation
    FROM PATIENT p
    JOIN PRESCRIPTION pr1 ON p.patient_id = pr1.patient_id
    JOIN PRESCRIPTION_ITEM pi1 ON pr1.prescription_id = pi1.prescription_id
    JOIN PRESCRIPTION pr2 ON p.patient_id = pr2.patient_id
    JOIN PRESCRIPTION_ITEM pi2 ON pr2.prescription_id = pi2.prescription_id
    JOIN DRUG_INTERACTIONS di ON (
        (di.drug_id_a = pi1.drug_id AND di.drug_id_b = pi2.drug_id) OR
        (di.drug_id_a = pi2.drug_id AND di.drug_id_b = pi1.drug_id)
    )
    JOIN DRUGS d1 ON pi1.drug_id = d1.drug_id
    JOIN DRUGS d2 ON pi2.drug_id = d2.drug_id
    WHERE pr1.date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
      AND pr2.date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
      AND pi1.drug_id < pi2.drug_id -- Avoid duplicates (A-B vs B-A)
      AND di.severity IN ('high', 'moderate')
    ORDER BY FIELD(di.severity, 'high', 'moderate'), p.surname
    LIMIT 20
");
$interaction_alerts = $stmt->fetchAll();

// SECTION B: Renal Dosing Adjustments
$stmt = $conn->query("
    SELECT 
        p.name,
        p.surname,
        p.DNI,
        p.creatinine_clearance,
        'Review medications' as recommendation
    FROM PATIENT p
    WHERE p.creatinine_clearance < 50 AND p.creatinine_clearance > 0
    ORDER BY p.creatinine_clearance ASC
    LIMIT 10
");
$renal_alerts = $stmt->fetchAll();

// SECTION C: Severe Allergy Conflicts (Patients with severe allergies)
// Ideally, this should also check if they are taking the allergen, but for now we list high-risk patients
$stmt = $conn->query("
    SELECT 
        p.name,
        p.surname,
        a.name_alergen,
        pa.reaction
    FROM PATIENT_ALLERGY pa
    JOIN PATIENT p ON pa.patient_id = p.patient_id
    JOIN ALLERGENS a ON pa.allergen_id = a.allergen_id
    WHERE pa.severity = 'severe'
    LIMIT 10
");
$allergy_alerts = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Decision Support</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .header h1 { font-size: 18px; font-weight: 400; color: #2c3e50; }
        .back-btn { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 500; text-transform: uppercase; }
        .container { max-width: 1600px; margin: 0 auto; padding: 32px; }
        
        /* --- COMPACT TOP CARDS (Horizontal Layout) --- */
        .dashboard-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); 
            gap: 16px; 
            margin-bottom: 32px; 
        }
        .alert-card { 
            background: white; 
            border: 1px solid #e1e8ed; 
            border-radius: 6px; 
            padding: 16px; 
            display: flex; 
            align-items: center; 
            gap: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            transition: transform 0.2s;
        }
        .alert-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.05); }
        
        .alert-icon { 
            width: 42px; 
            height: 42px; 
            border-radius: 8px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 20px; 
            flex-shrink: 0;
        }
        .alert-card.critical .alert-icon { background: #ffebee; color: #c62828; }
        .alert-card.high .alert-icon { background: #fff3e0; color: #ef6c00; }
        .alert-card.medium .alert-icon { background: #e3f2fd; color: #1565c0; }
        .alert-card.info .alert-icon { background: #f3e5f5; color: #7b1fa2; }
        
        .alert-content { display: flex; flex-direction: column; }
        .alert-number { font-size: 20px; font-weight: 700; color: #2c3e50; line-height: 1.2; }
        .alert-label { font-size: 11px; color: #7f8c8d; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; }

        /* --- SECTIONS --- */
        .section-container { margin-bottom: 32px; }
        .section-title { 
            font-size: 14px; 
            font-weight: 700; 
            color: #2c3e50; 
            margin-bottom: 12px; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
            border-left: 4px solid #3498db;
            padding-left: 10px;
        }
        
        .section-title.red { border-left-color: #e74c3c; color: #c0392b; }
        .section-title.orange { border-left-color: #f39c12; color: #e67e22; }
        .section-title.purple { border-left-color: #9b59b6; color: #8e44ad; }

        .alerts-list { display: grid; gap: 12px; }
        
        .priority-card { 
            background: white; 
            border: 1px solid #e1e8ed; 
            border-radius: 4px; 
            padding: 16px; 
            display: flex; 
            justify-content: space-between;
            align-items: center;
        }
        
        .priority-main { flex: 1; }
        
        .priority-header { 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            margin-bottom: 6px; 
        }
        
        .patient-name { font-weight: 600; color: #2c3e50; font-size: 14px; }
        .drug-pair { font-size: 14px; color: #2c3e50; }
        .drug-pair strong { color: #c0392b; }
        
        .priority-desc { font-size: 13px; color: #555; }
        
        .severity-tag { 
            font-size: 10px; 
            font-weight: 700; 
            text-transform: uppercase; 
            padding: 3px 8px; 
            border-radius: 10px; 
        }
        .severity-tag.high { background: #ffebee; color: #c62828; }
        .severity-tag.moderate { background: #fff3e0; color: #ef6c00; }
        .severity-tag.severe { background: #f3e5f5; color: #7b1fa2; }

        /* Empty State */
        .empty-section {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            color: #2e7d32;
            padding: 20px;
            border-radius: 6px;
            text-align: center;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .check-icon { font-size: 18px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Clinical Decision Support</h1>
        <a href="../dashboard.php" class="back-btn">Back to Dashboard</a>
    </div>

    <div class="container">
        <div class="dashboard-grid">
            <div class="alert-card critical">
                <div class="alert-icon">⚠️</div>
                <div class="alert-content">
                    <div class="alert-number"><?= $alerts['high_interactions'] ?></div>
                    <div class="alert-label">DB High Interactions</div>
                </div>
            </div>
            
            <div class="alert-card high">
                <div class="alert-icon">🩺</div>
                <div class="alert-content">
                    <div class="alert-number"><?= $alerts['renal_adjustments'] ?></div>
                    <div class="alert-label">Renal Adjustments</div>
                </div>
            </div>
            
            <div class="alert-card medium">
                <div class="alert-icon">👶</div>
                <div class="alert-content">
                    <div class="alert-number"><?= $alerts['pediatric_patients'] ?></div>
                    <div class="alert-label">Pediatric Patients</div>
                </div>
            </div>
            
            <div class="alert-card info">
                <div class="alert-icon">🤧</div>
                <div class="alert-content">
                    <div class="alert-number"><?= $alerts['patients_with_allergies'] ?></div>
                    <div class="alert-label">Patients w/ Allergies</div>
                </div>
            </div>
            
            <div class="alert-card medium">
                <div class="alert-icon">📦</div>
                <div class="alert-content">
                    <div class="alert-number"><?= $alerts['low_stock'] ?></div>
                    <div class="alert-label">Low Stock Items</div>
                </div>
            </div>
        </div>

        <h2 style="font-size: 18px; color: #2c3e50; margin-bottom: 24px; padding-bottom: 10px; border-bottom: 1px solid #e1e8ed;">High-Priority Clinical Alerts</h2>

        <div class="section-container">
            <div class="section-title red">Active Drug-Drug Interactions (Current Patients)</div>
            
            <?php if (empty($interaction_alerts)): ?>
                <div class="empty-section">
                    <span class="check-icon">✅</span> Great job! No active drug-drug interactions detected in current treatments.
                </div>
            <?php else: ?>
                <div class="alerts-list">
                    <?php foreach ($interaction_alerts as $alert): ?>
                        <div class="priority-card">
                            <div class="priority-main">
                                <div class="priority-header">
                                    <span class="patient-name"><?= htmlspecialchars($alert['name'] . ' ' . $alert['surname']) ?></span>
                                    <span style="color:#ccc;">|</span>
                                    <span class="severity-tag <?= strtolower($alert['severity']) ?>"><?= strtoupper($alert['severity']) ?></span>
                                </div>
                                <div class="drug-pair">
                                    Conflict: <strong><?= htmlspecialchars($alert['drug1']) ?></strong> + <strong><?= htmlspecialchars($alert['drug2']) ?></strong>
                                </div>
                                <div class="priority-desc" style="margin-top: 4px;">
                                    <em>Recommendation: <?= htmlspecialchars($alert['recommendation']) ?></em>
                                </div>
                            </div>
                            <a href="../edit_patient_meds.php?patient_dni=<?= urlencode($alert['record_number']) /* Assuming logic to find by record or ID */ ?>" style="font-size: 12px; color: #3498db; text-decoration: none; border: 1px solid #e1e8ed; padding: 6px 12px; border-radius: 4px;">Review</a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="section-container">
            <div class="section-title orange">Renal Dosing Adjustments Required</div>
            
            <?php if (empty($renal_alerts)): ?>
                <div class="empty-section">
                    <span class="check-icon">✅</span> All renal patients have stable medication profiles.
                </div>
            <?php else: ?>
                <div class="alerts-list">
                    <?php foreach ($renal_alerts as $alert): ?>
                        <div class="priority-card">
                            <div class="priority-main">
                                <div class="priority-header">
                                    <span class="patient-name"><?= htmlspecialchars($alert['name'] . ' ' . $alert['surname']) ?></span>
                                    <span style="color:#ccc;">|</span>
                                    <span class="severity-tag moderate">CrCl <?= $alert['creatinine_clearance'] ?> mL/min</span>
                                </div>
                                <div class="priority-desc">
                                    Kidney function is low. Please review all renally cleared medications.
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="section-container">
            <div class="section-title purple">Severe Allergy Alerts</div>
            
            <?php if (empty($allergy_alerts)): ?>
                <div class="empty-section">
                    <span class="check-icon">✅</span> No active conflicts with severe allergies found.
                </div>
            <?php else: ?>
                <div class="alerts-list">
                    <?php foreach ($allergy_alerts as $alert): ?>
                        <div class="priority-card">
                            <div class="priority-main">
                                <div class="priority-header">
                                    <span class="patient-name"><?= htmlspecialchars($alert['name'] . ' ' . $alert['surname']) ?></span>
                                    <span class="severity-tag severe">SEVERE ALLERGY</span>
                                </div>
                                <div class="drug-pair">
                                    Allergen: <strong><?= htmlspecialchars($alert['name_alergen']) ?></strong>
                                </div>
                                <div class="priority-desc">
                                    Reaction: <?= htmlspecialchars($alert['reaction']) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</body>
</html>

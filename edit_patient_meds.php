<?php
// FILE: edit_patient_meds.php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Authorization: All professional roles can VIEW
if (!hasAnyRole(['admin', 'doctor', 'nurse', 'pharmacist'])) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$patient = null;
$patient_matches = []; 
$patient_medications = [];
$error = '';
$search_term = '';

// --- LOGIC 1: DIRECT ACCESS VIA ID (From Doctor's Prescribe Page) ---
if (isset($_GET['patient_id'])) {
    $stmt = $conn->prepare("SELECT * FROM PATIENT WHERE patient_id = :id");
    $stmt->execute(['id' => $_GET['patient_id']]);
    $patient = $stmt->fetch();
    if (!$patient) $error = "Patient ID not found.";
}

// --- LOGIC 2: SEARCH BY NAME OR DNI ---
if (isset($_POST['search_patient'])) {
    $search_term = trim($_POST['search_term']);
    
    // Buscar por DNI exacto O coincidencias en Nombre/Apellido
    $stmt = $conn->prepare("
        SELECT * FROM PATIENT 
        WHERE DNI LIKE :search 
           OR name LIKE :wildcard 
           OR surname LIKE :wildcard
    ");
    $stmt->execute([
        'search' => $search_term,
        'wildcard' => "%$search_term%"
    ]);
    $results = $stmt->fetchAll();

    if (count($results) == 1) {
        $patient = $results[0]; // Solo uno encontrado
    } elseif (count($results) > 1) {
        $patient_matches = $results; // Varios encontrados
    } else {
        $error = 'No patient found matching "' . htmlspecialchars($search_term) . '"';
    }
}

// --- LOGIC 3: SELECT FROM LIST ---
if (isset($_POST['select_patient_id'])) {
    $stmt = $conn->prepare("SELECT * FROM PATIENT WHERE patient_id = :id");
    $stmt->execute(['id' => $_POST['select_patient_id']]);
    $patient = $stmt->fetch();
}

// --- IF PATIENT FOUND, LOAD HISTORY ---
if ($patient) {
    // Get Prescriptions + Dispensing Info
    $stmt = $conn->prepare("
        SELECT 
            pr.prescription_id,
            pr.date as prescription_date,
            pr.indications,
            pi.prescription_item_id,
            pi.dose,
            pi.frequency,
            pi.duration,
            d.comercial_name,
            d.active_principle,
            d.code_ATC,
            prof.name as doctor_name,
            prof.surname as doctor_surname,
            diag.disease_name,
            COALESCE(SUM(disp.quantity), 0) as dispensed_quantity,
            (SELECT MAX(administered_at) FROM ADMINISTRATION a 
             JOIN DISPENSING d2 ON a.dispensing_id = d2.dispensing_id 
             WHERE d2.prescription_item_id = pi.prescription_item_id) as last_admin
        FROM PRESCRIPTION pr
        JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
        JOIN DRUGS d ON pi.drug_id = d.drug_id
        JOIN PROFESSIONAL prof ON pr.professional_id = prof.professional_id
        LEFT JOIN DIAGNOSTICS diag ON pr.diagnostic_id = diag.diagnostic_id
        LEFT JOIN DISPENSING disp ON pi.prescription_item_id = disp.prescription_item_id
        WHERE pr.patient_id = :patient_id
        GROUP BY pi.prescription_item_id
        ORDER BY pr.date DESC, d.comercial_name ASC
    ");
    $stmt->execute(['patient_id' => $patient['patient_id']]);
    $patient_medications = $stmt->fetchAll();
}

// *** CORRECCIÓN: Eliminada la función formatDate() duplicada *** ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Prescription Review</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; }
        
        .header-module { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .header-module h1 { font-size: 18px; font-weight: 400; color: #2c3e50; }
        
        .container { max-width: 1200px; margin: 0 auto; padding: 32px; }
        .box { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 32px; margin-bottom: 24px; }
        
        .btn-back { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 500; text-transform: uppercase; }
        .btn-primary { padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 4px; cursor: pointer; }
        
        .alert-error { background: #fee; border-left: 3px solid #e74c3c; color: #c0392b; padding: 15px; margin-bottom: 20px; }
        
        /* SEARCH & LIST STYLES */
        input[type="text"] { width: 100%; padding: 10px; border: 1px solid #dce1e6; border-radius: 4px; }
        .results-list { list-style: none; padding: 0; border: 1px solid #e1e8ed; border-radius: 4px; }
        .result-item { padding: 15px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .result-item:last-child { border-bottom: none; }
        .result-item:hover { background: #f9f9f9; }
        .btn-select { background: #27ae60; color: white; padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; }

        /* PATIENT INFO CARD */
        .patient-info { background: #fafbfc; border: 1px solid #e1e8ed; padding: 20px; border-radius: 4px; margin-bottom: 24px; }
        .patient-info-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e1e8ed; padding-bottom: 15px; margin-bottom: 15px; }
        .patient-name-large { font-size: 18px; font-weight: 700; color: #2c3e50; }
        
        .patient-info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
        .label { color: #7f8c8d; font-size: 11px; text-transform: uppercase; display: block; margin-bottom: 4px; }
        .value { color: #2c3e50; font-weight: 500; }
        
        /* ACTION BUTTONS */
        .btn-dashboard { background: #8e44ad; color: white; text-decoration: none; padding: 8px 12px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; display: inline-flex; align-items: center; gap: 5px; }
        .btn-dashboard:hover { background: #732d91; }
        
        .btn-add-rx { background: #27ae60; color: white; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-size: 12px; font-weight: 700; text-transform: uppercase; }
        .btn-add-rx:hover { background: #219150; }

        /* TABLE */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #f8f9fa; padding: 12px; text-align: left; color: #7f8c8d; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #e1e8ed; }
        td { padding: 14px 12px; border-bottom: 1px solid #f5f7fa; color: #2c3e50; font-size: 13px; vertical-align: top; }
        .status-badge { padding: 4px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; text-transform: uppercase; }
        .status-dispensed { background: #e8f8f5; color: #2ecc71; }
        .status-pending { background: #fff3e0; color: #f39c12; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1>💊 Patient Prescription Review</h1>
        <a href="dashboard.php" class="btn-back">Dashboard</a>
    </div>

    <div class="container">
        <div class="box">
            <h2 style="margin-top:0; font-size:16px; color:#2c3e50;">Find Patient</h2>
            <form method="POST" style="display:flex; gap:10px;">
                <input type="text" name="search_term" placeholder="Enter Name, Surname or DNI..." value="<?= htmlspecialchars($search_term) ?>" required style="flex:1;">
                <button type="submit" name="search_patient" class="btn-primary">Search</button>
            </form>

            <?php if (!empty($patient_matches)): ?>
                <h3 style="margin-top:20px; font-size:14px;">Select Patient:</h3>
                <ul class="results-list">
                    <?php foreach ($patient_matches as $match): ?>
                        <li class="result-item">
                            <div>
                                <strong><?= htmlspecialchars($match['name'] . ' ' . $match['surname']) ?></strong>
                                <br><span style="font-size:12px; color:#7f8c8d;">DNI: <?= $match['DNI'] ?></span>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="select_patient_id" value="<?= $match['patient_id'] ?>">
                                <button type="submit" class="btn-select">Select</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        
        <?php if ($error): ?><div class="alert-error">⚠️ <?= $error ?></div><?php endif; ?>

        <?php if ($patient): ?>
            <div class="patient-info">
                <div class="patient-info-header">
                    <div class="patient-name-large">
                        <?= htmlspecialchars($patient['name'] . ' ' . $patient['surname']) ?>
                    </div>
                    <a href="patient_dashboard.php?patient_id=<?= $patient['patient_id'] ?>" target="_blank" class="btn-dashboard">
                        📊 Full Medical History
                    </a>
                </div>
                
                <div class="patient-info-grid">
                    <div><span class="label">DNI</span><span class="value"><?= htmlspecialchars($patient['DNI']) ?></span></div>
                    <div><span class="label">Record #</span><span class="value"><?= htmlspecialchars($patient['record_number']) ?></span></div>
                    <div><span class="label">DOB</span><span class="value"><?= formatDate($patient['birth_date']) ?></span></div>
                    <div><span class="label">Blood Type</span><span class="value"><?= htmlspecialchars($patient['blood_type'] ?? '-') ?></span></div>
                </div>
            </div>

            <div class="box">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2 style="margin:0;">Prescription History</h2>
                    
                    <?php if (hasRole('doctor')): ?>
                        <a href="modules/prescribe.php?patient_id=<?= $patient['patient_id'] ?>" class="btn-add-rx">
                            + New Prescription
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($patient_medications)): ?>
                    <p style="color:#95a5a6; font-style:italic;">No medication history found.</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Medication</th>
                                <th>Dose / Freq</th>
                                <th>Prescriber</th>
                                <th>Dispensed?</th>
                                <th>Last Admin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($patient_medications as $med): ?>
                                <tr>
                                    <td><?= formatDate($med['prescription_date']) ?></td>
                                    <td>
                                        <strong><?= htmlspecialchars($med['comercial_name']) ?></strong><br>
                                        <span style="color:#7f8c8d; font-size:11px;"><?= htmlspecialchars($med['active_principle']) ?></span>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($med['dose']) ?><br>
                                        <small><?= htmlspecialchars($med['frequency']) ?></small>
                                    </td>
                                    <td>Dr. <?= htmlspecialchars($med['doctor_surname']) ?></td>
                                    <td>
                                        <?php if ($med['dispensed_quantity'] > 0): ?>
                                            <span class="status-badge status-dispensed">Yes (<?= $med['dispensed_quantity'] ?>)</span>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $med['last_admin'] ? date('d/m H:i', strtotime($med['last_admin'])) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
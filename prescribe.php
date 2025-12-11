<?php
// FILE: modules/prescribe.php
require_once 'includes/auth.php';
requireRole('doctor');

$conn = getDBConnection();

$success = false;
$error = '';
$patient = null;
$patient_matches = []; // Para manejar múltiples resultados
$diagnostics = [];
$drugs_raw = [];

// 1. Get Diagnostics
$stmt = $conn->query("SELECT diagnostic_id, disease_name, code_ICD_10 FROM DIAGNOSTICS ORDER BY disease_name");
$diagnostics = $stmt->fetchAll();

// 2. GET DRUGS (Raw List for JS Grouping)
$stmt = $conn->query("
    SELECT drug_id, comercial_name, active_principle, code_ATC, standard_concentration, presentation 
    FROM DRUGS 
    WHERE actual_inventory > 0 
    ORDER BY comercial_name, standard_concentration
");
$drugs_raw = $stmt->fetchAll();

// Professional ID
$stmt = $conn->prepare("SELECT professional_id FROM PROFESSIONAL WHERE email = :email");
$stmt->execute(['email' => $current_user_email]);
$professional_data = $stmt->fetch();
$professional_id = $professional_data['professional_id'];

// --- LOGICA DE BÚSQUEDA DE PACIENTE (MEJORADA) ---

// Caso A: El usuario envía un término de búsqueda
if (isset($_POST['search_patient'])) {
    $search_term = trim($_POST['patient_search']);
    
    // Busca por DNI exacto, Nº Historia exacto, O coincidencias en Nombre/Apellido
    $stmt = $conn->prepare("
        SELECT patient_id, name, surname, DNI, record_number, birth_date 
        FROM PATIENT 
        WHERE DNI LIKE :search 
           OR record_number LIKE :search 
           OR name LIKE :wildcard 
           OR surname LIKE :wildcard
    ");
    $stmt->execute([
        'search' => $search_term,
        'wildcard' => "%$search_term%"
    ]);
    $results = $stmt->fetchAll();
    
    if (count($results) == 1) {
        // Solo un resultado: Selección automática
        $patient = $results[0];
    } elseif (count($results) > 1) {
        // Múltiples resultados: Guardamos para mostrar lista
        $patient_matches = $results;
    } else {
        $error = 'Patient not found matching: ' . htmlspecialchars($search_term);
    }
}

// Caso B: El usuario selecciona un paciente específico de la lista de coincidencias
if (isset($_POST['select_patient_from_list'])) {
    $selected_id = $_POST['selected_patient_id'];
    $stmt = $conn->prepare("SELECT * FROM PATIENT WHERE patient_id = :id");
    $stmt->execute(['id' => $selected_id]);
    $patient = $stmt->fetch();
}


// --- AJAX ENDPOINTS (SEGURIDAD COMPLETA) ---

// 1. Check Interactions
if (isset($_GET['check_interactions'])) {
    header('Content-Type: application/json');
    
    $patient_id = intval($_GET['patient_id'] ?? 0);
    $drug_ids = json_decode($_GET['drug_ids'] ?? '[]');
    $all_interactions = [];
    
    try {
        // Get patient's existing medications (last 6 months)
        $stmt = $conn->prepare("
            SELECT DISTINCT d.drug_id, d.comercial_name
            FROM PRESCRIPTION pr
            JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
            JOIN DRUGS d ON pi.drug_id = d.drug_id
            WHERE pr.patient_id = :patient_id
            AND pr.date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        ");
        $stmt->execute(['patient_id' => $patient_id]);
        $existing_drugs = $stmt->fetchAll();
        
        $interaction_pairs = [];
        
        // Check new vs existing
        foreach ($drug_ids as $new_drug_id) {
            foreach ($existing_drugs as $existing) {
                if ($new_drug_id == $existing['drug_id']) continue;
                
                $pair_key = min($new_drug_id, $existing['drug_id']) . '-' . max($new_drug_id, $existing['drug_id']);
                if (isset($interaction_pairs[$pair_key])) continue;
                
                $stmt = $conn->prepare("
                    SELECT severity, description, recommendation,
                           d1.comercial_name as drug_a, d2.comercial_name as drug_b
                    FROM DRUG_INTERACTIONS di
                    JOIN DRUGS d1 ON di.drug_id_a = d1.drug_id
                    JOIN DRUGS d2 ON di.drug_id_b = d2.drug_id
                    WHERE ((drug_id_a = :drug1 AND drug_id_b = :drug2) OR (drug_id_a = :drug2 AND drug_id_b = :drug1))
                    AND severity IN ('high', 'moderate')
                ");
                $stmt->execute(['drug1' => $new_drug_id, 'drug2' => $existing['drug_id']]);
                $interaction = $stmt->fetch();
                
                if ($interaction) {
                    $interaction_pairs[$pair_key] = true;
                    $all_interactions[] = $interaction;
                }
            }
        }
        
        // Check new vs new
        for ($i = 0; $i < count($drug_ids); $i++) {
            for ($j = $i + 1; $j < count($drug_ids); $j++) {
                $pair_key = min($drug_ids[$i], $drug_ids[$j]) . '-' . max($drug_ids[$i], $drug_ids[$j]);
                if (isset($interaction_pairs[$pair_key])) continue;
                
                $stmt = $conn->prepare("
                    SELECT severity, description, recommendation,
                           d1.comercial_name as drug_a, d2.comercial_name as drug_b
                    FROM DRUG_INTERACTIONS di
                    JOIN DRUGS d1 ON di.drug_id_a = d1.drug_id
                    JOIN DRUGS d2 ON di.drug_id_b = d2.drug_id
                    WHERE ((drug_id_a = :drug1 AND drug_id_b = :drug2) OR (drug_id_a = :drug2 AND drug_id_b = :drug1))
                    AND severity IN ('high', 'moderate')
                ");
                $stmt->execute(['drug1' => $drug_ids[$i], 'drug2' => $drug_ids[$j]]);
                $interaction = $stmt->fetch();
                
                if ($interaction) {
                    $interaction_pairs[$pair_key] = true;
                    $all_interactions[] = $interaction;
                }
            }
        }
        echo json_encode(['success' => true, 'interactions' => $all_interactions]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// 2. Check Allergies
if (isset($_GET['check_allergies'])) {
    header('Content-Type: application/json');
    $patient_id = intval($_GET['patient_id'] ?? 0);
    $drug_ids = json_decode($_GET['drug_ids'] ?? '[]');
    $all_allergy_conflicts = [];
    
    try {
        $stmt = $conn->prepare("
            SELECT pa.patient_allergy_id, a.allergen_id, a.name_alergen, a.category, pa.severity, pa.reaction, pa.notes
            FROM PATIENT_ALLERGY pa
            JOIN ALLERGENS a ON pa.allergen_id = a.allergen_id
            WHERE pa.patient_id = :patient_id
        ");
        $stmt->execute(['patient_id' => $patient_id]);
        $patient_allergies = $stmt->fetchAll();
        
        foreach ($drug_ids as $drug_id) {
            $stmt = $conn->prepare("SELECT comercial_name, active_principle FROM DRUGS WHERE drug_id = :drug_id");
            $stmt->execute(['drug_id' => $drug_id]);
            $drug_info = $stmt->fetch();
            if (!$drug_info) continue;
            
            foreach ($patient_allergies as $allergy) {
                $stmt = $conn->prepare("SELECT COUNT(*) as has_allergen FROM MEDICAMENT_ALLERGEN WHERE drug_id = :drug_id AND allergen_id = :allergen_id");
                $stmt->execute(['drug_id' => $drug_id, 'allergen_id' => $allergy['allergen_id']]);
                $result = $stmt->fetch();
                
                if ($result['has_allergen'] > 0) {
                    $all_allergy_conflicts[] = [
                        'drug_name' => $drug_info['comercial_name'],
                        'active_principle' => $drug_info['active_principle'],
                        'allergen_name' => $allergy['name_alergen'],
                        'allergen_category' => $allergy['category'],
                        'severity' => $allergy['severity'],
                        'reaction' => $allergy['reaction'],
                        'notes' => $allergy['notes']
                    ];
                }
            }
        }
        echo json_encode(['success' => true, 'allergies' => $all_allergy_conflicts]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit();
}

// --- PROCESS CREATION ---
if (isset($_POST['create_prescription'])) {
    try {
        $conn->beginTransaction();
        
        $patient_id = $_POST['patient_id'];
        $diagnostic_id = $_POST['diagnostic_id'];
        $indications = $_POST['indications'];
        $medications = $_POST['medications'] ?? [];
        
        if (empty($medications)) throw new Exception('At least one medication is required');
        
        // Insert Header
        $stmt = $conn->prepare("
            INSERT INTO PRESCRIPTION (patient_id, professional_id, diagnostic_id, indications, date)
            VALUES (:pid, :prof_id, :diag_id, :ind, NOW())
        ");
        $stmt->execute([
            'pid' => $patient_id, 'prof_id' => $professional_id, 'diag_id' => $diagnostic_id, 'ind' => $indications
        ]);
        $prescription_id = $conn->lastInsertId();
        
        // Insert Items
        foreach ($medications as $med) {
            $real_drug_id = $med['drug_id']; 
            
            if (!empty($real_drug_id)) {
                // Get standard concentration text from DB for accuracy
                $d_stmt = $conn->prepare("SELECT standard_concentration FROM DRUGS WHERE drug_id = :id");
                $d_stmt->execute(['id' => $real_drug_id]);
                $d_row = $d_stmt->fetch();
                $dose_text = $d_row['standard_concentration'];

                $frequency = $med['frequency_value'] . ' ' . $med['frequency_unit'];
                $duration = $med['duration_value'] . ' ' . $med['duration_unit'];
                
                $stmt = $conn->prepare("
                    INSERT INTO PRESCRIPTION_ITEM (prescription_id, drug_id, dose, frequency, duration)
                    VALUES (:pid, :did, :dose, :freq, :dur)
                ");
                $stmt->execute([
                    'pid' => $prescription_id, 'did' => $real_drug_id, 'dose' => $dose_text, 'freq' => $frequency, 'dur' => $duration
                ]);
            }
        }
        
        $conn->commit();
        $success = true;
        // Keep patient data to show success message, but clear matches
        $patient = null;
        $patient_matches = [];
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Write Prescription</title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; }
        
        .header { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .header h1 { font-size: 18px; font-weight: 400; color: #2c3e50; }
        .back-btn { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 12px; text-transform: uppercase; transition: all 0.2s; }
        .back-btn:hover { border-color: #95a5a6; color: #2c3e50; }
        
        .container { max-width: 1000px; margin: 0 auto; padding: 32px; }
        .box { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 32px; margin-bottom: 24px; }
        .box h2 { font-size: 16px; font-weight: 500; color: #2c3e50; margin-bottom: 24px; }
        
        .alert { padding: 14px 20px; border-radius: 4px; margin-bottom: 24px; font-size: 13px; }
        .alert-success { background: #d4edda; border-left: 3px solid #27ae60; color: #155724; }
        .alert-error { background: #fee; border-left: 3px solid #e74c3c; color: #c0392b; }
        
        .form-group { margin-bottom: 24px; }
        label { display: block; margin-bottom: 8px; color: #2c3e50; font-weight: 500; font-size: 12px; text-transform: uppercase; }
        input, select, textarea { width: 100%; padding: 12px 16px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; background: #fafbfc; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #3498db; background: white; }
        textarea { resize: vertical; min-height: 80px; }
        
        button { padding: 12px 24px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer; text-transform: uppercase; }
        button:hover { background: #2980b9; }
        
        /* Patient Info Card (Restored Style) */
        .patient-info { background: #fff; border: 1px solid #e1e8ed; padding: 25px; border-radius: 4px; margin-bottom: 30px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .patient-info-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
        .patient-title { font-size: 14px; font-weight: 700; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; }
        
        .patient-info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
        .patient-info-label { color: #7f8c8d; font-size: 11px; text-transform: uppercase; margin-bottom: 5px; font-weight: 600; }
        .patient-info-value { color: #2c3e50; font-weight: 500; font-size: 15px; }
        
        .action-buttons-header { display:flex; gap:10px; }
        .btn-history { background: #3498db; color: white; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: 700; text-transform: uppercase; transition: background 0.2s; }
        .btn-allergy-check { background: #e74c3c; color: white; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 11px; font-weight: 700; text-transform: uppercase; transition: background 0.2s; }
        
        /* Select Patient List */
        .patient-select-list { border: 1px solid #dce1e6; border-radius: 4px; overflow: hidden; margin-top: 10px; }
        .patient-option { padding: 15px; border-bottom: 1px solid #eee; cursor: pointer; display: flex; justify-content: space-between; align-items: center; background: white; }
        .patient-option:last-child { border-bottom: none; }
        .patient-option:hover { background: #f9fbfd; }
        .patient-option strong { color: #2c3e50; display: block; margin-bottom: 3px; }
        .patient-option span { font-size: 12px; color: #7f8c8d; }

        /* Medication Item */
        .medication-item { border: 1px solid #e1e8ed; padding: 20px; border-radius: 4px; margin-bottom: 16px; background: #fafbfc; }
        .medication-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .medication-header h4 { font-size: 13px; font-weight: 500; color: #2c3e50; text-transform: uppercase; margin: 0; }
        .form-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
        .form-row-split { display: grid; grid-template-columns: 2fr 1fr; gap: 8px; }
        
        /* Select2 Fixes */
        .select2-container { width: 100% !important; display: block; }
        .select2-container .select2-selection--single { height: 45px !important; border: 1px solid #dce1e6 !important; background-color: #fafbfc !important; padding: 8px 0 !important; }
        .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 26px !important; padding-left: 16px !important; color: #333 !important; font-size: 14px !important; }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 43px !important; right: 10px !important; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📝 Write Prescription</h1>
        <a href="dashboard.php" class="back-btn">← Dashboard</a>
    </div>

    <div class="container">
        <?php if ($success): ?><div class="alert alert-success">✅ Prescription created successfully!</div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if (!$patient): ?>
            <div class="box">
                <h2>Step 1: Patient Identification</h2>
                
                <?php if (empty($patient_matches)): ?>
                    <form method="POST">
                        <div class="form-group">
                            <label>Patient Search</label>
                            <input type="text" name="patient_search" placeholder="Enter DNI, Record Number, Name or Surname..." required autofocus>
                        </div>
                        <button type="submit" name="search_patient">🔍 Search Patient</button>
                    </form>
                
                <?php else: ?>
                    <p style="margin-bottom:15px; color:#7f8c8d;">Multiple patients found matching "<strong><?= htmlspecialchars($_POST['patient_search']) ?></strong>". Please select the correct one:</p>
                    <form method="POST">
                        <div class="patient-select-list">
                            <?php foreach ($patient_matches as $match): ?>
                                <label class="patient-option">
                                    <div>
                                        <strong><?= htmlspecialchars($match['name'] . ' ' . $match['surname']) ?></strong>
                                        <span>DNI: <?= htmlspecialchars($match['DNI']) ?> | Record: <?= htmlspecialchars($match['record_number']) ?> | DOB: <?= $match['birth_date'] ?></span>
                                    </div>
                                    <input type="radio" name="selected_patient_id" value="<?= $match['patient_id'] ?>" style="width:auto;" required>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div style="margin-top:20px;">
                            <button type="submit" name="select_patient_from_list">Continue with Selected Patient</button>
                            <a href="prescribe.php" style="margin-left:15px; font-size:13px; color:#7f8c8d;">Cancel Search</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php else: ?>
            
            <div class="patient-info">
                <div class="patient-info-header">
                    <span class="patient-title">Patient Information</span>
                    <div class="action-buttons-header">
                        <a href="../edit_patient_meds.php?patient_id=<?= $patient['patient_id'] ?>" target="_blank" class="btn-history">
                         📄 View History
                         </a>
                        <a href="modules/verify_allergies.php?patient_dni=<?= htmlspecialchars($patient['DNI']) ?>" target="_blank" class="btn-allergy-check">
                            🤧 Check Allergies
                        </a>
                    </div>
                </div>
                <div class="patient-info-grid">
                    <div><div class="patient-info-label">Full Name</div><div class="patient-info-value"><?= htmlspecialchars($patient['name'] . ' ' . $patient['surname']) ?></div></div>
                    <div><div class="patient-info-label">DNI / ID</div><div class="patient-info-value"><?= htmlspecialchars($patient['DNI']) ?></div></div>
                    <div><div class="patient-info-label">Medical Record</div><div class="patient-info-value"><?= htmlspecialchars($patient['record_number']) ?></div></div>
                    <div><div class="patient-info-label">Date of Birth</div><div class="patient-info-value"><?= htmlspecialchars($patient['birth_date']) ?></div></div>
                </div>
            </div>

            <div class="box">
                <h2>Step 2: Prescription Details</h2>
                <form method="POST" id="prescriptionForm">
                    <input type="hidden" name="patient_id" value="<?= $patient['patient_id'] ?>" id="patient_id">

                    <div class="form-group">
                        <label>Diagnostic Code</label>
                        <select name="diagnostic_id" class="searchable-select" required>
                            <option value="">Select diagnostic</option>
                            <?php foreach ($diagnostics as $diag): ?>
                                <option value="<?= $diag['diagnostic_id'] ?>"><?= htmlspecialchars($diag['code_ICD_10'] . ' – ' . $diag['disease_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>General Instructions</label>
                        <textarea name="indications" placeholder="Instructions for patient"></textarea>
                    </div>

                    <label style="margin-top: 32px; margin-bottom: 16px; display:block;">Medications</label>
                    <div id="medications-list"></div>
                    
                    <button type="button" onclick="addMedication()" style="background:#27ae60; margin-top:10px;">➕ Add Medication</button>

                    <div style="margin-top: 32px; padding-top: 32px; border-top: 1px solid #e1e8ed;">
                        <button type="button" onclick="checkAndSubmit()">✅ Create Prescription</button>
                        <button type="button" onclick="location.reload()" style="background:#95a5a6; margin-left:10px;">Cancel</button>
                    </div>
                    <input type="hidden" name="create_prescription" value="1">
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
        let medicationCount = 0;
        
        // 1. PREPARE DATA: Group raw rows by Medication Name
        const rawDrugs = <?= json_encode($drugs_raw) ?>;
        const drugGroups = {};

        rawDrugs.forEach(d => {
            const key = d.comercial_name + " (" + d.active_principle + ")";
            if (!drugGroups[key]) { drugGroups[key] = []; }
            drugGroups[key].push({
                id: d.drug_id,
                dose: d.standard_concentration ? d.standard_concentration : 'Standard Dose',
                presentation: d.presentation
            });
        });

        const sortedKeys = Object.keys(drugGroups).sort();

        $(document).ready(function() {
            $('.searchable-select').select2({ placeholder: "Type to search...", width: '100%' });
        });

        function addMedication() {
            const container = document.getElementById('medications-list');
            const medId = medicationCount++;
            
            let nameOptions = '<option value="">Select medication...</option>';
            sortedKeys.forEach(name => {
                nameOptions += `<option value="${name}">${name}</option>`;
            });

            const html = `
                <div class="medication-item" id="med-${medId}">
                    <div class="medication-header">
                        <h4>Medication ${medId + 1}</h4>
                        <button type="button" onclick="removeMedication(${medId})" style="background:#e74c3c; padding:6px 12px; font-size:11px;">❌ Remove</button>
                    </div>
                    
                    <div class="form-row" style="grid-template-columns: 2fr 1fr;">
                        <div class="form-group">
                            <label>Medication Name</label>
                            <select class="drug-name-select-${medId}" onchange="updateDoseOptions(${medId}, this.value)" style="width: 100%;">
                                ${nameOptions}
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Dosage (Available)</label>
                            <select name="medications[${medId}][drug_id]" id="dose-select-${medId}" required style="width: 100%; background:#e8f6f3; font-weight:bold;">
                                <option value="">← Select name first</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Frequency</label>
                            <div class="form-row-split">
                                <input type="number" name="medications[${medId}][frequency_value]" placeholder="Every..." min="1" required>
                                <select name="medications[${medId}][frequency_unit]" required>
                                    <option value="hours" selected>Hours</option>
                                    <option value="days">Days</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Duration</label>
                            <div class="form-row-split">
                                <input type="number" name="medications[${medId}][duration_value]" placeholder="For..." min="1" required>
                                <select name="medications[${medId}][duration_unit]" required>
                                    <option value="days" selected>Days</option>
                                    <option value="weeks">Weeks</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', html);

            $(`.drug-name-select-${medId}`).select2({
                placeholder: "Search medication name...",
                width: '100%'
            });
        }

        function updateDoseOptions(id, selectedName) {
            const doseSelect = document.getElementById(`dose-select-${id}`);
            doseSelect.innerHTML = ''; 

            if (!selectedName || !drugGroups[selectedName]) {
                let opt = document.createElement('option');
                opt.text = "← Select name first";
                doseSelect.add(opt);
                return;
            }

            const options = drugGroups[selectedName];
            options.forEach(optData => {
                let opt = document.createElement('option');
                opt.value = optData.id; // THE REAL DRUG ID IS HERE
                opt.text = `${optData.dose} (${optData.presentation})`; 
                doseSelect.add(opt);
            });
        }

        function removeMedication(id) {
            if ($(`.drug-name-select-${id}`).data('select2')) { $(`.drug-name-select-${id}`).select2('destroy'); }
            document.getElementById(`med-${id}`).remove();
        }

        async function checkAndSubmit() {
            const patientId = document.getElementById('patient_id').value;
            const selects = document.querySelectorAll('select[name^="medications"][name$="[drug_id]"]');
            
            const drugIds = [];
            selects.forEach(s => { if (s.value) drugIds.push(s.value); });
            
            if (drugIds.length === 0) {
                alert('Please add at least one medication');
                return;
            }
            
            let hasWarnings = false;
            let warningMessage = '';
            
            try {
                // 1. Check Interactions
                const interactionsResponse = await fetch(`?check_interactions=1&patient_id=${patientId}&drug_ids=${encodeURIComponent(JSON.stringify(drugIds))}`);
                const interactionsData = await interactionsResponse.json();
                
                if (interactionsData.success && interactionsData.interactions.length > 0) {
                    hasWarnings = true;
                    warningMessage += '⚠️ DRUG INTERACTIONS DETECTED\n\n';
                    interactionsData.interactions.forEach(int => {
                        warningMessage += `${int.drug_a} ↔ ${int.drug_b}\n`;
                        warningMessage += `Severity: ${int.severity.toUpperCase()}\n`;
                        warningMessage += `${int.description}\n\n`;
                    });
                    warningMessage += '---\n\n';
                }
                
                // 2. Check Allergies
                const allergiesResponse = await fetch(`?check_allergies=1&patient_id=${patientId}&drug_ids=${encodeURIComponent(JSON.stringify(drugIds))}`);
                const allergiesData = await allergiesResponse.json();
                
                if (allergiesData.success && allergiesData.allergies.length > 0) {
                    hasWarnings = true;
                    warningMessage += '🚨 ALLERGY CONFLICTS DETECTED\n\n';
                    allergiesData.allergies.forEach(allergy => {
                        warningMessage += `Drug: ${allergy.drug_name}\n`;
                        warningMessage += `Allergen: ${allergy.allergen_name}\n`;
                        warningMessage += `Severity: ${allergy.severity}\n\n`;
                    });
                }
                
                if (hasWarnings) {
                    warningMessage += 'Are you sure you want to continue?';
                    if (!confirm(warningMessage)) { return; }
                }
                document.getElementById('prescriptionForm').submit();
                
            } catch (error) {
                console.error('Error checking safety:', error);
                if (confirm('Could not complete safety checks. Continue anyway?')) {
                    document.getElementById('prescriptionForm').submit();
                }
            }
        }

        addMedication();
    </script>
</body>
</html>
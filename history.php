<?php
require_once 'includes/auth.php'; 
require_once 'includes/functions.php';

// Authorization check: All professionals
if (!hasAnyRole(['admin', 'nurse', 'doctor', 'pharmacist'])) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// FIX: Initialize $error to prevent Undefined Variable warning
$error = ''; 

// Filters
$filter_patient = $_GET['patient'] ?? '';
$filter_type = $_GET['type'] ?? 'prescriptions'; // prescriptions is default tab
$filter_date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$filter_date_to = $_GET['date_to'] ?? date('Y-m-d');


// --- Query 1: Prescriptions (Creation) ---
$prescriptions_query = "
    SELECT 
        pr.prescription_id,
        pr.date,
        pr.indications,
        p.name as patient_name,
        p.surname as patient_surname,
        p.DNI as patient_dni,
        prof.name as doctor_name,
        prof.surname as doctor_surname,
        diag.disease_name,
        GROUP_CONCAT(CONCAT(d.comercial_name, ' (', pi.dose, ', ', pi.frequency, ')') SEPARATOR '<br>') as medications
    FROM PRESCRIPTION pr
    JOIN PATIENT p ON pr.patient_id = p.patient_id
    JOIN PROFESSIONAL prof ON pr.professional_id = prof.professional_id
    LEFT JOIN DIAGNOSTICS diag ON pr.diagnostic_id = diag.diagnostic_id
    LEFT JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
    LEFT JOIN DRUGS d ON pi.drug_id = d.drug_id
    WHERE pr.date BETWEEN :date_from AND DATE_ADD(:date_to, INTERVAL 1 DAY)
";

$params = [
    'date_from' => $filter_date_from,
    'date_to' => $filter_date_to
];

if (!empty($filter_patient)) {
    $prescriptions_query .= " AND (p.name LIKE :patient OR p.surname LIKE :patient OR p.DNI LIKE :patient)";
    $params['patient'] = '%' . $filter_patient . '%';
}

$prescriptions_query .= " GROUP BY pr.prescription_id ORDER BY pr.date DESC LIMIT 50";

$stmt = $conn->prepare($prescriptions_query);
$stmt->execute($params);
$prescriptions = $stmt->fetchAll();


// --- Query 2: Dispensations (Validation/Stock Out) ---
$dispensations_query = "
    SELECT 
        disp.dispensing_id, /* Added dispensing_id for administration status lookup */
        disp.data_dispensing,
        disp.quantity,
        d.comercial_name,
        d.active_principle,
        p.name as patient_name,
        p.surname as patient_surname,
        pr.prescription_id,
        prof.name as pharmacist_name,
        prof.surname as pharmacist_surname,
        s.name as storage_name
    FROM DISPENSING disp
    JOIN PRESCRIPTION_ITEM pi ON disp.prescription_item_id = pi.prescription_item_id
    JOIN PRESCRIPTION pr ON pi.prescription_id = pr.prescription_id
    JOIN PATIENT p ON pr.patient_id = p.patient_id
    JOIN DRUGS d ON pi.drug_id = d.drug_id
    JOIN PROFESSIONAL prof ON disp.pharmacist_id = prof.professional_id
    LEFT JOIN STORAGE s ON disp.storage_id = s.storage_id
    WHERE disp.data_dispensing BETWEEN :date_from AND DATE_ADD(:date_to, INTERVAL 1 DAY)
";

$disp_params = [
    'date_from' => $filter_date_from,
    'date_to' => $filter_date_to
];

if (!empty($filter_patient)) {
    $dispensations_query .= " AND (p.name LIKE :patient OR p.surname LIKE :patient OR p.DNI LIKE :patient)";
    $disp_params['patient'] = '%' . $filter_patient . '%';
}

$dispensations_query .= " ORDER BY disp.data_dispensing DESC LIMIT 50";

$stmt = $conn->prepare($dispensations_query);
$stmt->execute($disp_params);
$dispensations = $stmt->fetchAll();


// --- Query 3: Administrations (Patient Application) ---
$administrations_query = "
    SELECT 
        adm.administration_id, /* Select administration ID */
        adm.administered_at,
        disp.quantity,
        d.comercial_name,
        p.name as patient_name,
        p.surname as patient_surname,
        pr.prescription_id,
        prof.name as nurse_name,
        prof.surname as nurse_surname
    FROM ADMINISTRATION adm
    JOIN DISPENSING disp ON adm.dispensing_id = disp.dispensing_id
    JOIN PRESCRIPTION_ITEM pi ON disp.prescription_item_id = pi.prescription_item_id
    JOIN PRESCRIPTION pr ON pi.prescription_id = pr.prescription_id
    JOIN PATIENT p ON pr.patient_id = p.patient_id
    JOIN DRUGS d ON pi.drug_id = d.drug_id
    JOIN PROFESSIONAL prof ON adm.nurse_id = prof.professional_id
    WHERE adm.administered_at BETWEEN :date_from AND DATE_ADD(:date_to, INTERVAL 1 DAY)
";

$adm_params = [
    'date_from' => $filter_date_from,
    'date_to' => $filter_date_to
];

if (!empty($filter_patient)) {
    $administrations_query .= " AND (p.name LIKE :patient OR p.surname LIKE :patient OR p.DNI LIKE :patient)";
    $adm_params['patient'] = '%' . $filter_patient . '%';
}

$administrations_query .= " ORDER BY adm.administered_at DESC LIMIT 50";

try {
    $stmt = $conn->prepare($administrations_query);
    $stmt->execute($adm_params);
    $administrations = $stmt->fetchAll();
} catch (PDOException $e) {
    $administrations = [];
    if (strpos($e->getMessage(), 'no such table') !== false || strpos($e->getMessage(), 'unknown table') !== false) {
        $error = "Administration History not available yet (ADMINISTRATION table not found). Run Administration module first.";
    } else {
        $error = "Database Error in Administration query: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movement History - Hospital les Corts</title>
    
    <style>
        /* --- UNIFIED DASHBOARD STYLES --- */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            padding: 0;
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
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e1e8ed;
        }

        header h1 {
            font-size: 24px;
            color: #2c3e50;
            font-weight: 300;
        }
        
        header p {
            color: #7f8c8d;
            font-size: 13px;
        }
        
        h2 {
            font-size: 16px;
            font-weight: 600;
            color: #34495e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }
        
        /* Buttons */
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
            cursor: pointer;
        }
        
        .btn-back:hover {
            border-color: #95a5a6;
            color: #2c3e50;
        }

        .btn-primary {
            display: inline-block;
            padding: 10px 20px;
            background-color: #2980b9; 
            color: white;
            text-align: center;
            text-decoration: none;
            border: none;
            cursor: pointer;
            border-radius: 4px;
            font-weight: 500;
            transition: background-color 0.3s;
            font-size: 14px;
        }
        .btn-primary:hover { background-color: #3498db; }

        /* Alert Styles */
        .alert-error {
            background-color: #fcebeb;
            color: #c0392b;
            border: 1px solid #e74c3c;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success {
            background-color: #e8f8f5;
            color: #16a085;
            border: 1px solid #1abc9c;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        /* Filter & Tabs */
        .section-box {
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 4px;
            padding: 20px;
            overflow-x: auto;
            margin-bottom: 30px;
        }

        .filter-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
            margin-bottom: 20px;
        }
        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        .form-group input[type="text"], .form-group input[type="date"] {
            padding: 10px;
            border: 1px solid #dce1e6;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
        }
        
        .tab-controls {
            display: flex;
            border-bottom: 2px solid #e1e8ed;
            margin-top: 20px;
            padding-bottom: 0;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 500;
            color: #7f8c8d;
            border: none;
            border-radius: 4px 4px 0 0;
            background: transparent;
            margin-bottom: -2px; 
            transition: color 0.2s, background-color 0.2s;
            font-size: 14px;
        }
        .tab.active {
            color: #2980b9;
            border-bottom: 2px solid #2980b9;
            background: #f9fbfd;
            z-index: 1;
        }
        .content-box {
            display: none;
            padding-top: 20px;
        }
        .content-box.active {
            display: block;
        }

        /* History Card Styles */
        .history-card {
            border: 1px solid #dce1e6;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            background: #fff;
        }
        .history-card.prescription { border-left: 5px solid #2980b9; } 
        .history-card.dispensation { border-left: 5px solid #16a085; } 
        .history-card.administration { border-left: 5px solid #8e44ad; } 

        .card-details { flex: 1; }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            border-bottom: 1px dashed #ecf0f1;
            padding-bottom: 8px;
        }
        .card-title { font-size: 16px; font-weight: 700; color: #2c3e50; }
        .card-date { font-size: 12px; color: #7f8c8d; }
        .info-group { display: flex; gap: 20px; margin-top: 10px; }
        .info-item { flex: 1; font-size: 13px; }
        .info-item strong { display: block; color: #34495e; margin-bottom: 3px; }
        .medications-list { font-size: 0.95em; line-height: 1.5; color: #555; margin-top: 5px; }
        .admin-quantity { font-size: 1.1em; font-weight: 700; color: #8e44ad; }
    </style>
</head>
<body>
    
    <div class="header-module">
        <h1>⏰ General Movement History</h1>
        <a href="dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>

    <div class="container">
        
        <header>
            <div>
                <h1>⏰ General Movement History</h1>
                <p>View the comprehensive log of all prescriptions, dispensations (validation), and administrations (last 50 movements).</p>
            </div>
        </header>
        
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="section-box">
            <h2>Filtering</h2>
            <form method="GET" class="filter-form">
                <input type="hidden" name="type" id="filter_type_input" value="<?= htmlspecialchars($filter_type) ?>">
                <div class="form-group">
                    <label for="filter_date_from">Date From</label>
                    <input type="date" id="filter_date_from" name="date_from" value="<?= htmlspecialchars($filter_date_from) ?>" required>
                </div>
                <div class="form-group">
                    <label for="filter_date_to">Date To</label>
                    <input type="date" id="filter_date_to" name="date_to" value="<?= htmlspecialchars($filter_date_to) ?>" required>
                </div>
                <div class="form-group" style="flex: 2;">
                    <label for="filter_patient">Patient Name or DNI</label>
                    <input type="text" id="filter_patient" name="patient" placeholder="Search patient..." value="<?= htmlspecialchars($filter_patient) ?>">
                </div>
                <button type="submit" class="btn-primary" style="padding: 10px 20px;">Filter</button>
            </form>

            <div class="tab-controls">
                <button type="button" class="tab <?= $filter_type == 'prescriptions' ? 'active' : '' ?>" onclick="switchTab('prescriptions', this)">
                    📜 Prescriptions (<?= count($prescriptions) ?>)
                </button>
                <button type="button" class="tab <?= $filter_type == 'dispensations' ? 'active' : '' ?>" onclick="switchTab('dispensations', this)">
                    ✅ Dispensations (<?= count($dispensations) ?>) </button>
                <button type="button" class="tab <?= $filter_type == 'administrations' ? 'active' : '' ?>" onclick="switchTab('administrations', this)">
                    💉 Administrations (<?= count($administrations) ?>)
                </button>
            </div>
            
            <div id="tab-prescriptions" class="content-box <?= $filter_type == 'prescriptions' ? 'active' : '' ?>">
                <?php if (empty($prescriptions)): ?>
                    <div class="alert-error">No prescriptions found with the current filters.</div>
                <?php else: ?>
                    <?php foreach ($prescriptions as $pr): ?>
                        <div class="history-card prescription">
                            <div class="card-details">
                                <div class="card-header">
                                    <div class="card-title">Prescription #<?= htmlspecialchars($pr['prescription_id']) ?> (Created)</div>
                                    <div class="card-date">Date: <?= htmlspecialchars(date('d/m/Y', strtotime($pr['date']))) ?></div>
                                </div>
                                <div class="info-group">
                                    <div class="info-item">
                                        <strong>Patient</strong> 
                                        <?= htmlspecialchars($pr['patient_name'] . ' ' . $pr['patient_surname'] . ' (' . $pr['patient_dni'] . ')') ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Prescribed by</strong> 
                                        <?= htmlspecialchars($pr['doctor_name'] . ' ' . $pr['doctor_surname']) ?>
                                    </div>
                                </div>
                                <div class="info-group">
                                    <div class="info-item">
                                        <strong>Diagnosis</strong> 
                                        <?= htmlspecialchars($pr['disease_name'] ?? 'N/A') ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Indications</strong> 
                                        <?= htmlspecialchars($pr['indications'] ?? 'N/A') ?>
                                    </div>
                                </div>
                                <div class="info-group" style="margin-top: 15px;">
                                    <div class="info-item" style="flex-basis: 100%;">
                                        <strong>Prescribed Medications</strong> 
                                        <p class="medications-list"><?= $pr['medications'] ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="tab-dispensations" class="content-box <?= $filter_type == 'dispensations' ? 'active' : '' ?>">
                <?php if (empty($dispensations)): ?>
                    <div class="alert-error">No dispensations (validation movements) found with the current filters.</div>
                <?php else: ?>
                    <?php foreach ($dispensations as $disp): ?>
                        <div class="history-card dispensation">
                            <div class="card-details">
                                <div class="card-header">
                                    <div class="card-title">Dispensation (Validated) #<?= htmlspecialchars($disp['dispensing_id']) ?></div>
                                    <div class="card-date">Date: <?= htmlspecialchars(date('d/m/Y H:i', strtotime($disp['data_dispensing']))) ?></div>
                                </div>
                                <div class="info-group">
                                    <div class="info-item">
                                        <strong>Medication</strong> 
                                        <?= htmlspecialchars($disp['comercial_name'] . ' (' . $disp['active_principle'] . ')') ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Quantity Dispatched</strong> 
                                        <span class="admin-quantity" style="color: #16a085;"><?= number_format($disp['quantity']) ?> units</span>
                                    </div>
                                </div>
                                <div class="info-group">
                                    <div class="info-item">
                                        <strong>Patient</strong> 
                                        <?= htmlspecialchars($disp['patient_name'] . ' ' . $disp['patient_surname']) ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Dispensed/Validated by</strong> 
                                        <?= htmlspecialchars($disp['pharmacist_name'] . ' ' . $disp['pharmacist_surname']) ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Source Storage</strong> 
                                        <?= htmlspecialchars($disp['storage_name'] ?? 'N/A') ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div id="tab-administrations" class="content-box <?= $filter_type == 'administrations' ? 'active' : '' ?>">
                <?php if (empty($administrations)): ?>
                    <div class="alert-error">No administration records found with the current filters.</div>
                <?php else: ?>
                    <?php foreach ($administrations as $adm): ?>
                        <div class="history-card administration">
                            <div class="card-details">
                                <div class="card-header">
                                    <div class="card-title">Administration #<?= htmlspecialchars($adm['administration_id']) ?></div>
                                    <div class="card-date">Date: <?= htmlspecialchars(date('d/m/Y H:i', strtotime($adm['administered_at']))) ?></div>
                                </div>
                                <div class="info-group">
                                    <div class="info-item">
                                        <strong>Medication</strong> 
                                        <?= htmlspecialchars($adm['comercial_name']) ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Quantity Administered</strong> 
                                        <span class="admin-quantity"><?= number_format($adm['quantity']) ?> units</span>
                                    </div>
                                </div>
                                <div class="info-group">
                                    <div class="info-item">
                                        <strong>Patient</strong> 
                                        <?= htmlspecialchars($adm['patient_name'] . ' ' . $adm['patient_surname']) ?>
                                    </div>
                                    <div class="info-item">
                                        <strong>Administered by</strong> 
                                        <?= htmlspecialchars($adm['nurse_name'] . ' ' . $adm['nurse_surname']) ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function switchTab(tabName, clickedTab) {
            // 1. Update hidden input for form submission
            document.getElementById('filter_type_input').value = tabName;

            // 2. Manage tab active states
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            clickedTab.classList.add('active');
            
            // 3. Manage content box visibility
            document.querySelectorAll('.content-box').forEach(box => {
                box.classList.remove('active');
            });
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // 4. Update URL without refreshing
            const url = new URL(window.location);
            url.searchParams.set('type', tabName);
            window.history.pushState({}, '', url);
        }
        
        // Ensure the correct tab is displayed on initial load (from URL filter)
        document.addEventListener('DOMContentLoaded', () => {
            const initialType = new URLSearchParams(window.location.search).get('type') || 'prescriptions'; 
            
            document.querySelectorAll('.content-box').forEach(box => {
                box.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            document.getElementById('tab-' + initialType).classList.add('active');
            // Find the button corresponding to the initialType
            document.querySelectorAll('.tab').forEach(tab => {
                if (tab.getAttribute('onclick').includes(initialType)) {
                    tab.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>
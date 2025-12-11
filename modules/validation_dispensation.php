<?php
// FILE: modules/validation_dispensation.php
require_once '../includes/auth.php'; 
require_once '../includes/functions.php';

if (!hasAnyRole(['pharmacist', 'admin'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();
$error = '';
$success = '';

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

$current_user_email = $_SESSION['email'];
$stmt = $conn->prepare("SELECT professional_id FROM PROFESSIONAL WHERE email = :email");
$stmt->execute(['email' => $current_user_email]);
$prof_data = $stmt->fetch();
$pharmacist_id = $prof_data['professional_id'];

// PROCESS VALIDATION
if (isset($_POST['validate_dispense'])) {
    try {
        $conn->beginTransaction();
        
        $prescription_item_id = $_POST['prescription_item_id'];
        $quantity_to_dispense = (int)$_POST['quantity'];
        $drug_id = $_POST['drug_id'];
        
        $stmt = $conn->prepare("SELECT lot_id, lot_number, quantity, storage_id FROM LOT_NUMBERS WHERE drug_id = :drug_id AND quantity > 0 AND expiration_date >= NOW() ORDER BY expiration_date ASC");
        $stmt->execute(['drug_id' => $drug_id]);
        $available_lots = $stmt->fetchAll();

        if (!$available_lots) throw new Exception('Stock Validation Failed: No active lots available.');

        $remaining = $quantity_to_dispense;
        $primary_storage_id = null;

        foreach ($available_lots as $lot) {
            if ($remaining <= 0) break;
            $take = min($remaining, $lot['quantity']);
            $upd = $conn->prepare("UPDATE LOT_NUMBERS SET quantity = quantity - :take WHERE lot_id = :lid");
            $upd->execute(['take' => $take, 'lid' => $lot['lot_id']]);
            if ($primary_storage_id === null) $primary_storage_id = $lot['storage_id']; 
            $remaining -= $take;
        }

        if ($remaining > 0) throw new Exception("Insufficient Stock: Missing $remaining units.");
        
        $stmt = $conn->prepare("INSERT INTO DISPENSING (prescription_item_id, quantity, data_dispensing, pharmacist_id, storage_id) VALUES (:pid, :qty, NOW(), :phid, :sid)");
        $stmt->execute(['pid' => $prescription_item_id, 'qty' => $quantity_to_dispense, 'phid' => $pharmacist_id, 'sid' => $primary_storage_id]);
        
        $stmt = $conn->prepare("UPDATE DRUGS SET actual_inventory = (SELECT COALESCE(SUM(quantity), 0) FROM LOT_NUMBERS WHERE drug_id = :did AND quantity > 0) WHERE drug_id = :did");
        $stmt->execute(['did' => $drug_id]);
        
        $conn->commit();
        header('Location: validation_dispensation.php?success=' . urlencode("Dispensed $quantity_to_dispense units successfully."));
        exit();
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// SEARCH
$search = $_GET['search'] ?? '';

// QUEUE QUERY (Added Diagnosis)
$sql_queue = "
    SELECT 
        pi.prescription_item_id, pr.prescription_id, pi.drug_id, pi.dose, pi.frequency, pi.duration, 
        d.comercial_name, d.actual_inventory, 
        p.name as patient_name, p.surname as patient_surname,
        diag.disease_name
    FROM PRESCRIPTION_ITEM pi
    JOIN PRESCRIPTION pr ON pi.prescription_id = pr.prescription_id
    JOIN DRUGS d ON pi.drug_id = d.drug_id
    JOIN PATIENT p ON pr.patient_id = p.patient_id
    LEFT JOIN DIAGNOSTICS diag ON pr.diagnostic_id = diag.diagnostic_id
    WHERE pi.prescription_item_id NOT IN (SELECT prescription_item_id FROM DISPENSING)
";
if ($search) {
    $sql_queue .= " AND (p.name LIKE :s OR p.surname LIKE :s OR p.DNI LIKE :s)";
}
$sql_queue .= " ORDER BY pr.date ASC";
$stmt = $conn->prepare($sql_queue);
if ($search) $stmt->bindValue(':s', "%$search%");
$stmt->execute();
$queue = $stmt->fetchAll();

// HISTORY QUERY (Added Diagnosis)
$status_history_sql = "
    SELECT 
        pi.prescription_item_id, pi.drug_id, pi.dose, pi.frequency, pi.duration, 
        d.comercial_name, 
        p.name as patient_name, p.surname as patient_surname, 
        prof_doc.name as doctor_name, prof_doc.surname as doctor_surname,
        diag.disease_name,
        (SELECT MAX(prof_pharm.name) FROM DISPENSING disp_p JOIN PROFESSIONAL prof_pharm ON disp_p.pharmacist_id = prof_pharm.professional_id WHERE disp_p.prescription_item_id = pi.prescription_item_id) as pharmacist_name,
        pr.date as prescribed_date,
        (SELECT MAX(data_dispensing) FROM DISPENSING disp WHERE disp.prescription_item_id = pi.prescription_item_id) as dispensed_date,
        (SELECT MAX(adm.administered_at) FROM ADMINISTRATION adm JOIN DISPENSING disp ON adm.dispensing_id = disp.dispensing_id WHERE disp.prescription_item_id = pi.prescription_item_id) as administered_date
    FROM PRESCRIPTION_ITEM pi
    JOIN PRESCRIPTION pr ON pi.prescription_id = pr.prescription_id
    JOIN DRUGS d ON pi.drug_id = d.drug_id
    JOIN PATIENT p ON pr.patient_id = p.patient_id
    JOIN PROFESSIONAL prof_doc ON pr.professional_id = prof_doc.professional_id 
    LEFT JOIN DIAGNOSTICS diag ON pr.diagnostic_id = diag.diagnostic_id
    WHERE 1=1
";
if ($search) {
    $status_history_sql .= " AND (p.name LIKE :s OR p.surname LIKE :s OR p.DNI LIKE :s)";
}
$status_history_sql .= " GROUP BY pi.prescription_item_id ORDER BY pr.date DESC LIMIT 50";
$stmt = $conn->prepare($status_history_sql);
if ($search) $stmt->bindValue(':s', "%$search%");
$stmt->execute();
$status_history = $stmt->fetchAll();

function get_status_class($dates) {
    if ($dates['administered_date']) return 'status-administered';
    if ($dates['dispensed_date']) return 'status-dispensed';
    return 'status-prescribed';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Validation & Dispensation</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f7fa; margin: 0; overflow-x: hidden; }
        .header-module { background: white; border-bottom: 1px solid #e1e8ed; padding: 12px 32px; display: flex; align-items: center; justify-content: space-between; height: 64px; }
        .btn-back { color: #7f8c8d; text-decoration: none; font-size: 12px; font-weight: 500; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; }
        .container { max-width: 1200px; margin: 32px auto; padding: 0 20px; }
        .card { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 20px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
        .dispense-form { display: flex; align-items: center; gap: 10px; justify-content: flex-end; }
        .dispense-form label { font-size: 12px; font-weight: 600; color: #7f8c8d; margin: 0; }
        .dispense-form input { width: 60px; padding: 6px; border: 1px solid #3498db; border-radius: 4px; text-align: center; font-weight: bold; color: #2c3e50; font-size: 14px; }
        .dispense-form .suggestion { font-size: 11px; color: #27ae60; font-weight: 600; background: #e8f5e9; padding: 4px 8px; border-radius: 4px; white-space: nowrap; }
        .btn-action-validate { background: #f39c12; color: white; border: none; padding: 8px 18px; border-radius: 4px; cursor: pointer; font-weight: 600; text-transform: uppercase; font-size: 11px; transition: background 0.2s; white-space: nowrap; }
        .btn-action-validate:hover { background: #e67e22; }
        .alert-success { background: #e8f8f5; color: #16a085; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-error { background: #fcebeb; color: #c0392b; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .drug-title { font-size: 16px; font-weight: 700; color: #2c3e50; }
        .patient-sub { font-size: 13px; color: #7f8c8d; margin-top: 4px; display:block; }
        .diagnosis-info { font-size: 13px; color: #2c3e50; margin-top: 2px; font-weight: 500; }
        .order-badge { background: #eef2f7; color: #2c3e50; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 10px; margin-top: 8px; border: 1px solid #dce1e6; }
        .status-bar { display: flex; align-items: center; justify-content: space-between; margin-top: 10px; width: 100%; max-width: 350px;}
        .status-point { text-align: center; font-size: 10px; font-weight: 600; color: #7f8c8d; position: relative; flex: 1; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; background: #ecf0f1; margin: 0 auto 5px; position: relative; z-index: 1; border: 2px solid #bdc3c7; }
        .status-line { position: absolute; top: 5px; left: 50%; width: 100%; height: 2px; background: #ecf0f1; z-index: 0; }
        .status-point:last-child .status-line { display: none; }
        .status-complete .status-dot { background: #27ae60; border-color: #27ae60; }
        .status-complete .status-line { background: #27ae60; }
        .status-action-container { width: 180px; margin-left: 20px; text-align: right; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1 style="font-size: 18px; color: #2c3e50;">Validation & Dispensation Queue</h1>
        <a href="../dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>

    <div class="container">
        <?php if ($success): ?><div class="alert-success">✅ <?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="GET" style="margin-bottom: 24px; display:flex; gap:10px;">
            <input type="text" name="search" placeholder="Search Patient Name or DNI..." value="<?= htmlspecialchars($search) ?>" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px;">
            <button type="submit" class="btn-action-validate" style="background:#3498db;">Search</button>
        </form>

        <h2>Pending Validation (<?= count($queue) ?>)</h2>
        <?php if (empty($queue)): ?>
            <p style="text-align: center; color: #95a5a6; padding: 30px;">No pending prescriptions.</p>
        <?php else: ?>
            <?php foreach ($queue as $item): ?>
                <?php
                    $freq = strtolower($item['frequency']);
                    $dur_str = strtolower($item['duration']);
                    $dur_val = (int)filter_var($dur_str, FILTER_SANITIZE_NUMBER_INT);
                    if($dur_val == 0) $dur_val = 1;
                    $per_day = 1;
                    if (strpos($freq, '8') !== false) $per_day = 3;
                    elseif (strpos($freq, '12') !== false) $per_day = 2;
                    elseif (strpos($freq, '6') !== false) $per_day = 4;
                    elseif (strpos($freq, '4') !== false) $per_day = 6;
                    elseif (strpos($freq, 'once') !== false || strpos($freq, '24') !== false) $per_day = 1;
                    $calculated_qty = $per_day * $dur_val;
                ?>
                <div class="card" style="border-left: 4px solid #f39c12;">
                    <div style="flex: 1;">
                        <div class="drug-title">💊 <?= htmlspecialchars($item['comercial_name']) ?></div>
                        <div class="patient-sub">
                            Patient: <strong><?= htmlspecialchars($item['patient_name'] . ' ' . $item['patient_surname']) ?></strong> 
                            | Stock: <?= $item['actual_inventory'] ?> units
                        </div>
                        <div class="diagnosis-info">
                            Diagnosis: <strong><?= htmlspecialchars($item['disease_name'] ?? 'N/A') ?></strong>
                        </div>
                        <div class="order-badge">
                            <span>Rx: <?= htmlspecialchars($item['dose']) ?></span>
                            <span>• Freq: <?= htmlspecialchars($item['frequency']) ?></span>
                            <span>• Dur: <?= htmlspecialchars($item['duration']) ?></span>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <form method="POST" class="dispense-form">
                            <input type="hidden" name="prescription_item_id" value="<?= $item['prescription_item_id'] ?>">
                            <input type="hidden" name="drug_id" value="<?= $item['drug_id'] ?>">
                            <label>Dispense Quantity:</label>
                            <input type="number" name="quantity" value="<?= $calculated_qty ?>" min="1" required>
                            <span class="suggestion">Suggested: <?= $calculated_qty ?></span>
                            <button type="submit" name="validate_dispense" class="btn-action-validate">Validate & Dispense</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <h3 style="margin-top: 40px; color:#2c3e50; font-size: 16px;">History & Actions</h3>
        <div class="card" style="display: block; border-left: none; padding:0;">
            <?php foreach ($status_history as $item): ?>
                <?php 
                    $status_class = get_status_class($item);
                    $is_dispensed = $item['dispensed_date'] !== null;
                    $is_administered = $item['administered_date'] !== null;
                ?>
                <div style="border-bottom: 1px solid #eee; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center;">
                    <div style="flex: 1;">
                        <div style="font-size: 14px; font-weight: 700; color: #2c3e50;">
                            #<?= $item['prescription_item_id'] ?>: <?= htmlspecialchars($item['comercial_name']) ?>
                        </div>
                        <div style="font-size: 12px; color: #7f8c8d; margin-top:2px;">
                            <?= htmlspecialchars($item['patient_name'] . ' ' . $item['patient_surname']) ?> | 
                            Diagnosis: <strong><?= htmlspecialchars($item['disease_name'] ?? 'N/A') ?></strong>
                        </div>
                    </div>
                    <div class="status-bar">
                        <div class="status-point status-complete"><div class="status-dot"></div>Prescribed</div>
                        <div class="status-line"></div>
                        <div class="status-point <?= $is_dispensed ? 'status-complete' : '' ?>"><div class="status-dot"></div>Dispensed</div>
                        <div class="status-line"></div>
                        <div class="status-point <?= $is_administered ? 'status-complete' : '' ?>"><div class="status-dot"></div>Administered</div>
                    </div>
                    <div class="status-action-container">
                        <?php if (!$is_dispensed): ?>
                            <?php 
                                $freq = strtolower($item['frequency'] ?? '');
                                $dur_str = strtolower($item['duration'] ?? '');
                                $dur_val = (int)filter_var($dur_str, FILTER_SANITIZE_NUMBER_INT);
                                if($dur_val == 0) $dur_val = 1;
                                $per_day = 1;
                                if (strpos($freq, '8') !== false) $per_day = 3;
                                elseif (strpos($freq, '12') !== false) $per_day = 2;
                                elseif (strpos($freq, '6') !== false) $per_day = 4;
                                $hist_calc = $per_day * $dur_val;
                            ?>
                            <form method="POST" style="display:flex; gap:5px; justify-content: flex-end; align-items:center;"> 
                                <input type="hidden" name="prescription_item_id" value="<?= $item['prescription_item_id'] ?>">
                                <input type="hidden" name="drug_id" value="<?= $item['drug_id'] ?>">
                                <input type="number" name="quantity" value="<?= $hist_calc ?>" min="1" style="width:40px; font-size:11px; padding:4px; border:1px solid #ccc; border-radius:3px;"> 
                                <button type="submit" name="validate_dispense" class="btn-action-validate" style="padding: 5px 10px; font-size: 10px;">
                                    Validate & Dispense
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="font-size: 11px; color: #27ae60; font-weight: 600;">✓ Completed</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>

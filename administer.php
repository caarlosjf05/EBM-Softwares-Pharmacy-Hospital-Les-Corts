<?php
// FILE: modules/administer.php
require_once 'includes/auth.php'; 
require_once 'includes/functions.php';

if (!hasAnyRole(['nurse', 'admin', 'doctor'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$current_user_email = $_SESSION['email'];
$stmt = $conn->prepare("SELECT professional_id FROM PROFESSIONAL WHERE email = :email");
$stmt->execute(['email' => $current_user_email]);
$nurse_id = $stmt->fetch()['professional_id'];

$success = false;
$error = '';

// RECORD ADMIN
if (isset($_POST['administer'])) {
    try {
        $dispensing_id = $_POST['dispensing_id'];
        $qty_administered = $_POST['qty_administered'];
        
        if ($qty_administered <= 0) throw new Exception("Quantity must be greater than 0.");

        $stmt = $conn->prepare("
            INSERT INTO ADMINISTRATION (dispensing_id, administered_at, nurse_id, notes, quantity)
            VALUES (:dispensing_id, NOW(), :nurse_id, :note, :qty)
        ");
        $stmt->execute([
            'dispensing_id' => $dispensing_id,
            'nurse_id' => $nurse_id,
            'note' => "Administered $qty_administered units",
            'qty' => $qty_administered
        ]);
        
        $success = true;
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// FILTERS
$search_patient = $_GET['search_patient'] ?? '';

// 1. ACTIVE WARD STOCK (Added Diagnosis)
$sql_stock = "
    SELECT 
        disp.dispensing_id,
        disp.data_dispensing,
        disp.quantity as total_dispensed,
        d.comercial_name,
        d.active_principle,
        pi.dose,
        pi.frequency,
        pi.duration,
        p.name as patient_name,
        p.surname as patient_surname,
        p.DNI as patient_dni,
        diag.disease_name,
        (SELECT COALESCE(SUM(quantity), 0) FROM ADMINISTRATION a WHERE a.dispensing_id = disp.dispensing_id) as total_administered,
        (SELECT MAX(administered_at) FROM ADMINISTRATION a WHERE a.dispensing_id = disp.dispensing_id) as last_admin_time
    FROM DISPENSING disp
    JOIN PRESCRIPTION_ITEM pi ON disp.prescription_item_id = pi.prescription_item_id
    JOIN DRUGS d ON pi.drug_id = d.drug_id
    JOIN PRESCRIPTION pr ON pi.prescription_id = pr.prescription_id
    JOIN PATIENT p ON pr.patient_id = p.patient_id
    LEFT JOIN DIAGNOSTICS diag ON pr.diagnostic_id = diag.diagnostic_id
    WHERE 1=1
";

if ($search_patient) {
    $sql_stock .= " AND (p.name LIKE :search OR p.surname LIKE :search OR p.DNI LIKE :search)";
}
$sql_stock .= " ORDER BY p.surname ASC";

$stmt = $conn->prepare($sql_stock);
if ($search_patient) $stmt->bindValue(':search', "%$search_patient%");
$stmt->execute();
$all_ward_items = $stmt->fetchAll();

$ready_queue = [];
$scheduled_queue = [];

foreach ($all_ward_items as $item) {
    $remaining = $item['total_dispensed'] - $item['total_administered'];
    if ($remaining <= 0) continue; 

    $freq_str = strtolower($item['frequency']);
    $hours = 24; 
    if (strpos($freq_str, '8') !== false) $hours = 8;
    elseif (strpos($freq_str, '6') !== false) $hours = 6;
    elseif (strpos($freq_str, '12') !== false) $hours = 12;
    elseif (strpos($freq_str, '4') !== false) $hours = 4;

    $last_time = $item['last_admin_time'];
    
    if (!$last_time) {
        $item['next_due'] = "Now (First Dose)";
        $item['remaining'] = $remaining;
        $ready_queue[] = $item;
    } else {
        $next_ts = strtotime($last_time) + ($hours * 3600);
        $item['next_due'] = date('H:i', $next_ts);
        $item['remaining'] = $remaining;
        
        if (time() >= ($next_ts - 3600)) {
            $ready_queue[] = $item;
        } else {
            $scheduled_queue[] = $item;
        }
    }
}

// 2. PENDING PHARMACY (Added Diagnosis)
$sql_pending = "
    SELECT 
        pi.prescription_item_id,
        d.comercial_name,
        d.active_principle,
        pi.dose,
        pi.frequency,
        pi.duration,
        p.name as patient_name,
        p.surname as patient_surname,
        p.DNI as patient_dni,
        diag.disease_name,
        pr.date as prescribed_date
    FROM PRESCRIPTION_ITEM pi
    JOIN PRESCRIPTION pr ON pi.prescription_id = pr.prescription_id
    JOIN DRUGS d ON pi.drug_id = d.drug_id
    JOIN PATIENT p ON pr.patient_id = p.patient_id
    LEFT JOIN DIAGNOSTICS diag ON pr.diagnostic_id = diag.diagnostic_id
    WHERE pi.prescription_item_id NOT IN (SELECT prescription_item_id FROM DISPENSING)
";
if ($search_patient) {
    $sql_pending .= " AND (p.name LIKE :search OR p.surname LIKE :search OR p.DNI LIKE :search)";
}
$stmt = $conn->prepare($sql_pending);
if ($search_patient) $stmt->bindValue(':search', "%$search_patient%");
$stmt->execute();
$pending_queue = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Medication Administration</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f7fa; margin: 0; }
        .header-module { background: white; border-bottom: 1px solid #e1e8ed; padding: 12px 32px; display: flex; align-items: center; justify-content: space-between; height: 64px; }
        .btn-back { color: #7f8c8d; text-decoration: none; font-size: 12px; font-weight: 500; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; }
        .container { max-width: 1200px; margin: 32px auto; padding: 0 20px; }
        .card { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 20px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; border-left: 4px solid #636e72; }
        .card-scheduled { opacity: 0.7; background: #fafafa; border-left-color: #b2bec3; }
        .card-pending { border-left: 4px solid #f39c12; opacity: 0.8; }
        .btn-administer { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: 600; text-transform: uppercase; font-size: 11px; letter-spacing: 0.5px; transition: background 0.2s; }
        .btn-administer:hover { background: #219150; }
        .alert-success { background: #e8f8f5; color: #16a085; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-error { background: #fcebeb; color: #c0392b; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .drug-title { font-size: 16px; font-weight: 700; color: #2c3e50; }
        .patient-sub { font-size: 13px; color: #7f8c8d; margin-top: 4px; display:block; }
        .diagnosis-info { font-size: 13px; color: #2c3e50; margin-top: 2px; font-weight: 500; }
        .order-badge { background: #f0f3f4; color: #2c3e50; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; gap: 10px; margin-top: 8px; border: 1px solid #dce1e6; }
        .ward-stock { font-size: 11px; color: #e67e22; font-weight: 600; margin-top: 8px; }
        .status-tag { background: #e8f5e9; color: #2e7d32; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; display: inline-block; }
        .filter-bar { background: white; padding: 20px; border-radius: 8px; border: 1px solid #e1e8ed; margin-bottom: 24px; display: flex; gap: 15px; align-items: center; }
        .filter-input { padding: 10px; border: 1px solid #dce1e6; border-radius: 4px; flex-grow: 1; font-size: 14px; }
        .btn-primary { background: #636e72; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
        .section-header { margin: 40px 0 15px 0; font-size: 14px; text-transform: uppercase; color: #95a5a6; font-weight: 700; border-bottom: 1px solid #e1e8ed; padding-bottom: 5px; }
        .qty-input { width: 50px; padding: 5px; border: 1px solid #ccc; border-radius: 4px; text-align: center; font-weight: bold; margin-right: 5px; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1 style="font-size: 18px; color: #2c3e50;">Medication Administration</h1>
        <a href="../dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>

    <div class="container">
        <?php if ($success): ?><div class="alert-success">✅ Administration logged. Next dose scheduled.</div><?php endif; ?>
        <?php if ($error): ?><div class="alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="GET" class="filter-bar">
            <input type="text" name="search_patient" class="filter-input" placeholder="Scan Wristband, enter Name, Surname or DNI..." value="<?= htmlspecialchars($search_patient) ?>">
            <button type="submit" class="btn-primary">🔍 Search</button>
        </form>

        <div class="section-header" style="color: #27ae60;">🚨 Due for Administration</div>
        <?php if (empty($ready_queue)): ?>
            <div style="text-align: center; padding: 20px; color: #95a5a6; background:white;">All tasks up to date.</div>
        <?php else: ?>
            <?php foreach ($ready_queue as $item): ?>
                <div class="card">
                    <div style="flex: 1;">
                        <span class="status-tag">Due: <?= $item['next_due'] ?></span>
                        <div class="drug-title">💊 <?= htmlspecialchars($item['comercial_name']) ?></div>
                        <div class="patient-sub">
                            <strong><?= htmlspecialchars($item['patient_name'] . ' ' . $item['patient_surname']) ?></strong> (<?= htmlspecialchars($item['patient_dni']) ?>)
                        </div>
                        <div class="diagnosis-info">
                            Diagnosis: <strong><?= htmlspecialchars($item['disease_name'] ?? 'N/A') ?></strong>
                        </div>
                        <div class="order-badge">
                            <span>Rx: <?= htmlspecialchars($item['dose']) ?></span>
                            <span>• <?= htmlspecialchars($item['frequency']) ?></span>
                            <span>• <?= htmlspecialchars($item['duration']) ?></span>
                        </div>
                        <div class="ward-stock">
                            Remaining for Patient: <?= $item['remaining'] ?> units
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <form method="POST">
                            <input type="hidden" name="dispensing_id" value="<?= $item['dispensing_id'] ?>">
                            <div style="margin-bottom: 5px;">
                                <label style="font-size:11px; color:#7f8c8d;">Qty:</label>
                                <input type="number" name="qty_administered" class="qty-input" value="1" min="0.5" step="0.5" required>
                            </div>
                            <button type="submit" name="administer" class="btn-administer">✓ Administer</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="section-header">🕒 Scheduled (Next 24h)</div>
        <?php if (empty($scheduled_queue)): ?>
            <div style="text-align: center; padding: 20px; color: #95a5a6;">No future doses scheduled.</div>
        <?php else: ?>
            <?php foreach ($scheduled_queue as $item): ?>
                <div class="card card-scheduled">
                    <div style="flex: 1;">
                        <span class="status-tag" style="background:#f0f3f4; color:#7f8c8d;">Next: <?= $item['next_due'] ?></span>
                        <div class="drug-title" style="color:#7f8c8d;">💊 <?= htmlspecialchars($item['comercial_name']) ?></div>
                        <div class="patient-sub"><?= htmlspecialchars($item['patient_name'] . ' ' . $item['patient_surname']) ?></div>
                        <div class="diagnosis-info">
                            Diagnosis: <strong><?= htmlspecialchars($item['disease_name'] ?? 'N/A') ?></strong>
                        </div>
                        <div class="order-badge" style="opacity: 0.7;">
                            <span>Rx: <?= htmlspecialchars($item['dose']) ?></span>
                            <span>• <?= htmlspecialchars($item['frequency']) ?></span>
                            <span>• <?= htmlspecialchars($item['duration']) ?></span>
                        </div>
                        <div class="ward-stock" style="color:#95a5a6;">Stock: <?= $item['remaining'] ?> units</div>
                    </div>
                    <div style="text-align: right; color:#95a5a6; font-size:12px; font-style:italic;">Wait for time</div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="section-header">⏳ Pending Pharmacy Validation</div>
        <?php if (empty($pending_queue)): ?>
            <div style="text-align: center; padding: 20px; color: #95a5a6;">No pending orders.</div>
        <?php else: ?>
            <?php foreach ($pending_queue as $item): ?>
                <div class="card card-pending">
                    <div style="flex: 1;">
                        <span class="status-tag" style="background:#ecf0f1; color:#95a5a6;">Waiting</span>
                        <div class="drug-title" style="color:#7f8c8d;">💊 <?= htmlspecialchars($item['comercial_name']) ?></div>
                        <div class="patient-sub"><?= htmlspecialchars($item['patient_name'] . ' ' . $item['patient_surname']) ?></div>
                        <div class="diagnosis-info">
                            Diagnosis: <strong><?= htmlspecialchars($item['disease_name'] ?? 'N/A') ?></strong>
                        </div>
                        <div class="order-badge" style="opacity:0.7;">
                            <span>Rx: <?= htmlspecialchars($item['dose']) ?></span>
                            <span>• <?= htmlspecialchars($item['frequency']) ?></span>
                            <span>• <?= htmlspecialchars($item['duration']) ?></span>
                        </div>
                    </div>
                    <div style="text-align: right; color:#95a5a6; font-size:12px; font-weight:600;">Stock not released</div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
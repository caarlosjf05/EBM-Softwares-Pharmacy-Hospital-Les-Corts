<?php
// FILE: modules/lot_tracking.php
require_once '../includes/auth.php';

if (!hasAnyRole(['admin', 'pharmacist'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();
$success = '';
$error = '';

// Add new lot
if (isset($_POST['add_lot'])) {
    try {
        $conn->beginTransaction(); 
        
        $drug_id = $_POST['drug_id'];
        $quantity_added = (int)$_POST['quantity'];

        $stmt = $conn->prepare("
            INSERT INTO LOT_NUMBERS (drug_id, lot_number, expiration_date, quantity, storage_id, received_date, manufacturer, created_at)
            VALUES (:drug_id, :lot_number, :expiration_date, :quantity, :storage_id, NOW(), :manufacturer, NOW())
        ");
        $stmt->execute([
            'drug_id' => $drug_id,
            'lot_number' => $_POST['lot_number'],
            'expiration_date' => $_POST['expiration_date'],
            'quantity' => $quantity_added,
            'storage_id' => $_POST['storage_id'],
            'manufacturer' => $_POST['manufacturer']
        ]);
        
        $stmt = $conn->prepare("UPDATE DRUGS SET actual_inventory = actual_inventory + :qty WHERE drug_id = :drug_id");
        $stmt->execute(['qty' => $quantity_added, 'drug_id' => $drug_id]);
        
        $conn->commit();
        $success = 'Lot number registered and inventory updated successfully.';

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $error = 'Error: ' . $e->getMessage();
    }
}

// Issue recall
if (isset($_POST['issue_recall'])) {
    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("
            INSERT INTO RECALLS (lot_number, recall_date, reason, severity, status, issued_by)
            VALUES (:lot_number, NOW(), :reason, :severity, 'active', :issued_by)
        ");
        $stmt->execute([
            'lot_number' => $_POST['lot_number'],
            'reason' => $_POST['reason'],
            'severity' => $_POST['severity'],
            'issued_by' => $_POST['issued_by']
        ]);
        $conn->commit();
        $success = 'Recall issued successfully';
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

// SEARCH LOGIC
$search = $_GET['search'] ?? '';
$search_sql = '';
$params = [];

if ($search) {
    $search_sql = " AND (l.lot_number LIKE :s OR d.comercial_name LIKE :s OR d.code_ATC LIKE :s)";
    $params['s'] = "%$search%";
}

// Get all lots
$stmt = $conn->prepare("
    SELECT 
        l.*,
        d.comercial_name, d.active_principle, d.code_ATC,
        s.name as storage_name
    FROM LOT_NUMBERS l
    JOIN DRUGS d ON l.drug_id = d.drug_id
    LEFT JOIN STORAGE s ON l.storage_id = s.storage_id
    WHERE NOT EXISTS (SELECT 1 FROM RECALLS r WHERE r.lot_number = l.lot_number AND r.status = 'active')
    $search_sql
    ORDER BY l.received_date DESC
");
$stmt->execute($params);
$lots = $stmt->fetchAll();

// QUERY RECALLS MODIFICADA: Añadido JOIN a STORAGE (s)
$stmt = $conn->prepare("
    SELECT 
        r.*,
        l.drug_id,
        d.comercial_name,
        d.code_ATC,
        s.name as storage_name
    FROM RECALLS r
    JOIN LOT_NUMBERS l ON r.lot_number = l.lot_number
    JOIN DRUGS d ON l.drug_id = d.drug_id
    LEFT JOIN STORAGE s ON l.storage_id = s.storage_id
    WHERE r.status = 'active'
    ORDER BY r.recall_date DESC
");
$stmt->execute();
$recalls = $stmt->fetchAll();

// Get drugs and storages for form
$drugs = $conn->query("SELECT drug_id, comercial_name, code_ATC FROM DRUGS ORDER BY comercial_name")->fetchAll();
$storages = $conn->query("SELECT storage_id, name FROM STORAGE ORDER BY name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lot Number Tracking & Recall Management</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .header h1 { font-size: 18px; font-weight: 400; color: #2c3e50; }
        .header-buttons { display: flex; gap: 12px; }
        .back-btn, .add-btn { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; border: none; cursor: pointer; }
        .back-btn { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; }
        .add-btn { background: #27ae60; color: white; }
        .container { max-width: 1600px; margin: 0 auto; padding: 32px; }
        .alert { padding: 14px 20px; border-radius: 4px; margin-bottom: 24px; font-size: 13px; }
        .alert-success { background: #d4edda; border-left: 3px solid #27ae60; color: #155724; }
        .alert-error { background: #fee; border-left: 3px solid #e74c3c; color: #c0392b; }
        .section-header { font-size: 13px; font-weight: 600; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; margin: 32px 0 16px 0; }
        .box { background: white; border: 1px solid #e1e8ed; border-radius: 4px; overflow: hidden; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; }
        table thead { background: #fafbfc; }
        table th { padding: 14px 16px; text-align: left; font-weight: 500; color: #2c3e50; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        table td { padding: 16px; border-bottom: 1px solid #f5f7fa; color: #2c3e50; font-size: 13px; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .recall-badge { display: inline-block; padding: 4px 10px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .recall-badge.critical { background: #ffebee; color: #c62828; } .recall-badge.major { background: #fff3e0; color: #e65100; } .recall-badge.minor { background: #fff9c4; color: #f57f17; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal.active { display: flex; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 32px; border-radius: 4px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
        .modal-header h2 { font-size: 16px; font-weight: 500; color: #2c3e50; }
        .close-btn { background: #e74c3c; padding: 6px 12px; color: white; border: none; border-radius: 3px; cursor: pointer; font-size: 11px; font-weight: 500; text-transform: uppercase; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #2c3e50; font-weight: 500; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        input, select, textarea { width: 100%; padding: 12px 16px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; background: #fafbfc; }
        button[type="submit"] { padding: 12px 24px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; }
        .action-btn { padding: 6px 12px; background: #e74c3c; color: white; border: none; border-radius: 3px; font-size: 11px; font-weight: 500; cursor: pointer; text-transform: uppercase; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Lot Number Tracking & Recall Management</h1>
        <div class="header-buttons">
            <button class="add-btn" onclick="openModal('addLot')">+ Add Lot</button>
            <a href="../dashboard.php" class="back-btn">Back to Dashboard</a>
        </div>
    </div>

    <div class="container">
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if (!empty($recalls)): ?>
        <div class="section-header">Active Recalls</div>
<div class="box">
    <table>
        <thead>
            <tr>
                <th>Severity</th>
                <th>Lot Number</th>
                <th>Medication</th>
                <th>ATC Code</th>
                <th>Storage</th>
                <th>Recall Date</th>
                <th>Reason</th>
                <th>Issued By</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recalls as $recall): ?>
            <tr>
                <td><span class="recall-badge <?= $recall['severity'] ?>"><?= strtoupper($recall['severity']) ?></span></td>
                <td><strong><?= htmlspecialchars($recall['lot_number']) ?></strong></td>
                <td><?= htmlspecialchars($recall['comercial_name']) ?></td>
                <td><?= htmlspecialchars($recall['code_ATC']) ?></td>
                <td><?= htmlspecialchars($recall['storage_name'] ?? 'N/A') ?></td>
                <td><?= date('Y-m-d', strtotime($recall['recall_date'])) ?></td>
                <td><?= htmlspecialchars($recall['reason']) ?></td>
                <td><?= htmlspecialchars($recall['issued_by']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
       
        <?php endif; ?>

        <form method="GET" style="margin-bottom: 20px; display:flex; gap:10px;">
            <input type="text" name="search" placeholder="Search Lot Number, Medication Name or ATC..." value="<?= htmlspecialchars($search) ?>" style="padding:10px; flex-grow:1; border:1px solid #ccc; border-radius:4px;">
            <button type="submit" class="add-btn" style="background:#3498db;">Search</button>
        </form>

        <div class="section-header">All Lot Numbers</div>
        <div class="box">
            <table>
                <thead>
                    <tr><th>Lot Number</th><th>Medication</th><th>ATC Code</th><th>Manufacturer</th><th>Expiration</th><th>Quantity</th><th>Received</th><th>Storage</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($lots as $lot): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($lot['lot_number']) ?></strong></td>
                        <td><?= htmlspecialchars($lot['comercial_name']) ?></td>
                        <td><?= htmlspecialchars($lot['code_ATC']) ?></td>
                        <td><?= htmlspecialchars($lot['manufacturer'] ?? 'N/A') ?></td>
                        <td><?= date('Y-m-d', strtotime($lot['expiration_date'])) ?></td>
                        <td><?= $lot['quantity'] ?> units</td>
                        <td><?= date('Y-m-d', strtotime($lot['received_date'])) ?></td>
                        <td><?= htmlspecialchars($lot['storage_name'] ?? 'N/A') ?></td>
                        <td><button class="action-btn" onclick="issueRecall('<?= htmlspecialchars($lot['lot_number']) ?>')">Issue Recall</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="addLotModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><h2>Register New Lot Number</h2><button class="close-btn" onclick="closeModal('addLot')">Close</button></div>
            <form method="POST">
                <div class="form-group"><label>Medication</label><select name="drug_id" required><option value="">Select medication...</option><?php foreach ($drugs as $drug): ?><option value="<?= $drug['drug_id'] ?>"><?= htmlspecialchars($drug['comercial_name'] . ' (' . $drug['code_ATC'] . ')') ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Lot Number</label><input type="text" name="lot_number" required></div>
                <div class="form-group"><label>Expiration Date</label><input type="date" name="expiration_date" required></div>
                <div class="form-group"><label>Quantity</label><input type="number" name="quantity" min="1" required></div>
                <div class="form-group"><label>Storage Location</label><select name="storage_id" required><option value="">Select storage...</option><?php foreach ($storages as $storage): ?><option value="<?= $storage['storage_id'] ?>"><?= htmlspecialchars($storage['name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Manufacturer</label><input type="text" name="manufacturer"></div>
                <button type="submit" name="add_lot">Register Lot</button>
            </form>
        </div>
    </div>

    <div id="recallModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><h2>Issue Recall</h2><button class="close-btn" onclick="closeModal('recall')">Close</button></div>
            <form method="POST">
                <input type="hidden" name="lot_number" id="recall_lot_number">
                <div class="form-group"><label>Severity</label><select name="severity" required><option value="critical">Critical - Life threatening</option><option value="major">Major - Serious health hazard</option><option value="minor">Minor - Low health risk</option></select></div>
                <div class="form-group"><label>Reason</label><textarea name="reason" rows="4" required></textarea></div>
                <div class="form-group"><label>Issued By</label><input type="text" name="issued_by" value="<?= htmlspecialchars($current_user_email) ?>" required></div>
                <button type="submit" name="issue_recall">Issue Recall</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(type) { document.getElementById(type + 'Modal').classList.add('active'); }
        function closeModal(type) { document.getElementById(type + 'Modal').classList.remove('active'); }
        function issueRecall(lotNumber) {
            document.getElementById('recall_lot_number').value = lotNumber;
            openModal('recall');
        }
    </script>
</body>
</html>

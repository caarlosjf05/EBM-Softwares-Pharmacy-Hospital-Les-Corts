<?php
// FILE: modules/stock_alerts.php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!hasAnyRole(['admin', 'pharmacist'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();
$success = '';
$error = '';

if (isset($_POST['add_batch'])) {
    try {
        $conn->beginTransaction();
        $drug_id = $_POST['drug_id'];
        $qty = (int)$_POST['quantity'];
        
        // FIXED: Removed created_at column, added manufacturer
        $stmt = $conn->prepare("INSERT INTO LOT_NUMBERS (drug_id, lot_number, quantity, expiration_date, storage_id, manufacturer, received_date) VALUES (:did, :lnum, :qty, :exp, :sid, :manu, NOW())");
        $stmt->execute([
            'did' => $drug_id, 
            'lnum' => $_POST['lot_number'], 
            'qty' => $qty, 
            'exp' => $_POST['expiration_date'], 
            'sid' => $_POST['storage_id'],
            'manu' => $_POST['manufacturer']
        ]);
        
        $update_total = $conn->prepare("UPDATE DRUGS SET actual_inventory = (SELECT COALESCE(SUM(quantity), 0) FROM LOT_NUMBERS WHERE drug_id = :did AND quantity > 0) WHERE drug_id = :did");
        $update_total->execute(['did' => $drug_id]);
        
        $conn->commit();
        $success = 'Stock replenished successfully.';
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Error adding batch: " . $e->getMessage();
    }
}

$storages_list = $conn->query("SELECT storage_id, name FROM STORAGE ORDER BY name ASC")->fetchAll();

$search = $_GET['search'] ?? '';
$search_clause = '';
$params = [];
if ($search) {
    $search_clause = " AND (d.comercial_name LIKE :s OR d.active_principle LIKE :s OR d.code_ATC LIKE :s)";
    $params['s'] = "%$search%";
}

$query = "
    SELECT d.drug_id, d.comercial_name, d.active_principle, d.code_ATC, d.minimum_stock, d.maximum_stock, d.unitary_price, d.actual_inventory
    FROM DRUGS d
    WHERE d.actual_inventory <= d.minimum_stock
    $search_clause
    ORDER BY d.actual_inventory ASC
";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$alerts = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock & Order Alerts</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f7fa; color: #333; margin: 0; }
        .header-module { background: white; border-bottom: 1px solid #e1e8ed; padding: 12px 32px; display: flex; align-items: center; justify-content: space-between; height: 64px; }
        .btn-back { color: #7f8c8d; text-decoration: none; font-size: 12px; font-weight: 500; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; }
        .container { max-width: 1200px; margin: 32px auto; padding: 0 20px; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: 500; font-size: 13px; }
        .alert-success { background: #e6f7ee; color: #27ae60; border: 1px solid #2ecc71; }
        .alert-error { background: #fdeaea; color: #e74c3c; border: 1px solid #c0392b; }
        .table-wrapper { background: white; border-radius: 4px; border: 1px solid #e1e8ed; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; text-align: left; padding: 15px; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #7f8c8d; border-bottom: 1px solid #e1e8ed; }
        td { padding: 15px; border-bottom: 1px solid #f5f7fa; font-size: 13px; color: #2c3e50; vertical-align: middle; }
        .stock-critical { color: #e74c3c; font-weight: 700; background: #ffebee; padding: 4px 8px; border-radius: 4px; }
        .stock-low { color: #f39c12; font-weight: 700; background: #fef9e7; padding: 4px 8px; border-radius: 4px; }
        .btn-restock { background: #2ecc71; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; text-transform: uppercase; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 25px; border-radius: 8px; width: 90%; max-width: 500px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 600; color: #7f8c8d; text-transform: uppercase; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; }
        .modal-footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1 style="font-size: 18px; color: #2c3e50;">Stock & Order Alerts</h1>
        <a href="../dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>

    <div class="container">
        <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="GET" style="margin-bottom: 20px; display:flex; gap:10px;">
            <input type="text" name="search" placeholder="Search by Name, Active Principle, or ATC..." value="<?= htmlspecialchars($search) ?>" style="padding:10px; flex-grow:1; border:1px solid #ccc; border-radius:4px;">
            <button type="submit" class="btn-restock" style="background:#3498db; font-size:13px; padding:10px 20px;">Search</button>
        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>Status</th><th>Medication</th><th>ATC Code</th><th>Current Stock</th><th>Min Required</th><th>Deficit</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($alerts)): ?>
                        <tr><td colspan="7" style="text-align: center; padding: 40px; color: #95a5a6;">All stock levels are healthy. No alerts.</td></tr>
                    <?php else: ?>
                        <?php foreach ($alerts as $item): ?>
                            <?php 
                                $current = $item['actual_inventory']; 
                                $min = $item['minimum_stock'];
                                $deficit = $min - $current;
                                $status = ($current == 0) ? '<span class="stock-critical">OUT OF STOCK</span>' : '<span class="stock-low">LOW STOCK</span>';
                            ?>
                            <tr>
                                <td><?= $status ?></td>
                                <td><strong><?= htmlspecialchars($item['comercial_name']) ?></strong><br><span style="color:#7f8c8d; font-size:11px;"><?= htmlspecialchars($item['active_principle']) ?></span></td>
                                <td><code><?= htmlspecialchars($item['code_ATC']) ?></code></td>
                                <td style="font-weight: 700; color: #c0392b;"><?= $current ?></td>
                                <td><?= $min ?></td>
                                <td style="color: #e74c3c;">-<?= $deficit ?></td>
                                <td><button class="btn-restock" onclick="openBatchModal(<?= $item['drug_id'] ?>)">+ Batch</button></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="batchModal" class="modal">
        <div class="modal-content">
            <h2>Receive Restock Batch</h2>
            <form method="POST">
                <input type="hidden" name="drug_id" id="batch_drug_id">
                <div class="form-group"><label>Lot Number</label><input type="text" name="lot_number" class="form-control" required></div>
                <div class="form-group"><label>Manufacturer</label><input type="text" name="manufacturer" class="form-control" placeholder="e.g. Pfizer, Novartis, Roche..." required></div>
                <div class="form-group"><label>Quantity</label><input type="number" name="quantity" min="1" class="form-control" required></div>
                <div class="form-group"><label>Expiration Date</label><input type="date" name="expiration_date" class="form-control" required></div>
                <div class="form-group"><label>Storage Location</label><select name="storage_id" class="form-control" required><?php foreach ($storages_list as $s): ?><option value="<?= $s['storage_id'] ?>"><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?></select></div>
                <div class="modal-footer"><button type="button" onclick="document.getElementById('batchModal').classList.remove('active')" class="btn-back">Cancel</button><button type="submit" name="add_batch" class="btn-restock">Save Batch</button></div>
            </form>
        </div>
    </div>
    <script>
        function openBatchModal(drugId) { document.getElementById('batch_drug_id').value = drugId; document.getElementById('batchModal').classList.add('active'); }
    </script>
</body>
</html>

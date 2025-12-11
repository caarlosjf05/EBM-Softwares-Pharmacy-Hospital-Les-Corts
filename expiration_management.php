<?php
// FILE: modules/expiration_management.php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!hasAnyRole(['admin', 'pharmacist'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();
$success = '';
$error = '';

if (isset($_POST['update_lot']) && hasRole('admin')) {
    try {
        $lot_id = $_POST['lot_id'];
        $new_qty = $_POST['quantity'];
        $new_date = $_POST['expiration_date'];
        $stmt = $conn->prepare("UPDATE LOT_NUMBERS SET quantity = :qty, expiration_date = :exp WHERE lot_id = :id");
        $stmt->execute(['qty' => $new_qty, 'exp' => $new_date, 'id' => $lot_id]);
        
        $get_drug = $conn->prepare("SELECT drug_id FROM LOT_NUMBERS WHERE lot_id = :id");
        $get_drug->execute(['id' => $lot_id]);
        $drug_id = $get_drug->fetchColumn();
        if ($drug_id) {
            $update_total = $conn->prepare("UPDATE DRUGS SET actual_inventory = (SELECT COALESCE(SUM(quantity),0) FROM LOT_NUMBERS WHERE drug_id = :did AND quantity > 0) WHERE drug_id = :did");
            $update_total->execute(['did' => $drug_id]);
        }
        $success = "Batch details updated successfully.";
    } catch (Exception $e) {
        $error = "Error updating batch: " . $e->getMessage();
    }
}

$search = $_GET['search'] ?? '';
$search_sql = '';
$params = [];
if ($search) {
    $search_sql = " AND (d.comercial_name LIKE :s OR d.active_principle LIKE :s OR d.code_ATC LIKE :s)";
    $params['s'] = "%$search%";
}

$query = "
    SELECT l.lot_id, l.lot_number, l.expiration_date, l.quantity, d.comercial_name, d.active_principle, d.unitary_price, s.name as storage_name
    FROM LOT_NUMBERS l
    JOIN DRUGS d ON l.drug_id = d.drug_id
    JOIN STORAGE s ON l.storage_id = s.storage_id
    WHERE l.quantity > 0
    $search_sql
    ORDER BY l.expiration_date ASC
";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$lots = $stmt->fetchAll();

$stats = ['total_lots' => count($lots), 'expired_count' => 0, 'soon_count' => 0, 'value_at_risk' => 0];
$now = new DateTime();
foreach ($lots as $lot) {
    $exp = new DateTime($lot['expiration_date']);
    $diff = $now->diff($exp);
    $days = (int)$diff->format('%r%a');
    $value = $lot['quantity'] * $lot['unitary_price'];
    if ($days < 0) { $stats['expired_count']++; $stats['value_at_risk'] += $value; }
    elseif ($days < 90) { $stats['soon_count']++; $stats['value_at_risk'] += $value; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Expiration Management</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f7fa; color: #333; margin: 0; }
        .header-module { background: white; border-bottom: 1px solid #e1e8ed; padding: 12px 32px; display: flex; align-items: center; justify-content: space-between; height: 64px; }
        .btn-back { color: #7f8c8d; text-decoration: none; font-size: 12px; font-weight: 500; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; }
        .container { max-width: 1200px; margin: 32px auto; padding: 0 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 4px; border: 1px solid #e1e8ed; }
        .stat-title { font-size: 11px; font-weight: 700; color: #95a5a6; text-transform: uppercase; margin-bottom: 10px; }
        .stat-value { font-size: 28px; font-weight: 300; color: #2c3e50; }
        .stat-card.red { border-left: 4px solid #e74c3c; } .stat-card.red .stat-value { color: #e74c3c; }
        .stat-card.orange { border-left: 4px solid #f39c12; } .stat-card.orange .stat-value { color: #f39c12; }
        .stat-card.blue { border-left: 4px solid #3498db; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; font-weight: 500; }
        .alert-success { background: #e6f7ee; color: #27ae60; border: 1px solid #2ecc71; }
        .alert-error { background: #fdeaea; color: #e74c3c; border: 1px solid #c0392b; }
        .table-wrapper { background: white; border-radius: 4px; border: 1px solid #e1e8ed; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; text-align: left; padding: 15px; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #7f8c8d; border-bottom: 1px solid #e1e8ed; }
        td { padding: 15px; border-bottom: 1px solid #f5f7fa; font-size: 13px; color: #2c3e50; vertical-align: middle; }
        .badge { padding: 4px 8px; border-radius: 4px; font-weight: 700; font-size: 10px; text-transform: uppercase; display: inline-block; }
        .expired { background: #ffebee; color: #c62828; } .soon { background: #fff3e0; color: #ef6c00; } .ok { background: #e8f5e9; color: #2e7d32; }
        .btn-edit { background: white; color: #3498db; border: 1px solid #dce1e6; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 8px; width: 400px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-size: 12px; font-weight: 600; color: #7f8c8d; text-transform: uppercase; }
        input { width: 100%; padding: 10px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; }
        .modal-footer { margin-top: 25px; text-align: right; display: flex; justify-content: flex-end; gap: 10px; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1 style="font-size: 18px; color: #2c3e50;">Expiration Date Management</h1>
        <a href="../dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>

    <div class="container">
        <div class="stats-grid">
            <div class="stat-card red"><div class="stat-title">Critical / Expired</div><div class="stat-value"><?= $stats['expired_count'] ?></div></div>
            <div class="stat-card orange"><div class="stat-title">Expiring Soon (< 90 Days)</div><div class="stat-value"><?= $stats['soon_count'] ?></div></div>
            <div class="stat-card blue"><div class="stat-title">Active Lots</div><div class="stat-value"><?= $stats['total_lots'] ?></div></div>
            <div class="stat-card"><div class="stat-title">Value at Risk</div><div class="stat-value">€<?= number_format($stats['value_at_risk'], 2) ?></div></div>
        </div>

        <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="GET" style="margin-bottom: 20px; display:flex; gap:10px;">
            <input type="text" name="search" placeholder="Search by Name, Active Principle, or ATC..." value="<?= htmlspecialchars($search) ?>" style="padding:10px; flex-grow:1; border:1px solid #ccc; border-radius:4px;">
            <button type="submit" class="btn-edit" style="background:#3498db; color:white; border:none; padding:10px 20px;">Search</button>
        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr><th>Status</th><th>Expiration Date</th><th>Medication</th><th>Lot Number</th><th>Quantity</th><th>Value</th><th>Location</th><?php if (hasRole('admin')): ?><th>Actions</th><?php endif; ?></tr>
                </thead>
                <tbody>
                    <?php if (empty($lots)): ?>
                        <tr><td colspan="8" style="text-align: center; color: #95a5a6; padding: 30px;">No active lots found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($lots as $lot): ?>
                            <?php
                                $exp = new DateTime($lot['expiration_date']);
                                $now = new DateTime();
                                $diff = $now->diff($exp);
                                $days = (int)$diff->format('%r%a');
                                if ($days < 0) { $class = 'expired'; $text = 'EXPIRED'; } 
                                elseif ($days < 90) { $class = 'soon'; $text = 'Expiring Soon'; } 
                                else { $class = 'ok'; $text = 'Good'; }
                            ?>
                            <tr>
                                <td><span class="badge <?= $class ?>"><?= $text ?></span></td>
                                <td style="font-weight: 600; color: #2c3e50;"><?= htmlspecialchars($lot['expiration_date']) ?></td>
                                <td><strong><?= htmlspecialchars($lot['comercial_name']) ?></strong><br><span style="color:#7f8c8d; font-size:11px;"><?= htmlspecialchars($lot['active_principle']) ?></span></td>
                                <td style="font-family: monospace; color: #555;"><?= htmlspecialchars($lot['lot_number']) ?></td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($lot['quantity']) ?></td>
                                <td style="color: #7f8c8d;">€<?= number_format($lot['quantity'] * $lot['unitary_price'], 2) ?></td>
                                <td><?= htmlspecialchars($lot['storage_name']) ?></td>
                                <?php if (hasRole('admin')): ?>
                                <td><button class="btn-edit" onclick="openEditModal(<?= $lot['lot_id'] ?>, '<?= $lot['quantity'] ?>', '<?= $lot['expiration_date'] ?>')">Edit</button></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (hasRole('admin')): ?>
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2>Edit Batch Details</h2>
            <form method="POST">
                <input type="hidden" name="lot_id" id="modal_lot_id">
                <div class="form-group"><label>Quantity</label><input type="number" name="quantity" id="modal_qty" required min="0"></div>
                <div class="form-group"><label>Expiration Date</label><input type="date" name="expiration_date" id="modal_date" required></div>
                <div class="modal-footer">
                    <button type="button" onclick="document.getElementById('editModal').classList.remove('active')" style="background:transparent; border:1px solid #dce1e6; padding:10px 20px; border-radius:4px;">Cancel</button>
                    <button type="submit" name="update_lot" style="background:#3498db; color:white; border:none; padding:10px 20px; border-radius:4px;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openEditModal(id, qty, date) { document.getElementById('modal_lot_id').value = id; document.getElementById('modal_qty').value = qty; document.getElementById('modal_date').value = date; document.getElementById('editModal').classList.add('active'); }
    </script>
    <?php endif; ?>
</body>
</html>
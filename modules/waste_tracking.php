<?php
require_once '../includes/auth.php';

if (!hasAnyRole(['admin', 'nurse'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();
$success = '';
$error = '';

// Record waste
if (isset($_POST['record_waste'])) {
    try {
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("
            INSERT INTO WASTE_LOG (drug_id, lot_number, quantity, waste_type, reason, recorded_by, recorded_at, cost_impact)
            VALUES (:drug_id, :lot_number, :quantity, :waste_type, :reason, :recorded_by, NOW(), :cost)
        ");
        $stmt->execute([
            'drug_id' => $_POST['drug_id'],
            'lot_number' => $_POST['lot_number'],
            'quantity' => $_POST['quantity'],
            'waste_type' => $_POST['waste_type'],
            'reason' => $_POST['reason'],
            'recorded_by' => $current_user_email,
            'cost' => $_POST['cost_impact']
        ]);
        
        // Update inventory
        $stmt = $conn->prepare("UPDATE DRUGS SET actual_inventory = actual_inventory - :qty WHERE drug_id = :drug_id");
        $stmt->execute(['qty' => $_POST['quantity'], 'drug_id' => $_POST['drug_id']]);
        
        // Update lot quantity
        if ($_POST['lot_number']) {
            $stmt = $conn->prepare("UPDATE LOT_NUMBERS SET quantity = quantity - :qty WHERE lot_number = :lot");
            $stmt->execute(['qty' => $_POST['quantity'], 'lot' => $_POST['lot_number']]);
        }
        
        $conn->commit();
        $success = 'Waste recorded successfully';
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

// Get waste logs
$stmt = $conn->query("
    SELECT 
        w.*,
        d.comercial_name,
        d.code_ATC
    FROM WASTE_LOG w
    JOIN DRUGS d ON w.drug_id = d.drug_id
    ORDER BY w.recorded_at DESC
    LIMIT 100
");
$waste_logs = $stmt->fetchAll();

// Calculate waste statistics
$stmt = $conn->query("
    SELECT 
        waste_type,
        COUNT(*) as count,
        SUM(quantity) as total_qty,
        SUM(cost_impact) as total_cost
    FROM WASTE_LOG
    WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY waste_type
");
$waste_stats = $stmt->fetchAll();

// Get drugs for form
$stmt = $conn->query("SELECT drug_id, comercial_name, code_ATC, unitary_price FROM DRUGS ORDER BY comercial_name");
$drugs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waste Tracking</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .header h1 { font-size: 18px; font-weight: 400; color: #2c3e50; }
        .back-btn, .add-btn { padding: 8px 16px; border-radius: 4px; font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; text-decoration: none; border: none; cursor: pointer; }
        .back-btn { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; }
        .add-btn { background: #e74c3c; color: white; }
        .container { max-width: 1600px; margin: 0 auto; padding: 32px; }
        .alert { padding: 14px 20px; border-radius: 4px; margin-bottom: 24px; font-size: 13px; }
        .alert-success { background: #d4edda; border-left: 3px solid #27ae60; color: #155724; }
        .alert-error { background: #fee; border-left: 3px solid #e74c3c; color: #c0392b; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-bottom: 32px; }
        .stat-card { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 20px; }
        .stat-label { color: #7f8c8d; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-number { font-size: 28px; font-weight: 300; color: #2c3e50; }
        .stat-card.expired .stat-number { color: #e67e22; }
        .stat-card.damaged .stat-number { color: #e74c3c; }
        .stat-card.returned .stat-number { color: #3498db; }
        .section-header { font-size: 13px; font-weight: 600; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; margin: 32px 0 16px 0; }
        .box { background: white; border: 1px solid #e1e8ed; border-radius: 4px; overflow: hidden; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; }
        table thead { background: #fafbfc; }
        table th { padding: 14px 16px; text-align: left; font-weight: 500; color: #2c3e50; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        table td { padding: 16px; border-bottom: 1px solid #f5f7fa; color: #2c3e50; font-size: 13px; }
        .waste-badge { display: inline-block; padding: 4px 10px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .waste-badge.expired { background: #fff3e0; color: #e65100; }
        .waste-badge.damaged { background: #ffebee; color: #c62828; }
        .waste-badge.returned { background: #e3f2fd; color: #1565c0; }
        .waste-badge.other { background: #f5f5f5; color: #616161; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal.active { display: flex; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 32px; border-radius: 4px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #2c3e50; font-weight: 500; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        input, select, textarea { width: 100%; padding: 12px 16px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; background: #fafbfc; }
        button[type="submit"] { padding: 12px 24px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🗑️ Waste Tracking</h1>
        <div style="display: flex; gap: 12px;">
            <button class="add-btn" onclick="openModal()">+ Record Waste</button>
            <a href="../dashboard.php" class="back-btn">Back to Dashboard</a>
        </div>
    </div>

    <div class="container">
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="section-header">📊 Waste Statistics (Last 30 Days)</div>
        <div class="stats-grid">
            <?php foreach ($waste_stats as $stat): ?>
            <div class="stat-card <?= $stat['waste_type'] ?>">
                <div class="stat-label"><?= ucfirst($stat['waste_type']) ?></div>
                <div class="stat-number"><?= $stat['total_qty'] ?> units</div>
                <div style="color: #7f8c8d; font-size: 12px; margin-top: 8px;">
                    <?= $stat['count'] ?> incidents | €<?= number_format($stat['total_cost'], 2) ?> loss
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="section-header">📋 Waste Log</div>
        <div class="box">
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Medication</th>
                        <th>ATC</th>
                        <th>Lot #</th>
                        <th>Quantity</th>
                        <th>Cost Impact</th>
                        <th>Reason</th>
                        <th>Recorded</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($waste_logs as $log): ?>
                    <tr>
                        <td><span class="waste-badge <?= $log['waste_type'] ?>"><?= ucfirst($log['waste_type']) ?></span></td>
                        <td><?= htmlspecialchars($log['comercial_name']) ?></td>
                        <td><?= htmlspecialchars($log['code_ATC']) ?></td>
                        <td><?= htmlspecialchars($log['lot_number'] ?: 'N/A') ?></td>
                        <td><?= $log['quantity'] ?></td>
                        <td>€<?= number_format($log['cost_impact'], 2) ?></td>
                        <td><?= htmlspecialchars($log['reason']) ?></td>
                        <td><?= date('Y-m-d H:i', strtotime($log['recorded_at'])) ?></td>
                        <td><?= htmlspecialchars($log['recorded_by']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="wasteModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 24px; font-size: 16px;">Record Waste</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Medication</label>
                    <select name="drug_id" id="drug_select" onchange="updatePrice()" required>
                        <option value="">Select...</option>
                        <?php foreach ($drugs as $drug): ?>
                            <option value="<?= $drug['drug_id'] ?>" data-price="<?= $drug['unitary_price'] ?>">
                                <?= htmlspecialchars($drug['comercial_name'] . ' (' . $drug['code_ATC'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Waste Type</label>
                    <select name="waste_type" required>
                        <option value="expired">Expired</option>
                        <option value="damaged">Damaged</option>
                        <option value="returned">Patient Return</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Lot Number (Optional)</label>
                    <input type="text" name="lot_number">
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" id="quantity" min="1" onchange="calculateCost()" required>
                </div>
                <div class="form-group">
                    <label>Cost Impact (€)</label>
                    <input type="number" name="cost_impact" id="cost_impact" step="0.01" required>
                </div>
                <div class="form-group">
                    <label>Reason</label>
                    <textarea name="reason" rows="3" required></textarea>
                </div>
                <button type="submit" name="record_waste">Record Waste</button>
                <button type="button" onclick="closeModal()" style="background: #95a5a6; margin-left: 12px;">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('wasteModal').classList.add('active');
        }
        function closeModal() {
            document.getElementById('wasteModal').classList.remove('active');
        }
        function updatePrice() {
            const select = document.getElementById('drug_select');
            const price = select.options[select.selectedIndex].dataset.price;
            document.getElementById('cost_impact').value = price || 0;
            calculateCost();
        }
        function calculateCost() {
            const qty = document.getElementById('quantity').value || 0;
            const select = document.getElementById('drug_select');
            const price = select.options[select.selectedIndex].dataset.price || 0;
            document.getElementById('cost_impact').value = (qty * price).toFixed(2);
        }
    </script>
</body>
</html>

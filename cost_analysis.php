<?php
require_once '../includes/auth.php';

if (!hasAnyRole(['admin', 'nurse', 'doctor'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// Date filter
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// Calculate generic substitution savings
$stmt = $conn->prepare("
    SELECT 
        d.comercial_name,
        d.active_principle,
        d.unitary_price,
        COUNT(DISTINCT disp.dispensing_id) as times_dispensed,
        SUM(disp.quantity) as total_qty,
        (d.unitary_price * SUM(disp.quantity)) as total_cost
    FROM DRUGS d
    JOIN PRESCRIPTION_ITEM pi ON d.drug_id = pi.drug_id
    JOIN DISPENSING disp ON pi.prescription_item_id = disp.prescription_item_id
    WHERE disp.data_dispensing BETWEEN :date_from AND :date_to
    GROUP BY d.drug_id
    ORDER BY total_cost DESC
");
$stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
$medications = $stmt->fetchAll();

// Find generic substitution opportunities
$stmt = $conn->prepare("
    SELECT 
        d1.comercial_name as brand_name,
        d2.comercial_name as generic_name,
        d1.active_principle,
        d1.unitary_price as brand_price,
        d2.unitary_price as generic_price,
        (d1.unitary_price - d2.unitary_price) as savings_per_unit,
        COUNT(disp.dispensing_id) as times_dispensed,
        SUM(disp.quantity) as total_qty,
        ((d1.unitary_price - d2.unitary_price) * SUM(disp.quantity)) as potential_savings
    FROM DRUGS d1
    JOIN DRUGS d2 ON d1.active_principle = d2.active_principle 
        AND d1.drug_id != d2.drug_id 
        AND d1.unitary_price > d2.unitary_price
    JOIN PRESCRIPTION_ITEM pi ON d1.drug_id = pi.drug_id
    JOIN DISPENSING disp ON pi.prescription_item_id = disp.prescription_item_id
    WHERE disp.data_dispensing BETWEEN :date_from AND :date_to
    GROUP BY d1.drug_id, d2.drug_id
    HAVING potential_savings > 0
    ORDER BY potential_savings DESC
    LIMIT 20
");
$stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
$substitution_opportunities = $stmt->fetchAll();

// Calculate totals
$total_spent = array_sum(array_column($medications, 'total_cost'));
$potential_savings = array_sum(array_column($substitution_opportunities, 'potential_savings'));
$generic_rate = 0;
if (count($medications) > 0) {
    $generic_count = count(array_filter($medications, fn($m) => $m['unitary_price'] < 10));
    $generic_rate = ($generic_count / count($medications)) * 100;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cost Analysis & Generic Substitution</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .header h1 { font-size: 18px; font-weight: 400; color: #2c3e50; }
        .back-btn { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
        .container { max-width: 1600px; margin: 0 auto; padding: 32px; }
        .filter-box { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 20px; margin-bottom: 24px; }
        .filter-form { display: flex; gap: 12px; align-items: center; }
        .filter-form input { padding: 10px 14px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; }
        .filter-form button { padding: 10px 20px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 12px; font-weight: 500; cursor: pointer; text-transform: uppercase; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
        .stat-card { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 20px; }
        .stat-label { color: #7f8c8d; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-number { font-size: 28px; font-weight: 300; color: #2c3e50; }
        .stat-card.savings .stat-number { color: #27ae60; }
        .section-header { font-size: 13px; font-weight: 600; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; margin: 32px 0 16px 0; }
        .box { background: white; border: 1px solid #e1e8ed; border-radius: 4px; overflow: hidden; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; }
        table thead { background: #fafbfc; }
        table th { padding: 14px 16px; text-align: left; font-weight: 500; color: #2c3e50; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        table td { padding: 16px; border-bottom: 1px solid #f5f7fa; color: #2c3e50; font-size: 13px; }
        .savings-badge { display: inline-block; padding: 4px 10px; border-radius: 3px; font-size: 10px; font-weight: 600; background: #d4edda; color: #155724; }
        .price { font-weight: 600; }
    </style>
</head>
<body>
    <div class="header">
        <h1>💰 Cost Analysis & Generic Substitution</h1>
        <a href="../dashboard.php" class="back-btn">Back to Dashboard</a>
    </div>

    <div class="container">
        <div class="filter-box">
            <form method="GET" class="filter-form">
                <label style="font-size: 12px; color: #7f8c8d;">From:</label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                <label style="font-size: 12px; color: #7f8c8d;">To:</label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                <button type="submit">Filter</button>
            </form>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Spent</div>
                <div class="stat-number">€<?= number_format($total_spent, 2) ?></div>
            </div>
            <div class="stat-card savings">
                <div class="stat-label">Potential Savings</div>
                <div class="stat-number">€<?= number_format($potential_savings, 2) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Generic Dispensing Rate</div>
                <div class="stat-number"><?= number_format($generic_rate, 1) ?>%</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Substitution Opportunities</div>
                <div class="stat-number"><?= count($substitution_opportunities) ?></div>
            </div>
        </div>

        <?php if (!empty($substitution_opportunities)): ?>
        <div class="section-header">💡 Generic Substitution Opportunities</div>
        <div class="box">
            <table>
                <thead>
                    <tr>
                        <th>Brand Name</th>
                        <th>Generic Alternative</th>
                        <th>Active Principle</th>
                        <th>Brand Price</th>
                        <th>Generic Price</th>
                        <th>Savings/Unit</th>
                        <th>Qty Dispensed</th>
                        <th>Total Potential Savings</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($substitution_opportunities as $opp): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($opp['brand_name']) ?></strong></td>
                        <td><?= htmlspecialchars($opp['generic_name']) ?></td>
                        <td><small style="color: #7f8c8d;"><?= htmlspecialchars($opp['active_principle']) ?></small></td>
                        <td class="price">€<?= number_format($opp['brand_price'], 2) ?></td>
                        <td class="price">€<?= number_format($opp['generic_price'], 2) ?></td>
                        <td><span class="savings-badge">€<?= number_format($opp['savings_per_unit'], 2) ?></span></td>
                        <td><?= $opp['total_qty'] ?> units</td>
                        <td><strong style="color: #27ae60;">€<?= number_format($opp['potential_savings'], 2) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="section-header">📊 Medication Cost Breakdown</div>
        <div class="box">
            <table>
                <thead>
                    <tr>
                        <th>Medication</th>
                        <th>Active Principle</th>
                        <th>Unit Price</th>
                        <th>Times Dispensed</th>
                        <th>Total Quantity</th>
                        <th>Total Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($medications as $med): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($med['comercial_name']) ?></strong></td>
                        <td><?= htmlspecialchars($med['active_principle']) ?></td>
                        <td class="price">€<?= number_format($med['unitary_price'], 2) ?></td>
                        <td><?= $med['times_dispensed'] ?></td>
                        <td><?= $med['total_qty'] ?> units</td>
                        <td><strong>€<?= number_format($med['total_cost'], 2) ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
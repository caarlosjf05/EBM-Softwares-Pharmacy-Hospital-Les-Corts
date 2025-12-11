<?php
// FILE: modules/temperature_control.php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Access: Pharmacist (and Admin)
if (!hasAnyRole(['pharmacist', 'admin'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();
$success = '';
$error = '';

// HANDLE FORM SUBMISSION (Update Temp)
if (isset($_POST['update_temp'])) {
    try {
        $storage_id = $_POST['storage_id'];
        $new_temp = $_POST['current_temp'];
        
        $stmt = $conn->prepare("UPDATE STORAGE SET current_temp = :temp, last_temp_update = NOW() WHERE storage_id = :id");
        $stmt->execute(['temp' => $new_temp, 'id' => $storage_id]);
        
        $success = "Temperature updated successfully.";
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// GET STORAGES (Only those that require temp control)
$stmt = $conn->query("
    SELECT * FROM STORAGE 
    WHERE min_temp IS NOT NULL OR max_temp IS NOT NULL 
    ORDER BY name ASC
");
$storages = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Temperature Control</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f7fa; margin: 0; }
        .header-module { background: white; border-bottom: 1px solid #e1e8ed; padding: 12px 32px; display: flex; align-items: center; justify-content: space-between; height: 64px; }
        .btn-back { color: #7f8c8d; text-decoration: none; font-size: 12px; font-weight: 500; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; text-transform: uppercase; }
        .container { max-width: 1000px; margin: 32px auto; padding: 0 20px; }
        
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-success { background: #e6f7ee; color: #27ae60; border: 1px solid #2ecc71; }
        
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }
        
        .card { background: white; border: 1px solid #e1e8ed; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: transform 0.2s; }
        .card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.05); }
        
        /* DYNAMIC BORDER COLOR BASED ON STATUS */
        .status-ok { border-left: 5px solid #2ecc71; }
        .status-alert { border-left: 5px solid #e74c3c; background-color: #fff5f5; }
        .status-null { border-left: 5px solid #95a5a6; }

        .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
        .storage-name { font-weight: 700; font-size: 16px; color: #2c3e50; }
        .storage-loc { font-size: 12px; color: #7f8c8d; background: #f5f6fa; padding: 2px 6px; border-radius: 4px; }
        
        .temp-display { font-size: 32px; font-weight: 300; margin: 10px 0; color: #2c3e50; display: flex; align-items: center; gap: 10px; }
        .warning-icon { font-size: 20px; color: #e74c3c; display: none; }
        .status-alert .warning-icon { display: inline-block; }
        
        .range-info { font-size: 12px; color: #7f8c8d; margin-bottom: 20px; }
        
        .update-form { display: flex; gap: 10px; border-top: 1px solid #eee; padding-top: 15px; }
        .input-temp { width: 100px; padding: 8px; border: 1px solid #dce1e6; border-radius: 4px; }
        .btn-update { background: #27ae60; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 12px; text-transform: uppercase; flex-grow: 1; }
        .btn-update:hover { background: #219150; }
        
        .last-update { font-size: 11px; color: #95a5a6; text-align: right; margin-top: 5px; font-style: italic; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1 style="font-size: 18px; color: #2c3e50;">Temperature Control</h1>
        <a href="../dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>

    <div class="container">
        <?php if ($success): ?><div class="alert alert-success">✅ <?= $success ?></div><?php endif; ?>

        <div class="grid">
            <?php foreach ($storages as $s): ?>
                <?php
                    // LOGIC: Check if temp is out of range
                    $current = $s['current_temp'];
                    $min = $s['min_temp'];
                    $max = $s['max_temp'];
                    $status_class = 'status-null';
                    
                    if ($current !== null) {
                        if ($current < $min || $current > $max) {
                            $status_class = 'status-alert';
                        } else {
                            $status_class = 'status-ok';
                        }
                    }
                ?>
                <div class="card <?= $status_class ?>">
                    <div class="card-header">
                        <div>
                            <div class="storage-name"><?= htmlspecialchars($s['name']) ?></div>
                            <span class="storage-loc"><?= htmlspecialchars($s['location_type']) ?></span>
                        </div>
                        <?php if($status_class == 'status-alert'): ?>
                            <span style="background:#e74c3c; color:white; padding:4px 8px; border-radius:4px; font-size:10px; font-weight:700;">ALERT</span>
                        <?php endif; ?>
                    </div>

                    <div class="temp-display">
                        <span class="warning-icon">⚠</span>
                        <?= $current !== null ? $current . '°C' : '--' ?>
                    </div>
                    
                    <div class="range-info">
                        Target Range: <strong><?= $min ?>°C</strong> to <strong><?= $max ?>°C</strong>
                    </div>

                    <form method="POST" class="update-form">
                        <input type="hidden" name="storage_id" value="<?= $s['storage_id'] ?>">
                        <input type="number" step="0.1" name="current_temp" class="input-temp" placeholder="New °C" required>
                        <button type="submit" name="update_temp" class="btn-update">Update</button>
                    </form>
                    
                    <?php if($s['last_temp_update']): ?>
                        <div class="last-update">Updated: <?= date('d M H:i', strtotime($s['last_temp_update'])) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
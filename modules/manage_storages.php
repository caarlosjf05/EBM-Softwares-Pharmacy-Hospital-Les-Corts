<?php
// FILE: modules/manage_storages.php
require_once '../includes/auth.php'; 
require_once '../includes/functions.php';

// Access: Admin, Nurse, Pharmacist
if (!hasAnyRole(['admin', 'nurse', 'pharmacist'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();
$success = '';
$error = '';

// 1. PROCESS NEW STORAGE (CREATE)
if (isset($_POST['add_storage']) && hasRole('admin')) {
    $name = trim($_POST['name']);
    
    if (empty($name)) {
        $error = 'The storage name is mandatory.';
    } else {
        try {
            $stmt = $conn->prepare("
                INSERT INTO STORAGE (
                    name, location_type, is_controlled_access, 
                    building, floor, min_temp, max_temp
                ) VALUES (
                    :name, :loc_type, :is_controlled, 
                    :bldg, :floor, :min_t, :max_t
                )
            ");
            
            $stmt->execute([
                'name' => $name,
                'loc_type' => $_POST['location_type'],
                'is_controlled' => $_POST['is_controlled_access'],
                'bldg' => $_POST['building'],
                'floor' => $_POST['floor'],
                'min_t' => !empty($_POST['min_temp']) ? $_POST['min_temp'] : null,
                'max_t' => !empty($_POST['max_temp']) ? $_POST['max_temp'] : null
            ]);
            
            header('Location: manage_storages.php?success=' . urlencode('New storage location added successfully.'));
            exit();
        } catch (PDOException $e) {
            $error = 'Error adding storage: ' . $e->getMessage();
        }
    }
}

// 2. PROCESS EDIT STORAGE (UPDATE)
if (isset($_POST['edit_storage']) && hasRole('admin')) {
    $id = $_POST['storage_id'];
    $name = trim($_POST['name']);
    
    try {
        $stmt = $conn->prepare("
            UPDATE STORAGE SET 
                name = :name,
                location_type = :loc_type,
                is_controlled_access = :is_controlled,
                building = :bldg,
                floor = :floor,
                min_temp = :min_t,
                max_temp = :max_t
            WHERE storage_id = :id
        ");
        
        $stmt->execute([
            'name' => $name,
            'loc_type' => $_POST['location_type'],
            'is_controlled' => $_POST['is_controlled_access'],
            'bldg' => $_POST['building'],
            'floor' => $_POST['floor'],
            'min_t' => !empty($_POST['min_temp']) ? $_POST['min_temp'] : null,
            'max_t' => !empty($_POST['max_temp']) ? $_POST['max_temp'] : null,
            'id' => $id
        ]);
        
        header('Location: manage_storages.php?success=' . urlencode('Storage details updated successfully.'));
        exit();
    } catch (PDOException $e) {
        $error = 'Error updating storage: ' . $e->getMessage();
    }
}

if (isset($_GET['success'])) {
    $success = htmlspecialchars($_GET['success']);
}

// GET STORAGES
$stmt = $conn->query("SELECT * FROM STORAGE ORDER BY name ASC");
$storages = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Storage Management</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f7fa; color: #333; margin: 0; }
        
        /* HEADER */
        .header-module { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .header-module h1 { font-size: 18px; font-weight: 400; color: #2c3e50; margin: 0; }
        .header-actions { display: flex; gap: 8px; align-items: center; }

        .container { max-width: 1300px; margin: 0 auto; padding: 32px; }
        
        /* BUTTONS */
        .btn-back { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 500; text-transform: uppercase; transition: all 0.2s; }
        .btn-back:hover { border-color: #95a5a6; color: #2c3e50; }
        
        .btn-action { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 600; color: white; border: none; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .btn-add { background: #2ecc71; } .btn-add:hover { background: #27ae60; }
        
        /* EDIT BUTTON */
        .btn-edit { background: white; border: 1px solid #dce1e6; color: #3498db; padding: 4px 12px; border-radius: 4px; font-size: 11px; font-weight: 600; cursor: pointer; text-transform: uppercase; }
        .btn-edit:hover { background: #ebf5fb; border-color: #3498db; }

        /* ALERTS */
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: 500; font-size: 13px; }
        .alert-success { background: #e6f7ee; color: #27ae60; border: 1px solid #2ecc71; }
        .alert-error { background: #fdeaea; color: #e74c3c; border: 1px solid #c0392b; }

        /* TABLE */
        .table-wrapper { background: white; border: 1px solid #e1e8ed; border-radius: 4px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; color: #7f8c8d; font-weight: 600; text-transform: uppercase; font-size: 11px; padding: 15px 20px; text-align: left; border-bottom: 1px solid #e1e8ed; }
        td { padding: 15px 20px; border-bottom: 1px solid #f5f7fa; font-size: 13px; color: #2c3e50; vertical-align: middle; }
        tr:hover { background-color: #fcfdff; }

        /* BADGES */
        .badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block; }
        .badge-controlled { background: #fdeef4; color: #e91e63; }
        .badge-open { background: #e8f8f5; color: #16a085; }
        
        .location-tag { background: #ebf5fb; color: #2980b9; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-family: monospace; font-weight: 600; }
        
        /* TEMP ALERTS */
        .temp-ok { color: #27ae60; font-weight: 600; }
        .temp-alert { color: #c0392b; font-weight: 700; background: #ffebee; padding: 4px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 5px; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }

        /* MODAL */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 30px; border-radius: 8px; width: 100%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); }
        .modal h2 { margin-top: 0; font-size: 18px; color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group.full { grid-column: span 2; }
        .form-group label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 600; color: #7f8c8d; text-transform: uppercase; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .form-control:focus { outline: none; border-color: #3498db; }
        
        .modal-footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1>Storage & Location Management</h1>
        <div class="header-actions">
            <?php if(hasRole('admin')): ?>
                <button onclick="openModal('addModal')" class="btn-action btn-add">+ New Storage</button>
            <?php endif; ?>
            <a href="../dashboard.php" class="btn-back">Dashboard</a>
        </div>
    </div>

    <div class="container">
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th>Storage Name</th>
                        <th>Physical Location</th>
                        <th>Status (Current Temp)</th>
                        <th>Target Range</th>
                        <th>Type</th>
                        <th>Security</th>
                        <?php if(hasRole('admin')): ?><th>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($storages as $storage): ?>
                        <?php 
                            // DATOS
                            $is_controlled = ($storage['is_controlled_access'] == 1);
                            $badge_class = $is_controlled ? 'badge-controlled' : 'badge-open';
                            $badge_text = $is_controlled ? 'Restricted' : 'Open';
                            
                            $cur = $storage['current_temp'];
                            $min = $storage['min_temp'];
                            $max = $storage['max_temp'];
                            
                            // LÓGICA DE ALERTA VISUAL
                            $status_html = '<span style="color:#95a5a6;">--</span>';
                            $range_html = '<span style="color:#ccc;">Ambient</span>';

                            if ($min !== null || $max !== null) {
                                // CORRECCIÓN AQUÍ: Concatenación limpia para evitar errores
                                $range_html = $min . '° - ' . $max . '°C';
                                
                                if ($cur !== null) {
                                    if ($cur < $min || $cur > $max) {
                                        $status_html = "<span class='temp-alert'>⚠ {$cur}°C (ALERT)</span>";
                                    } else {
                                        $status_html = "<span class='temp-ok'>✓ {$cur}°C</span>";
                                    }
                                } else {
                                    $status_html = "<span style='color:#f39c12;'>No Data</span>";
                                }
                            }

                            // Datos seguros para JS
                            $js_name = htmlspecialchars($storage['name'], ENT_QUOTES);
                            $js_loc = htmlspecialchars($storage['location_type'], ENT_QUOTES);
                            $js_bldg = htmlspecialchars($storage['building'] ?? '', ENT_QUOTES);
                            $js_floor = htmlspecialchars($storage['floor'] ?? '', ENT_QUOTES);
                            $js_min = $storage['min_temp'] ?? '';
                            $js_max = $storage['max_temp'] ?? '';
                        ?>
                        <tr>
                            <td style="color: #95a5a6;">#<?= htmlspecialchars($storage['storage_id']) ?></td>
                            <td style="font-weight: 600; color: #2c3e50;">
                                <?= htmlspecialchars($storage['name']) ?>
                            </td>
                            <td>
                                <span class="location-tag">Bldg <?= htmlspecialchars($storage['building']) ?> / Fl <?= htmlspecialchars($storage['floor']) ?></span>
                            </td>
                            <td><?= $status_html ?></td>
                            <td style="font-size: 12px; color: #7f8c8d;"><?= $range_html ?></td>
                            <td><?= htmlspecialchars($storage['location_type']) ?></td>
                            <td>
                                <span class="badge <?= $badge_class ?>"><?= $badge_text ?></span>
                            </td>
                            
                            <?php if(hasRole('admin')): ?>
                            <td style="text-align: right;">
                                <button class="btn-edit" onclick="openEditModal(
                                    <?= $storage['storage_id'] ?>, 
                                    '<?= $js_name ?>', 
                                    '<?= $js_loc ?>', 
                                    <?= $storage['is_controlled_access'] ?>, 
                                    '<?= $js_bldg ?>', 
                                    '<?= $js_floor ?>', 
                                    '<?= $js_min ?>', 
                                    '<?= $js_max ?>'
                                )">Edit</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if(hasRole('admin')): ?>
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2>Add New Storage Location</h2>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Storage Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="location_type" class="form-control" required>
                            <option value="General">General</option>
                            <option value="Refrigerated">Refrigerated</option>
                            <option value="Ward Stock">Ward Stock</option>
                            <option value="Pharmacy">Pharmacy</option>
                            <option value="Secure Vault">Vault</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Access Control</label>
                        <select name="is_controlled_access" class="form-control" required>
                            <option value="0">No - Standard</option>
                            <option value="1">Yes - Restricted</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Building</label><input type="text" name="building" class="form-control" required></div>
                    <div class="form-group"><label>Floor</label><input type="text" name="floor" class="form-control" required></div>
                    <div class="form-group"><label>Min Temp (°C)</label><input type="number" name="min_temp" step="0.1" class="form-control"></div>
                    <div class="form-group"><label>Max Temp (°C)</label><input type="number" name="max_temp" step="0.1" class="form-control"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addModal')" class="btn-back">Cancel</button>
                    <button type="submit" name="add_storage" class="btn-action btn-add">Save</button>
                </div>
            </form>
        </div>
    </div>

    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2>Edit Storage Location</h2>
            <form method="POST">
                <input type="hidden" name="storage_id" id="edit_storage_id">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Storage Name</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="location_type" id="edit_location_type" class="form-control" required>
                            <option value="General">General</option>
                            <option value="Refrigerated">Refrigerated</option>
                            <option value="Ward Stock">Ward Stock</option>
                            <option value="Pharmacy">Pharmacy</option>
                            <option value="Secure Vault">Vault</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Access Control</label>
                        <select name="is_controlled_access" id="edit_is_controlled_access" class="form-control" required>
                            <option value="0">No - Standard</option>
                            <option value="1">Yes - Restricted</option>
                        </select>
                    </div>
                    <div class="form-group"><label>Building</label><input type="text" name="building" id="edit_building" class="form-control" required></div>
                    <div class="form-group"><label>Floor</label><input type="text" name="floor" id="edit_floor" class="form-control" required></div>
                    <div class="form-group"><label>Min Temp (°C)</label><input type="number" name="min_temp" id="edit_min_temp" step="0.1" class="form-control"></div>
                    <div class="form-group"><label>Max Temp (°C)</label><input type="number" name="max_temp" id="edit_max_temp" step="0.1" class="form-control"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('editModal')" class="btn-back">Cancel</button>
                    <button type="submit" name="edit_storage" class="btn-action" style="background:#f39c12; color:white;">Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(id) { document.getElementById(id).classList.add('active'); }
        function closeModal(id) { document.getElementById(id).classList.remove('active'); }

        function openEditModal(id, name, type, controlled, bldg, floor, minT, maxT) {
            document.getElementById('edit_storage_id').value = id;
            document.getElementById('edit_name').value = name;
            document.getElementById('edit_location_type').value = type;
            document.getElementById('edit_is_controlled_access').value = controlled;
            document.getElementById('edit_building').value = bldg;
            document.getElementById('edit_floor').value = floor;
            document.getElementById('edit_min_temp').value = minT;
            document.getElementById('edit_max_temp').value = maxT;
            openModal('editModal');
        }
    </script>
    <?php endif; ?>
</body>
</html>

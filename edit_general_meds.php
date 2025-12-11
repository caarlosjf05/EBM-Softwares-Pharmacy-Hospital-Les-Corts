<?php
// FILE: edit_general_meds.php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

if (!hasAnyRole(['admin', 'nurse'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$success = false;
$error = '';

// 1. OBTENER DATOS PARA AUTOCOMPLETADO
$autocomplete_stmt = $conn->query("SELECT DISTINCT comercial_name, active_principle, code_ATC FROM DRUGS ORDER BY comercial_name ASC");
$autocomplete_list = $autocomplete_stmt->fetchAll();

// Lógica de "Añadir Nuevo Medicamento"
if (isset($_POST['add_drug'])) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO DRUGS (
                code_ATC, comercial_name, active_principle, presentation, 
                via_administration, standard_concentration, unitary_price, 
                priority, actual_inventory, minimum_stock, maximum_stock
            ) VALUES (
                :code_ATC, :comercial_name, :active_principle, :presentation,
                :via_administration, :standard_concentration, :unitary_price,
                :priority, :actual_inventory, :minimum_stock, :maximum_stock
            )
        ");
        
        $stmt->execute([
            'code_ATC' => $_POST['code_ATC'],
            'comercial_name' => $_POST['comercial_name'],
            'active_principle' => $_POST['active_principle'],
            'presentation' => $_POST['presentation'],
            'via_administration' => $_POST['via_administration'],
            'standard_concentration' => $_POST['standard_concentration'],
            'unitary_price' => $_POST['unitary_price'],
            'priority' => $_POST['priority'],
            'actual_inventory' => 0,
            'minimum_stock' => $_POST['minimum_stock'],
            'maximum_stock' => $_POST['maximum_stock']
        ]);
        
        $success = 'Medicamento añadido correctamente.';
    } catch (Exception $e) {
        $error = "Error al añadir medicamento: " . $e->getMessage();
    }
}

// Lógica de "Actualizar Stock" (UPDATED)
if (isset($_POST['update_stock'])) {
    try {
        $conn->beginTransaction(); // Start transaction for safety

        $drug_id = $_POST['drug_id'];
        $quantity_change = (int)$_POST['quantity'];
        $operation = $_POST['operation'];
        
        // New Fields
        $lot_number = $_POST['lot_number'] ?? null;
        $expiration_date = $_POST['expiration_date'] ?? null;

        if ($operation === 'add') {
             // 1. Update Total in DRUGS table
             $stmt = $conn->prepare("UPDATE DRUGS SET actual_inventory = actual_inventory + :qty WHERE drug_id = :id");
             $stmt->execute(['qty' => $quantity_change, 'id' => $drug_id]);

             // 2. Insert into LOT_NUMBERS (If Lot/Exp provided)
             if (!empty($lot_number) && !empty($expiration_date)) {
                 // Assumes storage_id = 1 (Main Storage) by default. Adjust if your logic differs.
                 $stmt_lot = $conn->prepare("
                     INSERT INTO LOT_NUMBERS (drug_id, lot_number, expiration_date, quantity, storage_id) 
                     VALUES (:did, :lot, :exp, :qty, 1)
                 ");
                 $stmt_lot->execute([
                     'did' => $drug_id,
                     'lot' => $lot_number,
                     'exp' => $expiration_date,
                     'qty' => $quantity_change
                 ]);
             }

        } elseif ($operation === 'subtract') {
             // Update Total in DRUGS table
             $stmt = $conn->prepare("UPDATE DRUGS SET actual_inventory = GREATEST(0, actual_inventory - :qty) WHERE drug_id = :id");
             $stmt->execute(['qty' => $quantity_change, 'id' => $drug_id]);
             
             // Note: Subtracting usually requires selecting a specific Batch to subtract from. 
             // For this simple general view, we just reduce the main count.
        } else {
            throw new Exception("Operación no válida.");
        }

        $conn->commit();
        $success = 'Stock actualizado correctamente.';

    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error = "Error al actualizar stock: " . $e->getMessage();
    }
}

// BÚSQUEDA Y FILTRADO
$search = $_GET['search'] ?? '';
$search_clause = '';
$params = [];

if ($search) {
    $search_clause = " WHERE d.comercial_name LIKE :search OR d.active_principle LIKE :search OR d.code_ATC LIKE :search";
    $params['search'] = '%' . $search . '%';
}

$stmt = $conn->prepare("
    SELECT 
        d.drug_id,
        d.code_ATC,
        d.comercial_name,
        d.active_principle,
        d.presentation,
        d.unitary_price,
        d.actual_inventory,
        d.minimum_stock,
        d.maximum_stock,
        (
            SELECT s.name 
            FROM LOT_NUMBERS l
            JOIN STORAGE s ON l.storage_id = s.storage_id
            WHERE l.drug_id = d.drug_id AND l.quantity > 0 
            ORDER BY l.quantity DESC
            LIMIT 1
        ) as storage_name
    FROM DRUGS d
    $search_clause
    ORDER BY d.comercial_name ASC
");
$stmt->execute($params);
$drugs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>General Medication Management</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f7fa; color: #333; margin: 0; }
        
        /* HEADER */
        .header-module {
            background: white;
            border-bottom: 1px solid #e1e8ed;
            padding: 0 32px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .header-module h1 { font-size: 18px; font-weight: 400; color: #2c3e50; margin: 0; }
        .header-actions { display: flex; gap: 8px; align-items: center; }

        .container { max-width: 1400px; margin: 0 auto; padding: 32px; }
        
        /* BUTTONS */
        .btn-back { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 500; text-transform: uppercase; transition: all 0.2s; }
        .btn-back:hover { border-color: #95a5a6; color: #2c3e50; }
        
        .btn-action { padding: 8px 16px; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 600; color: white; border: none; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
        .btn-add { background: #2ecc71; } .btn-add:hover { background: #27ae60; }
        .btn-export { background: #8e44ad; } .btn-export:hover { background: #732d91; }
        .btn-import { background: #9b59b6; } .btn-import:hover { background: #8e44ad; }

        /* ALERTS */
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: 500; font-size: 13px; }
        .alert-success { background: #e6f7ee; color: #27ae60; border: 1px solid #2ecc71; }
        .alert-error { background: #fdeaea; color: #e74c3c; border: 1px solid #c0392b; }
        
        /* SEARCH BAR */
        .search-container { background: white; padding: 20px; border: 1px solid #e1e8ed; border-radius: 4px; margin-bottom: 20px; display: flex; gap: 10px; align-items: center; }
        .search-input { flex-grow: 1; padding: 10px 12px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; }
        .search-input:focus { outline: none; border-color: #3498db; }
        .btn-search { background: #3498db; color: white; padding: 10px 20px; border: none; border-radius: 4px; font-weight: 500; cursor: pointer; }
        .btn-clear { color: #7f8c8d; text-decoration: none; font-size: 13px; font-weight: 500; padding: 0 10px; }

        /* TABLE */
        .table-wrapper { background: white; border: 1px solid #e1e8ed; border-radius: 4px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        
        th { background: #f8f9fa; color: #7f8c8d; font-weight: 600; text-transform: uppercase; font-size: 11px; padding: 12px 15px; text-align: left; border-bottom: 1px solid #e1e8ed; }
        td { padding: 12px 15px; border-bottom: 1px solid #f5f7fa; font-size: 13px; color: #2c3e50; vertical-align: middle; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        tr:hover { background-color: #fcfdff; }

        /* COLUMN WIDTHS FIX */
        th:nth-child(1) { width: 7%; }  /* ATC */
        th:nth-child(2) { width: 17%; } /* Name */
        th:nth-child(3) { width: 14%; } /* Principle */
        th:nth-child(4) { width: 10%; } /* Pres */
        th:nth-child(5) { width: 8%; }  /* Price */
        th:nth-child(6) { width: 15%; } /* Stock */
        th:nth-child(7) { width: 15%; } /* Location */
        th:nth-child(8) { width: 14%; } /* Actions */

        /* STOCK INDICATOR */
        .stock-wrapper { display: flex; align-items: center; gap: 8px; }
        .stock-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .stock-dot.ok { background: #2ecc71; }
        .stock-dot.low { background: #f39c12; }
        .stock-dot.empty { background: #e74c3c; }
        .stock-text { font-weight: 600; color: #2c3e50; }
        .stock-limits { font-size: 11px; color: #95a5a6; font-weight: 400; margin-left: 4px; }

        /* ACTION BUTTONS IN TABLE */
        .row-actions { display: flex; gap: 6px; }
        .btn-sm { padding: 4px 10px; border-radius: 3px; font-size: 11px; font-weight: 600; text-decoration: none; border: none; cursor: pointer; text-transform: uppercase; }
        .btn-edit { background: #fcf3cf; color: #f39c12; border: 1px solid #f9e79f; }
        .btn-edit:hover { background: #f9e79f; }
        .btn-stock { background: #d6eaf8; color: #2980b9; border: 1px solid #aed6f1; }
        .btn-stock:hover { background: #aed6f1; }

        /* MODAL */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); justify-content: center; align-items: center; }
        .modal.active { display: flex; }
        
        .modal-content { 
            background: white; 
            padding: 25px; 
            border-radius: 8px; 
            width: 90%; 
            max-width: 600px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.15); 
            max-height: 90vh; 
            overflow-y: auto; 
            position: relative; 
        }
        
        .modal h2 { margin-top: 0; font-size: 18px; color: #2c3e50; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px; }
        
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group.full { grid-column: span 2; }
        .form-group label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 600; color: #7f8c8d; text-transform: uppercase; }
        .form-control { width: 100%; padding: 10px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; }
        
        .modal-footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; display: flex; justify-content: flex-end; gap: 10px; background: white; position: sticky; bottom: 0; }
        
        /* Helper for hiding elements */
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1>Medication Catalog</h1>
        <div class="header-actions">
            <a href="export_interop.php" class="btn-action btn-export" target="_blank">Export Data</a>
            <a href="import_interop.php" class="btn-action btn-import" target="_blank">Import Data</a>
            <button onclick="openModal('addModal')" class="btn-action btn-add">+ New Drug</button>
            <a href="dashboard.php" class="btn-back">Dashboard</a>
        </div>
    </div>

    <div class="container">
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        
        <form method="GET" class="search-container">
            <input type="text" name="search" class="search-input" list="drug-suggestions" 
                   placeholder="Search by name, active principle or ATC code..." 
                   value="<?= htmlspecialchars($search) ?>" autocomplete="off">
            
            <datalist id="drug-suggestions">
                <?php foreach ($autocomplete_list as $item): ?>
                    <option value="<?= htmlspecialchars($item['comercial_name']) ?>">
                    <option value="<?= htmlspecialchars($item['active_principle']) ?>">
                    <option value="<?= htmlspecialchars($item['code_ATC']) ?>">
                <?php endforeach; ?>
            </datalist>

            <button type="submit" class="btn-search">Search</button>
            <?php if ($search): ?>
                <a href="edit_general_meds.php" class="btn-clear">Clear</a>
            <?php endif; ?>
        </form>

        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>ATC Code</th>
                        <th>Medication Name</th>
                        <th>Active Principle</th>
                        <th>Pres.</th>
                        <th>Price</th>
                        <th>Stock Status</th>
                        <th>Location</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($drugs)): ?>
                        <tr><td colspan="8" style="text-align: center; padding: 40px; color: #95a5a6;">No medications found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($drugs as $drug): ?>
                            <?php
                                $dot_class = 'ok';
                                if ($drug['actual_inventory'] <= 0) $dot_class = 'empty';
                                elseif ($drug['actual_inventory'] <= $drug['minimum_stock']) $dot_class = 'low';
                            ?>
                            <tr>
                                <td><code><?= htmlspecialchars($drug['code_ATC']) ?></code></td>
                                <td><strong><?= htmlspecialchars($drug['comercial_name']) ?></strong></td>
                                <td><?= htmlspecialchars($drug['active_principle']) ?></td>
                                <td><?= htmlspecialchars($drug['presentation']) ?></td>
                                <td>€<?= number_format($drug['unitary_price'], 2) ?></td>
                                <td>
                                    <div class="stock-wrapper">
                                        <div class="stock-dot <?= $dot_class ?>"></div>
                                        <span class="stock-text"><?= $drug['actual_inventory'] ?></span>
                                        <span class="stock-limits">(<?= $drug['minimum_stock'] ?>-<?= $drug['maximum_stock'] ?>)</span>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($drug['storage_name'] ?? '-') ?></td>
                                <td>
                                    <div class="row-actions">
                                        <a href="modules/edit_drug.php?id=<?= $drug['drug_id'] ?>" class="btn-sm btn-edit">Edit</a>
                                        <button onclick="openStockModal(<?= $drug['drug_id'] ?>)" class="btn-sm btn-stock">± Stock</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2>Add New Medication</h2>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group full">
                        <label>Commercial Name</label>
                        <input type="text" name="comercial_name" class="form-control" required>
                    </div>
                    <div class="form-group full">
                        <label>Active Principle</label>
                        <input type="text" name="active_principle" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>ATC Code</label>
                        <input type="text" name="code_ATC" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Presentation</label>
                        <input type="text" name="presentation" class="form-control" placeholder="e.g. Tablet 500mg" required>
                    </div>
                    <div class="form-group">
                        <label>Route</label>
                        <input type="text" name="via_administration" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Concentration</label>
                        <input type="text" name="standard_concentration" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Price (€)</label>
                        <input type="number" name="unitary_price" step="0.01" min="0" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority" class="form-control">
                            <option value="Essential">Essential</option>
                            <option value="Standard">Standard</option>
                            <option value="Low">Low</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Min Stock</label>
                        <input type="number" name="minimum_stock" min="0" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Max Stock</label>
                        <input type="number" name="maximum_stock" min="0" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal('addModal')" class="btn-back">Cancel</button>
                    <button type="submit" name="add_drug" class="btn-action btn-add">Save Drug</button>
                </div>
            </form>
        </div>
    </div>

    <div id="stockModal" class="modal">
        <div class="modal-content" style="max-width: 400px;">
            <h2>Adjust Inventory</h2>
            <form method="POST">
                <input type="hidden" name="drug_id" id="stock_drug_id">
                
                <div class="form-group">
                    <label>Action</label>
                    <select name="operation" id="stockOperation" class="form-control" style="font-weight: 600;" onchange="toggleLotFields()">
                        <option value="add">➕ Add Stock (Purchase/Return)</option>
                        <option value="subtract">➖ Subtract Stock (Loss/Expired)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" min="1" class="form-control" required autofocus>
                </div>

                <div id="lotFields">
                    <div class="form-group">
                        <label>Lot Number / Batch</label>
                        <input type="text" name="lot_number" class="form-control" placeholder="e.g. A1023">
                    </div>
                    <div class="form-group">
                        <label>Expiration Date</label>
                        <input type="date" name="expiration_date" class="form-control">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" onclick="closeModal('stockModal')" class="btn-back">Cancel</button>
                    <button type="submit" name="update_stock" class="btn-action btn-stock">Update Stock</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openModal(modalId) { 
            document.getElementById(modalId).classList.add('active'); 
            if(modalId === 'stockModal') {
                toggleLotFields(); // Ensure correct state when opening
            }
        }
        
        function closeModal(modalId) { 
            document.getElementById(modalId).classList.remove('active'); 
        }
        
        function openStockModal(drugId) {
            document.getElementById('stock_drug_id').value = drugId;
            openModal('stockModal');
        }

        // NEW: Toggle fields based on operation type
        function toggleLotFields() {
            var operation = document.getElementById('stockOperation').value;
            var lotDiv = document.getElementById('lotFields');
            var lotInput = lotDiv.querySelector('input[name="lot_number"]');
            var expInput = lotDiv.querySelector('input[name="expiration_date"]');

            if (operation === 'add') {
                lotDiv.style.display = 'block';
                // Make them required when adding stock (optional but recommended)
                lotInput.required = true;
                expInput.required = true;
            } else {
                lotDiv.style.display = 'none';
                lotInput.required = false;
                expInput.required = false;
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>
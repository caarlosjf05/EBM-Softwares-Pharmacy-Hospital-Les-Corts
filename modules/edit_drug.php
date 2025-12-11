<?php
// FILE: modules/edit_drug.php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Access Control: ADMIN ONLY
if (!hasRole('admin')) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$success = '';
$error = '';
$drug = null;

// 1. Get drug ID from URL
$drug_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$drug_id) {
    header('Location: ../edit_general_meds.php?error=invalid_id'); 
    exit();
}

// 2. Fetch Drug Details
$stmt = $conn->prepare("SELECT * FROM DRUGS WHERE drug_id = :id");
$stmt->execute(['id' => $drug_id]);
$drug = $stmt->fetch();

if (!$drug) {
    header('Location: ../edit_general_meds.php?error=drug_not_found'); 
    exit();
}

// 3. Handle Update
if (isset($_POST['update_drug'])) {
    try {
        $conn->beginTransaction();

        // REMOVED: storage_id is no longer updated here
        $stmt = $conn->prepare("
            UPDATE DRUGS SET
                comercial_name = :comercial_name,
                active_principle = :active_principle,
                code_ATC = :code_ATC,
                presentation = :presentation,
                via_administration = :via_administration,
                standard_concentration = :standard_concentration,
                unitary_price = :unitary_price,
                minimum_stock = :minimum_stock,
                maximum_stock = :maximum_stock,
                priority = :priority
            WHERE drug_id = :drug_id
        ");

        $stmt->execute([
            'comercial_name' => $_POST['comercial_name'],
            'active_principle' => $_POST['active_principle'],
            'code_ATC' => $_POST['code_ATC'],
            'presentation' => $_POST['presentation'],
            'via_administration' => $_POST['via_administration'],
            'standard_concentration' => !empty($_POST['standard_concentration']) ? $_POST['standard_concentration'] : null,
            'unitary_price' => $_POST['unitary_price'],
            'minimum_stock' => $_POST['minimum_stock'],
            'maximum_stock' => $_POST['maximum_stock'],
            'priority' => $_POST['priority'],
            'drug_id' => $drug_id
        ]);

        $conn->commit();
        $success = 'Drug details updated successfully.';

        // Reload updated data
        $stmt = $conn->prepare("SELECT * FROM DRUGS WHERE drug_id = :id");
        $stmt->execute(['id' => $drug_id]);
        $drug = $stmt->fetch();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = 'Error updating drug: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Drug - Hospital les Corts</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f5f7fa; min-height: 100vh; }
        
        /* HEADER MODULE */
        .header-module {
            background: white;
            border-bottom: 1px solid #e1e8ed;
            padding: 0 32px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .header-module h1 {
            font-size: 18px;
            font-weight: 400;
            color: #2c3e50;
            margin: 0;
        }
        
        /* BUTTON STYLES */
        .btn-back {
            background: transparent;
            color: #7f8c8d;
            border: 1px solid #dce1e6;
            padding: 8px 16px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.2s;
        }
        .btn-back:hover {
            border-color: #95a5a6;
            color: #2c3e50;
        }

        .container { max-width: 900px; margin: 0 auto; padding: 32px; }
        
        /* ALERTS */
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: 500; }
        .alert-error { background-color: #fcebeb; color: #c0392b; border: 1px solid #e74c3c; }
        .alert-success { background-color: #e8f8f5; color: #16a085; border: 1px solid #1abc9c; }
        
        /* FORM STYLES */
        .form-box { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 32px; }
        .section-title { font-size: 14px; font-weight: 700; color: #95a5a6; text-transform: uppercase; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #f0f0f0; letter-spacing: 0.5px; }
        
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 24px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full { grid-column: span 2; }
        
        .form-group label { display: block; margin-bottom: 8px; color: #2c3e50; font-weight: 600; font-size: 13px; }
        .form-group label .required { color: #e74c3c; margin-left: 4px; }
        
        .form-group input, .form-group select { padding: 10px 12px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; font-family: inherit; width: 100%; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #3498db; }
        
        .form-actions { display: flex; gap: 12px; margin-top: 32px; padding-top: 24px; border-top: 1px solid #e1e8ed; justify-content: flex-end; }
        .btn-primary { padding: 10px 24px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; }
        .btn-primary:hover { background: #2980b9; }
        .btn-secondary { padding: 10px 24px; background: white; color: #7f8c8d; border: 1px solid #dce1e6; border-radius: 4px; font-size: 13px; font-weight: 600; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; text-decoration: none; }
        .btn-secondary:hover { border-color: #95a5a6; color: #2c3e50; }
        
        .stock-display { font-size: 18px; font-weight: 700; color: #2c3e50; background: #f8f9fa; padding: 10px; border-radius: 4px; border: 1px solid #e1e8ed; display: block; }
        .stock-note { font-size: 11px; color: #7f8c8d; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1>Edit Drug Details</h1>
        <a href="../edit_general_meds.php" class="btn-back">← Back to Catalog</a>
    </div>

    <div class="container">
        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="form-box">
            
            <div class="section-title">📦 Inventory Definitions</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Current Total Inventory (Read Only)</label>
                    <span class="stock-display"><?= $drug['actual_inventory'] ?> units</span>
                    <span class="stock-note">To adjust stock, please use "Receive Batch" or "Dispense" in the main catalog.</span>
                </div>
                <div class="form-group">
                    <label>Minimum Stock Level <span class="required">*</span></label>
                    <input type="number" name="minimum_stock" min="0" value="<?= htmlspecialchars($drug['minimum_stock']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Maximum Stock Level <span class="required">*</span></label>
                    <input type="number" name="maximum_stock" min="0" value="<?= htmlspecialchars($drug['maximum_stock']) ?>" required>
                </div>
            </div>

            <div class="section-title">💊 Medication Information</div>
            <div class="form-grid">
                <div class="form-group full">
                    <label>Commercial Name <span class="required">*</span></label>
                    <input type="text" name="comercial_name" value="<?= htmlspecialchars($drug['comercial_name']) ?>" required>
                </div>
                <div class="form-group full">
                    <label>Active Principle <span class="required">*</span></label>
                    <input type="text" name="active_principle" value="<?= htmlspecialchars($drug['active_principle']) ?>" required>
                </div>
                <div class="form-group">
                    <label>ATC Code <span class="required">*</span></label>
                    <input type="text" name="code_ATC" value="<?= htmlspecialchars($drug['code_ATC']) ?>" required>
                </div>
                </div>

            <div class="section-title">⚙️ Details & Pricing</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Presentation <span class="required">*</span></label>
                    <input type="text" name="presentation" value="<?= htmlspecialchars($drug['presentation']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Route <span class="required">*</span></label>
                    <input type="text" name="via_administration" value="<?= htmlspecialchars($drug['via_administration']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Standard Concentration</label>
                    <input type="text" name="standard_concentration" value="<?= htmlspecialchars($drug['standard_concentration'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Unitary Price (€) <span class="required">*</span></label>
                    <input type="number" step="0.01" name="unitary_price" value="<?= htmlspecialchars($drug['unitary_price']) ?>" required min="0">
                </div>
                <div class="form-group">
                    <label>Priority</label>
                    <select name="priority">
                        <option value="Essential" <?= $drug['priority'] === 'Essential' ? 'selected' : '' ?>>Essential</option>
                        <option value="Standard" <?= $drug['priority'] === 'Standard' ? 'selected' : '' ?>>Standard</option>
                        <option value="Low" <?= $drug['priority'] === 'Low' ? 'selected' : '' ?>>Low</option>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <a href="../edit_general_meds.php" class="btn-secondary">Cancel</a>
                <button type="submit" name="update_drug" class="btn-primary">💾 Save Changes</button>
            </div>
        </form>
    </div>
</body>
</html>

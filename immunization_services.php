<?php
// FILE: modules/immunization_services.php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!hasAnyRole(['admin', 'nurse'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT professional_id FROM PROFESSIONAL WHERE email = :email");
$stmt->execute(['email' => $current_user_email]);
$professional_data = $stmt->fetch();
$professional_id = $professional_data['professional_id'];

$success = '';
$error = '';

if (isset($_POST['record_vaccine'])) {
    try {
        $conn->beginTransaction();
        $stmt = $conn->prepare("
            INSERT INTO IMMUNIZATIONS (
                patient_id, vaccine_name, manufacturer, lot_number, 
                dose_number, dose_volume, site, route, 
                administered_by, administered_date, next_dose_date, notes
            ) VALUES (
                :patient_id, :vaccine, :manufacturer, :lot, 
                :dose_num, :dose_vol, :site, :route, 
                :admin_by, NOW(), :next_dose, :notes
            )
        ");
        $stmt->execute([
            'patient_id' => $_POST['patient_id'],
            'vaccine' => $_POST['vaccine_name'],
            'manufacturer' => $_POST['manufacturer'],
            'lot' => $_POST['lot_number'],
            'dose_num' => $_POST['dose_number'],
            'dose_vol' => $_POST['dose_volume'],
            'site' => $_POST['injection_site'],
            'route' => $_POST['route'],
            'admin_by' => $professional_id,
            'next_dose' => !empty($_POST['next_dose_date']) ? $_POST['next_dose_date'] : null,
            'notes' => !empty($_POST['notes']) ? $_POST['notes'] : null
        ]);
        
        if (!empty($_POST['drug_id'])) {
            $stmt = $conn->prepare("UPDATE DRUGS SET actual_inventory = actual_inventory - 1 WHERE drug_id = :drug_id");
            $stmt->execute(['drug_id' => $_POST['drug_id']]);
        }
        
        $conn->commit();
        $success = 'Vaccination recorded successfully';
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// --- SEARCH LOGIC (INTEGRATED) ---
$search = $_GET['search'] ?? '';
$search_sql = '';
$params = [];

if ($search) {
    $search_sql = " WHERE (p.name LIKE :search OR p.surname LIKE :search OR p.DNI LIKE :search OR i.vaccine_name LIKE :search) ";
    $params['search'] = "%$search%";
}

// Get all immunizations
$stmt = $conn->prepare("
    SELECT 
        i.*,
        p.name as patient_name,
        p.surname as patient_surname,
        p.DNI,
        p.birth_date,
        prof.name as admin_name,
        prof.surname as admin_surname
    FROM IMMUNIZATIONS i
    JOIN PATIENT p ON i.patient_id = p.patient_id
    JOIN PROFESSIONAL prof ON i.administered_by = prof.professional_id
    $search_sql
    ORDER BY i.administered_date DESC
    LIMIT 100
");
$stmt->execute($params);
$immunizations = $stmt->fetchAll();

// Get patients for form
$patients = $conn->query("SELECT patient_id, name, surname, DNI, birth_date FROM PATIENT ORDER BY surname, name")->fetchAll();

// Get vaccine inventory
$vaccines = $conn->query("SELECT drug_id, comercial_name, actual_inventory FROM DRUGS WHERE via_administration LIKE '%Intramuscular%' OR via_administration LIKE '%Subcutaneous%' ORDER BY comercial_name")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Immunization Services</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .header h1 { font-size: 18px; font-weight: 400; color: #2c3e50; }
        .header-buttons { display: flex; gap: 12px; }
        .back-btn, .add-btn { padding: 8px 16px; border-radius: 4px; font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; text-decoration: none; border: none; cursor: pointer; }
        .back-btn { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; }
        .add-btn { background: #27ae60; color: white; }
        .container { max-width: 1600px; margin: 0 auto; padding: 32px; }
        .alert { padding: 14px 20px; border-radius: 4px; margin-bottom: 24px; font-size: 13px; }
        .alert-success { background: #d4edda; border-left: 3px solid #27ae60; color: #155724; }
        .alert-error { background: #fee; border-left: 3px solid #e74c3c; color: #c0392b; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
        .stat-card { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 20px; }
        .stat-label { color: #7f8c8d; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-number { font-size: 28px; font-weight: 300; color: #2c3e50; }
        .section-header { font-size: 13px; font-weight: 600; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; margin: 32px 0 16px 0; }
        .box { background: white; border: 1px solid #e1e8ed; border-radius: 4px; overflow: hidden; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; }
        table thead { background: #fafbfc; }
        table th { padding: 14px 16px; text-align: left; font-weight: 500; color: #2c3e50; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        table td { padding: 16px; border-bottom: 1px solid #f5f7fa; color: #2c3e50; font-size: 13px; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; }
        .modal.active { display: flex; justify-content: center; align-items: center; }
        .modal-content { background: white; padding: 32px; border-radius: 4px; max-width: 700px; width: 90%; max-height: 90vh; overflow-y: auto; }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
        .form-group { margin-bottom: 20px; }
        .form-group.full-width { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 8px; color: #2c3e50; font-weight: 500; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        input, select, textarea { width: 100%; padding: 12px 16px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; background: #fafbfc; }
        button[type="submit"] { padding: 12px 24px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; }
        .print-btn { padding: 6px 12px; background: #2196F3; color: white; border: none; border-radius: 3px; font-size: 11px; font-weight: 500; cursor: pointer; text-transform: uppercase; }
        .print-btn:hover { background: #1976D2; }
    </style>
</head>
<body>
    <div class="header">
        <h1>💉 Immunization Services</h1>
        <div class="header-buttons">
            <button class="add-btn" onclick="openModal()">+ Record Vaccination</button>
            <a href="../dashboard.php" class="back-btn">Back to Dashboard</a>
        </div>
    </div>

    <div class="container">
        <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-label">Total Vaccinations</div><div class="stat-number"><?= count($immunizations) ?></div></div>
            <div class="stat-card"><div class="stat-label">This Month</div><div class="stat-number"><?= count(array_filter($immunizations, fn($i) => date('Y-m', strtotime($i['administered_date'])) === date('Y-m'))) ?></div></div>
            <div class="stat-card"><div class="stat-label">Today</div><div class="stat-number"><?= count(array_filter($immunizations, fn($i) => date('Y-m-d', strtotime($i['administered_date'])) === date('Y-m-d'))) ?></div></div>
            <div class="stat-card"><div class="stat-label">Unique Patients</div><div class="stat-number"><?= count(array_unique(array_column($immunizations, 'patient_id'))) ?></div></div>
        </div>

        <form method="GET" style="margin-bottom: 20px; display:flex; gap:10px;">
            <input type="text" name="search" placeholder="Search by Patient Name, DNI, or Vaccine..." value="<?= htmlspecialchars($search) ?>" style="padding:10px; border:1px solid #dce1e6; border-radius:4px; flex-grow:1; font-size:14px;">
            <button type="submit" style="padding:10px 20px; background:#3498db; color:white; border:none; border-radius:4px; cursor:pointer;">Search</button>
        </form>

        <div class="section-header">📋 Immunization Records</div>
        <div class="box">
            <?php if (empty($immunizations)): ?>
                <div style="padding: 40px; text-align: center; color: #95a5a6;">No records found. Start by recording a vaccination.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr><th>Date</th><th>Patient</th><th>Age</th><th>Vaccine</th><th>Dose</th><th>Lot Number</th><th>Site</th><th>Route</th><th>Administered By</th><th>Action</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($immunizations as $imm): ?>
                    <?php $age = floor((time() - strtotime($imm['birth_date'])) / 31556926); ?>
                    <tr>
                        <td><?= date('Y-m-d H:i', strtotime($imm['administered_date'])) ?></td>
                        <td><strong><?= htmlspecialchars($imm['patient_name'] . ' ' . $imm['patient_surname']) ?></strong><br><small style="color: #7f8c8d;"><?= htmlspecialchars($imm['DNI']) ?></small></td>
                        <td><?= $age ?> years</td>
                        <td><strong><?= htmlspecialchars($imm['vaccine_name']) ?></strong><br><small><?= htmlspecialchars($imm['manufacturer']) ?></small></td>
                        <td><?= $imm['dose_number'] ?> (<?= $imm['dose_volume'] ?>)</td>
                        <td><?= htmlspecialchars($imm['lot_number']) ?></td>
                        <td><?= htmlspecialchars($imm['site']) ?></td>
                        <td><?= htmlspecialchars($imm['route']) ?></td>
                        <td><?= htmlspecialchars($imm['admin_name'] . ' ' . $imm['admin_surname']) ?></td>
                        <td><button class="print-btn" onclick="printCard(<?= $imm['immunization_id'] ?>)">🖨️ Print Card</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <div id="vaccineModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 24px; font-size: 16px;">Record Vaccination</h2>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group full-width"><label>Patient</label><select name="patient_id" required><option value="">Select patient...</option><?php foreach ($patients as $patient): ?><option value="<?= $patient['patient_id'] ?>"><?= htmlspecialchars($patient['name'] . ' ' . $patient['surname'] . ' (' . $patient['DNI'] . ')') ?></option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Vaccine Name</label><input type="text" name="vaccine_name" placeholder="e.g., COVID-19 mRNA" required></div>
                    <div class="form-group"><label>Manufacturer</label><input type="text" name="manufacturer" placeholder="e.g., Pfizer"></div>
                    <div class="form-group"><label>Lot Number</label><input type="text" name="lot_number" required></div>
                    <div class="form-group"><label>Inventory (Optional)</label><select name="drug_id"><option value="">Don't update inventory</option><?php foreach ($vaccines as $vaccine): ?><option value="<?= $vaccine['drug_id'] ?>"><?= htmlspecialchars($vaccine['comercial_name']) ?> (<?= $vaccine['actual_inventory'] ?> available)</option><?php endforeach; ?></select></div>
                    <div class="form-group"><label>Dose Number</label><select name="dose_number" required><option value="1">Dose 1</option><option value="2">Dose 2</option><option value="3">Dose 3 (Booster)</option><option value="4">Dose 4 (Booster)</option></select></div>
                    <div class="form-group"><label>Dose Volume</label><input type="text" name="dose_volume" placeholder="e.g., 0.5 mL" required></div>
                    <div class="form-group"><label>Injection Site</label><select name="injection_site" required><option value="Left Deltoid">Left Deltoid</option><option value="Right Deltoid">Right Deltoid</option><option value="Left Thigh">Left Thigh</option><option value="Right Thigh">Right Thigh</option><option value="Left Gluteal">Left Gluteal</option><option value="Right Gluteal">Right Gluteal</option></select></div>
                    <div class="form-group"><label>Route</label><select name="route" required><option value="Intramuscular">Intramuscular (IM)</option><option value="Subcutaneous">Subcutaneous (SC)</option><option value="Intradermal">Intradermal (ID)</option><option value="Oral">Oral</option><option value="Nasal">Nasal</option></select></div>
                    <div class="form-group"><label>Next Dose Date (Optional)</label><input type="date" name="next_dose_date"></div>
                    <div class="form-group full-width"><label>Notes</label><textarea name="notes" rows="3" placeholder="Any adverse reactions, special instructions..."></textarea></div>
                </div>
                <button type="submit" name="record_vaccine">Record Vaccination</button>
                <button type="button" onclick="closeModal()" style="background: #95a5a6; margin-left: 12px;">Cancel</button>
            </form>
        </div>
    </div>
    <script>
        function openModal() { document.getElementById('vaccineModal').classList.add('active'); }
        function closeModal() { document.getElementById('vaccineModal').classList.remove('active'); }
        function printCard(immunizationId) { window.open('../print_immunization_card.php?id=' + immunizationId, '_blank'); }
    </script>
</body>
</html>
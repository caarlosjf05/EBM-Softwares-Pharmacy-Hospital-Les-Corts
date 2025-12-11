<?php
require_once '../includes/auth.php';

if (!hasAnyRole(['pharmacist'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();
$success = '';
$error = '';

// Submit new PA
if (isset($_POST['submit_pa'])) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO PRIOR_AUTHORIZATION (prescription_id, insurance_provider, status, submitted_date, required_documents, submitted_by)
            VALUES (:rx_id, :insurance, 'pending', NOW(), :docs, :submitted_by)
        ");
        $stmt->execute([
            'rx_id' => $_POST['prescription_id'],
            'insurance' => $_POST['insurance_provider'],
            'docs' => $_POST['required_documents'],
            'submitted_by' => $current_user_email
        ]);
        $success = 'Prior authorization submitted successfully';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Update PA status
if (isset($_POST['update_status'])) {
    try {
        $stmt = $conn->prepare("
            UPDATE PRIOR_AUTHORIZATION 
            SET status = :status, 
                approval_date = :approval_date,
                denial_reason = :denial_reason,
                notes = :notes
            WHERE pa_id = :pa_id
        ");
        
        $approval_date = $_POST['status'] === 'approved' ? date('Y-m-d') : null;
        
        $stmt->execute([
            'status' => $_POST['status'],
            'approval_date' => $approval_date,
            'denial_reason' => $_POST['denial_reason'] ?? null,
            'notes' => $_POST['notes'] ?? null,
            'pa_id' => $_POST['pa_id']
        ]);
        $success = 'PA status updated';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get all PAs
$stmt = $conn->query("
    SELECT 
        pa.*,
        pr.prescription_id,
        p.name as patient_name,
        p.surname as patient_surname,
        p.DNI,
        prof.name as doctor_name,
        prof.surname as doctor_surname,
        GROUP_CONCAT(d.comercial_name SEPARATOR ', ') as medications
    FROM PRIOR_AUTHORIZATION pa
    JOIN PRESCRIPTION pr ON pa.prescription_id = pr.prescription_id
    JOIN PATIENT p ON pr.patient_id = p.patient_id
    JOIN PROFESSIONAL prof ON pr.professional_id = prof.professional_id
    JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
    JOIN DRUGS d ON pi.drug_id = d.drug_id
    GROUP BY pa.pa_id
    ORDER BY pa.submitted_date DESC
");
$pas = $stmt->fetchAll();

// Get pending prescriptions for PA
$stmt = $conn->query("
    SELECT 
        pr.prescription_id,
        pr.date,
        p.name as patient_name,
        p.surname as patient_surname,
        p.DNI,
        GROUP_CONCAT(d.comercial_name SEPARATOR ', ') as medications
    FROM PRESCRIPTION pr
    JOIN PATIENT p ON pr.patient_id = p.patient_id
    JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
    JOIN DRUGS d ON pi.drug_id = d.drug_id
    WHERE pr.prescription_id NOT IN (SELECT prescription_id FROM PRIOR_AUTHORIZATION)
    GROUP BY pr.prescription_id
    ORDER BY pr.date DESC
    LIMIT 50
");
$pending_prescriptions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prior Authorization Tracking</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .header h1 { font-size: 18px; font-weight: 400; color: #2c3e50; }
        .header-buttons { display: flex; gap: 12px; }
        .back-btn, .add-btn { padding: 8px 16px; border-radius: 4px; font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; text-decoration: none; border: none; cursor: pointer; }
        .back-btn { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; }
        .add-btn { background: #3498db; color: white; }
        .container { max-width: 1800px; margin: 0 auto; padding: 32px; }
        .alert { padding: 14px 20px; border-radius: 4px; margin-bottom: 24px; font-size: 13px; }
        .alert-success { background: #d4edda; border-left: 3px solid #27ae60; color: #155724; }
        .alert-error { background: #fee; border-left: 3px solid #e74c3c; color: #c0392b; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
        .stat-card { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 20px; }
        .stat-label { color: #7f8c8d; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-number { font-size: 28px; font-weight: 300; color: #2c3e50; }
        .stat-card.pending .stat-number { color: #f39c12; }
        .stat-card.approved .stat-number { color: #27ae60; }
        .stat-card.denied .stat-number { color: #e74c3c; }
        .section-header { font-size: 13px; font-weight: 600; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; margin: 32px 0 16px 0; }
        .box { background: white; border: 1px solid #e1e8ed; border-radius: 4px; overflow: hidden; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; }
        table thead { background: #fafbfc; }
        table th { padding: 14px 16px; text-align: left; font-weight: 500; color: #2c3e50; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; }
        table td { padding: 16px; border-bottom: 1px solid #f5f7fa; color: #2c3e50; font-size: 13px; }
        .status-badge { display: inline-block; padding: 4px 10px; border-radius: 3px; font-size: 10px; font-weight: 600; text-transform: uppercase; }
        .status-badge.pending { background: #fff3e0; color: #e65100; }
        .status-badge.in_review { background: #e3f2fd; color: #1565c0; }
        .status-badge.approved { background: #d4edda; color: #155724; }
        .status-badge.denied { background: #ffebee; color: #c62828; }
        .action-btn { padding: 6px 12px; background: #3498db; color: white; border: none; border-radius: 3px; font-size: 11px; font-weight: 500; cursor: pointer; text-transform: uppercase; }
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
        <h1>📄 Prior Authorization Tracking</h1>
        <div class="header-buttons">
            <button class="add-btn" onclick="openModal('add')">+ Submit PA</button>
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

        <div class="stats-grid">
            <div class="stat-card pending">
                <div class="stat-label">Pending</div>
                <div class="stat-number"><?= count(array_filter($pas, fn($p) => $p['status'] === 'pending')) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">In Review</div>
                <div class="stat-number"><?= count(array_filter($pas, fn($p) => $p['status'] === 'in_review')) ?></div>
            </div>
            <div class="stat-card approved">
                <div class="stat-label">Approved</div>
                <div class="stat-number"><?= count(array_filter($pas, fn($p) => $p['status'] === 'approved')) ?></div>
            </div>
            <div class="stat-card denied">
                <div class="stat-label">Denied</div>
                <div class="stat-number"><?= count(array_filter($pas, fn($p) => $p['status'] === 'denied')) ?></div>
            </div>
        </div>

        <div class="section-header">📋 All Prior Authorizations</div>
        <div class="box">
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>PA ID</th>
                        <th>Patient</th>
                        <th>DNI</th>
                        <th>Medications</th>
                        <th>Insurance</th>
                        <th>Submitted</th>
                        <th>Doctor</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pas as $pa): ?>
                    <tr>
                        <td><span class="status-badge <?= $pa['status'] ?>"><?= strtoupper(str_replace('_', ' ', $pa['status'])) ?></span></td>
                        <td><strong>#<?= $pa['pa_id'] ?></strong></td>
                        <td><?= htmlspecialchars($pa['patient_name'] . ' ' . $pa['patient_surname']) ?></td>
                        <td><?= htmlspecialchars($pa['DNI']) ?></td>
                        <td><?= htmlspecialchars($pa['medications']) ?></td>
                        <td><?= htmlspecialchars($pa['insurance_provider']) ?></td>
                        <td><?= date('Y-m-d', strtotime($pa['submitted_date'])) ?></td>
                        <td>Dr. <?= htmlspecialchars($pa['doctor_name'] . ' ' . $pa['doctor_surname']) ?></td>
                        <td>
                            <?php if ($pa['status'] === 'pending' || $pa['status'] === 'in_review'): ?>
                            <button class="action-btn" onclick="updateStatus(<?= $pa['pa_id'] ?>)">Update</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add PA Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 24px; font-size: 16px;">Submit Prior Authorization</h2>
            <form method="POST">
                <div class="form-group">
                    <label>Prescription</label>
                    <select name="prescription_id" required>
                        <option value="">Select prescription...</option>
                        <?php foreach ($pending_prescriptions as $rx): ?>
                            <option value="<?= $rx['prescription_id'] ?>">
                                RX #<?= $rx['prescription_id'] ?> - <?= htmlspecialchars($rx['patient_name'] . ' ' . $rx['patient_surname']) ?> - <?= htmlspecialchars($rx['medications']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Insurance Provider</label>
                    <input type="text" name="insurance_provider" required>
                </div>
                <div class="form-group">
                    <label>Required Documents</label>
                    <textarea name="required_documents" rows="4" placeholder="List required documents..."></textarea>
                </div>
                <button type="submit" name="submit_pa">Submit PA</button>
                <button type="button" onclick="closeModal('add')" style="background: #95a5a6; margin-left: 12px;">Cancel</button>
            </form>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="updateModal" class="modal">
        <div class="modal-content">
            <h2 style="margin-bottom: 24px; font-size: 16px;">Update PA Status</h2>
            <form method="POST">
                <input type="hidden" name="pa_id" id="update_pa_id">
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" required>
                        <option value="pending">Pending</option>
                        <option value="in_review">In Review</option>
                        <option value="approved">Approved</option>
                        <option value="denied">Denied</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Denial Reason (if denied)</label>
                    <textarea name="denial_reason" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" rows="3"></textarea>
                </div>
                <button type="submit" name="update_status">Update Status</button>
                <button type="button" onclick="closeModal('update')" style="background: #95a5a6; margin-left: 12px;">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        function openModal(type) {
            document.getElementById(type + 'Modal').classList.add('active');
        }
        function closeModal(type) {
            document.getElementById(type + 'Modal').classList.remove('active');
        }
        function updateStatus(paId) {
            document.getElementById('update_pa_id').value = paId;
            openModal('update');
        }
    </script>
</body>
</html>
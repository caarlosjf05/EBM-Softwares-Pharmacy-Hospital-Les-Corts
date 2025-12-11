<?php
require_once 'includes/auth.php';

if (!hasRole('admin')) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$success = '';
$error = '';
$patient = null;

// Get patient ID from URL
$patient_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$patient_id) {
    header('Location: manage_patients.php?error=invalid_id');
    exit();
}

// Get list of professionals for primary physician dropdown
$stmt = $conn->query("SELECT professional_id, name, surname, speciality FROM PROFESSIONAL ORDER BY surname, name");
$professionals = $stmt->fetchAll();

// Get patient data
$stmt = $conn->prepare("SELECT * FROM PATIENT WHERE patient_id = :id");
$stmt->execute(['id' => $patient_id]);
$patient = $stmt->fetch();

if (!$patient) {
    header('Location: manage_patients.php?error=patient_not_found');
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_patient'])) {
    try {
        $conn->beginTransaction();
        
        // Validate required fields
        $required_fields = ['name', 'surname', 'DNI', 'birth_date', 'sex', 'email'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }
        
        // Check if DNI already exists for another patient
        $stmt = $conn->prepare("SELECT patient_id FROM PATIENT WHERE DNI = :dni AND patient_id != :id");
        $stmt->execute(['dni' => $_POST['DNI'], 'id' => $patient_id]);
        if ($stmt->fetch()) {
            throw new Exception('Another patient with this DNI already exists.');
        }
        
        // Check if email already exists for another patient
        $stmt = $conn->prepare("SELECT patient_id FROM PATIENT WHERE email = :email AND patient_id != :id");
        $stmt->execute(['email' => $_POST['email'], 'id' => $patient_id]);
        if ($stmt->fetch()) {
            throw new Exception('Another patient with this email already exists.');
        }
        
        // Update patient
        $stmt = $conn->prepare("
            UPDATE PATIENT SET
                name = :name,
                surname = :surname,
                DNI = :DNI,
                birth_date = :birth_date,
                sex = :sex,
                blood_type = :blood_type,
                phone_number = :phone_number,
                email = :email,
                adress = :adress,
                emergency_contact = :emergency_contact,
                primary_physician = :primary_physician,
                creatinine_clearance = :creatinine_clearance
            WHERE patient_id = :patient_id
        ");
        
        $stmt->execute([
            'name' => $_POST['name'],
            'surname' => $_POST['surname'],
            'DNI' => $_POST['DNI'],
            'birth_date' => $_POST['birth_date'],
            'sex' => $_POST['sex'],
            'blood_type' => !empty($_POST['blood_type']) ? $_POST['blood_type'] : null,
            'phone_number' => !empty($_POST['phone_number']) ? $_POST['phone_number'] : null,
            'email' => $_POST['email'],
            'adress' => !empty($_POST['adress']) ? $_POST['adress'] : null,
            'emergency_contact' => !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null,
            'primary_physician' => !empty($_POST['primary_physician']) ? $_POST['primary_physician'] : null,
            'creatinine_clearance' => !empty($_POST['creatinine_clearance']) ? $_POST['creatinine_clearance'] : null,
            'patient_id' => $patient_id
        ]);
        
        $conn->commit();
        $success = 'Patient information updated successfully.';
        
        // Reload patient data
        $stmt = $conn->prepare("SELECT * FROM PATIENT WHERE patient_id = :id");
        $stmt->execute(['id' => $patient_id]);
        $patient = $stmt->fetch();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = 'Error updating patient: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Patient - Hospital les Corts</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header-module { background: white; border-bottom: 1px solid #e1e8ed; padding: 12px 32px; display: flex; align-items: center; justify-content: space-between; height: 64px; }
        .btn-back { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500; text-decoration: none; display: inline-block; transition: all 0.2s; text-transform: uppercase; letter-spacing: 0.5px; }
        .btn-back:hover { border-color: #95a5a6; color: #2c3e50; }
        .container { max-width: 900px; margin: 0 auto; padding: 32px; }
        header { margin-bottom: 32px; }
        header h1 { font-size: 24px; color: #2c3e50; font-weight: 300; margin-bottom: 8px; }
        header p { color: #7f8c8d; font-size: 13px; }
        .patient-badge { display: inline-block; background: #e8f4f8; color: #2980b9; padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; margin-left: 12px; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; font-weight: 500; }
        .alert-error { background-color: #fcebeb; color: #c0392b; border: 1px solid #e74c3c; }
        .alert-success { background-color: #e8f8f5; color: #16a085; border: 1px solid #1abc9c; }
        .form-box { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 32px; }
        .section-title { font-size: 16px; font-weight: 600; color: #2c3e50; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #e1e8ed; }
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 24px; }
        .form-group { display: flex; flex-direction: column; }
        .form-group.full-width { grid-column: 1 / -1; }
        .form-group label { display: block; margin-bottom: 8px; color: #2c3e50; font-weight: 600; font-size: 13px; }
        .form-group label .required { color: #e74c3c; margin-left: 4px; }
        .form-group input, .form-group select, .form-group textarea { padding: 10px 12px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; font-family: inherit; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: #3498db; }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-group input:disabled { background: #f8f9fa; color: #7f8c8d; cursor: not-allowed; }
        .form-actions { display: flex; gap: 12px; margin-top: 24px; padding-top: 24px; border-top: 1px solid #e1e8ed; }
        .btn-primary { padding: 12px 24px; background: #2980b9; color: white; border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; }
        .btn-primary:hover { background: #3498db; }
        .btn-secondary { padding: 12px 24px; background: #95a5a6; color: white; border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; text-decoration: none; display: inline-block; }
        .btn-secondary:hover { background: #7f8c8d; }
        .field-hint { font-size: 12px; color: #7f8c8d; margin-top: 4px; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1 style="font-size: 18px; font-weight: 400; color: #2c3e50;">Edit Patient</h1>
        <a href="manage_patients.php" class="btn-back">← Back to Patient Management</a>
    </div>

    <div class="container">
        <header>
            <h1>
                ✏️ Edit Patient Information
                <span class="patient-badge">Record #<?= htmlspecialchars($patient['record_number']) ?></span>
            </h1>
            <p>Update patient information below. Required fields are marked with *</p>
        </header>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" class="form-box">
            <div class="section-title">Personal Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Record Number</label>
                    <input type="text" value="<?= htmlspecialchars($patient['record_number']) ?>" disabled>
                    <div class="field-hint">Cannot be changed</div>
                </div>
                <div class="form-group">
                    <label>Patient ID</label>
                    <input type="text" value="<?= htmlspecialchars($patient['patient_id']) ?>" disabled>
                    <div class="field-hint">System generated</div>
                </div>
                <div class="form-group">
                    <label>First Name <span class="required">*</span></label>
                    <input type="text" name="name" value="<?= htmlspecialchars($patient['name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <input type="text" name="surname" value="<?= htmlspecialchars($patient['surname']) ?>" required>
                </div>
                <div class="form-group">
                    <label>DNI / ID Number <span class="required">*</span></label>
                    <input type="text" name="DNI" value="<?= htmlspecialchars($patient['DNI']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Date of Birth <span class="required">*</span></label>
                    <input type="date" name="birth_date" value="<?= htmlspecialchars($patient['birth_date']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Sex <span class="required">*</span></label>
                    <select name="sex" required>
                        <option value="M" <?= $patient['sex'] === 'M' ? 'selected' : '' ?>>Male</option>
                        <option value="F" <?= $patient['sex'] === 'F' ? 'selected' : '' ?>>Female</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Blood Type</label>
                    <select name="blood_type">
                        <option value="">Select...</option>
                        <?php 
                        $blood_types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                        foreach ($blood_types as $type): 
                        ?>
                            <option value="<?= $type ?>" <?= $patient['blood_type'] === $type ? 'selected' : '' ?>><?= $type ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="section-title">Contact Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" value="<?= htmlspecialchars($patient['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone_number" value="<?= htmlspecialchars($patient['phone_number'] ?? '') ?>">
                </div>
                <div class="form-group full-width">
                    <label>Address</label>
                    <textarea name="adress"><?= htmlspecialchars($patient['adress'] ?? '') ?></textarea>
                </div>
                <div class="form-group full-width">
                    <label>Emergency Contact</label>
                    <input type="text" name="emergency_contact" value="<?= htmlspecialchars($patient['emergency_contact'] ?? '') ?>" placeholder="Name and phone number">
                </div>
            </div>

            <div class="section-title">Medical Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Primary Physician</label>
                    <select name="primary_physician">
                        <option value="">Select physician...</option>
                        <?php foreach ($professionals as $prof): ?>
                            <option value="<?= $prof['professional_id'] ?>" <?= $patient['primary_physician'] == $prof['professional_id'] ? 'selected' : '' ?>>
                                Dr. <?= htmlspecialchars($prof['name'] . ' ' . $prof['surname']) ?>
                                <?= $prof['speciality'] ? ' - ' . htmlspecialchars($prof['speciality']) : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Creatinine Clearance</label>
                    <input type="number" step="0.01" name="creatinine_clearance" value="<?= htmlspecialchars($patient['creatinine_clearance'] ?? '') ?>" placeholder="mL/min">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="update_patient" class="btn-primary">💾 Save Changes</button>
                <a href="manage_patients.php" class="btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>
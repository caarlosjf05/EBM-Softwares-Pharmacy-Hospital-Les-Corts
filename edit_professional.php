<?php
require_once 'includes/auth.php';

if (!hasRole('admin')) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$success = '';
$error = '';
$professional = null;

// Get professional ID from URL
$professional_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$professional_id) {
    header('Location: manage_professionals.php?error=invalid_id');
    exit();
}

// Get professional data
$stmt = $conn->prepare("SELECT * FROM PROFESSIONAL WHERE professional_id = :id");
$stmt->execute(['id' => $professional_id]);
$professional = $stmt->fetch();

if (!$professional) {
    header('Location: manage_professionals.php?error=professional_not_found');
    exit();
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_professional'])) {
    try {
        $conn->beginTransaction();
        
        // Validate required fields
        $required_fields = ['name', 'surname', 'DNI', 'code', 'role', 'email', 'entry_date'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }
        
        // Check if DNI already exists for another professional
        $stmt = $conn->prepare("SELECT professional_id FROM PROFESSIONAL WHERE DNI = :dni AND professional_id != :id");
        $stmt->execute(['dni' => $_POST['DNI'], 'id' => $professional_id]);
        if ($stmt->fetch()) {
            throw new Exception('Another professional with this DNI already exists.');
        }
        
        // Check if code already exists for another professional
        $stmt = $conn->prepare("SELECT professional_id FROM PROFESSIONAL WHERE code = :code AND professional_id != :id");
        $stmt->execute(['code' => $_POST['code'], 'id' => $professional_id]);
        if ($stmt->fetch()) {
            throw new Exception('Another professional with this code already exists.');
        }
        
        // Check if email already exists for another professional
        $stmt = $conn->prepare("SELECT professional_id FROM PROFESSIONAL WHERE email = :email AND professional_id != :id");
        $stmt->execute(['email' => $_POST['email'], 'id' => $professional_id]);
        if ($stmt->fetch()) {
            throw new Exception('Another professional with this email already exists.');
        }
        
        // Update professional
        $stmt = $conn->prepare("
            UPDATE PROFESSIONAL SET
                code = :code,
                DNI = :DNI,
                name = :name,
                surname = :surname,
                role = :role,
                speciality = :speciality,
                telephone = :telephone,
                email = :email,
                turn = :turn,
                entry_date = :entry_date
            WHERE professional_id = :professional_id
        ");
        
        $stmt->execute([
            'code' => $_POST['code'],
            'DNI' => $_POST['DNI'],
            'name' => $_POST['name'],
            'surname' => $_POST['surname'],
            'role' => $_POST['role'],
            'speciality' => !empty($_POST['speciality']) ? $_POST['speciality'] : null,
            'telephone' => !empty($_POST['telephone']) ? $_POST['telephone'] : null,
            'email' => $_POST['email'],
            'turn' => !empty($_POST['turn']) ? $_POST['turn'] : null,
            'entry_date' => $_POST['entry_date'],
            'professional_id' => $professional_id
        ]);
        
        $conn->commit();
        $success = 'Professional information updated successfully.';
        
        // Reload professional data
        $stmt = $conn->prepare("SELECT * FROM PROFESSIONAL WHERE professional_id = :id");
        $stmt->execute(['id' => $professional_id]);
        $professional = $stmt->fetch();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = 'Error updating professional: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Professional - Hospital les Corts</title>
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
        .professional-badge { display: inline-block; background: #e8f4f8; color: #2980b9; padding: 4px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; margin-left: 12px; }
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
        <h1 style="font-size: 18px; font-weight: 400; color: #2c3e50;">Edit Professional</h1>
        <a href="manage_professionals.php" class="btn-back">← Back to Professional Management</a>
    </div>

    <div class="container">
        <header>
            <h1>
                ✏️ Edit Professional Information
                <span class="professional-badge">Code: <?= htmlspecialchars($professional['code']) ?></span>
            </h1>
            <p>Update professional information below. Required fields are marked with *</p>
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
                    <label>Professional ID</label>
                    <input type="text" value="<?= htmlspecialchars($professional['professional_id']) ?>" disabled>
                    <div class="field-hint">System generated</div>
                </div>
                <div class="form-group">
                    <label>Professional Code <span class="required">*</span></label>
                    <input type="text" name="code" value="<?= htmlspecialchars($professional['code']) ?>" required>
                </div>
                <div class="form-group">
                    <label>DNI / ID Number <span class="required">*</span></label>
                    <input type="text" name="DNI" value="<?= htmlspecialchars($professional['DNI']) ?>" required>
                </div>
                <div class="form-group">
                    <label>First Name <span class="required">*</span></label>
                    <input type="text" name="name" value="<?= htmlspecialchars($professional['name']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <input type="text" name="surname" value="<?= htmlspecialchars($professional['surname']) ?>" required>
                </div>
            </div>

            <div class="section-title">Professional Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Role <span class="required">*</span></label>
                    <select name="role" required>
                        <option value="">Select...</option>
                        <option value="doctor" <?= $professional['role'] === 'doctor' ? 'selected' : '' ?>>Doctor</option>
                        <option value="nurse" <?= $professional['role'] === 'nurse' ? 'selected' : '' ?>>Nurse</option>
                        <option value="pharmacist" <?= $professional['role'] === 'pharmacist' ? 'selected' : '' ?>>Pharmacist</option>
                        <option value="admin" <?= $professional['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Speciality</label>
                    <input type="text" name="speciality" value="<?= htmlspecialchars($professional['speciality'] ?? '') ?>" placeholder="e.g., Cardiology, Pediatrics">
                </div>
                <div class="form-group">
                    <label>Turn / Shift</label>
                    <select name="turn">
                        <option value="">Select...</option>
                        <option value="morning" <?= $professional['turn'] === 'morning' ? 'selected' : '' ?>>Morning</option>
                        <option value="evening" <?= $professional['turn'] === 'evening' ? 'selected' : '' ?>>Evening</option>
                        <option value="night" <?= $professional['turn'] === 'night' ? 'selected' : '' ?>>Night</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Entry Date <span class="required">*</span></label>
                    <input type="date" name="entry_date" value="<?= htmlspecialchars($professional['entry_date']) ?>" required>
                </div>
            </div>

            <div class="section-title">Contact Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" value="<?= htmlspecialchars($professional['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Telephone</label>
                    <input type="tel" name="telephone" value="<?= htmlspecialchars($professional['telephone'] ?? '') ?>">
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="update_professional" class="btn-primary">💾 Save Changes</button>
                <a href="manage_professionals.php" class="btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</body>
</html>
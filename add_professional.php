<?php
require_once 'includes/auth.php';

if (!hasRole('admin')) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$success = '';
$error = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_professional'])) {
    try {
        $conn->beginTransaction();
        
        // Validate required fields
        $required_fields = ['name', 'surname', 'DNI', 'code', 'role', 'email', 'entry_date', 'password'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }
        
        // Validate telephone if provided
        if (!empty($_POST['telephone'])) {
            $phone = trim($_POST['telephone']);
            // Remove spaces and common separators
            $phone_clean = preg_replace('/[\s\-\(\)]+/', '', $phone);
            
            // Check if it starts with +34 and remove it for digit counting
            if (strpos($phone_clean, '+34') === 0) {
                $phone_digits = substr($phone_clean, 3);
            } else {
                $phone_digits = $phone_clean;
            }
            
            // Validate that we have exactly 9 digits
            if (!preg_match('/^\d{9}$/', $phone_digits)) {
                throw new Exception('Telephone must have exactly 9 digits (with optional +34 prefix). Example: +34 123456789 or 123456789');
            }
            
            // Store the cleaned phone with +34 prefix
            $phone_to_store = '+34' . $phone_digits;
        } else {
            $phone_to_store = null;
        }
        
        // Check if DNI already exists
        $stmt = $conn->prepare("SELECT professional_id FROM PROFESSIONAL WHERE DNI = :dni");
        $stmt->execute(['dni' => $_POST['DNI']]);
        if ($stmt->fetch()) {
            throw new Exception('A professional with this DNI already exists.');
        }
        
        // Check if code already exists
        $stmt = $conn->prepare("SELECT professional_id FROM PROFESSIONAL WHERE code = :code");
        $stmt->execute(['code' => $_POST['code']]);
        if ($stmt->fetch()) {
            throw new Exception('A professional with this code already exists.');
        }
        
        // Check if email already exists in PROFESSIONAL or users
        $stmt = $conn->prepare("SELECT professional_id FROM PROFESSIONAL WHERE email = :email");
        $stmt->execute(['email' => $_POST['email']]);
        if ($stmt->fetch()) {
            throw new Exception('A professional with this email already exists.');
        }
        
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $_POST['email']]);
        if ($stmt->fetch()) {
            throw new Exception('This email is already registered in the system.');
        }
        
        // Validate password strength
        if (strlen($_POST['password']) < 6) {
            throw new Exception('Password must be at least 6 characters long.');
        }
        
        // Insert new professional
        $stmt = $conn->prepare("
            INSERT INTO PROFESSIONAL (
                code, DNI, name, surname, role, speciality, 
                telephone, email, turn, entry_date
            ) VALUES (
                :code, :DNI, :name, :surname, :role, :speciality, 
                :telephone, :email, :turn, :entry_date
            )
        ");
        
        $stmt->execute([
            'code' => $_POST['code'],
            'DNI' => $_POST['DNI'],
            'name' => $_POST['name'],
            'surname' => $_POST['surname'],
            'role' => $_POST['role'],
            'speciality' => !empty($_POST['speciality']) ? $_POST['speciality'] : null,
            'telephone' => $phone_to_store,
            'email' => $_POST['email'],
            'turn' => !empty($_POST['turn']) ? $_POST['turn'] : null,
            'entry_date' => $_POST['entry_date']
        ]);
        
        // Get the newly created professional_id
        $professional_id = $conn->lastInsertId();
        
        // Hash password
        $password_hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
        
        // Insert into users table
        $stmt = $conn->prepare("
            INSERT INTO users (
                email, password_hash, type_user, status, 
                registration_date, professional_id
            ) VALUES (
                :email, :password_hash, :type_user, 'active',
                NOW(), :professional_id
            )
        ");
        
        $stmt->execute([
            'email' => $_POST['email'],
            'password_hash' => $password_hash,
            'type_user' => $_POST['role'], // doctor, nurse, admin, pharmacist
            'professional_id' => $professional_id
        ]);
        
        $conn->commit();
        $success = 'Professional added successfully. Login credentials created.';
        
        // Clear form data after successful submission
        $_POST = [];
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = 'Error adding professional: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Professional - Hospital les Corts</title>
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
        .form-group input.error { border-color: #e74c3c; }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-actions { display: flex; gap: 12px; margin-top: 24px; padding-top: 24px; border-top: 1px solid #e1e8ed; }
        .btn-primary { padding: 12px 24px; background: #2980b9; color: white; border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; }
        .btn-primary:hover { background: #3498db; }
        .btn-secondary { padding: 12px 24px; background: #95a5a6; color: white; border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; text-decoration: none; display: inline-block; }
        .btn-secondary:hover { background: #7f8c8d; }
        .field-hint { font-size: 12px; color: #7f8c8d; margin-top: 4px; }
        .field-hint.error { color: #e74c3c; }
        .security-notice { background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; color: #856404; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1 style="font-size: 18px; font-weight: 400; color: #2c3e50;">Add Professional</h1>
        <a href="dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>

    <div class="container">
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
                    <label>Professional Code <span class="required">*</span></label>
                    <input type="text" name="code" value="<?= htmlspecialchars($_POST['code'] ?? '') ?>" required placeholder="e.g., DOC001">
                    <div class="field-hint">Unique identifier for the professional</div>
                </div>
                <div class="form-group">
                    <label>DNI / ID Number <span class="required">*</span></label>
                    <input type="text" name="DNI" value="<?= htmlspecialchars($_POST['DNI'] ?? '') ?>" required placeholder="e.g., 12345678A">
                </div>
                <div class="form-group">
                    <label>First Name <span class="required">*</span></label>
                    <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <input type="text" name="surname" value="<?= htmlspecialchars($_POST['surname'] ?? '') ?>" required>
                </div>
            </div>

            <div class="section-title">Professional Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Role <span class="required">*</span></label>
                    <select name="role" required>
                        <option value="">Select...</option>
                        <option value="doctor" <?= ($_POST['role'] ?? '') === 'doctor' ? 'selected' : '' ?>>Doctor</option>
                        <option value="nurse" <?= ($_POST['role'] ?? '') === 'nurse' ? 'selected' : '' ?>>Nurse</option>
                        <option value="pharmacist" <?= ($_POST['role'] ?? '') === 'pharmacist' ? 'selected' : '' ?>>Pharmacist</option>
                        <option value="admin" <?= ($_POST['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                    <div class="field-hint">This will determine their system permissions</div>
                </div>
                <div class="form-group">
                    <label>Speciality</label>
                    <input type="text" name="speciality" value="<?= htmlspecialchars($_POST['speciality'] ?? '') ?>" placeholder="e.g., Cardiology, Pediatrics">
                </div>
                <div class="form-group">
                    <label>Turn / Shift</label>
                    <select name="turn">
                        <option value="">Select...</option>
                        <option value="morning" <?= ($_POST['turn'] ?? '') === 'morning' ? 'selected' : '' ?>>Morning</option>
                        <option value="evening" <?= ($_POST['turn'] ?? '') === 'evening' ? 'selected' : '' ?>>Evening</option>
                        <option value="night" <?= ($_POST['turn'] ?? '') === 'night' ? 'selected' : '' ?>>Night</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Entry Date <span class="required">*</span></label>
                    <input type="date" name="entry_date" value="<?= htmlspecialchars($_POST['entry_date'] ?? date('Y-m-d')) ?>" required>
                </div>
            </div>

            <div class="section-title">Contact Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required placeholder="email@hospital.com">
                    <div class="field-hint">Must be unique - will be used for login</div>
                </div>
                <div class="form-group">
                    <label>Telephone</label>
                    <input type="tel" name="telephone" id="telephone" value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>" placeholder="+34 123456789">
                    <div class="field-hint" id="phone-hint">9 digits required (optional +34 prefix)</div>
                </div>
            </div>

            <div class="section-title">Login Credentials</div>
            <div class="security-notice">
                🔒 These credentials will allow the professional to access the system according to their assigned role.
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Password <span class="required">*</span></label>
                    <input type="password" name="password" required minlength="6">
                    <div class="field-hint">Minimum 6 characters</div>
                </div>
                <div class="form-group">
                    <label>Confirm Password <span class="required">*</span></label>
                    <input type="password" name="password_confirm" required minlength="6">
                    <div class="field-hint">Must match password</div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="add_professional" class="btn-primary">💾 Add Professional</button>
                <a href="manage_professionals.php" class="btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        const phoneInput = document.getElementById('telephone');
        const phoneHint = document.getElementById('phone-hint');

        // Validate phone format in real-time
        phoneInput.addEventListener('input', function() {
            if (this.value.trim() === '') {
                phoneInput.classList.remove('error');
                phoneHint.classList.remove('error');
                phoneHint.textContent = '9 digits required (optional +34 prefix)';
                return;
            }

            const phone = this.value.replace(/[\s\-\(\)]+/g, '');
            let digits = phone;
            
            if (phone.startsWith('+34')) {
                digits = phone.substring(3);
            }
            
            if (/^\d{9}$/.test(digits)) {
                phoneInput.classList.remove('error');
                phoneHint.classList.remove('error');
                phoneHint.textContent = '✓ Valid phone number';
            } else {
                phoneInput.classList.add('error');
                phoneHint.classList.add('error');
                phoneHint.textContent = '✗ Must be exactly 9 digits';
            }
        });

        // Create error message container
        function showError(message) {
            // Remove existing error if any
            const existingError = document.querySelector('.validation-error');
            if (existingError) {
                existingError.remove();
            }

            // Create new error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'alert alert-error validation-error';
            errorDiv.innerHTML = '❌ ' + message;
            
            // Insert at the top of the form
            const formBox = document.querySelector('.form-box');
            formBox.insertBefore(errorDiv, formBox.firstChild);
            
            // Scroll to error
            errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // Validate form before submission
        document.querySelector('form').addEventListener('submit', function(e) {
            const password = document.querySelector('input[name="password"]').value;
            const confirm = document.querySelector('input[name="password_confirm"]').value;
            
            if (password !== confirm) {
                e.preventDefault();
                showError('Passwords do not match. Please verify and try again.');
                document.querySelector('input[name="password_confirm"]').focus();
                return false;
            }

            // Validate phone if provided
            const phone = phoneInput.value.trim();
            if (phone !== '') {
                const phoneClean = phone.replace(/[\s\-\(\)]+/g, '');
                let digits = phoneClean;
                
                if (phoneClean.startsWith('+34')) {
                    digits = phoneClean.substring(3);
                }
                
                if (!/^\d{9}$/.test(digits)) {
                    e.preventDefault();
                    showError('Telephone must have exactly 9 digits (with optional +34 prefix). Example: +34 123456789 or 123456789');
                    phoneInput.focus();
                    return false;
                }
            }
        });
    </script>
</body>
</html>
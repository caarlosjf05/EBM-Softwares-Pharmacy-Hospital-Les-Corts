<?php
require_once 'includes/auth.php';

if (!hasRole('admin')) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$success = '';
$error = '';

// Get list of professionals for primary physician dropdown
$stmt = $conn->query("SELECT professional_id, name, surname, speciality FROM PROFESSIONAL ORDER BY surname, name");
$professionals = $stmt->fetchAll();

// Get list of allergens for allergies selection
$stmt = $conn->query("SELECT allergen_id, name_alergen FROM ALLERGENS ORDER BY name_alergen");
$allergens = $stmt->fetchAll();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_patient'])) {
    try {
        $conn->beginTransaction();
        
        // Validate required fields
        $required_fields = ['name', 'surname', 'DNI', 'birth_date', 'sex', 'email', 'password'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }
        
        // Check if DNI already exists in PATIENT
        $stmt = $conn->prepare("SELECT patient_id FROM PATIENT WHERE DNI = :dni");
        $stmt->execute(['dni' => $_POST['DNI']]);
        if ($stmt->fetch()) {
            throw new Exception('A patient with this DNI already exists.');
        }
        
        // Check if email already exists in PATIENT or users
        $stmt = $conn->prepare("SELECT patient_id FROM PATIENT WHERE email = :email");
        $stmt->execute(['email' => $_POST['email']]);
        if ($stmt->fetch()) {
            throw new Exception('A patient with this email already exists.');
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
        
        // Validate phone number format if provided
        if (!empty($_POST['phone_number'])) {
            if (!preg_match('/^\+34[0-9]{9}$/', $_POST['phone_number'])) {
                throw new Exception('Phone number must be in format +34XXXXXXXXX (9 digits after +34)');
            }
        }
        
        // Validate emergency contact format if provided
        if (!empty($_POST['emergency_contact'])) {
            if (!preg_match('/^\+34[0-9]{9}$/', $_POST['emergency_contact'])) {
                throw new Exception('Emergency contact must be in format +34XXXXXXXXX (9 digits after +34)');
            }
        }
        
        // Generate record number
        $stmt = $conn->query("SELECT MAX(CAST(SUBSTRING(record_number, 4) AS UNSIGNED)) as max_num FROM PATIENT WHERE record_number LIKE 'MRN%'");
        $result = $stmt->fetch();
        $next_num = ($result['max_num'] ?? 0) + 1;
        $record_number = 'MRN' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
        
        // Insert patient
        $stmt = $conn->prepare("
            INSERT INTO PATIENT (
                name, surname, DNI, record_number, birth_date, sex, blood_type,
                phone_number, email, adress, emergency_contact, primary_physician
            ) VALUES (
                :name, :surname, :DNI, :record_number, :birth_date, :sex, :blood_type,
                :phone_number, :email, :adress, :emergency_contact, :primary_physician
            )
        ");
        
        $stmt->execute([
            'name' => $_POST['name'],
            'surname' => $_POST['surname'],
            'DNI' => $_POST['DNI'],
            'record_number' => $record_number,
            'birth_date' => $_POST['birth_date'],
            'sex' => $_POST['sex'],
            'blood_type' => !empty($_POST['blood_type']) ? $_POST['blood_type'] : null,
            'phone_number' => !empty($_POST['phone_number']) ? $_POST['phone_number'] : null,
            'email' => $_POST['email'],
            'adress' => !empty($_POST['adress']) ? $_POST['adress'] : null,
            'emergency_contact' => !empty($_POST['emergency_contact']) ? $_POST['emergency_contact'] : null,
            'primary_physician' => !empty($_POST['primary_physician']) ? $_POST['primary_physician'] : null,
        ]);
        
        // Get the newly created patient_id
        $patient_id = $conn->lastInsertId();
        
        // Insert allergies if selected
        if (!empty($_POST['allergies']) && is_array($_POST['allergies'])) {
            $stmt = $conn->prepare("
                INSERT INTO PATIENT_ALLERGY (patient_id, allergen_id)
                VALUES (:patient_id, :allergen_id)
            ");
            
            foreach ($_POST['allergies'] as $allergen_id) {
                if (!empty($allergen_id)) {
                    $stmt->execute([
                        'patient_id' => $patient_id,
                        'allergen_id' => $allergen_id
                    ]);
                }
            }
        }
        
        // Hash password
        $password_hash = password_hash($_POST['password'], PASSWORD_BCRYPT);
        
        // Insert into users table
        $stmt = $conn->prepare("
            INSERT INTO users (
                email, password_hash, type_user, status, 
                registration_date, patient_id
            ) VALUES (
                :email, :password_hash, 'patient', 'active',
                NOW(), :patient_id
            )
        ");
        
        $stmt->execute([
            'email' => $_POST['email'],
            'password_hash' => $password_hash,
            'patient_id' => $patient_id
        ]);
        
        $conn->commit();
        $success = 'Patient added successfully with record number: ' . $record_number . '. Login credentials created.';
        
        // Clear form
        $_POST = [];
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = 'Error adding patient: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Patient - Hospital les Corts</title>
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
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-actions { display: flex; gap: 12px; margin-top: 24px; padding-top: 24px; border-top: 1px solid #e1e8ed; }
        .btn-primary { padding: 12px 24px; background: #2980b9; color: white; border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; }
        .btn-primary:hover { background: #3498db; }
        .btn-secondary { padding: 12px 24px; background: #95a5a6; color: white; border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; text-decoration: none; display: inline-block; }
        .btn-secondary:hover { background: #7f8c8d; }
        .field-hint { font-size: 12px; color: #7f8c8d; margin-top: 4px; }
        .security-notice { background: #fff3cd; border: 1px solid #ffc107; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; color: #856404; }
        
        /* Autocomplete styles */
        .autocomplete-container { position: relative; }
        .autocomplete-input { width: 100%; }
        .autocomplete-results { position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #dce1e6; border-top: none; border-radius: 0 0 4px 4px; max-height: 200px; overflow-y: auto; z-index: 1000; display: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .autocomplete-results.active { display: block; }
        .autocomplete-item { padding: 10px 12px; cursor: pointer; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .autocomplete-item:hover, .autocomplete-item.selected { background: #f5f7fa; }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item .item-main { color: #2c3e50; font-weight: 500; }
        .autocomplete-item .item-sub { color: #7f8c8d; font-size: 12px; margin-top: 2px; }
        
        /* Selected items display */
        .selected-items { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; min-height: 32px; }
        .selected-item { display: inline-flex; align-items: center; gap: 6px; background: #e8f4f8; color: #2980b9; padding: 6px 10px; border-radius: 4px; font-size: 13px; border: 1px solid #bee5eb; }
        .selected-item .remove-btn { cursor: pointer; font-weight: bold; color: #2980b9; background: none; border: none; padding: 0; font-size: 16px; line-height: 1; }
        .selected-item .remove-btn:hover { color: #1a5a7a; }
        
        .selected-physician { display: inline-flex; align-items: center; gap: 6px; background: #d4edda; color: #155724; padding: 8px 12px; border-radius: 4px; font-size: 13px; border: 1px solid #c3e6cb; margin-top: 8px; }
        .selected-physician .remove-btn { cursor: pointer; font-weight: bold; color: #155724; background: none; border: none; padding: 0; font-size: 16px; line-height: 1; margin-left: 4px; }
        .selected-physician .remove-btn:hover { color: #0a3d14; }
    </style>
</head>
<body>
    <div class="header-module">
        <h1 style="font-size: 18px; font-weight: 400; color: #2c3e50;">Add New Patient</h1>
        <a href="manage_patients.php" class="btn-back">← Back to Patient Management</a>
    </div>

    <div class="container">
        <header>
            <h1>👤 Patient Registration</h1>
            <p>Complete the form below to add a new patient to the system</p>
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
                    <label>First Name <span class="required">*</span></label>
                    <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Last Name <span class="required">*</span></label>
                    <input type="text" name="surname" value="<?= htmlspecialchars($_POST['surname'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>DNI / ID Number <span class="required">*</span></label>
                    <input type="text" name="DNI" value="<?= htmlspecialchars($_POST['DNI'] ?? '') ?>" required>
                    <div class="field-hint">Must be unique</div>
                </div>
                <div class="form-group">
                    <label>Date of Birth <span class="required">*</span></label>
                    <input type="date" name="birth_date" value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Sex <span class="required">*</span></label>
                    <select name="sex" required>
                        <option value="">Select...</option>
                        <option value="M" <?= ($_POST['sex'] ?? '') === 'M' ? 'selected' : '' ?>>Male</option>
                        <option value="F" <?= ($_POST['sex'] ?? '') === 'F' ? 'selected' : '' ?>>Female</option>
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
                            <option value="<?= $type ?>" <?= ($_POST['blood_type'] ?? '') === $type ? 'selected' : '' ?>><?= $type ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="section-title">Contact Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Email <span class="required">*</span></label>
                    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                    <div class="field-hint">Must be unique - will be used for login</div>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" 
                           name="phone_number" 
                           id="phone_number"
                           value="<?= htmlspecialchars($_POST['phone_number'] ?? '') ?>"
                           placeholder="+34612345678"
                           pattern="\+34[0-9]{9}"
                           title="Format: +34 followed by 9 digits (e.g., +34612345678)">
                    <div class="field-hint">Spanish mobile format: +34XXXXXXXXX</div>
                </div>
                <div class="form-group full-width">
                    <label>Address</label>
                    <textarea name="adress"><?= htmlspecialchars($_POST['adress'] ?? '') ?></textarea>
                </div>
                <div class="form-group full-width">
                    <label>Emergency Contact</label>
                    <input type="tel" 
                           name="emergency_contact" 
                           id="emergency_contact"
                           value="<?= htmlspecialchars($_POST['emergency_contact'] ?? '') ?>" 
                           placeholder="+34612345678"
                           pattern="\+34[0-9]{9}"
                           title="Format: +34 followed by 9 digits (e.g., +34612345678)">
                    <div class="field-hint">Spanish mobile format: +34XXXXXXXXX</div>
                </div>
            </div>

            <div class="section-title">Login Credentials</div>
            <div class="security-notice">
                🔒 These credentials will allow the patient to access their medical records and prescriptions through the patient portal.
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

            <div class="section-title">Medical Information</div>
            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Primary Physician</label>
                    <div class="autocomplete-container">
                        <input type="text" 
                               id="physician_search" 
                               class="autocomplete-input" 
                               placeholder="Type to search for a physician..."
                               autocomplete="off">
                        <div id="physician_results" class="autocomplete-results"></div>
                        <input type="hidden" name="primary_physician" id="primary_physician_id">
                        <div id="selected_physician_display"></div>
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <label>Allergies</label>
                    <div class="autocomplete-container">
                        <input type="text" 
                               id="allergy_search" 
                               class="autocomplete-input" 
                               placeholder="Type to search and add allergies..."
                               autocomplete="off">
                        <div id="allergy_results" class="autocomplete-results"></div>
                        <div id="selected_allergies" class="selected-items"></div>
                    </div>
                    <div class="field-hint">Type to search and select multiple allergies</div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" name="add_patient" class="btn-primary">✅ Add Patient</button>
                <a href="manage_patients.php" class="btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <script>
        // Data from PHP
        const professionals = <?= json_encode($professionals) ?>;
        const allergens = <?= json_encode($allergens) ?>;
        
        // Function to format phone numbers
        function formatPhoneNumber(input) {
            input.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, ''); // Remove non-digits
                
                // If doesn't start with 34, add it
                if (!value.startsWith('34')) {
                    value = '34' + value;
                }
                
                // Limit to 11 digits (34 + 9)
                value = value.substring(0, 11);
                
                // Add + prefix
                if (value.length > 0) {
                    e.target.value = '+' + value;
                }
            });
            
            // Ensure +34 on focus if empty
            input.addEventListener('focus', function(e) {
                if (e.target.value === '') {
                    e.target.value = '+34';
                }
            });
        }
        
        // Apply formatting to both phone fields
        const phoneInput = document.getElementById('phone_number');
        const emergencyInput = document.getElementById('emergency_contact');
        
        if (phoneInput) {
            formatPhoneNumber(phoneInput);
        }
        
        if (emergencyInput) {
            formatPhoneNumber(emergencyInput);
        }
        
        // Physician Autocomplete
        const physicianSearch = document.getElementById('physician_search');
        const physicianResults = document.getElementById('physician_results');
        const physicianIdInput = document.getElementById('primary_physician_id');
        const physicianDisplay = document.getElementById('selected_physician_display');
        let selectedPhysician = null;
        
        physicianSearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            
            if (searchTerm.length < 1) {
                physicianResults.classList.remove('active');
                return;
            }
            
            const filtered = professionals.filter(prof => {
                const fullName = `${prof.name} ${prof.surname}`.toLowerCase();
                const speciality = (prof.speciality || '').toLowerCase();
                return fullName.includes(searchTerm) || speciality.includes(searchTerm);
            });
            
            if (filtered.length > 0) {
                physicianResults.innerHTML = filtered.map(prof => `
                    <div class="autocomplete-item" data-id="${prof.professional_id}">
                        <div class="item-main">Dr. ${prof.name} ${prof.surname}</div>
                        ${prof.speciality ? `<div class="item-sub">${prof.speciality}</div>` : ''}
                    </div>
                `).join('');
                physicianResults.classList.add('active');
            } else {
                physicianResults.innerHTML = '<div class="autocomplete-item" style="color: #7f8c8d;">No physicians found</div>';
                physicianResults.classList.add('active');
            }
        });
        
        physicianResults.addEventListener('click', function(e) {
            const item = e.target.closest('.autocomplete-item');
            if (item && item.dataset.id) {
                const profId = item.dataset.id;
                const prof = professionals.find(p => p.professional_id == profId);
                
                if (prof) {
                    selectedPhysician = prof;
                    physicianIdInput.value = prof.professional_id;
                    physicianSearch.value = '';
                    physicianResults.classList.remove('active');
                    
                    physicianDisplay.innerHTML = `
                        <div class="selected-physician">
                            Dr. ${prof.name} ${prof.surname}
                            ${prof.speciality ? ` - ${prof.speciality}` : ''}
                            <button type="button" class="remove-btn" onclick="removePhysician()">×</button>
                        </div>
                    `;
                }
            }
        });
        
        function removePhysician() {
            selectedPhysician = null;
            physicianIdInput.value = '';
            physicianDisplay.innerHTML = '';
        }
        
        // Close physician dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!physicianSearch.contains(e.target) && !physicianResults.contains(e.target)) {
                physicianResults.classList.remove('active');
            }
        });
        
        // Allergy Autocomplete with Multiple Selection
        const allergySearch = document.getElementById('allergy_search');
        const allergyResults = document.getElementById('allergy_results');
        const selectedAllergiesDiv = document.getElementById('selected_allergies');
        let selectedAllergies = [];
        
        allergySearch.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            
            if (searchTerm.length < 1) {
                allergyResults.classList.remove('active');
                return;
            }
            
            // Filter out already selected allergies
            const filtered = allergens.filter(allerg => {
                const isNotSelected = !selectedAllergies.find(s => s.allergen_id === allerg.allergen_id);
                const matchesSearch = allerg.name_alergen.toLowerCase().includes(searchTerm);
                return isNotSelected && matchesSearch;
            });
            
            if (filtered.length > 0) {
                allergyResults.innerHTML = filtered.map(allerg => `
                    <div class="autocomplete-item" data-id="${allerg.allergen_id}">
                        <div class="item-main">${allerg.name_alergen}</div>
                    </div>
                `).join('');
                allergyResults.classList.add('active');
            } else {
                allergyResults.innerHTML = '<div class="autocomplete-item" style="color: #7f8c8d;">No allergies found</div>';
                allergyResults.classList.add('active');
            }
        });
        
        allergyResults.addEventListener('click', function(e) {
            const item = e.target.closest('.autocomplete-item');
            if (item && item.dataset.id) {
                const allergId = item.dataset.id;
                const allerg = allergens.find(a => a.allergen_id == allergId);
                
                if (allerg && !selectedAllergies.find(s => s.allergen_id === allerg.allergen_id)) {
                    selectedAllergies.push(allerg);
                    updateSelectedAllergies();
                    allergySearch.value = '';
                    allergyResults.classList.remove('active');
                }
            }
        });
        
        function updateSelectedAllergies() {
            selectedAllergiesDiv.innerHTML = selectedAllergies.map(allerg => `
                <div class="selected-item">
                    ${allerg.name_alergen}
                    <button type="button" class="remove-btn" onclick="removeAllergy(${allerg.allergen_id})">×</button>
                    <input type="hidden" name="allergies[]" value="${allerg.allergen_id}">
                </div>
            `).join('');
        }
        
        function removeAllergy(allergenId) {
            selectedAllergies = selectedAllergies.filter(a => a.allergen_id != allergenId);
            updateSelectedAllergies();
        }
        
        // Close allergy dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!allergySearch.contains(e.target) && !allergyResults.contains(e.target)) {
                allergyResults.classList.remove('active');
            }
        });
        
        // Validate form before submission
        document.querySelector('form').addEventListener('submit', function(e) {
            const password = document.querySelector('input[name="password"]').value;
            const confirm = document.querySelector('input[name="password_confirm"]').value;
            
            // Validate passwords match
            if (password !== confirm) {
                e.preventDefault();
                alert('Passwords do not match. Please verify and try again.');
                return false;
            }
            
            // Validate phone number if filled
            const phone = phoneInput.value;
            if (phone && phone !== '+34') {
                const phoneDigits = phone.replace(/\D/g, '');
                if (phoneDigits.length !== 11 || !phoneDigits.startsWith('34')) {
                    e.preventDefault();
                    alert('Phone number must be in format +34XXXXXXXXX (9 digits after +34)');
                    return false;
                }
            }
            
            // Validate emergency contact if filled
            const emergency = emergencyInput.value;
            if (emergency && emergency !== '+34') {
                const emergencyDigits = emergency.replace(/\D/g, '');
                if (emergencyDigits.length !== 11 || !emergencyDigits.startsWith('34')) {
                    e.preventDefault();
                    alert('Emergency contact must be in format +34XXXXXXXXX (9 digits after +34)');
                    return false;
                }
            }
        });
    </script>
</body>
</html>
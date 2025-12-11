<?php
require_once 'includes/auth.php';

if (!hasRole('admin')) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$success = '';
$error = '';

// Delete patient
if (isset($_POST['delete_patient'])) {
    try {
        $patient_id = $_POST['patient_id'];
        
        // Check if patient has prescriptions
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM PRESCRIPTION WHERE patient_id = :id");
        $stmt->execute(['id' => $patient_id]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            $error = 'Cannot delete patient with existing prescriptions. Please archive instead.';
        } else {
            $stmt = $conn->prepare("DELETE FROM PATIENT WHERE patient_id = :id");
            $stmt->execute(['id' => $patient_id]);
            $success = 'Patient deleted successfully.';
        }
    } catch (Exception $e) {
        $error = 'Error deleting patient: ' . $e->getMessage();
    }
}

// Search and filter
$search = $_GET['search'] ?? '';
$search_clause = '';
$params = [];

if ($search) {
    $search_clause = " WHERE name LIKE :search OR surname LIKE :search OR DNI LIKE :search OR record_number LIKE :search";
    $params['search'] = '%' . $search . '%';
}

// Get all patients
$stmt = $conn->prepare("
    SELECT 
        patient_id,
        name,
        surname,
        DNI,
        record_number,
        birth_date,
        sex,
        blood_type,
        phone_number,
        email,
        adress,
        emergency_contact,
        primary_physician
    FROM PATIENT
    $search_clause
    ORDER BY surname, name
");
$stmt->execute($params);
$patients = $stmt->fetchAll();

// Calculate age function
function calculateAge($birthdate) {
    if (!$birthdate) return 'N/A';
    $today = new DateTime();
    $birth = new DateTime($birthdate);
    return $today->diff($birth)->y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Management - Hospital les Corts</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            padding: 0;
        }

        .header-module {
            background: white;
            border-bottom: 1px solid #e1e8ed;
            padding: 12px 32px;
            display: flex;
            align-items: center;
            justify-content: flex-start;
            height: 64px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e1e8ed;
        }

        header h1 {
            font-size: 24px;
            color: #2c3e50;
            font-weight: 300;
        }
        
        header p {
            color: #7f8c8d;
            font-size: 13px;
        }
        
        .btn-back {
            background: transparent;
            color: #7f8c8d;
            border: 1px solid #dce1e6;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-back:hover {
            border-color: #95a5a6;
            color: #2c3e50;
        }

        .btn-primary {
            display: inline-block;
            padding: 10px 20px;
            background-color: #2980b9; 
            color: white;
            text-align: center;
            text-decoration: none;
            border: none;
            cursor: pointer;
            border-radius: 4px;
            font-weight: 500;
            transition: background-color 0.3s;
            font-size: 14px;
        }
        
        .btn-primary:hover { 
            background-color: #3498db; 
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 500;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s;
            text-decoration: none;
            display: inline-block;
            margin: 2px;
            border: none;
        }
        
        .btn-edit {
            background-color: #fcf3cf;
            color: #f39c12;
            border: 1px solid #f7f7e8;
        }
        
        .btn-edit:hover { 
            background-color: #f9eeb9; 
        }
        
        .btn-delete {
            background-color: #fdeaea;
            color: #e74c3c;
            border: 1px solid #fcebeb;
        }
        
        .btn-delete:hover { 
            background-color: #fbd5d5; 
        }

        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background-color: #fcebeb;
            color: #c0392b;
            border: 1px solid #e74c3c;
        }
        
        .alert-success {
            background-color: #e8f8f5;
            color: #16a085;
            border: 1px solid #1abc9c;
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .search-form input[type="text"] {
            flex-grow: 1;
            padding: 10px;
            border: 1px solid #dce1e6;
            border-radius: 4px;
            font-size: 14px;
        }

        .section-box {
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 4px;
            padding: 20px;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            border-bottom: 1px solid #ecf0f1;
            padding: 12px 15px;
            text-align: left;
            font-size: 14px;
        }
        
        th {
            background-color: #f9fbfd;
            color: #2c3e50;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 12px;
            border-bottom: 2px solid #e1e8ed;
        }
        
        tr:hover td {
            background-color: #fcfdff;
        }
        
        .sex-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .sex-male {
            background-color: #d6eaf8;
            color: #2980b9;
        }
        
        .sex-female {
            background-color: #fce4ec;
            color: #e91e63;
        }
    </style>
</head>
<body>
    <div class="header-module">
        <a href="dashboard.php" class="btn-back">⬅️ Back to Dashboard</a>
    </div>

    <div class="container">
        <header>
            <div>
                <h1>👥 Patient Management</h1>
                <p>View, edit, and manage patient records</p>
            </div>
            <a href="add_patient.php" class="btn-primary">➕ Add New Patient</a>
        </header>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="search-form">
            <form method="GET" action="manage_patients.php" style="display: flex; gap: 10px; width: 100%;">
                <input type="text" name="search" placeholder="Search by name, DNI, or record number..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn-primary">🔍 Search</button>
            </form>
        </div>

        <div class="section-box">
            <h2 style="font-size: 16px; font-weight: 600; color: #34495e; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 16px;">
                Registered Patients (<?= count($patients) ?>)
            </h2>
            
            <?php if (!empty($patients)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Record #</th>
                        <th>Full Name</th>
                        <th>DNI</th>
                        <th>Birth Date / Age</th>
                        <th>Sex</th>
                        <th>Blood Type</th>
                        <th>Contact</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($patient['record_number']) ?></strong></td>
                            <td><?= htmlspecialchars($patient['name'] . ' ' . $patient['surname']) ?></td>
                            <td><?= htmlspecialchars($patient['DNI']) ?></td>
                            <td>
                                <?= htmlspecialchars(date('d/m/Y', strtotime($patient['birth_date']))) ?>
                                <br>
                                <small style="color: #7f8c8d;">(<?= calculateAge($patient['birth_date']) ?> years)</small>
                            </td>
                            <td>
                                <span class="sex-badge <?= $patient['sex'] === 'M' ? 'sex-male' : 'sex-female' ?>">
                                    <?= $patient['sex'] === 'M' ? '👨 Male' : '👩 Female' ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($patient['blood_type'] ?? 'N/A') ?></td>
                            <td>
                                <?= htmlspecialchars($patient['phone_number'] ?? 'N/A') ?>
                                <br>
                                <small style="color: #7f8c8d;"><?= htmlspecialchars($patient['email'] ?? 'N/A') ?></small>
                            </td>
                            <td style="white-space: nowrap;">
                                <a href="edit_patient.php?id=<?= $patient['patient_id'] ?>" class="btn-small btn-edit">Edit</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this patient?');">
                                    <input type="hidden" name="patient_id" value="<?= $patient['patient_id'] ?>">
                                    <button type="submit" name="delete_patient" class="btn-small btn-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="alert alert-error">No patients registered or found with the current search criteria.</div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

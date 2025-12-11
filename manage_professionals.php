<?php
require_once 'includes/auth.php';

if (!hasRole('admin')) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

$success = '';
$error = '';

// Delete professional
if (isset($_POST['delete_professional'])) {
    try {
        $professional_id = $_POST['professional_id'];
        
        // Check if professional has prescriptions
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM PRESCRIPTION WHERE professional_id = :id");
        $stmt->execute(['id' => $professional_id]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            $error = 'Cannot delete professional with existing prescriptions. Please archive instead.';
        } else {
            $stmt = $conn->prepare("DELETE FROM PROFESSIONAL WHERE professional_id = :id");
            $stmt->execute(['id' => $professional_id]);
            $success = 'Professional deleted successfully.';
        }
    } catch (Exception $e) {
        $error = 'Error deleting professional: ' . $e->getMessage();
    }
}

// Search and filter
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';
$search_clause = '';
$params = [];

$conditions = [];
if ($search) {
    $conditions[] = "(name LIKE :search OR surname LIKE :search OR DNI LIKE :search OR code LIKE :search)";
    $params['search'] = '%' . $search . '%';
}
if ($role_filter) {
    $conditions[] = "role = :role";
    $params['role'] = $role_filter;
}
if (!empty($conditions)) {
    $search_clause = " WHERE " . implode(' AND ', $conditions);
}

// Get all professionals
$stmt = $conn->prepare("
    SELECT 
        professional_id,
        code,
        DNI,
        name,
        surname,
        role,
        speciality,
        telephone,
        email,
        turn,
        entry_date
    FROM PROFESSIONAL
    $search_clause
    ORDER BY surname, name
");
$stmt->execute($params);
$professionals = $stmt->fetchAll();

// Calculate years of service
function calculateYearsOfService($entry_date) {
    if (!$entry_date) return 'N/A';
    $today = new DateTime();
    $entry = new DateTime($entry_date);
    return $today->diff($entry)->y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professional Management - Hospital les Corts</title>
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
        
        .search-form input[type="text"],
        .search-form select {
            padding: 10px;
            border: 1px solid #dce1e6;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .search-form input[type="text"] {
            flex-grow: 1;
        }
        
        .search-form select {
            min-width: 150px;
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
        
        .role-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .role-doctor {
            background-color: #d1f2eb;
            color: #16a085;
        }
        
        .role-nurse {
            background-color: #e9ecef;
            color: #34495e;
        }
        
        .role-admin {
            background-color: #fcf3cf;
            color: #f39c12;
        }
        
        .role-pharmacist {
            background-color: #c8e6c9;
            color: #2e7d32;
        }
        
        .turn-badge {
            padding: 3px 8px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .turn-morning {
            background-color: #fff3cd;
            color: #856404;
        }
        
        .turn-evening {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .turn-night {
            background-color: #d6d8db;
            color: #383d41;
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
                <h1>👨‍⚕️ Professional Management</h1>
                <p>View, edit, and manage professional records</p>
            </div>
            <a href="add_professional.php" class="btn-primary">➕ Add New Professional</a>
        </header>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <div class="search-form">
            <form method="GET" action="manage_professionals.php" style="display: flex; gap: 10px; width: 100%;">
                <input type="text" name="search" placeholder="Search by name, DNI, or code..." value="<?= htmlspecialchars($search) ?>">
                <select name="role">
                    <option value="">All Roles</option>
                    <option value="doctor" <?= $role_filter === 'doctor' ? 'selected' : '' ?>>Doctor</option>
                    <option value="nurse" <?= $role_filter === 'nurse' ? 'selected' : '' ?>>Nurse</option>
                    <option value="pharmacist" <?= $role_filter === 'pharmacist' ? 'selected' : '' ?>>Pharmacist</option>
                    <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admin</option>
                </select>
                <button type="submit" class="btn-primary">🔍 Search</button>
            </form>
        </div>

        <div class="section-box">
            <h2 style="font-size: 16px; font-weight: 600; color: #34495e; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 16px;">
                Registered Professionals (<?= count($professionals) ?>)
            </h2>
            
            <?php if (!empty($professionals)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Full Name</th>
                        <th>DNI</th>
                        <th>Role</th>
                        <th>Speciality</th>
                        <th>Turn</th>
                        <th>Contact</th>
                        <th>Entry Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($professionals as $prof): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($prof['code']) ?></strong></td>
                            <td><?= htmlspecialchars($prof['name'] . ' ' . $prof['surname']) ?></td>
                            <td><?= htmlspecialchars($prof['DNI']) ?></td>
                            <td>
                                <span class="role-badge role-<?= $prof['role'] ?>">
                                    <?= htmlspecialchars(ucfirst($prof['role'])) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($prof['speciality'] ?? 'N/A') ?></td>
                            <td>
                                <?php if ($prof['turn']): ?>
                                    <span class="turn-badge turn-<?= $prof['turn'] ?>">
                                        <?= htmlspecialchars(ucfirst($prof['turn'])) ?>
                                    </span>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($prof['telephone'] ?? 'N/A') ?>
                                <br>
                                <small style="color: #7f8c8d;"><?= htmlspecialchars($prof['email'] ?? 'N/A') ?></small>
                            </td>
                            <td>
                                <?= htmlspecialchars(date('d/m/Y', strtotime($prof['entry_date']))) ?>
                                <br>
                                <small style="color: #7f8c8d;">(<?= calculateYearsOfService($prof['entry_date']) ?> years)</small>
                            </td>
                            <td style="white-space: nowrap;">
                                <a href="edit_professional.php?id=<?= $prof['professional_id'] ?>" class="btn-small btn-edit">Edit</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this professional?');">
                                    <input type="hidden" name="professional_id" value="<?= $prof['professional_id'] ?>">
                                    <button type="submit" name="delete_professional" class="btn-small btn-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <div class="alert alert-error">No professionals registered or found with the current search criteria.</div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
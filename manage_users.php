<?php
// FORZAR UTF-8 desde el inicio
header('Content-Type: text/html; charset=UTF-8');

require_once 'includes/auth.php';

if (!hasRole('admin')) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// Asegurar UTF-8 en la conexión
$conn->exec("SET NAMES utf8mb4");

$success = '';
$error = '';

// Procesar acciones (activar/desactivar usuario)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $user_id = (int)$_POST['user_id'];
    
    if ($_POST['action'] === 'toggle_status') {
        try {
            $stmt = $conn->prepare("UPDATE users SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = :id");
            $stmt->execute(['id' => $user_id]);
            $success = 'User status updated successfully';
        } catch (PDOException $e) {
            $error = 'Error updating status: ' . $e->getMessage();
        }
    }
    
    if ($_POST['action'] === 'delete') {
        try {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute(['id' => $user_id]);
            $success = 'User deleted successfully';
        } catch (PDOException $e) {
            $error = 'Error deleting user: ' . $e->getMessage();
        }
    }
}

// Obtener todos los usuarios
$stmt = $conn->query("SELECT id, name, email, type_user, status, last_login, created_at FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Hospital les Corts</title>
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
        }
        
        .header {
            background: white;
            border-bottom: 1px solid #e1e8ed;
            padding: 0 32px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .header h1 {
            font-size: 18px;
            font-weight: 400;
            color: #2c3e50;
        }
        
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
        }
        
        .btn-back:hover {
            border-color: #95a5a6;
            color: #2c3e50;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        
        .page-header h2 {
            font-size: 24px;
            font-weight: 300;
            color: #2c3e50;
        }
        
        .btn-primary {
            background: #3498db;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            display: inline-block;
            font-size: 13px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-primary:hover {
            background: #2980b9;
        }
        
        .alert {
            padding: 14px 20px;
            border-radius: 4px;
            margin-bottom: 24px;
            font-size: 13px;
        }
        
        .alert-success {
            background: #d4edda;
            border-left: 3px solid #27ae60;
            color: #155724;
        }
        
        .alert-error {
            background: #fee;
            border-left: 3px solid #e74c3c;
            color: #c0392b;
        }
        
        .users-table {
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 4px;
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background: #f8f9fa;
        }
        
        th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid #e1e8ed;
        }
        
        td {
            padding: 16px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }
        
        tbody tr:hover {
            background: #f8f9fa;
        }
        
        .role-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .role-admin { background: #e3f2fd; color: #1976d2; }
        .role-doctor { background: #f3e5f5; color: #7b1fa2; }
        .role-nurse { background: #e8f5e9; color: #388e3c; }
        .role-patient { background: #fce4ec; color: #c2185b; }
        
        .status-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-edit {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .btn-edit:hover {
            background: #1976d2;
            color: white;
        }
        
        .btn-toggle {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .btn-toggle:hover {
            background: #f57c00;
            color: white;
        }
        
        .btn-delete {
            background: #ffebee;
            color: #c62828;
        }
        
        .btn-delete:hover {
            background: #c62828;
            color: white;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>User Management</h1>
        <a href="dashboard.php" class="btn-back">Back to Dashboard</a>
    </div>

    <div class="container">
        <?php if ($success): ?>
            <div class="alert alert-success">
                Success: <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                Error: <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <div class="page-header">
            <h2>System Users</h2>
            <a href="add_user.php" class="btn-primary">+ Add New User</a>
        </div>
        
        <div class="users-table">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= $user['id'] ?></td>
                            <td><?= htmlspecialchars($user['name']) ?></td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td>
                                <span class="role-badge role-<?= strtolower($user['type_user']) ?>">
                                    <?= ucfirst($user['type_user']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-badge status-<?= strtolower($user['status']) ?>">
                                    <?= ucfirst($user['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                echo $user['last_login'] 
                                    ? date('d/m/Y H:i', strtotime($user['last_login'])) 
                                    : 'Never'; 
                                ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="edit_user.php?id=<?= $user['id'] ?>" class="btn-action btn-edit">
                                        Edit
                                    </a>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <button type="submit" class="btn-action btn-toggle">
                                            <?= $user['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                    
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <form method="POST" style="display: inline;" 
                                              onsubmit="return confirm('Are you sure you want to delete this user?');">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="btn-action btn-delete">
                                                Delete
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
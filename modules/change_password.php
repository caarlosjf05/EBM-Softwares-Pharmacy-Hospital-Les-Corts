<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

requireLogin();
requireRole('patient');

$conn = getDBConnection();

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validaciones
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = 'All fields are required.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match.';
    } elseif (strlen($new_password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $new_password)) {
        $error = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $new_password)) {
        $error = 'Password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $new_password)) {
        $error = 'Password must contain at least one number.';
    } elseif (!preg_match('/[^A-Za-z0-9]/', $new_password)) {
        $error = 'Password must contain at least one special character (!@#$%^&*).';
    } else {
        // Verificar contraseña actual
        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE email = :email");
        $stmt->execute(['email' => $current_user_email]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($current_password, $user['password_hash'])) {
            // Actualizar contraseña
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $conn->prepare("UPDATE users SET password_hash = :password_hash WHERE email = :email");
            
            if ($update_stmt->execute(['password_hash' => $new_hash, 'email' => $current_user_email])) {
                $success = true;
            } else {
                $error = 'Failed to update password. Please try again.';
            }
        } else {
            $error = 'Current password is incorrect.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Hospital les Corts</title>
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
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .hospital-name {
            font-size: 14px;
            font-weight: 600;
            color: #2c3e50;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .btn {
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
        
        .btn:hover {
            border-color: #95a5a6;
            color: #2c3e50;
        }
        
        .btn-primary {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .btn-primary:hover {
            background: #5568d3;
            border-color: #5568d3;
        }
        
        .container {
            max-width: 600px;
            margin: 50px auto;
            padding: 0 20px;
        }
        
        .card {
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 8px;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .card-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 32px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e1e8ed;
        }
        
        .card-icon {
            font-size: 32px;
        }
        
        .card-title {
            font-size: 24px;
            font-weight: 600;
            color: #2c3e50;
        }
        
        .alert {
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #dce1e6;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .form-help {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 6px;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 32px;
        }
        
        .form-actions .btn {
            flex: 1;
        }
        
        .password-requirements {
            background: #f8f9fa;
            border: 1px solid #e1e8ed;
            border-radius: 6px;
            padding: 16px;
            margin-top: 24px;
        }
        
        .password-requirements h4 {
            font-size: 13px;
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 12px;
        }
        
        .password-requirements ul {
            list-style: none;
            padding: 0;
        }
        
        .password-requirements li {
            font-size: 12px;
            color: #7f8c8d;
            padding: 4px 0;
            padding-left: 20px;
            position: relative;
        }
        
        .password-requirements li:before {
            content: '✓';
            position: absolute;
            left: 0;
            color: #27ae60;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <div class="hospital-name">Hospital les Corts</div>
        </div>
        <div class="header-right">
            <a href="../dashboard.php" class="btn">← Back to Dashboard</a>
            <a href="../logout.php" class="btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <div class="card-header">
                <div class="card-icon">🔐</div>
                <div>
                    <div class="card-title">Change Password</div>
                    <div style="font-size: 13px; color: #7f8c8d; margin-top: 4px;">
                        Update your account security credentials
                    </div>
                </div>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <span style="font-size: 20px;">✓</span>
                    <div>
                        <strong>Password changed successfully!</strong><br>
                        Your password has been updated. You can now use it to log in.
                    </div>
                </div>
                <div class="form-actions">
                    <a href="../dashboard.php" class="btn btn-primary" style="text-align: center;">Return to Dashboard</a>
                </div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <span style="font-size: 20px;">⚠</span>
                        <div>
                            <strong>Error:</strong> <?= htmlspecialchars($error) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label class="form-label" for="current_password">Current Password</label>
                        <input 
                            type="password" 
                            id="current_password" 
                            name="current_password" 
                            class="form-input"
                            required
                            autocomplete="current-password"
                        >
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="new_password">New Password</label>
                        <input 
                            type="password" 
                            id="new_password" 
                            name="new_password" 
                            class="form-input"
                            required
                            autocomplete="new-password"
                            minlength="8"
                        >
                        <div class="form-help">Must be at least 8 characters long</div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            class="form-input"
                            required
                            autocomplete="new-password"
                            minlength="8"
                        >
                        <div class="form-help">Re-enter your new password</div>
                    </div>

                    <div class="password-requirements">
                        <h4>Password Requirements:</h4>
                        <ul>
                            <li>Minimum 8 characters long</li>
                            <li>At least one uppercase letter (A-Z)</li>
                            <li>At least one lowercase letter (a-z)</li>
                            <li>At least one number (0-9)</li>
                            <li>At least one special character (!@#$%^&*)</li>
                        </ul>
                    </div>

                    <div class="form-actions">
                        <a href="../dashboard.php" class="btn">Cancel</a>
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

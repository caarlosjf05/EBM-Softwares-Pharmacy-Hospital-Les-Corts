<?php
// FILE: index.php
session_start();
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital les Corts - Pharmacy System</title>
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
            display: flex;
        }
        
        /* --- LEFT PANEL --- */
        .left-panel {
            flex: 1;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 60px;
            color: white;
            overflow: hidden;
        }

        /* Fondo con imagen difuminada */
        .left-panel::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: 
                linear-gradient(135deg, rgba(44, 62, 80, 0.65) 0%, rgba(52, 152, 219, 0.65) 100%),
                url('https://images.unsplash.com/photo-1587854692152-cbe660dbde88?q=80&w=1470&auto=format&fit=crop') center/cover no-repeat;
            filter: blur(6px);
            transform: scale(1.05);
            z-index: 0;
        }

        .left-panel-content {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        /* --- LOGO EBM --- */
        .hospital-logo {
            width: 280px;
            height: 280px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: -20px;
            background: transparent;
            border-radius: 20px;
            padding: 10px;
        }
        
        .hospital-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,0.4));
        }

        /* --- TEXTOS --- */
        .left-panel h1 {
            font-size: 39px;
            font-weight: 300;
            margin-bottom: 6px;
            text-align: center;
            letter-spacing: 0.5px;
        }
        .left-panel h2 {
            font-size: 22px;
            font-weight: 400;
            margin-bottom: 12px;
            opacity: 0.95;
        }
        
        .developer-credit {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-weight: 600;
            opacity: 0.8;
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .developer-credit::before,
        .developer-credit::after {
            content: '';
            display: block;
            width: 30px;
            height: 1px;
            background: rgba(255,255,255,0.6);
        }

        .left-panel p {
            font-size: 15px;
            line-height: 1.8;
            opacity: 0.9;
            text-align: center;
            max-width: 450px;
            font-weight: 400;
        }

        /* --- RIGHT PANEL --- */
        .right-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px;
            background: white;
        }
        .login-container { max-width: 420px; width: 100%; }
        .login-header { margin-bottom: 40px; }
        .login-header h3 { font-size: 24px; font-weight: 400; color: #2c3e50; margin-bottom: 8px; }
        .login-header p { font-size: 13px; color: #7f8c8d; }
        
        .error { background: #fee; border-left: 3px solid #e74c3c; color: #c0392b; padding: 12px 16px; border-radius: 4px; margin-bottom: 24px; font-size: 13px; }
        
        .form-group { margin-bottom: 24px; }
        label { display: block; margin-bottom: 8px; color: #2c3e50; font-weight: 500; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        input[type="email"], input[type="password"] { width: 100%; padding: 12px 16px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; transition: border-color 0.2s; background: #fafbfc; }
        input:focus { outline: none; border-color: #3498db; background: white; }
        
        .consent-wrapper { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 24px; padding: 15px; background: #f8f9fa; border: 1px solid #e1e8ed; border-radius: 4px; }
        .consent-wrapper input[type="checkbox"] { margin-top: 4px; width: 16px; height: 16px; cursor: pointer; }
        .consent-text { font-size: 12px; color: #555; line-height: 1.5; }
        .consent-text a { color: #3498db; text-decoration: none; font-weight: 600; }

        button { width: 100%; padding: 14px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background 0.2s; text-transform: uppercase; letter-spacing: 0.5px; }
        button:hover { background: #2980b9; }
        
        .footer-text { text-align: center; color: #95a5a6; font-size: 11px; margin-top: 40px; text-transform: uppercase; letter-spacing: 0.5px; }
        
        @media (max-width: 768px) {
            body { flex-direction: column; }
            .left-panel { padding: 60px 20px; min-height: 300px;}
            .right-panel { padding: 40px 20px; }
            .hospital-logo { width: 200px; height: 200px; }
        }
    </style>
</head>
<body>
    <div class="left-panel">
        <div class="left-panel-content">
            <div class="hospital-logo">
                <img src="logo-ebm1.png" alt="EBM Softwares Logo">
            </div>
            <h1>Hospital Les Corts</h1>
            <h2>Pharmacy System</h2>
            
            <div class="developer-credit">
                Developed by EBM Softwares
            </div>

            <p>Integrated pharmaceutical management system for healthcare professionals and patients. Secure prescription processing, inventory control, and patient medication tracking.</p>
        </div>
    </div>

    <div class="right-panel">
        <div class="login-container">
            <div class="login-header">
                <h3>System Access</h3>
                <p>Enter your credentials to access the pharmacy platform</p>
            </div>

            <?php if (isset($_GET['error'])): ?>
                <div class="error">
                    <?php
                        switch($_GET['error']) {
                            case 'invalid': echo 'Invalid credentials. Please verify your email and password.'; break;
                            case 'empty': echo 'All fields are required.'; break;
                            case 'inactive': echo 'Account is inactive. Contact system administrator.'; break;
                            default: echo 'Authentication error. Please try again.'; break;
                        }
                    ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required autofocus>
                </div>

                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required>
                </div>

                <div class="consent-wrapper">
                    <input type="checkbox" name="terms_accepted" id="terms" required>
                    <label for="terms" class="consent-text">
                        I acknowledge and accept the <a href="#" onclick="alert('Terms of Use:\n\n1. Data Protection: Compliance with GDPR/LOPD.\n2. Access: Only authorized personnel.\n3. Auditing: All actions are logged.'); return false;">Terms of Use</a> and allow the processing of medical data for hospital purposes.
                    </label>
                </div>

                <button type="submit">Sign In</button>
            </form>

            <div class="footer-text">
                © 2025 Hospital les Corts. All rights reserved.
            </div>
        </div>
    </div>
</body>
</html>
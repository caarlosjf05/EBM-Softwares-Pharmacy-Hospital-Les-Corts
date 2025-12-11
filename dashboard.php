<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$conn = getDBConnection();
$stats = getPrescriptionStats(); 

$user_info = null;
$display_name = $current_user_email;

// Get User Info
if (hasRole('patient') or hasRole('admin') or hasRole('pharmacist') or hasRole('doctor') or hasRole('nurse')) {
    $stmt = $conn->prepare("SELECT name, surname FROM PROFESSIONAL WHERE email = :email");
    $stmt->execute(['email' => $current_user_email]);
    $prof = $stmt->fetch();
    if ($prof) $display_name = $prof['name'] . ' ' . $prof['surname'];
} elseif (hasRole('patient')) {
    $stmt = $conn->prepare("SELECT name, surname FROM PATIENT WHERE email = :email");
    $stmt->execute(['email' => $current_user_email]);
    $patient = $stmt->fetch();
    if ($patient) $display_name = $patient['name'] . ' ' . $patient['surname'];
}

// Helper for grid alignment
function inject_empty_cells($item_count, $cols = 3) {
    $remainder = $item_count % $cols;
    if ($remainder !== 0) {
        $needed = $cols - $remainder;
        for ($i = 0; $i < $needed; $i++) {
            echo '<a href="#" class="function-item" style="visibility: hidden; cursor: default;"></a>';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Hospital les Corts</title>
    <style>
        /* CORE STYLES */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f5f7fa; min-height: 100vh; }
        
        /* HEADER */
        .header { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; }
        .header-left { display: flex; align-items: center; gap: 16px; }
        .hospital-name { font-size: 14px; font-weight: 600; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; }
        .department { font-size: 14px; color: #7f8c8d; padding-left: 16px; border-left: 1px solid #e1e8ed; }
        .header-right { display: flex; align-items: center; gap: 24px; }
        .user-info { text-align: right; }
        .user-name { font-size: 13px; font-weight: 500; color: #2c3e50; }
        .user-role { font-size: 11px; color: #7f8c8d; text-transform: uppercase; letter-spacing: 0.5px; }
        .logout-btn { background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-size: 12px; font-weight: 500; text-decoration: none; display: inline-block; transition: all 0.2s; text-transform: uppercase; letter-spacing: 0.5px; }
        .logout-btn:hover { border-color: #95a5a6; color: #2c3e50; }
        
        /* LAYOUT */
        .container { max-width: 1400px; margin: 0 auto; padding: 32px; }
        
        /* STATS CARDS */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
        .stat-card { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 20px; }
        .stat-label { color: #7f8c8d; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-number { font-size: 28px; font-weight: 300; color: #2c3e50; }
        .stat-card.warning .stat-number { color: #e67e22; }
        .stat-card.critical .stat-number { color: #e74c3c; }
        
        .section-title { font-size: 13px; font-weight: 600; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; margin: 40px 0 16px 0; }
        
        /* MODULE GRID */
        .function-list { background: white; border: 1px solid #e1e8ed; border-radius: 4px; overflow: hidden; display: grid; grid-template-columns: repeat(3, 1fr); border-bottom: none; border-right: none; }
        .function-item { display: flex; align-items: center; padding: 16px 20px; text-decoration: none; color: #2c3e50; border-bottom: 1px solid #e1e8ed; border-right: 1px solid #e1e8ed; transition: background-color 0.15s; }
        .function-item:hover { background-color: #fcfdff; }
        .function-list .function-item:nth-child(3n) { border-right: none; }
        
        /* ICONS & BADGES */
        .function-icon { font-size: 28px; margin-right: 16px; padding: 10px; border-radius: 6px; }
        .function-icon.doctor { background-color: #e8f8f5; color: #16a085; }
        .badge-doctor { background-color: #d1f2eb; color: #16a085; }
        .function-icon.nurse { background-color: #f2f3f5; color: #636e72; }
        .badge-nurse { background-color: #dfe6e9; color: #636e72; }
        .function-icon.pharmacist { background-color: #e8f5e9; color: #2e7d32; }
        .badge-pharmacist { background-color: #c8e6c9; color: #2e7d32; }
        .function-icon.admin { background-color: #fef9e7; color: #f39c12; }
        .badge-admin { background-color: #fcf3cf; color: #f39c12; }
        .function-icon.all-roles { background-color: #ebf5fb; color: #2980b9; }
        .badge-all { background-color: #d6eaf8; color: #2980b9; }
        .function-icon.patient { background-color: #fdeef4; color: #e91e63; }
        .badge-patient { background-color: #fce4ec; color: #e91e63; }
        
        .function-content { flex-grow: 1; }
        .function-name { font-weight: 600; font-size: 14px; }
        .function-description { font-size: 12px; color: #7f8c8d; margin-top: 4px; }
        .function-badge { font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 4px 8px; border-radius: 12px; white-space: nowrap; }

        /* CHAT WIDGET STYLES */
        .chat-widget-container {
            position: fixed; bottom: 30px; right: 30px; z-index: 9999;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .chat-button {
            width: 60px; height: 60px; border-radius: 50%;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white; border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 28px; transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .chat-button:hover { transform: scale(1.1); }
        .chat-window {
            position: absolute; bottom: 80px; right: 0;
            width: 350px; height: 500px;
            background: white; border-radius: 12px;
            box-shadow: 0 5px 30px rgba(0,0,0,0.2);
            border: 1px solid #e1e8ed;
            display: none; flex-direction: column; overflow: hidden;
            animation: slideUp 0.3s ease-out;
        }
        .chat-window.active { display: flex; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .chat-header { background: #3498db; color: white; padding: 15px; display: flex; justify-content: space-between; align-items: center; font-weight: 600; }
        .chat-body { flex: 1; padding: 15px; overflow-y: auto; background: #f9fbfd; display: flex; flex-direction: column; gap: 10px; }
        .message { max-width: 85%; padding: 10px 14px; border-radius: 10px; font-size: 13px; line-height: 1.4; }
        .bot-message { background: white; border: 1px solid #e1e8ed; color: #2c3e50; align-self: flex-start; border-bottom-left-radius: 2px; }
        .user-message { background: #3498db; color: white; align-self: flex-end; border-bottom-right-radius: 2px; }
        .chat-footer { padding: 10px; background: white; border-top: 1px solid #e1e8ed; display: flex; gap: 10px; }
        .chat-input { flex: 1; padding: 10px; border: 1px solid #dce1e6; border-radius: 20px; outline: none; font-size: 13px; }
        .send-btn { background: #3498db; color: white; border: none; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .quick-action { display: inline-block; margin-top: 5px; padding: 4px 10px; background: #e3f2fd; color: #1976d2; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 11px; border: 1px solid #bbdefb; }
        .quick-action:hover { background: #bbdefb; }
        
        .support-box {
            background: #fff3e0; border: 1px solid #ffe0b2; color: #e65100;
            padding: 10px; border-radius: 6px; font-size: 12px; margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <div class="hospital-name">Hospital les Corts</div>
            <div class="department">Pharmacy</div>
        </div>
        <div class="header-right">
            <div class="user-info">
                <div class="user-name"><?= htmlspecialchars($display_name) ?></div>
                <div class="user-role"><?= htmlspecialchars(strtoupper($current_user_role)) ?></div>
            </div>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <h1>Main Dashboard</h1>
        
        <?php if (hasRole('nurse') or hasRole('pharmacist') or hasRole('doctor')): ?>
        <div class="section-title">KEY INDICATORS</div>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Prescriptions</div>
                <div class="stat-number"><?= number_format($stats['total_prescriptions'] ?? 0) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Dispensations</div>
                <div class="stat-number"><?= number_format($stats['total_dispensations'] ?? 0) ?></div>
            </div>
            <div class="stat-card <?= ($stats['low_stock_count'] ?? 0) > 0 ? 'warning' : '' ?>">
                <div class="stat-label">Low Stock Medications</div>
                <div class="stat-number"><?= number_format($stats['low_stock_count'] ?? 0) ?></div>
            </div>
            <div class="stat-card <?= ($stats['no_stock_count'] ?? 0) > 0 ? 'critical' : '' ?>">
                <div class="stat-label">Out of Stock Medications</div>
                <div class="stat-number"><?= number_format($stats['no_stock_count'] ?? 0) ?></div>
            </div>
        </div>
        <hr>
        <?php endif; ?>

        <?php if (hasRole('doctor')): ?>
        <div class="section-title">MEDICAL OPERATIONS</div>
        <div class="function-list">
            <a href="prescribe.php" class="function-item">
                <div class="function-icon doctor">✏️</div>
                <div class="function-content">
                    <div class="function-name">New Medical Prescription</div>
                    <div class="function-description">Register a new (electronic) treatment for a patient.</div>
                </div>
                <span class="function-badge badge-doctor">Doctor</span>
            </a>
            <?php inject_empty_cells(1); ?>
        </div>
        <hr>
        <?php endif; ?>


        <?php if (hasRole('nurse')): ?>
        <div class="section-title">NURSING OPERATIONS</div>
        <div class="function-list">
            <a href="administer.php" class="function-item">
                <div class="function-icon nurse">💉</div>
                <div class="function-content">
                    <div class="function-name">Medication Administration</div>
                    <div class="function-description">Record the final administration of validated prescriptions.</div>
                </div>
                <span class="function-badge badge-nurse">Nurse</span>
            </a>

            <a href="modules/immunization_services.php" class="function-item">
                <div class="function-icon nurse">💉</div>
                <div class="function-content">
                    <div class="function-name">Immunization Services</div>
                    <div class="function-description">Record administered vaccines and generate vaccination cards.</div>
                </div>
                <span class="function-badge badge-nurse">Nurse</span>
            </a>

            <a href="history.php" class="function-item">
                <div class="function-icon nurse">📋</div>
                <div class="function-content">
                    <div class="function-name">Movement History Logs</div>
                    <div class="function-description">View detailed logs of all dispensations and prescriptions.</div>
                </div>
                <span class="function-badge badge-nurse">Nurse</span>
            </a>

            <?php inject_empty_cells(3); ?>
        </div>
        <hr>
        <?php endif; ?>


        <?php if (hasRole('pharmacist')): ?>
        <div class="section-title">PHARMACY OPERATIONS</div>
        <div class="function-list">
            <?php $pharm_count = 0; ?>
            
            <a href="modules/validation_dispensation.php" class="function-item">
                <div class="function-icon pharmacist">📦</div>
                <div class="function-content">
                    <div class="function-name">Validation & Dispensation</div>
                    <div class="function-description">Validate prescriptions, dispense medications, and manage stock outflow.</div>
                </div>
                <span class="function-badge badge-pharmacist">Pharmacist</span>
            </a>
            <?php $pharm_count++; ?>

            <a href="modules/temperature_control.php" class="function-item">
                <div class="function-icon pharmacist">🌡️</div>
                <div class="function-content">
                    <div class="function-name">Temperature Control</div>
                    <div class="function-description">Log readings for cold chain and manage alerts.</div>
                </div>
                <span class="function-badge badge-pharmacist">Pharmacist</span>
            </a>
            <?php $pharm_count++; ?>

            <a href="modules/lot_tracking.php" class="function-item">
                <div class="function-icon pharmacist">🎫</div>
                <div class="function-content">
                    <div class="function-name">Lot Traceability & Recalls</div>
                    <div class="function-description">Track batches and quarantine recalled drugs.</div>
                </div>
                <span class="function-badge badge-pharmacist">Pharmacist</span>
            </a>
            <?php $pharm_count++; ?>

            <a href="modules/manage_storages.php" class="function-item">
                <div class="function-icon pharmacist">🏬</div>
                <div class="function-content">
                    <div class="function-name">Storage Location Management</div>
                    <div class="function-description">View and edit storage locations and temperature ranges.</div>
                </div>
                <span class="function-badge badge-pharmacist">Pharmacist</span>
            </a>
            <?php $pharm_count++; ?>
            
            <a href="modules/stock_alerts.php" class="function-item">
                <div class="function-icon pharmacist">📉</div>
                <div class="function-content">
                    <div class="function-name">Stock & Order Alerts</div>
                    <div class="function-description">View low stock items and purchasing suggestions.</div>
                </div>
                <span class="function-badge badge-pharmacist">Pharmacist</span>
            </a>
            <?php $pharm_count++; ?>

            <a href="modules/expiration_management.php" class="function-item">
                <div class="function-icon pharmacist">🗓️</div>
                <div class="function-content">
                    <div class="function-name">Expiration Date Management</div>
                    <div class="function-description">Monitor soon-to-expire batches and apply the FEFO methodology.</div>
                </div>
                <span class="function-badge badge-pharmacist">Pharmacist</span>
            </a>
            <?php $pharm_count++; ?>

            <a href="modules/prior_authorization.php" class="function-item">
                <div class="function-icon pharmacist">📄</div>
                <div class="function-content">
                    <div class="function-name">Prior Authorization Tracking</div>
                    <div class="function-description">Manage insurance approvals and status requests.</div>
                </div>
                <span class="function-badge badge-pharmacist">Pharmacist</span>
            </a>
            <?php $pharm_count++; ?>

            <a href="history.php" class="function-item">
                <div class="function-icon pharmacist">📋</div>
                <div class="function-content">
                    <div class="function-name">Movement History Logs</div>
                    <div class="function-description">View detailed logs of all dispensations and prescriptions.</div>
                </div>
                <span class="function-badge badge-pharmacist">Pharmacist</span>
            </a>
            <?php $pharm_count++; ?>
            
            <?php inject_empty_cells($pharm_count); ?>
        </div>
        <hr>
        <?php endif; ?>

        <?php if (hasAnyRole(['doctor', 'nurse', 'pharmacist'])): ?>
        <div class="section-title">CLINICAL & PATIENT DATA (SHARED)</div>
        <div class="function-list">
            <a href="edit_patient_meds.php" class="function-item">
                <div class="function-icon all-roles">🔍</div>
                <div class="function-content">
                    <div class="function-name">Review Patient History</div>
                    <div class="function-description">Consult patient's active prescriptions and history.</div>
                </div>
                <span class="function-badge badge-all">Clinical Staff</span>
            </a>

            <a href="modules/verify_allergies.php" class="function-item">
                <div class="function-icon all-roles">🤧</div>
                <div class="function-content">
                    <div class="function-name">Allergy Verification</div>
                    <div class="function-description">Check prescriptions for allergy conflicts.</div>
                </div>
                <span class="function-badge badge-all">Clinical Staff</span>
            </a>

            <a href="modules/drug_interactions.php" class="function-item">
                <div class="function-icon all-roles">👤</div>
                <div class="function-content">
                    <div class="function-name">Interaction Check (By Patient)</div>
                    <div class="function-description">Scan for conflicts in patient's active history.</div>
                </div>
                <span class="function-badge badge-all">Clinical Staff</span>
            </a>
            
            <a href="modules/drug_interaction_search.php" class="function-item">
                <div class="function-icon all-roles">🔍</div>
                <div class="function-content">
                    <div class="function-name">Interaction Check (Search)</div>
                    <div class="function-description">Manually search for interactions between two drugs.</div>
                </div>
                <span class="function-badge badge-all">Clinical Staff</span>
            </a>
            
            <a href="modules/clinical_decision_support.php" class="function-item">
                <div class="function-icon doctor">🧠</div>
                <div class="function-content">
                    <div class="function-name">Clinical Decision Support</div>
                    <div class="function-description">View active alerts, patient risks, and real-time interactions.</div>
                </div>
                <span class="function-badge badge-all">Clinical Staff</span>
            </a>

            <?php inject_empty_cells(5); ?>
        </div>
        <hr>
        <?php endif; ?>


        <?php if (hasRole('admin')): ?>
        <div class="section-title">MANAGEMENT OPERATIONS</div>
        <div class="function-list">
            <?php $mgmt_count = 0; ?>
            <a href="manage_patients.php" class="function-item">
                <div class="function-icon admin">👥</div>
                <div class="function-content">
                    <div class="function-name">Patient Management</div>
                    <div class="function-description">View, edit, and add patient records</div>
                </div>
                <span class="function-badge badge-admin">Admin</span>
            </a>
            <?php $mgmt_count++; ?>

            <a href="manage_professionals.php" class="function-item">
                <div class="function-icon admin">👨‍⚕️</div>
                <div class="function-content">
                    <div class="function-name">Professional Management</div>
                    <div class="function-description">View, edit, and add professional records</div>
                </div>
                <span class="function-badge badge-admin">Admin</span>
            </a>
            <?php $mgmt_count++; ?>
            
            <a href="edit_general_meds.php" class="function-item">
                <div class="function-icon admin">⚙️</div>
                <div class="function-content">
                    <div class="function-name">General Inventory Management</div>
                    <div class="function-description">Manage drug catalog and perform quick stock adjustments.</div>
                </div>
                <span class="function-badge badge-admin">Admin</span>
            </a>
            <?php $mgmt_count++; ?>

            <a href="modules/manage_storages.php" class="function-item">
                <div class="function-icon admin">📦</div>
                <div class="function-content">
                    <div class="function-name">Storage / Location Management</div>
                    <div class="function-description">Manage physical stock points.</div>
                </div>
                <span class="function-badge badge-admin">Admin</span>
            </a>
            <?php $mgmt_count++; ?>

            <a href="modules/expiration_management.php" class="function-item">
                <div class="function-icon admin">🗓️</div>
                <div class="function-content">
                    <div class="function-name">Expiration Date Management</div>
                    <div class="function-description">Monitor and edit stock expiry dates (Admin View).</div>
                </div>
                <span class="function-badge badge-admin">Admin</span>
            </a>
            <?php $mgmt_count++; ?>
            
            <?php inject_empty_cells($mgmt_count); ?>
        </div>
        <hr>
        <?php endif; ?>


        <?php if (hasRole('admin')): ?>
        <div class="section-title">ANALYSIS & LOGISTICS</div>
        <div class="function-list">
            <?php $logistics_count = 0; ?>
            
            <a href="modules/reports.php" class="function-item">
                <div class="function-icon admin">📈</div>
                <div class="function-content">
                    <div class="function-name">System Reports & Analytics</div>
                    <div class="function-description">Generate reports on prescriptions and usage.</div>
                </div>
                <span class="function-badge badge-admin">Admin</span>
            </a>
            <?php $logistics_count++; ?>

            <a href="modules/stock_alerts.php" class="function-item">
                <div class="function-icon admin">📉</div>
                <div class="function-content">
                    <div class="function-name">Stock & Order Alerts</div>
                    <div class="function-description">View low stock items and purchasing suggestions.</div>
                </div>
                <span class="function-badge badge-admin">Admin</span>
            </a>
            <?php $logistics_count++; ?>

            <a href="modules/lot_tracking.php" class="function-item">
                <div class="function-icon admin">🎫</div>
                <div class="function-content">
                    <div class="function-name">Lot Traceability & Recalls</div>
                    <div class="function-description">Track batches and quarantine recalled drugs.</div>
                </div>
                <span class="function-badge badge-admin">Admin</span>
            </a>
            <?php $logistics_count++; ?>
            
            <a href="modules/cost_analysis.php" class="function-item">
                <div class="function-icon admin">💰</div>
                <div class="function-content">
                    <div class="function-name">Cost Analysis & Substitution</div>
                    <div class="function-description">Analyze spending and generic substitution.</div>
                </div>
                <span class="function-badge badge-admin">Admin</span>
            </a>
            <?php $logistics_count++; ?>

            <a href="modules/waste_tracking.php" class="function-item">
                <div class="function-icon admin">🗑️</div>
                <div class="function-content">
                    <div class="function-name">Waste Tracking</div>
                    <div class="function-description">Log discarded medications and monitor cost.</div>
                </div>
                <span class="function-badge badge-admin">Admin</span>
            </a>
            <?php $logistics_count++; ?>
            
            <?php inject_empty_cells($logistics_count); ?>
        </div>
        <hr>
        <?php endif; ?>


        <?php if (hasRole('patient')): ?>
        <div class="section-title">MY MEDICAL INFORMATION</div>
        <div class="function-list">
            <a href="patient_dashboard.php" class="function-item">
                <div class="function-icon patient">📋</div>
                <div class="function-content">
                    <div class="function-name">My Medical Record</div>
                    <div class="function-description">View my personal information and medical data.</div>
                </div>
                <span class="function-badge badge-patient">Patient</span>
            </a>

            <a href="my_prescriptions.php" class="function-item">
                <div class="function-icon patient">💊</div>
                <div class="function-content">
                    <div class="function-name">My Prescriptions</div>
                    <div class="function-description">View my active prescriptions and their status.</div>
                </div>
                <span class="function-badge badge-patient">Patient</span>
            </a>
            <?php inject_empty_cells(2); ?>
        </div>
        <hr>

        <div class="section-title">SETTINGS</div>
        <div class="function-list">
            <a href="modules/change_password.php" class="function-item">
                <div class="function-icon patient">🔐</div>
                <div class="function-content">
                    <div class="function-name">Change Password</div>
                    <div class="function-description">Update your account security credentials.</div>
                </div>
                <span class="function-badge badge-patient">Patient</span>
            </a>
    
            <?php inject_empty_cells(1); ?>
        </div>
        <?php endif; ?>

    </div>

    <div class="chat-widget-container">
        <div class="chat-window" id="chatWindow">
            <div class="chat-header">
                <span>🤖 Hospital AI Assistant</span>
                <span style="cursor:pointer; font-size:18px;" onclick="toggleChat()">×</span>
            </div>
            
            <div class="chat-body" id="chatBody">
                <div class="support-box">
                    <strong>🆘 URGENT SUPPORT:</strong><br>
                    📞 +34 934 012 345<br>
                    📧 support@hospitalcort.com
                    <div style="font-size:10px; color:#e67e22; margin-top:5px; font-style:italic;">
                        ⚠️ Beta: This AI assistant is still in development phase.
                    </div>
                </div>

                <div class="message bot-message">
                    Hi <?= htmlspecialchars(explode(' ', $display_name)[0]) ?>! I'm your assistant.<br>
                    I know you are a <strong><?= strtoupper($current_user_role) ?></strong>.
                    <br><br>
                    How can I help you today?
                </div>
            </div>
            
            <div class="chat-footer">
                <input type="text" id="chatInput" class="chat-input" placeholder="Ask: 'stock', 'prescribe'..." onkeypress="handleEnter(event)">
                <button class="send-btn" onclick="sendMessage()">➤</button>
            </div>
        </div>

        <button class="chat-button" onclick="toggleChat()">💬</button>
    </div>

    <script>
        const currentUserRole = "<?= $current_user_role ?>";

        function toggleChat() {
            const chat = document.getElementById('chatWindow');
            chat.classList.toggle('active');
            if(chat.classList.contains('active')) {
                document.getElementById('chatInput').focus();
            }
        }

        function handleEnter(e) {
            if (e.key === 'Enter') sendMessage();
        }

        function sendMessage() {
            const input = document.getElementById('chatInput');
            const message = input.value.trim();
            if (!message) return;

            addMessage(message, 'user');
            input.value = '';

            setTimeout(() => {
                const botResponse = getSmartResponse(message.toLowerCase());
                addMessage(botResponse, 'bot');
            }, 500);
        }

        function addMessage(html, sender) {
            const body = document.getElementById('chatBody');
            const div = document.createElement('div');
            div.className = `message ${sender}-message`;
            div.innerHTML = html;
            body.appendChild(div);
            body.scrollTop = body.scrollHeight;
        }

        function has(text, keywords) {
            return keywords.some(k => text.includes(k));
        }

        function getSmartResponse(text) {
            // 1. SUPPORT / HELP
            if (has(text, ['help', 'support', 'error', 'bug', 'problem', 'contact'])) {
                return `For technical assistance:<br>📞 <b>+34 934 012 345</b><br>📧 support@hospitalcort.com`;
            }

            // 2. STOCK / INVENTORY (Restricted)
            if (has(text, ['stock', 'inventory', 'suppl', 'quantity', 'count'])) {
                if (['pharmacist', 'admin'].includes(currentUserRole)) {
                    return `Manage inventory here:<br><a href="modules/stock_alerts.php" class="quick-action">Stock Alerts</a>`;
                }
                return `🚫 <b>Access Denied:</b> Inventory is restricted to Pharmacy/Admin.`;
            }

            // 3. PRESCRIBE (Doctor Only)
            if (has(text, ['prescri', 'rx', 'drug', 'treat', 'receta'])) {
                if (['doctor'].includes(currentUserRole)) {
                    return `Create prescription:<br><a href="prescribe.php" class="quick-action">New Prescription</a>`;
                }
                return `🚫 <b>Access Denied:</b> Only Doctors prescribe.`;
            }

            // 4. ADMINISTER (Nurse Only)
            if (has(text, ['administer', 'give', 'nurs', 'dose', 'patient care'])) {
                if (['nurse'].includes(currentUserRole)) {
                    return `Record administration:<br><a href="administer.php" class="quick-action">Admin Panel</a>`;
                }
                return `🚫 <b>Access Denied.</b>`;
            }

            // 5. VACCINES
            if (has(text, ['vaccin', 'immuni', 'shot', 'inject'])) {
                if (['nurse', 'admin'].includes(currentUserRole)) {
                    return `Manage vaccinations:<br><a href="modules/immunization_services.php" class="quick-action">Immunization</a>`;
                }
                return `🚫 <b>Access Denied.</b>`;
            }

            // 6. VALIDATION / DISPENSING
            if (has(text, ['validat', 'dispens', 'queue', 'verify'])) {
                if (['pharmacist'].includes(currentUserRole)) {
                    return `Pharmacy Validation Queue:<br><a href="modules/validation_dispensation.php" class="quick-action">Validation</a>`;
                }
                return `🚫 <b>Access Denied:</b> Pharmacists Only.`;
            }

            // 7. PATIENT HISTORY
            if (has(text, ['patient', 'history', 'record', 'search', 'file'])) {
                if (['doctor', 'nurse', 'pharmacist', 'admin'].includes(currentUserRole)) {
                    return `Search patient records:<br><a href="edit_patient_meds.php" class="quick-action">Patient Search</a>`;
                }
                return `View your own history in "My Medical Record".`;
            }

            // 8. ALLERGIES / SAFETY
            if (has(text, ['allerg', 'react', 'safe', 'check'])) {
                return `Check allergies here:<br><a href="modules/verify_allergies.php" class="quick-action">Verify Allergies</a>`;
            }

            // 9. INTERACTIONS
            if (has(text, ['interact', 'conflict', 'contra'])) {
                return `Check drug interactions:<br><a href="modules/drug_interaction_search.php" class="quick-action">Search Interactions</a>`;
            }

            // 10. TEMPERATURE
            if (has(text, ['temp', 'fridge', 'cold', 'sensor'])) {
                if (['pharmacist', 'admin'].includes(currentUserRole)) {
                    return `Monitor Cold Chain:<br><a href="modules/temperature_control.php" class="quick-action">Temp Control</a>`;
                }
                return `🚫 <b>Access Denied.</b>`;
            }

            // 11. LOTS / RECALLS
            if (has(text, ['lot', 'batch', 'recall', 'trace', 'track'])) {
                if (['pharmacist', 'admin'].includes(currentUserRole)) {
                    return `Manage Lots & Recalls:<br><a href="modules/lot_tracking.php" class="quick-action">Lot Tracking</a>`;
                }
                return `🚫 <b>Access Denied.</b>`;
            }

            // 12. EXPIRATION / FEFO
            if (has(text, ['expir', 'date', 'fefo', 'old'])) {
                if (['pharmacist', 'admin'].includes(currentUserRole)) {
                    return `Manage Expiration Dates:<br><a href="modules/expiration_management.php" class="quick-action">Expiration Mgmt</a>`;
                }
                return `🚫 <b>Access Denied.</b>`;
            }

            // 13. REPORTS / ANALYSIS
            if (has(text, ['report', 'analy', 'graph', 'stat'])) {
                if (['admin'].includes(currentUserRole)) {
                    return `View Analytics:<br><a href="modules/reports.php" class="quick-action">Reports</a>`;
                }
                return `🚫 <b>Access Denied:</b> Admin Only.`;
            }

            // 14. WASTE
            if (has(text, ['waste', 'trash', 'discard', 'loss'])) {
                if (['admin'].includes(currentUserRole)) {
                    return `Log Waste:<br><a href="modules/waste_tracking.php" class="quick-action">Waste Tracking</a>`;
                }
                return `🚫 <b>Access Denied.</b>`;
            }

            // 15. COST
            if (has(text, ['cost', 'money', 'price', 'financ', 'spend'])) {
                if (['admin'].includes(currentUserRole)) {
                    return `Cost Analysis:<br><a href="modules/cost_analysis.php" class="quick-action">Cost Analysis</a>`;
                }
                return `🚫 <b>Access Denied.</b>`;
            }

            // 16. PRIOR AUTH
            if (has(text, ['prior', 'auth', 'insur', 'approv'])) {
                if (['pharmacist', 'admin'].includes(currentUserRole)) {
                    return `Manage Authorizations:<br><a href="modules/prior_authorization.php" class="quick-action">Prior Auth</a>`;
                }
                return `🚫 <b>Access Denied.</b>`;
            }

            if (has(text, ['hello', 'hi', 'hola', 'hey'])) {
                return `Hello! How can I assist you?`;
            }

            return `I didn't understand. Try "Stock", "Prescribe", "Recalls" or "Support".`;
        }
    </script>
</body>
</html>
<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();
requireRole('patient');

$conn = getDBConnection();

// Get patient_id of current user
$stmt = $conn->prepare("SELECT patient_id FROM PATIENT WHERE email = :email");
$stmt->execute(['email' => $current_user_email]);
$patient_data = $stmt->fetch();

if (!$patient_data) {
    die("Patient information not found.");
}

$patient_id = $patient_data['patient_id'];

// Search filter
$search = $_GET['search'] ?? '';

// Main query
// Note: SUM(d.quantity) calculation removed from query as it won't be used.
$query = "
    SELECT 
        pr.prescription_id,
        pr.date,
        pr.indications,
        pi.prescription_item_id,
        pi.dose,
        pi.frequency,
        pi.duration,
        m.comercial_name AS medication_name,
        m.active_principle,
        m.presentation,
        doc.name AS doctor_name,
        doc.surname AS doctor_surname,
        doc.speciality
    FROM PRESCRIPTION pr
    JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
    JOIN DRUGS m ON pi.drug_id = m.drug_id
    LEFT JOIN PROFESSIONAL doc ON pr.professional_id = doc.professional_id
    WHERE pr.patient_id = :patient_id
";

$params = ['patient_id' => $patient_id];

// Apply search by medication or active ingredient
if (!empty($search)) {
    $query .= " AND (m.comercial_name LIKE :search OR m.active_principle LIKE :search)"; 
    $params['search'] = "%$search%";
}

// Group by each prescription item and order
$query .= " GROUP BY pi.prescription_item_id ORDER BY pr.date DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$prescriptions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Prescriptions - Hospital les Corts</title>
    <style>
        /* Professional and responsive CSS styles */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f4f7f9;
            margin: 0;
            padding: 0;
            color: #333;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 30px;
            background-color: #ffffff;
            border-bottom: 3px solid #2980b9;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        .hospital-name {
            font-size: 20px;
            font-weight: 700;
            color: #2980b9;
        }
        .header-right {
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 8px 15px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.1s;
        }
        .btn[type="submit"], .btn:not([href]) {
            background-color: #2980b9;
            color: white;
            border: none;
        }
        .btn[type="submit"]:hover, .btn:not([href]):hover {
            background-color: #3498db;
            transform: translateY(-1px);
        }
        .btn[href] {
            background-color: #ecf0f1;
            color: #2c3e50;
            border: 1px solid #bdc3c7;
        }
        .btn[href]:hover {
            background-color: #dce1e3;
        }
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        .page-title {
            color: #2c3e50;
            margin-bottom: 25px;
            border-bottom: 2px solid #ddd;
            padding-bottom: 10px;
        }
        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            padding: 20px;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            align-items: flex-end;
        }
        .filter-group {
            flex-grow: 1;
        }
        .filter-label {
            display: block;
            font-weight: 600;
            margin-bottom: 5px;
            color: #7f8c8d;
            font-size: 13px;
        }
        .filters input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }
        .filters input[type="text"]:focus {
            border-color: #3498db;
            outline: none;
        }
        .prescription-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
        }
        /* Adjustment: card no longer has horizontal flex display */
        .prescription-card {
            background-color: #ffffff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
        }
        .prescription-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }
        .prescription-main {
            padding: 20px;
        }
        /* Sidebar and progress styles removed */

        .prescription-header {
            display: flex;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        .medication-name {
            font-size: 18px;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1.2;
        }
        .medication-ingredient {
            font-size: 13px;
            color: #8e44ad;
            font-style: italic;
            margin-top: 2px;
            padding-left: 0; /* Without icon, alignment removed */
        }
        .prescription-info {
            display: grid;
            /* Adjusted to 3 columns for Dosage, Frequency and Duration */
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); 
            gap: 15px;
            margin-bottom: 15px;
            padding: 15px 0;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
        }
        .info-item {
            display: flex;
            flex-direction: column;
        }
        .info-label {
            font-size: 11px;
            color: #7f8c8d;
            font-weight: 500;
            text-transform: uppercase;
        }
        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: #34495e;
            margin-top: 3px;
        }
        .doctor-info {
            border-top: 1px solid #eee;
            padding-top: 15px;
        }
        .doctor-name {
            font-size: 15px;
            font-weight: 600;
            color: #2980b9;
        }
        .doctor-specialty {
            font-size: 13px;
            color: #95a5a6;
        }

        /* Empty State */
        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            padding: 50px;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            margin-top: 30px;
        }
        .empty-state-icon {
            font-size: 40px;
            margin-bottom: 15px;
        }
        .empty-state-text {
            font-size: 18px;
            font-weight: 600;
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        .empty-state-subtext {
            color: #95a5a6;
        }
        /* Responsiveness */
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 10px;
            }
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            /* Removed need to adjust .prescription-card and .prescription-sidebar */
            .prescription-info {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <div class="hospital-name">Hospital les Corts</div>
        </div>
        <div class="header-right">
            <a href="dashboard.php" class="btn">← Back to Dashboard</a>
            <a href="logout.php" class="btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <h1 class="page-title">My Prescriptions</h1>

        <!-- Search filter -->
        <form method="GET" class="filters">
            <div class="filter-group">
                <label class="filter-label">Search Medication</label>
                <input type="text" name="search" placeholder="Name or active ingredient..." 
                        value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn">Filter</button>
        </form>

        <!-- Prescription List -->
        <div class="prescription-list">
            <?php if (count($prescriptions) > 0): ?>
                <?php foreach ($prescriptions as $rx): ?>
                    <?php 
                        // Dose kept as float for consistent numeric display.
                        $dosage = floatval($rx['dose']);
                        // Frequency and duration used as original string from DB, 
                        // as they contain descriptive text (e.g., "every 12 hours")
                        $frequency = $rx['frequency']; 
                        $duration = $rx['duration'];
                    ?>
                    <div class="prescription-card">
                        <div class="prescription-main">
                            <div class="prescription-header">
                                <div>
                                    <div class="medication-name">
                                        💊 <?= htmlspecialchars($rx['medication_name']) ?>
                                        <?php if ($rx['presentation']): ?>
                                            <span style="font-weight: 400; font-size: 14px; color: #7f8c8d;">
                                                (<?= htmlspecialchars($rx['presentation']) ?>)
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($rx['active_principle']): ?>
                                        <div class="medication-ingredient">
                                            <?= htmlspecialchars($rx['active_principle']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="prescription-info">
                                <div class="info-item">
                                    <span class="info-label">Dosage</span>
                                    <!-- Using converted float variable for dose -->
                                    <span class="info-value"><?= htmlspecialchars($dosage) ?></span> 
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Frequency</span>
                                    <!-- Using raw string for frequency -->
                                    <span class="info-value"><?= htmlspecialchars($frequency) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Duration</span>
                                    <!-- Using raw string for duration -->
                                    <span class="info-value"><?= htmlspecialchars($duration) ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Prescription Date</span>
                                    <span class="info-value">
                                        <?= htmlspecialchars(date('d/m/Y', strtotime($rx['date']))) ?>
                                    </span>
                                </div>
                                <!-- Assuming 'indications' is used for notes/instructions -->
                                <?php if ($rx['indications']): ?> 
                                <div class="info-item" style="grid-column: span 2;">
                                    <span class="info-label">Instructions</span>
                                    <span class="info-value"><?= htmlspecialchars($rx['indications']) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <div class="doctor-info">
                                <div class="doctor-name">
                                    Dr. <?= htmlspecialchars($rx['doctor_name'] . ' ' . $rx['doctor_surname']) ?>
                                </div>
                                <?php if ($rx['speciality']): ?>
                                    <div class="doctor-specialty"><?= htmlspecialchars($rx['speciality']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📋</div>
                    <div class="empty-state-text">No prescriptions found</div>
                    <div class="empty-state-subtext">
                        <?php if (!empty($search)): ?>
                            Try adjusting your search filters
                        <?php else: ?>
                            You don't have any registered prescriptions yet
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
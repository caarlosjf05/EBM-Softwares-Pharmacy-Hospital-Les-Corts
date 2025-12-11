<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Authorization check: Only for Admin/Professionals
if (!hasAnyRole(['admin', 'nurse', 'doctor'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// Filters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

// --- REPORT 1: Top Dispensed Medications ---
$stmt = $conn->prepare("
    SELECT 
        d.comercial_name,
        d.active_principle,
        COUNT(disp.dispensing_id) as times_dispensed,
        SUM(disp.quantity) as total_qty
    FROM DRUGS d
    LEFT JOIN PRESCRIPTION_ITEM pi ON d.drug_id = pi.drug_id
    LEFT JOIN DISPENSING disp ON pi.prescription_item_id = disp.prescription_item_id
    WHERE disp.data_dispensing BETWEEN :date_from AND :date_to
    GROUP BY d.drug_id
    ORDER BY total_qty DESC
    LIMIT 10
");
$stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
$top_dispensed = $stmt->fetchAll();

// --- REPORT 2: Top Prescribing Doctors ---
$stmt = $conn->prepare("
    SELECT 
        prof.name,
        prof.surname,
        COUNT(pr.prescription_id) as prescriptions,
        COUNT(DISTINCT pr.patient_id) as unique_patients
    FROM PROFESSIONAL prof
    LEFT JOIN PRESCRIPTION pr ON prof.professional_id = pr.professional_id
    WHERE pr.date BETWEEN :date_from AND :date_to
    GROUP BY prof.professional_id
    ORDER BY prescriptions DESC
    LIMIT 10
");
$stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
$top_prescribers = $stmt->fetchAll();

// --- REPORT 3: Medications by Administration Route ---
$stmt = $conn->prepare("
    SELECT 
        d.via_administration,
        COUNT(DISTINCT d.drug_id) as drug_count,
        SUM(disp.quantity) as total_qty
    FROM DRUGS d
    JOIN PRESCRIPTION_ITEM pi ON d.drug_id = pi.drug_id
    JOIN DISPENSING disp ON pi.prescription_item_id = disp.prescription_item_id
    WHERE disp.data_dispensing BETWEEN :date_from AND :date_to
    GROUP BY d.via_administration
    ORDER BY drug_count DESC
");
$stmt->execute(['date_from' => $date_from, 'date_to' => $date_to]);
$by_route = $stmt->fetchAll();

// --- REPORT 4: Storages with Most Lots ---
$stmt = $conn->prepare("
    SELECT 
        s.storage_id,
        s.name as storage_name,
        s.location_type,
        s.building,
        s.floor,
        COUNT(l.lot_id) as total_lots,
        SUM(l.quantity) as total_quantity
    FROM STORAGE s
    LEFT JOIN LOT_NUMBERS l ON s.storage_id = l.storage_id
    GROUP BY s.storage_id
    ORDER BY total_lots DESC
    LIMIT 10
");
$stmt->execute();
$storage_lots = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Hospital les Corts</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        /* --- UNIFIED DASHBOARD STYLES --- */
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
        
        h2 {
            font-size: 16px;
            font-weight: 600;
            color: #34495e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }
        
        /* Buttons */
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
        .btn-primary:hover { background-color: #3498db; }
        
        /* Alert Styles */
        .alert-error {
            background-color: #fcebeb;
            color: #c0392b;
            border: 1px solid #e74c3c;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success {
            background-color: #e8f8f5;
            color: #16a085;
            border: 1px solid #1abc9c;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .section-box {
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 4px;
            padding: 20px;
            overflow-x: auto;
            margin-bottom: 30px;
        }

        /* Filter Form */
        .filter-form {
            display: flex; 
            gap: 15px; 
            align-items: flex-end;
            padding: 15px;
            border: 1px solid #f0f3f5;
            border-radius: 4px;
        }
        .form-group {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        .form-group input[type="date"] {
            padding: 10px;
            border: 1px solid #dce1e6;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        /* Report Grid */
        .report-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 30px;
        }

        .box {
            background: white;
            border: 1px solid #e1e8ed;
            border-radius: 4px;
            padding: 20px;
            overflow-x: auto;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin-top: 20px;
        }

        /* Table Styles */
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
        
        .location-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            background-color: #ecf0f1;
            color: #2c3e50;
        }
    </style>
</head>
<body>
    
    <div class="header-module">
        <a href="../dashboard.php" class="btn-back">⬅️ Back to Dashboard</a>
    </div>

    <div class="container">
        
        <header>
            <div>
                <h1>📈 System Reports & Analytics</h1>
                <p>Generate reports on prescriptions, dispensations, and drug usage patterns for the period from <?= htmlspecialchars(date('d/m/Y', strtotime($date_from))) ?> to <?= htmlspecialchars(date('d/m/Y', strtotime($date_to))) ?>.</p>
            </div>
        </header>

        <div class="section-box">
            <h2>Filtering Options</h2>
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label for="date_from">Date From</label>
                    <input type="date" id="date_from" name="date_from" value="<?= htmlspecialchars($date_from) ?>" required>
                </div>
                <div class="form-group">
                    <label for="date_to">Date To</label>
                    <input type="date" id="date_to" name="date_to" value="<?= htmlspecialchars($date_to) ?>" required>
                </div>
                <button type="submit" class="btn-primary" style="padding: 10px 20px;">Generate Report</button>
            </form>
        </div>

        <div class="report-grid">
            
            <!-- TOP DISPENSED MEDICATIONS -->
            <div class="box" style="grid-column: 1 / -1;">
                <h2>💊 Top 10 Dispensed Medications</h2>
                <?php if (!empty($top_dispensed)): ?>
                    <div class="chart-container">
                        <canvas id="topDispensedChart"></canvas>
                    </div>
                    <table style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>Commercial Name</th>
                                <th>Active Principle</th>
                                <th>Total Quantity</th>
                                <th>Times Dispensed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_dispensed as $med): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($med['comercial_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($med['active_principle']) ?></td>
                                    <td><?= number_format($med['total_qty']) ?></td>
                                    <td><?= number_format($med['times_dispensed']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="alert-error">No dispensing data found in the selected period.</div>
                <?php endif; ?>
            </div>

            <!-- TOP PRESCRIBING PROFESSIONALS -->
            <div class="box">
                <h2>🧑‍⚕️ Top 10 Prescribing Professionals</h2>
                <?php if (!empty($top_prescribers)): ?>
                    <div class="chart-container">
                        <canvas id="topPrescribersChart"></canvas>
                    </div>
                    <table style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>Professional</th>
                                <th>Prescriptions</th>
                                <th>Unique Patients</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_prescribers as $prescriber): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($prescriber['name'] . ' ' . $prescriber['surname']) ?></strong></td>
                                    <td><?= $prescriber['prescriptions'] ?></td>
                                    <td><?= $prescriber['unique_patients'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="alert-error">No prescription data found in the selected period.</div>
                <?php endif; ?>
            </div>

            <!-- ADMINISTRATION ROUTES -->
            <div class="box">
                <h2>💉 Dispensation by Administration Route</h2>
                <?php if (!empty($by_route)): ?>
                    <div class="chart-container">
                        <canvas id="routeChart"></canvas>
                    </div>
                    <table style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>Administration Route</th>
                                <th>Unique Medications</th>
                                <th>Total Quantity Dispensed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($by_route as $route): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($route['via_administration']) ?></strong></td>
                                    <td><?= $route['drug_count'] ?></td>
                                    <td><?= number_format($route['total_qty'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="alert-error">No data for administration routes in the selected period.</div>
                <?php endif; ?>
            </div>

            <!-- STORAGES WITH MOST LOTS -->
            <div class="box" style="grid-column: 1 / -1;">
                <h2>📦 Top 10 Storages by Number of Lots</h2>
                <?php if (!empty($storage_lots)): ?>
                    <div class="chart-container">
                        <canvas id="storageLotsChart"></canvas>
                    </div>
                    <table style="margin-top: 20px;">
                        <thead>
                            <tr>
                                <th>Storage Name</th>
                                <th>Location</th>
                                <th>Type</th>
                                <th>Total Lots</th>
                                <th>Total Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($storage_lots as $storage): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($storage['storage_name']) ?></strong></td>
                                    <td>
                                        <?php if ($storage['building']): ?>
                                            Building <?= htmlspecialchars($storage['building']) ?>
                                            <?php if ($storage['floor']): ?>
                                                - Floor <?= htmlspecialchars($storage['floor']) ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #95a5a6;">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="location-badge">
                                            <?= htmlspecialchars($storage['location_type'] ?? 'Standard') ?>
                                        </span>
                                    </td>
                                    <td><?= number_format($storage['total_lots'] ?? 0) ?></td>
                                    <td><?= number_format($storage['total_quantity'] ?? 0) ?> units</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="alert-error">No storage data found.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Colors for charts
        const colors = [
            '#3498db', '#e74c3c', '#2ecc71', '#f39c12', '#9b59b6',
            '#1abc9c', '#e67e22', '#34495e', '#16a085', '#c0392b'
        ];

        // Chart 1: Top Dispensed Medications (Bar Chart)
        <?php if (!empty($top_dispensed)): ?>
        const topDispensedData = {
            labels: <?= json_encode(array_map(function($m) { return $m['comercial_name']; }, $top_dispensed)) ?>,
            datasets: [{
                label: 'Total Quantity Dispensed',
                data: <?= json_encode(array_map(function($m) { return $m['total_qty']; }, $top_dispensed)) ?>,
                backgroundColor: colors[0],
                borderColor: colors[0],
                borderWidth: 1
            }]
        };

        new Chart(document.getElementById('topDispensedChart'), {
            type: 'bar',
            data: topDispensedData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: 'Medication Dispensation Volume'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        <?php endif; ?>

        // Chart 2: Top Prescribers (Horizontal Bar Chart)
        <?php if (!empty($top_prescribers)): ?>
        const topPrescribersData = {
            labels: <?= json_encode(array_map(function($p) { return $p['name'] . ' ' . $p['surname']; }, $top_prescribers)) ?>,
            datasets: [{
                label: 'Number of Prescriptions',
                data: <?= json_encode(array_map(function($p) { return $p['prescriptions']; }, $top_prescribers)) ?>,
                backgroundColor: colors[1],
                borderColor: colors[1],
                borderWidth: 1
            }]
        };

        new Chart(document.getElementById('topPrescribersChart'), {
            type: 'bar',
            data: topPrescribersData,
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: 'Prescription Activity'
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true
                    }
                }
            }
        });
        <?php endif; ?>

        // Chart 3: Administration Routes (Pie Chart)
        <?php if (!empty($by_route)): ?>
        const routeData = {
            labels: <?= json_encode(array_map(function($r) { return $r['via_administration']; }, $by_route)) ?>,
            datasets: [{
                label: 'Total Quantity',
                data: <?= json_encode(array_map(function($r) { return $r['total_qty']; }, $by_route)) ?>,
                backgroundColor: colors,
                borderColor: '#fff',
                borderWidth: 2
            }]
        };

        new Chart(document.getElementById('routeChart'), {
            type: 'doughnut',
            data: routeData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    },
                    title: {
                        display: true,
                        text: 'Distribution by Route'
                    }
                }
            }
        });
        <?php endif; ?>

        // Chart 4: Storage Lots (Bar Chart)
        <?php if (!empty($storage_lots)): ?>
        const storageLotsData = {
            labels: <?= json_encode(array_map(function($s) { return $s['storage_name']; }, $storage_lots)) ?>,
            datasets: [{
                label: 'Number of Lots',
                data: <?= json_encode(array_map(function($s) { return $s['total_lots']; }, $storage_lots)) ?>,
                backgroundColor: colors[2],
                borderColor: colors[2],
                borderWidth: 1
            }]
        };

        new Chart(document.getElementById('storageLotsChart'), {
            type: 'bar',
            data: storageLotsData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    title: {
                        display: true,
                        text: 'Lot Distribution Across Storage Locations'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>
<?php
require_once 'includes/auth.php'; 
require_once 'includes/functions.php';

// Authorization check: All professionals
if (!hasAnyRole(['admin', 'nurse', 'doctor'])) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// Process search
$search_query = $_GET['q'] ?? '';
$results = [];

// Function to determine stock status (Using the one from functions.php if available, otherwise defined here)
if (!function_exists('getStockStatus')) {
    function getStockStatus($actual_stock, $minimum_stock) {
        if ($actual_stock == 0) {
            return ['status' => 'empty', 'text' => 'Out of Stock'];
        } elseif ($actual_stock <= $minimum_stock) {
            return ['status' => 'low', 'text' => 'Low Stock'];
        } else {
            return ['status' => 'available', 'text' => 'Available'];
        }
    }
}


if (!empty($search_query)) {
    // Original Query
    $stmt = $conn->prepare("
        SELECT 
            d.drug_id,
            d.code_ATC,
            d.comercial_name,
            d.active_principle,
            d.presentation,
            d.via_administration,
            d.standard_concentration,
            d.actual_inventory,
            d.minimum_stock,
            -- Subquery to get the storage name of the lot with the most stock (FEFO priority)
            (
                SELECT s.name 
                FROM LOT_NUMBERS l
                JOIN STORAGE s ON l.storage_id = s.storage_id
                WHERE l.drug_id = d.drug_id AND l.quantity > 0 AND l.expiration_date >= NOW()
                ORDER BY l.quantity DESC, l.expiration_date ASC
                LIMIT 1
            ) as storage_name
        FROM DRUGS d
        WHERE 
            d.comercial_name LIKE :search 
            OR d.active_principle LIKE :search 
            OR d.code_ATC LIKE :search
        ORDER BY d.comercial_name ASC
    ");
    
    $search_param = '%' . $search_query . '%';
    $stmt->execute(['search' => $search_param]);
    $results = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medication Search - Hospital les Corts</title>
    
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
            background-color: #fcebeb; /* Light Red */
            color: #c0392b; /* Dark Red */
            border: 1px solid #e74c3c;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success {
            background-color: #e8f8f5; /* Light Green/Teal */
            color: #16a085; /* Dark Green/Teal */
            border: 1px solid #1abc9c;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }

        /* Search Form */
        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 32px;
        }
        .search-form input[type="text"] {
            flex-grow: 1;
            padding: 10px;
            border: 1px solid #dce1e6;
            border-radius: 4px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .search-form input[type="text"]:focus {
            border-color: #3498db;
            outline: none;
        }

        /* Table Styles */
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
        
        /* Custom elements for search results */
        .code-badge {
            background: #ecf0f1;
            color: #7f8c8d;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        .stock-indicator {
            display: flex;
            align-items: center;
            font-weight: 500;
            color: #34495e;
        }
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .status-dot.empty { background-color: #e74c3c; color: #e74c3c;} /* Red */
        .status-dot.low { background-color: #f39c12; color: #f39c12;} /* Orange */
        .status-dot.available { background-color: #2ecc71; color: #2ecc71;} /* Green */
        .stock-indicator .empty { color: #e74c3c; }
        .stock-indicator .low { color: #f39c12; }
        .stock-indicator .available { color: #2ecc71; }
    </style>
</head>
<body>
    
    <div class="header-module">
        <a href="dashboard.php" class="btn-back">⬅️ Back to Dashboard</a>
    </div>

    <div class="container">
        
        <header>
            <div>
                <h1>🔍 Quick Medication Search</h1>
                <p>Search for medications by commercial name, active ingredient, or ATC code and view key inventory details.</p>
            </div>
        </header>
        
        <div class="search-form">
            <form method="GET" action="search.php" style="display: flex; gap: 10px; width: 100%;">
                <input type="text" name="q" placeholder="Enter medication name, ATC code, or active ingredient..." value="<?= htmlspecialchars($search_query) ?>" required>
                <button type="submit" class="btn-primary">🔍 Search</button>
            </form>
        </div>

        <?php if (!empty($search_query)): ?>
            <?php if (empty($results)): ?>
                <div class="alert-error">No results found for **<?= htmlspecialchars($search_query) ?>**.</div>
            <?php else: ?>
                <div class="section-box">
                    <h2>Search Results (<?= count($results) ?> found)</h2>
                    <table>
                        <thead>
                            <tr>
                                <th>ATC Code</th>
                                <th>Commercial Name</th>
                                <th>Active Principle</th>
                                <th>Presentation</th>
                                <th>Route</th>
                                <th>Concentration</th>
                                <th>Stock Status</th>
                                <th>Main Storage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $drug): ?>
                                <?php 
                                    $status_data = getStockStatus($drug['actual_inventory'], $drug['minimum_stock']);
                                    $status = $status_data['status'];
                                    $status_text = $status_data['text'];
                                    $stock = $drug['actual_inventory'];
                                ?>
                                <tr>
                                    <td><span class="code-badge"><?= htmlspecialchars($drug['code_ATC']) ?></span></td>
                                    <td><strong><?= htmlspecialchars($drug['comercial_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($drug['active_principle']) ?></td>
                                    <td><?= htmlspecialchars($drug['presentation']) ?></td>
                                    <td><?= htmlspecialchars($drug['via_administration']) ?></td>
                                    <td><?= htmlspecialchars($drug['standard_concentration'] ?? '—') ?></td>
                                    <td>
                                        <div class="stock-indicator">
                                            <span class="status-dot <?= $status ?>"></span>
                                            <span class="<?= $status ?>">
                                                <?= $status_text ?> (<?= $stock ?> units)
                                            </span>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($drug['storage_name'] ?? '—') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
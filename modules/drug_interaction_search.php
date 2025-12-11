<?php
/**
 * GENERAL DRUG INTERACTION SEARCH (Database View)
 * File: modules/drug_interaction_search.php
 */

require_once '../includes/auth.php';
require_once '../includes/functions.php'; // Aseguramos que functions esté incluido

// Access Control: Clinical Staff & Admin
if (!hasAnyRole(['admin', 'pharmacist', 'doctor', 'nurse'])) {
    header('Location: ../dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// 1. [NUEVO] Obtener lista de medicamentos para el Autocompletado
$all_drugs_stmt = $conn->query("SELECT comercial_name, active_principle FROM DRUGS ORDER BY comercial_name ASC");
$all_drugs_list = $all_drugs_stmt->fetchAll();

// Search functionality inputs
$search_drug = $_GET['search_drug'] ?? '';
$severity_filter = $_GET['severity'] ?? '';

// Build query with filters
$query = "
    SELECT 
        di.interaction_id,
        di.severity,
        di.description,
        di.recommendation,
        d1.drug_id as drug_a_id,
        d1.comercial_name as drug_a_name,
        d1.active_principle as drug_a_active,
        d2.drug_id as drug_b_id,
        d2.comercial_name as drug_b_name,
        d2.active_principle as drug_b_active
    FROM DRUG_INTERACTIONS di
    JOIN DRUGS d1 ON di.drug_id_a = d1.drug_id
    JOIN DRUGS d2 ON di.drug_id_b = d2.drug_id
    WHERE 1=1
";

$params = [];

if ($search_drug) {
    // Busca coincidencias en cualquiera de los dos medicamentos implicados
    $query .= " AND (d1.comercial_name LIKE :search OR d2.comercial_name LIKE :search 
                OR d1.active_principle LIKE :search OR d2.active_principle LIKE :search)";
    $params['search'] = "%$search_drug%";
}

if ($severity_filter) {
    $query .= " AND di.severity = :severity";
    $params['severity'] = $severity_filter;
}

$query .= " ORDER BY 
    CASE di.severity 
        WHEN 'high' THEN 1 
        WHEN 'moderate' THEN 2 
        WHEN 'low' THEN 3 
    END,
    d1.comercial_name";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$interactions = $stmt->fetchAll();

// Get statistics for the dashboard cards
$stats_query = "
    SELECT 
        severity,
        COUNT(*) as count
    FROM DRUG_INTERACTIONS
    GROUP BY severity
";
$stats = $conn->query($stats_query)->fetchAll(PDO::FETCH_KEY_PAIR);

$total_interactions = array_sum($stats);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drug Interactions Database</title>
    
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: #f5f7fa; 
            min-height: 100vh; 
        }
        
        /* HEADER STYLE */
        .header { background: white; border-bottom: 1px solid #e1e8ed; padding: 0 32px; height: 64px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
        .header h1 { font-size: 18px; font-weight: 400; color: #2c3e50; }
        .back-btn { padding: 8px 16px; border-radius: 4px; font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; text-decoration: none; background: transparent; color: #7f8c8d; border: 1px solid #dce1e6; transition: all 0.2s; }
        .back-btn:hover { border-color: #95a5a6; color: #2c3e50; }
        
        .container { max-width: 1600px; margin: 0 auto; padding: 32px; }
        
        /* STATS CARDS */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 32px; }
        .stat-card { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 20px; }
        .stat-label { color: #7f8c8d; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-number { font-size: 28px; font-weight: 300; color: #2c3e50; }
        .stat-card.high { border-left: 3px solid #e74c3c; }
        .stat-card.moderate { border-left: 3px solid #f39c12; }
        .stat-card.low { border-left: 3px solid #3498db; }
        
        /* FILTERS SECTION */
        .filters { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 24px; margin-bottom: 24px; }
        .filter-grid { display: grid; grid-template-columns: 2fr 1fr auto; gap: 16px; align-items: end; }
        .form-group { display: flex; flex-direction: column; }
        
        label { margin-bottom: 8px; color: #2c3e50; font-weight: 500; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        
        /* Standard inputs */
        select.std-input { padding: 12px 16px; border: 1px solid #dce1e6; border-radius: 4px; font-size: 14px; background: #fafbfc; height: 45px; }
        
        .search-btn { padding: 12px 24px; background: #3498db; color: white; border: none; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; height: 45px; }
        .search-btn:hover { background: #2980b9; }
        
        .clear-filters { padding: 12px 24px; background: #95a5a6; color: white; border: none; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer; text-transform: uppercase; letter-spacing: 0.5px; text-decoration: none; display: inline-block; height: 45px; line-height: 21px; margin-left: 8px; }
        .clear-filters:hover { background: #7f8c8d; }
        
        /* INTERACTION CARDS */
        .interactions-list { display: grid; gap: 16px; }
        .interaction-card { background: white; border: 1px solid #e1e8ed; border-radius: 4px; padding: 24px; transition: box-shadow 0.2s; }
        .interaction-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .interaction-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
        
        .drugs-interaction { display: flex; align-items: center; gap: 16px; flex: 1; }
        .drug-box { flex: 1; padding: 12px; background: #f8f9fa; border-radius: 4px; border: 1px solid #e1e8ed; }
        .drug-name { font-weight: 600; color: #2c3e50; font-size: 14px; margin-bottom: 4px; }
        .drug-active { font-size: 12px; color: #7f8c8d; }
        .interaction-icon { font-size: 24px; color: #e74c3c; }
        
        .severity-badge { padding: 6px 12px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .severity-high { background: #fee; color: #c0392b; }
        .severity-moderate { background: #fef5e7; color: #d68910; }
        .severity-low { background: #eaf2f8; color: #2874a6; }
        
        .interaction-description { padding: 16px; background: #fafbfc; border-left: 3px solid #3498db; border-radius: 4px; margin-bottom: 12px; }
        .description-title { font-weight: 600; font-size: 12px; text-transform: uppercase; color: #7f8c8d; letter-spacing: 0.5px; margin-bottom: 8px; }
        .description-text { color: #2c3e50; font-size: 14px; line-height: 1.5; }
        
        .interaction-recommendation { padding: 16px; background: #d4edda; border-left: 3px solid #27ae60; border-radius: 4px; }
        .recommendation-title { font-weight: 600; font-size: 12px; text-transform: uppercase; color: #155724; letter-spacing: 0.5px; margin-bottom: 8px; }
        .recommendation-text { color: #155724; font-size: 14px; line-height: 1.5; }
        
        .no-results { text-align: center; padding: 48px; color: #7f8c8d; background: white; border: 1px solid #e1e8ed; border-radius: 4px; }
        .section-header { font-size: 13px; font-weight: 600; color: #2c3e50; text-transform: uppercase; letter-spacing: 0.5px; margin: 32px 0 16px 0; }

        /* SELECT2 CUSTOMIZATION TO MATCH THEME */
        .select2-container { width: 100% !important; }
        .select2-container .select2-selection--single {
            height: 45px !important;
            border: 1px solid #dce1e6 !important;
            border-radius: 4px !important;
            background-color: #fafbfc !important;
            padding: 8px 0 !important;
        }
        .select2-container--open .select2-selection--single {
            border-color: #3498db !important;
            background-color: white !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 26px !important;
            padding-left: 16px !important;
            color: #333 !important;
            font-size: 14px !important;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 43px !important;
            right: 10px !important;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>⚠️ Drug Interactions Database Search</h1>
        <a href="../dashboard.php" class="back-btn">Back to Dashboard</a>
    </div>

    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Interactions</div>
                <div class="stat-number"><?= $total_interactions ?></div>
            </div>
            <div class="stat-card high">
                <div class="stat-label">High Severity</div>
                <div class="stat-number"><?= $stats['high'] ?? 0 ?></div>
            </div>
            <div class="stat-card moderate">
                <div class="stat-label">Moderate Severity</div>
                <div class="stat-number"><?= $stats['moderate'] ?? 0 ?></div>
            </div>
            <div class="stat-card low">
                <div class="stat-label">Low Severity</div>
                <div class="stat-number"><?= $stats['low'] ?? 0 ?></div>
            </div>
        </div>

        <div class="filters">
            <form method="GET">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Search Drug</label>
                        <select name="search_drug" class="searchable-select">
                            <option value="">Select or type a drug...</option>
                            <?php foreach ($all_drugs_list as $drug): ?>
                                <option value="<?= htmlspecialchars($drug['comercial_name']) ?>" 
                                    <?= ($search_drug === $drug['comercial_name']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($drug['comercial_name']) ?> (<?= htmlspecialchars($drug['active_principle']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Severity Level</label>
                        <select name="severity" class="std-input">
                            <option value="">All Severities</option>
                            <option value="high" <?= $severity_filter === 'high' ? 'selected' : '' ?>>High</option>
                            <option value="moderate" <?= $severity_filter === 'moderate' ? 'selected' : '' ?>>Moderate</option>
                            <option value="low" <?= $severity_filter === 'low' ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>
                    
                    <div>
                        <button type="submit" class="search-btn">Search</button>
                        <?php if ($search_drug || $severity_filter): ?>
                            <a href="drug_interaction_search.php" class="clear-filters">Clear</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <div class="section-header">
            <?= count($interactions) ?> Drug Interactions Found
        </div>

        <?php if (empty($interactions)): ?>
            <div class="no-results">
                <h3>No drug interactions found</h3>
                <p>Try searching for a different drug or adjusting filters.</p>
            </div>
        <?php else: ?>
            <div class="interactions-list">
                <?php foreach ($interactions as $interaction): ?>
                    <div class="interaction-card">
                        <div class="interaction-header">
                            <div class="drugs-interaction">
                                <div class="drug-box">
                                    <div class="drug-name"><?= htmlspecialchars($interaction['drug_a_name']) ?></div>
                                    <div class="drug-active"><?= htmlspecialchars($interaction['drug_a_active']) ?></div>
                                </div>
                                
                                <div class="interaction-icon">⚠️</div>
                                
                                <div class="drug-box">
                                    <div class="drug-name"><?= htmlspecialchars($interaction['drug_b_name']) ?></div>
                                    <div class="drug-active"><?= htmlspecialchars($interaction['drug_b_active']) ?></div>
                                </div>
                            </div>
                            
                            <span class="severity-badge severity-<?= $interaction['severity'] ?>">
                                <?= strtoupper($interaction['severity']) ?>
                            </span>
                        </div>
                        
                        <div class="interaction-description">
                            <div class="description-title">⚕️ Interaction Description</div>
                            <div class="description-text">
                                <?= htmlspecialchars($interaction['description']) ?>
                            </div>
                        </div>
                        
                        <?php if ($interaction['recommendation']): ?>
                            <div class="interaction-recommendation">
                                <div class="recommendation-title">💊 Clinical Recommendation</div>
                                <div class="recommendation-text">
                                    <?= htmlspecialchars($interaction['recommendation']) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        $(document).ready(function() {
            $('.searchable-select').select2({
                placeholder: "Select or type a drug...",
                allowClear: true,
                width: '100%'
            });
        });
    </script>
</body>
</html>

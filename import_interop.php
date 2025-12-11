<?php
// This file is just a simulation
// FILE: import_interop.php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Seguridad: Solo Admin
if (!hasRole('admin')) {
    die("Access Denied: Only Administrators can import external catalog data.");
}

$conn = getDBConnection();

// 1. SIMULACIÓN DE DATOS ENTRANTES (Payload JSON Externo)
// Imaginemos que esto llega de "Hospital Clinic"
$external_json_payload = '[
    {
        "universal_id": "N02BE01", 
        "medication_name": "Paracetamol Genérico (Importado)", 
        "stock_level": 100
    },
    {
        "universal_id": "M01AE01", 
        "medication_name": "Ibuprofeno Ayuda (Importado)", 
        "stock_level": 50
    }
]';

$incoming_data = json_decode($external_json_payload, true);
$logs = [];

// 2. PROCESAR DATOS (El Suscriptor)
foreach ($incoming_data as $item) {
    
    // --- CAPA DE MAPPING (Traducción Inversa) ---
    // Mapeamos claves externas -> Variables lógicas
    $external_ref  = $item['universal_id'];   // Usamos el ATC como clave universal
    $incoming_qty  = $item['stock_level'];

    // 3. LÓGICA DE NEGOCIO (Actualizar Base de Datos Local)
    // Buscamos si tenemos ese medicamento por su código ATC
    $stmt = $conn->prepare("SELECT drug_id, comercial_name, actual_inventory FROM DRUGS WHERE code_ATC = :atc");
    $stmt->execute(['atc' => $external_ref]);
    $existing_drug = $stmt->fetch();

    if ($existing_drug) {
        // UPDATE: Si existe, sumamos el stock
        $update = $conn->prepare("UPDATE DRUGS SET actual_inventory = actual_inventory + :qty WHERE drug_id = :id");
        $update->execute(['qty' => $incoming_qty, 'id' => $existing_drug['drug_id']]);
        
        $logs[] = "✅ <strong>MATCH FOUND:</strong> Received {$incoming_qty} units for ATC <code>{$external_ref}</code>. Updated local stock for '{$existing_drug['comercial_name']}'.";
    } else {
        // INSERT: En este ejemplo simple, solo avisamos
        $logs[] = "⚠️ <strong>NEW ITEM:</strong> ATC <code>{$external_ref}</code> not found in local catalog. Queued for creation.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Interoperability Import Status</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f5f7fa; padding: 40px; }
        .container { max-width: 700px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; border: 1px solid #e1e8ed; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        h1 { color: #2c3e50; border-bottom: 2px solid #eee; padding-bottom: 15px; margin-top: 0; }
        .log-entry { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; }
        .log-entry:last-child { border-bottom: none; }
        .btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 4px; font-weight: 600; }
        .btn:hover { background: #2980b9; }
        code { background: #eee; padding: 2px 5px; border-radius: 3px; font-family: monospace; color: #c7254e; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Data Import Result</h1>
        <p>Processed incoming JSON payload via <strong>Interoperability Mapping Layer</strong>.</p>
        
        <div style="background: #f8f9fa; border: 1px solid #e1e8ed; border-radius: 4px;">
            <?php foreach ($logs as $log): ?>
                <div class="log-entry"><?= $log ?></div>
            <?php endforeach; ?>
        </div>

        <a href="edit_general_meds.php" class="btn">← Return to Inventory</a>
    </div>
</body>
</html>
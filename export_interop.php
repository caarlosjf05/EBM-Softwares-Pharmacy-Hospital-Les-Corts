<?php
// FILE: export_interop.php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Seguridad: Solo Admin o Farmacéutico
if (!hasAnyRole(['admin', 'pharmacist', 'nurse'])) {
    die("Access Denied");
}

$conn = getDBConnection();

// 1. OBTENER DATOS INTERNOS (Tu esquema de base de datos)
$stmt = $conn->query("SELECT comercial_name, active_principle, code_ATC, actual_inventory, unitary_price FROM DRUGS");
$my_drugs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. CAPA DE MAPPING (Traducción Interno -> Estándar Universal)
$interoperable_payload = [];

foreach ($my_drugs as $drug) {
    $interoperable_payload[] = [
        // Clave Estándar (JSON)    =>  Tu Columna Interna (DB)
        "universal_id"              =>  $drug['code_ATC'],       // El código ATC es el estándar médico
        "medication_name"           =>  $drug['comercial_name'],
        "active_ingredient"         =>  $drug['active_principle'],
        "stock_level"               =>  (int)$drug['actual_inventory'],
        "unit_cost"                 =>  (float)$drug['unitary_price'],
        "source_system"             =>  "Hospital_Les_Corts_Pharmacy_v1",
        "exported_at"               =>  date('c')
    ];
}

// 3. GENERAR DESCARGA DE ARCHIVO JSON
header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="interoperability_export_' . date('Y-m-d') . '.json"');

// Imprimir el JSON bonito (Pretty Print) para que se lea bien si lo abres
echo json_encode($interoperable_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit();
?>
<?php
require_once __DIR__ . '/../config.php';

// Function name maintained as it's a specific action
function getPatientAllergies($patient_id) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("
        SELECT 
            pa.patient_allergy_id,
            a.name_alergen,
            a.category,
            pa.severity,
            pa.reaction
        FROM PATIENT_ALLERGY pa
        JOIN ALLERGENS a ON pa.allergen_id = a.allergen_id
        WHERE pa.patient_id = :patient_id
        ORDER BY pa.severity DESC
    ");
    $stmt->execute(['patient_id' => $patient_id]);
    return $stmt->fetchAll();
}

// Function name maintained as it's a specific action
function checkDrugAllergy($drug_id, $patient_id) {
    $conn = getDBConnection();
    
    $stmt = $conn->prepare("
        SELECT a.name_alergen, pa.severity
        FROM MEDICAMENT_ALLERGEN ma
        JOIN ALLERGENS a ON ma.allergen_id = a.allergen_id
        JOIN PATIENT_ALLERGY pa ON pa.allergen_id = a.allergen_id
        WHERE ma.drug_id = :drug_id AND pa.patient_id = :patient_id
    ");
    $stmt->execute(['drug_id' => $drug_id, 'patient_id' => $patient_id]);
    return $stmt->fetchAll();
}

/**
 * Gets the stock status for display purposes.
 * @param int $actual_stock
 * @param int $minimum_stock
 * @return array{status: string, text: string, color: string}
 */
function getStockStatus($actual_stock, $minimum_stock) {
    // String translations
    if ($actual_stock == 0) {
        return ['status' => 'empty', 'text' => 'Out of Stock', 'color' => '#f44336'];
    } elseif ($actual_stock <= $minimum_stock) {
        return ['status' => 'low', 'text' => 'Low Stock', 'color' => '#ff9800'];
    } else {
        return ['status' => 'ok', 'text' => 'Available', 'color' => '#4caf50'];
    }
}

// Function name maintained for clarity
function getLowStockMedications($threshold = null) {
    $conn = getDBConnection();
    $query = "
        SELECT 
            d.drug_id,
            d.comercial_name,
            d.active_principle,
            d.actual_inventory,
            d.minimum_stock,
            s.name as storage_name
        FROM DRUGS d
        LEFT JOIN STORAGE s ON d.storage_id = s.storage_id
        WHERE d.actual_inventory <= d.minimum_stock
        ORDER BY d.actual_inventory ASC
    ";
    
    if ($threshold) {
        $query = "
            SELECT 
                d.drug_id,
                d.comercial_name,
                d.active_principle,
                d.actual_inventory,
                d.minimum_stock,
                s.name as storage_name
            FROM DRUGS d
            LEFT JOIN STORAGE s ON d.storage_id = s.storage_id
            WHERE d.actual_inventory <= :threshold
            ORDER BY d.actual_inventory ASC
        ";
        $stmt = $conn->prepare($query);
        $stmt->execute(['threshold' => $threshold]);
    } else {
        $stmt = $conn->query($query);
    }
    
    return $stmt->fetchAll();
}

// Function name maintained
function calculatePatientAge($birth_date) {
    $today = new DateTime('today');
    $birthDate = new DateTime($birth_date);
    $age = $birthDate->diff($today)->y;
    return $age;
}

// Function name maintained
function isPediatricPatient($birth_date) {
    return calculatePatientAge($birth_date) < 18;
}

// Function name maintained
function getPatientMedicationHistory($patient_id, $limit = 50) {
    $conn = getDBConnection();
    $stmt = $conn->prepare("
        SELECT 
            pr.prescription_id,
            pr.date,
            pi.prescription_item_id,
            pi.dose,
            pi.frequency,
            pi.duration,
            d.comercial_name,
            d.active_principle,
            d.code_ATC,
            prof.name as doctor_name,
            prof.surname as doctor_surname,
            diag.disease_name,
            COALESCE(SUM(disp.quantity), 0) as dispensed_quantity
        FROM PRESCRIPTION pr
        JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
        JOIN DRUGS d ON pi.drug_id = d.drug_id
        JOIN PROFESSIONAL prof ON pr.professional_id = prof.professional_id
        LEFT JOIN DIAGNOSTICS diag ON pr.diagnostic_id = diag.diagnostic_id
        LEFT JOIN DISPENSING disp ON pi.prescription_item_id = disp.prescription_item_id
        WHERE pr.patient_id = :patient_id
        GROUP BY pi.prescription_item_id
        ORDER BY pr.date DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':patient_id', $patient_id, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Gets overall prescription and stock statistics.
 * @return array
 */
function getPrescriptionStats() {
    $conn = getDBConnection();
    
    $stats = [];
    
    // Total prescriptions (Comments translated)
    $stmt = $conn->query("SELECT COUNT(*) as total FROM PRESCRIPTION");
    $stats['total_prescriptions'] = $stmt->fetch()['total'];
    
    // Total dispensations (Comments translated)
    $stmt = $conn->query("SELECT COUNT(*) as total FROM DISPENSING");
    $stats['total_dispensations'] = $stmt->fetch()['total'];
    
    // Low stock medications (Comments translated)
    $stmt = $conn->query("SELECT COUNT(*) as total FROM DRUGS WHERE actual_inventory <= minimum_stock");
    $stats['low_stock_count'] = $stmt->fetch()['total'];
    
    // Out of stock medications (Comments translated)
    $stmt = $conn->query("SELECT COUNT(*) as total FROM DRUGS WHERE actual_inventory = 0");
    $stats['no_stock_count'] = $stmt->fetch()['total'];
    
    return $stats;
}

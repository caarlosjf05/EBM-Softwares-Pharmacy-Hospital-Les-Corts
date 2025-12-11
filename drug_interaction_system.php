<?php
/**
 * DRUG INTERACTION CHECKER - VERSIÓ CORREGIDA
 * 
 * Aquest fitxer proporciona funcions per comprovar interaccions
 * amb la taula DRUG_INTERACTIONS de forma correcta
 */

require_once 'includes/auth.php';

if (!hasAnyRole(['doctor', 'nurse', 'admin', 'pharmacist'])) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$conn = getDBConnection();

// ==============================================================
// API ENDPOINT: Comprovar interacció entre DOS medicaments específics
// ==============================================================
if (isset($_GET['action']) && $_GET['action'] === 'check_between_drugs') {
    header('Content-Type: application/json');
    
    $drug1_id = intval($_GET['drug1_id'] ?? 0);
    $drug2_id = intval($_GET['drug2_id'] ?? 0);
    
    if (!$drug1_id || !$drug2_id) {
        echo json_encode(['error' => 'Missing parameters', 'success' => false]);
        exit();
    }
    
    try {
        // Buscar interacció directament entre aquests dos medicaments
        // IMPORTANT: La taula pot tenir la interacció en qualsevol ordre (A-B o B-A)
        $stmt = $conn->prepare("
            SELECT 
                di.severity, 
                di.description, 
                di.recommendation,
                d1.comercial_name as drug_a_name,
                d2.comercial_name as drug_b_name
            FROM DRUG_INTERACTIONS di
            JOIN DRUGS d1 ON di.drug_id_a = d1.drug_id
            JOIN DRUGS d2 ON di.drug_id_b = d2.drug_id
            WHERE 
                (di.drug_id_a = :drug1 AND di.drug_id_b = :drug2)
                OR
                (di.drug_id_a = :drug2 AND di.drug_id_b = :drug1)
            LIMIT 1
        ");
        $stmt->execute([
            'drug1' => $drug1_id,
            'drug2' => $drug2_id
        ]);
        
        $interaction = $stmt->fetch();
        
        if ($interaction && strtolower($interaction['severity']) !== 'none' && strtolower($interaction['severity']) !== 'low') {
            echo json_encode([
                'success' => true,
                'has_interaction' => true,
                'interaction' => [
                    'severity' => $interaction['severity'],
                    'description' => $interaction['description'],
                    'recommendation' => $interaction['recommendation'],
                    'drug_a_name' => $interaction['drug_a_name'],
                    'drug_b_name' => $interaction['drug_b_name']
                ]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'has_interaction' => false
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    
    exit();
}

// ==============================================================
// API ENDPOINT: Comprovar interacció d'un nou medicament amb els existents del pacient
// ==============================================================
if (isset($_GET['action']) && $_GET['action'] === 'check_interaction') {
    header('Content-Type: application/json');
    
    $patient_id = intval($_GET['patient_id'] ?? 0);
    $new_drug_id = intval($_GET['new_drug_id'] ?? 0);
    
    if (!$patient_id || !$new_drug_id) {
        echo json_encode(['error' => 'Missing parameters', 'success' => false]);
        exit();
    }
    
    try {
        // Obtenir medicaments actius del pacient (últims 6 mesos)
        $stmt = $conn->prepare("
            SELECT DISTINCT 
                d.drug_id,
                d.comercial_name,
                d.active_principle
            FROM PRESCRIPTION pr
            JOIN PRESCRIPTION_ITEM pi ON pr.prescription_id = pi.prescription_id
            JOIN DRUGS d ON pi.drug_id = d.drug_id
            WHERE pr.patient_id = :patient_id
            AND pr.date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            AND d.drug_id != :new_drug_id
            GROUP BY d.drug_id
        ");
        $stmt->execute([
            'patient_id' => $patient_id,
            'new_drug_id' => $new_drug_id
        ]);
        $existing_drugs = $stmt->fetchAll();
        
        $interactions = [];
        
        // Comprovar cada medicament existent contra el nou
        foreach ($existing_drugs as $existing) {
            // Buscar a la taula DRUG_INTERACTIONS
            $stmt = $conn->prepare("
                SELECT severity, description, recommendation
                FROM DRUG_INTERACTIONS
                WHERE 
                    (drug_id_a = :new_drug AND drug_id_b = :existing_drug)
                    OR
                    (drug_id_a = :existing_drug AND drug_id_b = :new_drug)
                LIMIT 1
            ");
            $stmt->execute([
                'new_drug' => $new_drug_id,
                'existing_drug' => $existing['drug_id']
            ]);
            
            $interaction = $stmt->fetch();
            
            // Només afegir si és una interacció significativa (no 'none' ni 'low')
            if ($interaction && 
                strtolower($interaction['severity']) !== 'none' && 
                strtolower($interaction['severity']) !== 'low') {
                $interactions[] = [
                    'existing_drug_name' => $existing['comercial_name'],
                    'existing_drug_principle' => $existing['active_principle'],
                    'severity' => $interaction['severity'],
                    'description' => $interaction['description'],
                    'recommendation' => $interaction['recommendation']
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'has_interactions' => !empty($interactions),
            'interactions' => $interactions,
            'checked_against' => count($existing_drugs) . ' existing medications'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    
    exit();
}

// Si no és una petició API, redirigir
header('Location: dashboard.php');
exit();
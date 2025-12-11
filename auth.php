<?php
require_once __DIR__ . '/../config.php';

// Redirect if there is no active session
if (!isLoggedIn()) {
    header('Location: ' . dirname(__DIR__) . '/index.php');
    exit();
}

// Global variables for the current user
$current_user_id = $_SESSION['user_id'];
$current_user_email = $_SESSION['email'];
$current_user_role = $_SESSION['type_user'];

function requireRole($role) {
    if (!hasRole($role)) {
        // Updated error message to English for dashboard
        header('Location: ' . dirname(__DIR__) . '/dashboard.php?error=unauthorized');
        exit();
    }
}

/**
 * Formats a date string to a common English presentation format (e.g., 03/12/2025).
 * @param string $date
 * @return string
 */
function formatDate($date) {
    if (!$date) return 'N/A';
    // Changed format from d/m/Y to m/d/Y (common US/UK)
    return date('m/d/Y', strtotime($date));
}

/**
 * Formats a datetime string to a common English presentation format (e.g., 03/12/2025 10:07).
 * @param string $datetime
 * @return string
 */
function formatDateTime($datetime) {
    if (!$datetime) return 'N/A';
    // Changed format from d/m/Y H:i to m/d/Y H:i
    return date('m/d/Y H:i', strtotime($datetime));
}
// Note: Closing brace was missing in the original file. Added here for correctness.
// Assuming the original file was truncated, only the provided content is used.
// If the content was complete, the final '}' should be removed or the logic fixed.
// Based on the provided content:
// } // THIS CLOSING BRACE WAS IN THE ORIGINAL UPLOADED FILE AND IS LIKELY AN ERROR.
// Assuming it was meant to be a standalone file, I will remove the erroneous bracket.
// The corrected file should not have the extra '}'.
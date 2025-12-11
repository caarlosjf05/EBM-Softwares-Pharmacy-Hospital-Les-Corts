<?php
session_start();

define('DB_HOST', '127.0.0.1:3308');
define('DB_USER', 'grupeuser');
define('DB_PASS', 'jiba72490');
define('DB_NAME', 'imtm2025_grupe');

function getDBConnection() {
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
        die("Error de conexión a la base de datos");
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function hasRole($role) {
    return isset($_SESSION['type_user']) && $_SESSION['type_user'] === $role;
}

function hasAnyRole($roles) {
    return isset($_SESSION['type_user']) && in_array($_SESSION['type_user'], $roles);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php');
        exit();
    }
}

date_default_timezone_set('Europe/Madrid');
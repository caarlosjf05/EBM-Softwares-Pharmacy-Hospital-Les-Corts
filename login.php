<?php
session_start();
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

$email = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');

if (empty($email) || empty($password)) {
    header('Location: index.php?error=empty');
    exit();
}

try {
    $conn = getDBConnection();

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->bindParam(':email', $email);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        header('Location: index.php?error=invalid');
        exit();
    }

    if (!password_verify($password, $user['password_hash'])) {
        header('Location: index.php?error=invalid');
        exit();
    }

    if ($user['status'] !== 'active') {
        header('Location: index.php?error=inactive');
        exit();
    }

    // Crear sesión
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['type_user'] = $user['type_user'];

    // Actualizar último login
    $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
    $stmt->bindParam(':id', $user['id']);
    $stmt->execute();

    header('Location: dashboard.php');
    exit();

} catch (Exception $e) {
    error_log("Login Error: " . $e->getMessage());
    header('Location: index.php?error=db');
    exit();
}


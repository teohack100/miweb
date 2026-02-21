<?php
/**
 * Sistema de Autenticación - Registro y Login
 */
session_start();
require_once 'config.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$pdo = getConnection();

try {
    switch ($action) {
        // ========================
        // REGISTRO
        // ========================
        case 'registro':
            $nombre = trim($_POST['nombre'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $password2 = $_POST['password2'] ?? '';

            // Validaciones
            if (empty($nombre) || empty($email) || empty($password)) {
                throw new Exception('Todos los campos son requeridos');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Email inválido');
            }

            if (strlen($password) < 6) {
                throw new Exception('La contraseña debe tener al menos 6 caracteres');
            }

            if ($password !== $password2) {
                throw new Exception('Las contraseñas no coinciden');
            }

            // Verificar si el email ya existe
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                throw new Exception('Este email ya está registrado');
            }

            // Crear usuario
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$nombre, $email, $passwordHash]);

            // Iniciar sesión automáticamente
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['user_nombre'] = $nombre;
            $_SESSION['user_email'] = $email;

            echo json_encode(['success' => true, 'message' => 'Registro exitoso']);
            break;

        // ========================
        // LOGIN
        // ========================
        case 'login':
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($email) || empty($password)) {
                throw new Exception('Email y contraseña son requeridos');
            }

            // Buscar usuario
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                throw new Exception('Email o contraseña incorrectos');
            }

            // Iniciar sesión
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nombre'] = $user['nombre'];
            $_SESSION['user_email'] = $user['email'];

            echo json_encode(['success' => true, 'message' => 'Login exitoso', 'nombre' => $user['nombre']]);
            break;

        // ========================
        // LOGOUT
        // ========================
        case 'logout':
            session_destroy();
            echo json_encode(['success' => true, 'message' => 'Sesión cerrada']);
            break;

        // ========================
        // VERIFICAR SESIÓN
        // ========================
        case 'check':
            if (isset($_SESSION['user_id'])) {
                echo json_encode([
                    'loggedIn' => true,
                    'user' => [
                        'id' => $_SESSION['user_id'],
                        'nombre' => $_SESSION['user_nombre'],
                        'email' => $_SESSION['user_email']
                    ]
                ]);
            } else {
                echo json_encode(['loggedIn' => false]);
            }
            break;

        default:
            throw new Exception('Acción no válida');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

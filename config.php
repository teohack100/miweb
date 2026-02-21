<?php
/**
 * Configuración de Base de Datos - Dashboard Streaming
 * Conexión PDO a MySQL
 */

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'maikyram_mikro');
define('DB_USER', 'maikyram_mikro');
define('DB_PASS', 'K]ABIO.[rR5R');
define('DB_CHARSET', 'utf8mb4');

/**
 * Configuración de Veri Pagos API
 * https://veripagos.com/
 */
define('VERIPAGOS_USERNAME', 'programmit');
define('VERIPAGOS_PASSWORD', 'X!MJgck5Q?');
define('VERIPAGOS_SECRET_KEY', '863d987b-1b3d-4bc0-9a86-69d46bd05fe8');

// Tasa de conversión: 1 Bs = X créditos (ajustar según necesidad)
define('CREDITOS_POR_BS', 1.0);

/**
 * Crear conexión PDO
 * @return PDO
 */
function getConnection(): PDO {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    try {
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        file_put_contents('debug_log.txt', "DB Connection Error: " . $e->getMessage() . "\n", FILE_APPEND);
        die("Error de conexión: " . $e->getMessage());
    }
}

/**
 * Obtener todas las plataformas
 * @return array
 */
function getPlataformas(): array {
    $pdo = getConnection();
    $stmt = $pdo->query("SELECT * FROM plataformas ORDER BY nombre");
    return $stmt->fetchAll();
}

/**
 * Obtener todas las cuentas con información de plataforma
 * @return array
 */
function getCuentas(): array {
    $pdo = getConnection();
    $sql = "SELECT c.*, p.nombre as plataforma_nombre, p.imagen_url as plataforma_imagen 
            FROM cuentas c 
            LEFT JOIN plataformas p ON c.plataforma_id = p.id 
            ORDER BY c.id DESC";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

/**
 * Obtener una cuenta por ID
 * @param int $id
 * @return array|false
 */
function getCuentaById(int $id): array|false {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM cuentas WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Obtener créditos de un usuario
 * @param int $userId
 * @return float
 */
function getCreditos(int $userId): float {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT creditos FROM usuarios WHERE id = ?");
    $stmt->execute([$userId]);
    $result = $stmt->fetch();
    return $result ? (float)$result['creditos'] : 0.0;
}

/**
 * Agregar créditos a un usuario
 * @param int $userId
 * @param float $monto
 * @return bool
 */
function agregarCreditos(int $userId, float $monto): bool {
    $pdo = getConnection();
    $stmt = $pdo->prepare("UPDATE usuarios SET creditos = creditos + ? WHERE id = ?");
    return $stmt->execute([$monto, $userId]);
}

/**
 * Descontar créditos de un usuario
 * @param int $userId
 * @param float $monto
 * @return bool
 */
function descontarCreditos(int $userId, float $monto): bool {
    $pdo = getConnection();
    // Verificar que tenga suficientes créditos
    $creditos = getCreditos($userId);
    if ($creditos < $monto) {
        return false;
    }
    $stmt = $pdo->prepare("UPDATE usuarios SET creditos = creditos - ? WHERE id = ?");
    return $stmt->execute([$monto, $userId]);
}

/**
 * Crear una transacción de recarga
 * @param int $userId
 * @param string $movimientoId
 * @param float $monto
 * @param float $creditos
 * @param string $qrBase64
 * @param array $dataExtra
 * @return int|false ID de la transacción o false
 */
function crearTransaccion(int $userId, string $movimientoId, float $monto, float $creditos, string $qrBase64, array $dataExtra = []): int|false {
    $pdo = getConnection();
    $stmt = $pdo->prepare("
        INSERT INTO transacciones (usuario_id, movimiento_id, monto, creditos_agregados, qr_base64, data_extra)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $result = $stmt->execute([
        $userId, 
        $movimientoId, 
        $monto, 
        $creditos, 
        $qrBase64,
        json_encode($dataExtra)
    ]);
    return $result ? (int)$pdo->lastInsertId() : false;
}

/**
 * Obtener transacción por movimiento_id
 * @param string $movimientoId
 * @return array|false
 */
function getTransaccionPorMovimiento(string $movimientoId): array|false {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE movimiento_id = ?");
    $stmt->execute([$movimientoId]);
    return $stmt->fetch();
}

/**
 * Registrar una compra
 * @param int $userId
 * @param int $cuentaId
 * @param float $creditos
 * @return int|false
 */
function registrarCompra(int $userId, int $cuentaId, float $creditos): int|false {
    $pdo = getConnection();
    $stmt = $pdo->prepare("INSERT INTO compras (usuario_id, cuenta_id, creditos_usados) VALUES (?, ?, ?)");
    $result = $stmt->execute([$userId, $cuentaId, $creditos]);
    return $result ? (int)$pdo->lastInsertId() : false;
}

/**
 * Obtener todos los métodos de pago
 * @return array
 */
function getMetodosPago(): array {
    $pdo = getConnection();
    $stmt = $pdo->query("SELECT * FROM payment_methods ORDER BY display_order, id");
    return $stmt->fetchAll();
}

/**
 * Obtener solo métodos de pago activos
 * @param bool $forNewUsers - Si es true, solo retorna los que permiten nuevos usuarios
 * @return array
 */
function getMetodosPagoActivos(bool $forNewUsers = false): array {
    $pdo = getConnection();
    $sql = "SELECT * FROM payment_methods WHERE is_active = 1";
    if ($forNewUsers) {
        $sql .= " AND allow_new_users = 1";
    }
    $sql .= " ORDER BY display_order, id";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

/**
 * Obtener método de pago por ID
 * @param int $id
 * @return array|false
 */
function getMetodoPagoById(int $id): array|false {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM payment_methods WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// ========================================
// FUNCIONES DE PLANES DE MEMBRESÍA
// ========================================

/**
 * Obtener todos los planes de membresía
 * @return array
 */
function getPlanes(): array {
    $pdo = getConnection();
    $stmt = $pdo->query("SELECT * FROM membership_plans ORDER BY display_order, id");
    return $stmt->fetchAll();
}

/**
 * Obtener solo planes activos
 * @return array
 */
function getPlanesActivos(): array {
    $pdo = getConnection();
    $stmt = $pdo->query("SELECT * FROM membership_plans WHERE is_active = 1 ORDER BY display_order, id");
    return $stmt->fetchAll();
}

/**
 * Obtener plan por ID
 * @param int $id
 * @return array|false
 */
function getPlanById(int $id): array|false {
    $pdo = getConnection();
    $stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

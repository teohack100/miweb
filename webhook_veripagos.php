<?php
/**
 * Webhook para recibir notificaciones de Veri Pagos
 * URL: https://tudominio.com/webhook_veripagos.php
 * Método: POST
 * Auth: Basic Auth (mismas credenciales de la API)
 */

require_once 'config.php';
require_once 'veripagos.php';

// Log para debugging (opcional, eliminar en producción)
$logFile = __DIR__ . '/logs/veripagos_webhook.log';

function logWebhook($message) {
    global $logFile;
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

// Verificar que sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    logWebhook("ERROR: Método no permitido: " . $_SERVER['REQUEST_METHOD']);
    exit('Método no permitido');
}

// Crear instancia de VeriPagos
$veriPagos = new VeriPagos();

// Validar autenticación Basic Auth
if (!$veriPagos->validarWebhookAuth()) {
    http_response_code(401);
    logWebhook("ERROR: Autenticación fallida");
    exit('No autorizado');
}

// Obtener datos del body
$rawInput = file_get_contents('php://input');
logWebhook("Datos recibidos: " . $rawInput);

$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    logWebhook("ERROR: JSON inválido");
    exit('JSON inválido');
}

// Procesar datos del webhook
$webhookData = $veriPagos->procesarWebhookData($data);

// Validar que tengamos movimiento_id
if (empty($webhookData['movimiento_id'])) {
    http_response_code(400);
    logWebhook("ERROR: Falta movimiento_id");
    exit('Falta movimiento_id');
}

// Solo procesar si el estado es "Completado"
if ($webhookData['estado'] !== 'Completado') {
    logWebhook("INFO: Estado no es Completado, ignorando: " . $webhookData['estado']);
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'Estado no completado']);
    exit;
}

try {
    $pdo = getConnection();
    $pdo->beginTransaction();
    
    // Buscar la transacción por movimiento_id
    $stmt = $pdo->prepare("SELECT * FROM transacciones WHERE movimiento_id = ? AND estado = 'pendiente'");
    $stmt->execute([$webhookData['movimiento_id']]);
    $transaccion = $stmt->fetch();
    
    if (!$transaccion) {
        $pdo->rollBack();
        logWebhook("ERROR: Transacción no encontrada o ya procesada: " . $webhookData['movimiento_id']);
        http_response_code(404);
        exit('Transacción no encontrada');
    }
    
    // Actualizar transacción a completado
    $stmt = $pdo->prepare("
        UPDATE transacciones 
        SET estado = 'completado',
            remitente_nombre = ?,
            remitente_banco = ?,
            remitente_documento = ?,
            remitente_cuenta = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $webhookData['remitente']['nombre'] ?? null,
        $webhookData['remitente']['banco'] ?? null,
        $webhookData['remitente']['documento'] ?? null,
        $webhookData['remitente']['cuenta'] ?? null,
        $transaccion['id']
    ]);
    
    // Agregar créditos al usuario
    $stmt = $pdo->prepare("UPDATE usuarios SET creditos = creditos + ? WHERE id = ?");
    $stmt->execute([$transaccion['creditos_agregados'], $transaccion['usuario_id']]);
    
    $pdo->commit();
    
    logWebhook("SUCCESS: Pago procesado - Usuario: {$transaccion['usuario_id']}, Créditos: {$transaccion['creditos_agregados']}");
    
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Pago procesado correctamente',
        'usuario_id' => $transaccion['usuario_id'],
        'creditos_agregados' => $transaccion['creditos_agregados']
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    logWebhook("ERROR: Exception - " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error interno']);
}

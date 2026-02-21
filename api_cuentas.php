<?php
/**
 * API de Cuentas - Streaming Store
 * Devuelve todas las cuentas existentes en formato JSON.
 *
 * Autenticación:
 *   Header: X-Api-Key: <tu_api_key>
 *
 * Uso:
 *   GET /api_cuentas.php                     → todas las cuentas
 *   GET /api_cuentas.php?estado=disponible   → filtrar por estado (disponible|vendida|reservada)
 *   GET /api_cuentas.php?tipo=completa       → filtrar por tipo   (completa|perfil)
 *   GET /api_cuentas.php?plataforma=1        → filtrar por plataforma_id
 *   Se pueden combinar: ?estado=disponible&tipo=perfil
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/config.php';

// ── API Key válida ────────────────────────────────────────────────────────────
// Cambia este valor por uno secreto tuyo antes de usar en producción.

define('API_KEY', 'sk_stream_Xp9mK2rLqT7vNzJdWcYeUhBiOaFgDs');

// ── Helpers ──────────────────────────────────────────────────────────────────

function responder(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// ── Autenticación: X-Api-Key header ──────────────────────────────────────────

$apiKeyRecibida = $_SERVER['HTTP_X_API_KEY'] ?? '';

if (!hash_equals(API_KEY, $apiKeyRecibida)) {
    responder(401, [
        'ok'    => false,
        'error' => 'API key inválida o ausente. Envía el header: X-Api-Key: <tu_clave>',
    ]);
}

// ── Solo GET ─────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    responder(405, ['ok' => false, 'error' => 'Método no permitido. Usa GET.']);
}

// ── Filtros opcionales via query string ──────────────────────────────────────

$filtroEstado     = $_GET['estado']     ?? null;
$filtroTipo       = $_GET['tipo']       ?? null;
$filtroPlataforma = $_GET['plataforma'] ?? null;

$estadosValidos = ['disponible', 'vendida', 'reservada'];
$tiposValidos   = ['completa', 'perfil'];

if ($filtroEstado && !in_array($filtroEstado, $estadosValidos)) {
    responder(400, [
        'ok'    => false,
        'error' => 'Valor de "estado" inválido. Usa: ' . implode(', ', $estadosValidos),
    ]);
}

if ($filtroTipo && !in_array($filtroTipo, $tiposValidos)) {
    responder(400, [
        'ok'    => false,
        'error' => 'Valor de "tipo" inválido. Usa: ' . implode(', ', $tiposValidos),
    ]);
}

// ── Consulta ─────────────────────────────────────────────────────────────────

try {
    $pdo = getConnection();

    $sql    = "
        SELECT
            c.id,
            c.plataforma_id,
            p.nombre          AS plataforma,
            p.imagen_url      AS plataforma_imagen,
            c.correo,
            c.password,
            c.precio,
            c.precio_pro,
            c.tipo_cuenta,
            c.perfiles_disponibles,
            c.estado,
            c.oferta,
            c.created_at
        FROM cuentas c
        LEFT JOIN plataformas p ON p.id = c.plataforma_id
        WHERE 1 = 1
    ";
    $params = [];

    if ($filtroEstado) {
        $sql      .= " AND c.estado = ?";
        $params[]  = $filtroEstado;
    }

    if ($filtroTipo) {
        $sql      .= " AND c.tipo_cuenta = ?";
        $params[]  = $filtroTipo;
    }

    if ($filtroPlataforma) {
        $sql      .= " AND c.plataforma_id = ?";
        $params[]  = (int) $filtroPlataforma;
    }

    $sql .= " ORDER BY c.plataforma_id ASC, c.precio ASC, c.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Formatear campos ────────────────────────────────────────────────────

    $cuentas = array_map(function (array $row): array {
        return [
            'id'                  => (int)  $row['id'],
            'plataforma_id'       => (int)  $row['plataforma_id'],
            'plataforma'          => $row['plataforma'],
            'plataforma_imagen'   => $row['plataforma_imagen'],
            'correo'              => $row['correo'],
            'password'            => $row['password'],
            'precio'              => (float) $row['precio'],
            'precio_pro'          => $row['precio_pro'] !== null ? (float) $row['precio_pro'] : null,
            'tipo_cuenta'         => $row['tipo_cuenta'] ?? 'completa',
            'perfiles_disponibles'=> $row['perfiles_disponibles'] !== null ? (int) $row['perfiles_disponibles'] : null,
            'estado'              => $row['estado'],
            'oferta'              => (bool) $row['oferta'],
            'created_at'          => $row['created_at'],
        ];
    }, $rows);

    // ── Estadísticas rápidas ────────────────────────────────────────────────

    $stats = [
        'total'       => count($cuentas),
        'disponibles' => count(array_filter($cuentas, fn($c) => $c['estado'] === 'disponible')),
        'vendidas'    => count(array_filter($cuentas, fn($c) => $c['estado'] === 'vendida')),
        'reservadas'  => count(array_filter($cuentas, fn($c) => $c['estado'] === 'reservada')),
        'completas'   => count(array_filter($cuentas, fn($c) => $c['tipo_cuenta'] === 'completa')),
        'perfiles'    => count(array_filter($cuentas, fn($c) => $c['tipo_cuenta'] === 'perfil')),
    ];

    responder(200, [
        'ok'      => true,
        'stats'   => $stats,
        'filtros' => [
            'estado'     => $filtroEstado,
            'tipo'       => $filtroTipo,
            'plataforma' => $filtroPlataforma ? (int) $filtroPlataforma : null,
        ],
        'cuentas' => $cuentas,
    ]);

} catch (Throwable $e) {
    responder(500, [
        'ok'    => false,
        'error' => 'Error interno: ' . $e->getMessage(),
    ]);
}

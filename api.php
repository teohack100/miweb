<?php
/**
 * API para obtener plataformas y cuentas disponibles
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';

try {
    $plataformas = getPlataformas();
    $cuentas = getCuentas();

    // Agrupar por plataforma y tipo de cuenta
    file_put_contents('debug_log.txt', "Total cuentas: " . count($cuentas) . "\n", FILE_APPEND);
    
    // Filtrar solo cuentas disponibles (case insensitive)
    $cuentasDisponibles = array_filter($cuentas, fn($c) => isset($c['estado']) && strtolower($c['estado']) == 'disponible');
    $cuentasDisponibles = array_values($cuentasDisponibles); // Re-indexar

    // Cuentas marcadas como oferta (para la sección Precios y Ofertas)
    $cuentasOfertas = array_values(array_filter($cuentasDisponibles, fn($c) => !empty($c['oferta'])));

    file_put_contents('debug_log.txt', "Cuentas disponibles: " . count($cuentasDisponibles) . "\n", FILE_APPEND);

    // Agrupar por plataforma y tipo de cuenta
    $catalogo = [];
    foreach ($cuentasDisponibles as $cuenta) {
        $pid = $cuenta['plataforma_id'];
        $tipo = $cuenta['tipo_cuenta'] ?? 'completa';
        $key = $pid . '_' . $tipo;
        
        if (!isset($catalogo[$key])) {
            $catalogo[$key] = [
                'id' => $pid, // Mantenemos el ID de la plataforma para referencias
                'uid' => $key, // ID único para el frontend
                'nombre' => $cuenta['plataforma_nombre'] . ($tipo == 'perfil' ? ' (Perfil)' : ''),
                'plataforma_nombre' => $cuenta['plataforma_nombre'],
                'imagen' => $cuenta['plataforma_imagen'],
                'cantidad' => 0,
                'precio_min' => $cuenta['precio'],
                'tipo_cuenta' => $tipo
            ];
        }
        $catalogo[$key]['cantidad']++;
        if ($cuenta['precio'] < $catalogo[$key]['precio_min']) {
            $catalogo[$key]['precio_min'] = $cuenta['precio'];
        }
    }

    // Cursos (desde JSON creados en admin)
    $cursosPath = __DIR__ . '/cursos.json';
    $cursos = file_exists($cursosPath) ? json_decode(file_get_contents($cursosPath), true) : [];
    $cursos = is_array($cursos) ? array_values(array_filter($cursos, fn($c) => ($c['estado'] ?? '') === 'publicado')) : [];

    $response = [
        'plataformas' => array_values($plataformas),
        'catalogo' => array_values($catalogo),
        'cuentas' => $cuentasDisponibles,
        'cuentasOfertas' => $cuentasOfertas,
        'cursos' => $cursos
    ];

    $json = json_encode($response, JSON_UNESCAPED_UNICODE);
    
    if ($json === false) {
        throw new Exception("Error JSON Encode: " . json_last_error_msg());
    }

    echo $json;

} catch (Exception $e) {
    file_put_contents('debug_log.txt', "API Error: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

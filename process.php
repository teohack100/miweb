<?php
/**
 * Procesamiento de formularios - Dashboard Streaming
 */

session_start();
require_once 'config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$pdo = getConnection();

// Para endpoints AJAX, devolver JSON
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($isAjax || in_array($action, ['iniciar_recarga', 'verificar_pago', 'comprar_cuenta', 'get_creditos'])) {
    header('Content-Type: application/json');
}

try {
    switch ($action) {
        // ========================
        // CONFIGURACIÓN DE MARCA
        // ========================
        case 'guardar_marca':
            $nombre_tienda = trim($_POST['nombre_tienda'] ?? '');
            $color_principal = trim($_POST['color_principal'] ?? '#9333ea');
            
            if (empty($nombre_tienda)) {
                throw new Exception('El nombre de la tienda es requerido');
            }
            
            // Cargar configuración actual
            $configPath = __DIR__ . '/config_marca.json';
            $config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
            
            // Actualizar valores
            $config['nombre_tienda'] = $nombre_tienda;
            $config['color_principal'] = $color_principal;
            
            // Subir logo si se proporcionó
            if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                $fileInfo = pathinfo($_FILES['logo']['name']);
                $extension = strtolower($fileInfo['extension']);
                $allowedTypes = ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'];
                
                if (!in_array($extension, $allowedTypes)) {
                    throw new Exception('Tipo de archivo no permitido');
                }
                
                if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                    throw new Exception('El archivo es muy grande (máximo 2MB)');
                }
                
                $fileName = 'logo_' . time() . '.' . $extension;
                $filePath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $filePath)) {
                    $config['logo_url'] = 'uploads/' . $fileName;
                } else {
                    throw new Exception('Error al subir el archivo');
                }
            }
            
            // Guardar configuración
            file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            header('Location: admin.php?seccion=apariencia&msg=marca_guardada');
            exit;
        
        case 'guardar_apariencia':
            $color_principal = trim($_POST['color_principal'] ?? '#22c55e');
            $fondo_principal = trim($_POST['fondo_principal'] ?? '#0a0a0a');
            $fondo_secundario = trim($_POST['fondo_secundario'] ?? '#1a1a1a');
            $fondo_terciario = trim($_POST['fondo_terciario'] ?? '#2a2a2a');
            $texto_principal = trim($_POST['texto_principal'] ?? '#ffffff');
            $texto_secundario = trim($_POST['texto_secundario'] ?? '#a1a1aa');
            
            // Validar formato hexadecimal
            $colors = [
                'color_principal' => $color_principal,
                'fondo_principal' => $fondo_principal,
                'fondo_secundario' => $fondo_secundario,
                'fondo_terciario' => $fondo_terciario,
                'texto_principal' => $texto_principal,
                'texto_secundario' => $texto_secundario
            ];
            
            foreach ($colors as $key => $color) {
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                    throw new Exception("Formato de color inválido para $key: $color");
                }
            }
            
            // Cargar configuración actual y fusionar con defaults para no perder otros campos
            $configPath = __DIR__ . '/config_marca.json';
            $defaults = [
                'nombre_tienda' => 'Mi Tienda', 'logo_url' => '', 'color_principal' => '#22c55e',
                'favicon_url' => '', 'whatsapp_numero' => '', 'whatsapp_grupo' => '',
                'comunicado_mensaje' => '', 'comunicado_activo' => false,
            ];
            $config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
            if (!is_array($config)) $config = [];
            $config = array_merge($defaults, $config);
            
            // Actualizar valores de colores
            $config['color_principal'] = $color_principal;
            $config['fondo_principal'] = $fondo_principal;
            $config['fondo_secundario'] = $fondo_secundario;
            $config['fondo_terciario'] = $fondo_terciario;
            $config['texto_principal'] = $texto_principal;
            $config['texto_secundario'] = $texto_secundario;
            
            // Mantener otros valores existentes (nombre_tienda, logo_url, etc.)
            
            // Guardar configuración
            file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            header('Location: admin.php?seccion=apariencia&msg=apariencia_guardada');
            exit;
        
        case 'guardar_branding':
            $configPath = __DIR__ . '/config_marca.json';
            $defaults = [
                'nombre_tienda' => 'Mi Tienda', 'logo_url' => '', 'color_principal' => '#22c55e',
                'fondo_principal' => '#0a0a0a', 'fondo_secundario' => '#1a1a1a', 'fondo_terciario' => '#2a2a2a',
                'texto_principal' => '#ffffff', 'texto_secundario' => '#a1a1aa',
            ];
            $config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
            if (!is_array($config)) $config = [];
            $config = array_merge($defaults, $config);
            
            // Logo
            if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $fileInfo = pathinfo($_FILES['logo']['name']);
                $extension = strtolower($fileInfo['extension']);
                $allowedTypes = ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif', 'ico'];
                if (in_array($extension, $allowedTypes) && $_FILES['logo']['size'] <= 2 * 1024 * 1024) {
                    $fileName = 'logo_' . time() . '.' . $extension;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $fileName)) {
                        $config['logo_url'] = 'uploads/' . $fileName;
                    }
                }
            }
            
            // Favicon
            if (isset($_POST['eliminar_favicon']) && (int)$_POST['eliminar_favicon'] === 1) {
                $config['favicon_url'] = '';
            } elseif (!empty($_FILES['favicon']['name']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $fileInfo = pathinfo($_FILES['favicon']['name']);
                $extension = strtolower($fileInfo['extension']);
                $allowedTypes = ['png', 'jpg', 'jpeg', 'ico', 'gif'];
                if (in_array($extension, $allowedTypes) && $_FILES['favicon']['size'] <= 512 * 1024) {
                    $fileName = 'favicon_' . time() . '.' . $extension;
                    if (move_uploaded_file($_FILES['favicon']['tmp_name'], $uploadDir . $fileName)) {
                        $config['favicon_url'] = 'uploads/' . $fileName;
                    }
                }
            }
            
            $config['whatsapp_numero'] = trim($_POST['whatsapp_numero'] ?? '');
            $config['whatsapp_grupo'] = trim($_POST['whatsapp_grupo'] ?? '');
            $config['facebook_url'] = trim($_POST['facebook_url'] ?? '');
            $config['twitter_url'] = trim($_POST['twitter_url'] ?? '');
            $config['instagram_url'] = trim($_POST['instagram_url'] ?? '');
            $config['youtube_url'] = trim($_POST['youtube_url'] ?? '');
            $config['tiktok_url'] = trim($_POST['tiktok_url'] ?? '');
            $config['comunicado_mensaje'] = trim($_POST['comunicado_mensaje'] ?? '');
            $config['comunicado_activo'] = isset($_POST['comunicado_activo']) ? true : false;
            
            file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            header('Location: admin.php?seccion=apariencia&sub=branding&msg=branding_guardado');
            exit;
        
        case 'eliminar_favicon':
            $configPath = __DIR__ . '/config_marca.json';
            $config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
            if (is_array($config)) {
                $config['favicon_url'] = '';
                file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            header('Location: admin.php?seccion=apariencia&sub=branding&msg=favicon_eliminado');
            exit;

        // ========================
        // CURSOS
        // ========================
        case 'guardar_curso':
            $titulo = trim($_POST['titulo'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $precio = (float)($_POST['precio'] ?? 0);
            $videoPreviewUrl = trim($_POST['video_preview_url'] ?? '');
            $estado = in_array($_POST['estado'] ?? '', ['borrador', 'publicado']) ? $_POST['estado'] : 'borrador';

            if ($titulo === '') {
                throw new Exception('El título es requerido');
            }

            $cursosPath = __DIR__ . '/cursos.json';
            $cursos = file_exists($cursosPath) ? json_decode(file_get_contents($cursosPath), true) : [];
            if (!is_array($cursos)) {
                $cursos = [];
            }

            $id = empty($cursos) ? 1 : (max(array_column($cursos, 'id')) + 1);
            $imagenUrl = '';

            if (!empty($_FILES['imagen_curso']['name']) && $_FILES['imagen_curso']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/uploads/cursos/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $fileInfo = pathinfo($_FILES['imagen_curso']['name']);
                $ext = strtolower($fileInfo['extension'] ?? '');
                if (in_array($ext, ['jpg', 'jpeg', 'png']) && $_FILES['imagen_curso']['size'] <= 2 * 1024 * 1024) {
                    $fileName = 'curso_' . $id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['imagen_curso']['tmp_name'], $uploadDir . $fileName)) {
                        $imagenUrl = 'uploads/cursos/' . $fileName;
                    }
                }
            }

            $cursos[] = [
                'id' => $id,
                'titulo' => $titulo,
                'descripcion' => $descripcion,
                'precio' => $precio,
                'imagen_url' => $imagenUrl,
                'video_preview_url' => $videoPreviewUrl,
                'estado' => $estado,
                'modulos' => 0,
                'estudiantes' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ];

            file_put_contents($cursosPath, json_encode($cursos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            header('Location: admin.php?seccion=cursos&msg=curso_creado');
            exit;

        case 'eliminar_curso':
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) {
                header('Location: admin.php?seccion=cursos&error=id_invalido');
                exit;
            }
            $cursosPath = __DIR__ . '/cursos.json';
            $cursos = file_exists($cursosPath) ? json_decode(file_get_contents($cursosPath), true) : [];
            if (!is_array($cursos)) {
                $cursos = [];
            }
            $cursos = array_values(array_filter($cursos, fn($c) => (int)($c['id'] ?? 0) !== $id));
            file_put_contents($cursosPath, json_encode($cursos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            header('Location: admin.php?seccion=cursos&msg=curso_eliminado');
            exit;

        // ========================
        // AJUSTES / MÓDULOS
        // ========================
        case 'guardar_modulos':
            $enabled = isset($_POST['google_enabled']) ? 1 : 0;
            $clientId = trim($_POST['google_client_id'] ?? '');
            $clientSecret = trim($_POST['google_client_secret'] ?? '');
            $authorizedOrigins = trim($_POST['google_authorized_origins'] ?? '');
            $redirectUri = trim($_POST['google_redirect_uri'] ?? '');

            $configPath = __DIR__ . '/config_modulos.json';
            $config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
            if (!is_array($config)) {
                $config = [];
            }

            $config['google_signin'] = [
                'enabled' => $enabled ? true : false,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'authorized_origins' => $authorizedOrigins,
                'redirect_uri' => $redirectUri,
            ];

            file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            header('Location: admin.php?seccion=ajustes&msg=modulos_guardados');
            exit;
        
        // ========================
        // PLATAFORMAS
        // ========================
        case 'guardar_plataforma':
            $nombre = trim($_POST['nombre'] ?? '');
            
            if (empty($nombre)) {
                throw new Exception('El nombre es requerido');
            }
            
            // Subir logo de plataforma
            if (empty($_FILES['logo_plataforma']['name']) || $_FILES['logo_plataforma']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('El logo es requerido');
            }
            
            $uploadDir = __DIR__ . '/uploads/plataformas/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileInfo = pathinfo($_FILES['logo_plataforma']['name']);
            $extension = strtolower($fileInfo['extension']);
            $allowedTypes = ['png', 'jpg', 'jpeg', 'svg', 'webp', 'gif'];
            
            if (!in_array($extension, $allowedTypes)) {
                throw new Exception('Tipo de archivo no permitido');
            }
            
            if ($_FILES['logo_plataforma']['size'] > 2 * 1024 * 1024) {
                throw new Exception('El archivo es muy grande (máximo 2MB)');
            }
            
            $fileName = strtolower(str_replace(' ', '_', $nombre)) . '_' . time() . '.' . $extension;
            $filePath = $uploadDir . $fileName;
            
            if (!move_uploaded_file($_FILES['logo_plataforma']['tmp_name'], $filePath)) {
                throw new Exception('Error al subir el archivo');
            }
            
            $imagen_url = 'uploads/plataformas/' . $fileName;
            
            $stmt = $pdo->prepare("INSERT INTO plataformas (nombre, imagen_url) VALUES (?, ?)");
            $stmt->execute([$nombre, $imagen_url]);
            
            header('Location: admin.php?seccion=cuentas&msg=plataforma_guardada');
            exit;
            
        // ========================
        // CUENTAS
        // ========================
        case 'guardar_cuenta':
            $plataforma_id       = (int)($_POST['plataforma_id'] ?? 0);
            $correo              = trim($_POST['correo'] ?? '');
            $password            = trim($_POST['password'] ?? '');
            $precio              = (float)($_POST['precio'] ?? 0);
            $precio_pro_raw      = trim($_POST['precio_pro'] ?? '');
            $precio_pro          = ($precio_pro_raw !== '' && (float)$precio_pro_raw > 0) ? (float)$precio_pro_raw : null;
            $tipo_cuenta         = $_POST['tipo_cuenta'] ?? 'completa';
            $oferta              = isset($_POST['oferta']) ? 1 : 0;
            $perfiles_disponibles = max(1, (int)($_POST['perfiles_disponibles'] ?? 1));
            // Nuevos campos
            $nombre_servicio     = trim($_POST['nombre_servicio'] ?? '');
            $categoria           = trim($_POST['categoria'] ?? '');
            $tipo_entrega        = in_array($_POST['tipo_entrega'] ?? '', ['automatico','manual']) ? $_POST['tipo_entrega'] : 'automatico';
            $whatsapp_soporte    = trim($_POST['whatsapp_soporte'] ?? '');
            $dias                = max(1, (int)($_POST['dias'] ?? 30));
            $usuario_cuenta      = trim($_POST['usuario_cuenta'] ?? '');
            $pins                = trim($_POST['pins'] ?? '');
            $renovable           = isset($_POST['renovable']) ? (int)$_POST['renovable'] : 1;
            $terminos            = trim($_POST['terminos'] ?? '');
            $descripcion         = trim($_POST['descripcion'] ?? '');
            $imap_habilitado     = isset($_POST['imap_habilitado']) ? 1 : 0;
            $estado              = $_POST['estado'] ?? 'disponible';

            if ($tipo_entrega === 'automatico') {
                if ($plataforma_id <= 0 || empty($correo) || empty($password) || $precio <= 0) {
                    throw new Exception('Los campos Plataforma, Email, Contraseña y Precio son requeridos');
                }
            } else {
                // Manual: solo plataforma y precio son obligatorios
                if ($plataforma_id <= 0 || $precio <= 0) {
                    throw new Exception('Los campos Plataforma y Precio son requeridos');
                }
            }

            $stmt = $pdo->prepare("INSERT INTO cuentas
                (plataforma_id, correo, password, precio, precio_pro, tipo_cuenta, perfiles_disponibles, oferta, estado,
                 nombre_servicio, categoria, tipo_entrega, whatsapp_soporte, dias, usuario_cuenta, pins, renovable, terminos, descripcion, imap_habilitado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $plataforma_id, $correo, $password, $precio, $precio_pro, $tipo_cuenta, $perfiles_disponibles, $oferta, $estado,
                $nombre_servicio, $categoria, $tipo_entrega, $whatsapp_soporte, $dias, $usuario_cuenta, $pins, $renovable, $terminos, $descripcion, $imap_habilitado
            ]);

            $redirectSeccion = trim($_POST['_redirect_seccion'] ?? 'cuentas');
            header('Location: admin.php?seccion=' . $redirectSeccion . '&msg=cuenta_guardada');
            exit;
            
        case 'editar_cuenta':
            $id                  = (int)($_POST['id'] ?? 0);
            $plataforma_id       = (int)($_POST['plataforma_id'] ?? 0);
            $correo              = trim($_POST['correo'] ?? '');
            $password            = trim($_POST['password'] ?? '');
            $precio              = (float)($_POST['precio'] ?? 0);
            $precio_pro_raw      = trim($_POST['precio_pro'] ?? '');
            $precio_pro          = ($precio_pro_raw !== '' && (float)$precio_pro_raw > 0) ? (float)$precio_pro_raw : null;
            $estado              = $_POST['estado'] ?? 'disponible';
            $tipo_cuenta         = $_POST['tipo_cuenta'] ?? 'completa';
            $oferta              = isset($_POST['oferta']) ? 1 : 0;
            $perfiles_disponibles = max(1, (int)($_POST['perfiles_disponibles'] ?? 1));
            // Nuevos campos
            $nombre_servicio     = trim($_POST['nombre_servicio'] ?? '');
            $categoria           = trim($_POST['categoria'] ?? '');
            $tipo_entrega        = in_array($_POST['tipo_entrega'] ?? '', ['automatico','manual']) ? $_POST['tipo_entrega'] : 'automatico';
            $whatsapp_soporte    = trim($_POST['whatsapp_soporte'] ?? '');
            $dias                = max(1, (int)($_POST['dias'] ?? 30));
            $usuario_cuenta      = trim($_POST['usuario_cuenta'] ?? '');
            $pins                = trim($_POST['pins'] ?? '');
            $renovable           = isset($_POST['renovable']) ? (int)$_POST['renovable'] : 1;
            $terminos            = trim($_POST['terminos'] ?? '');
            $descripcion         = trim($_POST['descripcion'] ?? '');
            $imap_habilitado     = isset($_POST['imap_habilitado']) ? 1 : 0;

            if ($id <= 0) {
                throw new Exception('ID de cuenta inválido');
            }

            $stmt = $pdo->prepare("UPDATE cuentas SET
                plataforma_id = ?, correo = ?, password = ?, precio = ?, precio_pro = ?, estado = ?,
                tipo_cuenta = ?, perfiles_disponibles = ?, oferta = ?,
                nombre_servicio = ?, categoria = ?, tipo_entrega = ?, whatsapp_soporte = ?,
                dias = ?, usuario_cuenta = ?, pins = ?, renovable = ?, terminos = ?, descripcion = ?, imap_habilitado = ?
                WHERE id = ?");
            $stmt->execute([
                $plataforma_id, $correo, $password, $precio, $precio_pro, $estado,
                $tipo_cuenta, $perfiles_disponibles, $oferta,
                $nombre_servicio, $categoria, $tipo_entrega, $whatsapp_soporte,
                $dias, $usuario_cuenta, $pins, $renovable, $terminos, $descripcion, $imap_habilitado,
                $id
            ]);

            $redirectSeccion = trim($_POST['_redirect_seccion'] ?? 'cuentas');
            header('Location: admin.php?seccion=' . $redirectSeccion . '&msg=cuenta_actualizada');
            exit;
            
        case 'eliminar_cuenta':
            $id = (int)($_GET['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('ID de cuenta inválido');
            }
            
            $stmt = $pdo->prepare("DELETE FROM cuentas WHERE id = ?");
            $stmt->execute([$id]);
            
            // Volver a la sección de cuentas en el admin
            header('Location: admin.php?seccion=cuentas&msg=cuenta_eliminada');
            exit;

        // ========================
        // PRODUCTOS (Licencias, Cursos, Software-Sistemas)
        // ========================
        case 'guardar_producto':
            $categoria   = trim($_POST['categoria'] ?? '');
            $subp        = trim($_POST['subp'] ?? $categoria);
            $allowed_cats = ['Licencias','Cursos','Software-Sistemas'];
            if (!in_array($categoria, $allowed_cats)) {
                throw new Exception('Categoría de producto inválida');
            }
            $nombre             = trim($_POST['nombre'] ?? '');
            $descripcion        = trim($_POST['descripcion'] ?? '');
            $precio             = (float)($_POST['precio'] ?? 0);
            $precio_pro_raw     = trim($_POST['precio_pro'] ?? '');
            $precio_pro         = ($precio_pro_raw !== '' && (float)$precio_pro_raw > 0) ? (float)$precio_pro_raw : null;
            $stock              = max(0, (int)($_POST['stock'] ?? 0));
            $estado             = in_array($_POST['estado'] ?? '', ['disponible','agotado']) ? $_POST['estado'] : 'disponible';
            $tipo_entrega       = in_array($_POST['tipo_entrega'] ?? '', ['automatico','manual']) ? $_POST['tipo_entrega'] : 'manual';
            $contenido_entrega  = trim($_POST['contenido_entrega'] ?? '');
            $whatsapp_soporte   = trim($_POST['whatsapp_soporte'] ?? '');
            $terminos           = trim($_POST['terminos'] ?? '');
            $oferta             = isset($_POST['oferta']) ? 1 : 0;

            if (empty($nombre) || $precio <= 0) {
                throw new Exception('El nombre y el precio son requeridos');
            }

            $stmt = $pdo->prepare("INSERT INTO productos
                (categoria, nombre, descripcion, precio, precio_pro, stock, estado, tipo_entrega, contenido_entrega, whatsapp_soporte, terminos, oferta)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$categoria, $nombre, $descripcion, $precio, $precio_pro, $stock, $estado, $tipo_entrega, $contenido_entrega, $whatsapp_soporte, $terminos, $oferta]);

            header('Location: admin.php?seccion=productos&subp=' . urlencode($subp) . '&msg=producto_guardado');
            exit;

        case 'editar_producto':
            $id          = (int)($_POST['id'] ?? 0);
            $subp        = trim($_POST['subp'] ?? '');
            if ($id <= 0) throw new Exception('ID de producto inválido');

            $nombre             = trim($_POST['nombre'] ?? '');
            $descripcion        = trim($_POST['descripcion'] ?? '');
            $precio             = (float)($_POST['precio'] ?? 0);
            $precio_pro_raw     = trim($_POST['precio_pro'] ?? '');
            $precio_pro         = ($precio_pro_raw !== '' && (float)$precio_pro_raw > 0) ? (float)$precio_pro_raw : null;
            $stock              = max(0, (int)($_POST['stock'] ?? 0));
            $estado             = in_array($_POST['estado'] ?? '', ['disponible','agotado']) ? $_POST['estado'] : 'disponible';
            $tipo_entrega       = in_array($_POST['tipo_entrega'] ?? '', ['automatico','manual']) ? $_POST['tipo_entrega'] : 'manual';
            $contenido_entrega  = trim($_POST['contenido_entrega'] ?? '');
            $whatsapp_soporte   = trim($_POST['whatsapp_soporte'] ?? '');
            $terminos           = trim($_POST['terminos'] ?? '');
            $oferta             = isset($_POST['oferta']) ? 1 : 0;

            if (empty($nombre) || $precio <= 0) {
                throw new Exception('El nombre y el precio son requeridos');
            }

            $stmt = $pdo->prepare("UPDATE productos SET
                nombre = ?, descripcion = ?, precio = ?, precio_pro = ?, stock = ?,
                estado = ?, tipo_entrega = ?, contenido_entrega = ?, whatsapp_soporte = ?,
                terminos = ?, oferta = ?
                WHERE id = ?");
            $stmt->execute([$nombre, $descripcion, $precio, $precio_pro, $stock, $estado, $tipo_entrega, $contenido_entrega, $whatsapp_soporte, $terminos, $oferta, $id]);

            header('Location: admin.php?seccion=productos&subp=' . urlencode($subp) . '&msg=producto_actualizado');
            exit;

        case 'eliminar_producto':
            $id   = (int)($_GET['id'] ?? 0);
            $subp = trim($_GET['subp'] ?? 'Licencias');
            if ($id <= 0) throw new Exception('ID de producto inválido');

            $stmt = $pdo->prepare("DELETE FROM productos WHERE id = ?");
            $stmt->execute([$id]);

            header('Location: admin.php?seccion=productos&subp=' . urlencode($subp) . '&msg=producto_eliminado');
            exit;
            
        case 'cambiar_estado':
            $id = (int)($_POST['id'] ?? 0);
            $estado = $_POST['estado'] ?? 'disponible';
            
            $stmt = $pdo->prepare("UPDATE cuentas SET estado = ? WHERE id = ?");
            $stmt->execute([$estado, $id]);
            
            // Volver a la sección de cuentas en el admin
            header('Location: admin.php?seccion=cuentas&msg=estado_cambiado');
            exit;
            
        // ========================
        // SALDO
        // ========================
        case 'agregar_saldo':
            $monto = (float)($_POST['monto'] ?? 0);
            $descripcion = trim($_POST['descripcion'] ?? '');
            $tipo = $_POST['tipo'] ?? 'ingreso';
            
            if ($monto <= 0) {
                throw new Exception('El monto debe ser mayor a 0');
            }
            
            $stmt = $pdo->prepare("INSERT INTO saldo (monto, descripcion, tipo) VALUES (?, ?, ?)");
            $stmt->execute([$monto, $descripcion, $tipo]);
            
            header('Location: admin.php?seccion=saldo&msg=saldo_agregado');
            exit;
        
        // ========================
        // AGREGAR CRÉDITOS A USUARIOS (ADMIN)
        // ========================
        case 'agregar_creditos_admin':
            $usuario_id = (int)($_POST['usuario_id'] ?? 0);
            $monto = (float)($_POST['monto'] ?? 0);
            $motivo = trim($_POST['motivo'] ?? 'Agregado por administrador');
            
            if ($usuario_id <= 0) {
                throw new Exception('Usuario inválido');
            }
            
            if ($monto <= 0) {
                throw new Exception('El monto debe ser mayor a 0');
            }
            
            // Agregar créditos al usuario
            $stmt = $pdo->prepare("UPDATE usuarios SET creditos = creditos + ? WHERE id = ?");
            $stmt->execute([$monto, $usuario_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Usuario no encontrado');
            }
            
            // Registrar en transacciones (opcional, para historial)
            $stmt = $pdo->prepare("INSERT INTO transacciones (usuario_id, movimiento_id, monto, creditos_agregados, estado, data_extra) VALUES (?, ?, ?, ?, 'completado', ?)");
            $movimientoId = 'ADMIN_' . time() . '_' . $usuario_id;
            $stmt->execute([$usuario_id, $movimientoId, $monto, $monto, json_encode(['tipo' => 'manual', 'motivo' => $motivo])]);
            
            header('Location: admin.php?seccion=usuarios&msg=creditos_agregados');
            exit;
        
        // ========================
        // MÉTODOS DE PAGO
        // ========================
        case 'guardar_metodo_pago':
            $method_key = trim($_POST['method_key'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $min_amount = (float)($_POST['min_amount'] ?? 1.00);
            $max_amount = (float)($_POST['max_amount'] ?? 1000.00);
            $exchange_rate = (float)($_POST['exchange_rate'] ?? 6.96);
            $allow_new_users = isset($_POST['allow_new_users']) ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($method_key) || empty($name)) {
                throw new Exception('El identificador y nombre son requeridos');
            }
            
            $stmt = $pdo->prepare("INSERT INTO payment_methods (method_key, name, description, min_amount, max_amount, exchange_rate, allow_new_users, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$method_key, $name, $description, $min_amount, $max_amount, $exchange_rate, $allow_new_users, $is_active]);
            
            header('Location: admin.php?seccion=metodos_pago&msg=metodo_guardado');
            exit;
            
        case 'editar_metodo_pago':
            $id = (int)($_POST['id'] ?? 0);
            $method_key = trim($_POST['method_key'] ?? '');
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $min_amount = (float)($_POST['min_amount'] ?? 1.00);
            $max_amount = (float)($_POST['max_amount'] ?? 1000.00);
            $exchange_rate = (float)($_POST['exchange_rate'] ?? 6.96);
            $allow_new_users = isset($_POST['allow_new_users']) ? 1 : 0;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if ($id <= 0) {
                throw new Exception('ID de método inválido');
            }
            
            $stmt = $pdo->prepare("UPDATE payment_methods SET method_key = ?, name = ?, description = ?, min_amount = ?, max_amount = ?, exchange_rate = ?, allow_new_users = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$method_key, $name, $description, $min_amount, $max_amount, $exchange_rate, $allow_new_users, $is_active, $id]);
            
            header('Location: admin.php?seccion=metodos_pago&msg=metodo_actualizado');
            exit;
            
        case 'eliminar_metodo_pago':
            $id = (int)($_GET['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('ID de método inválido');
            }
            
            $stmt = $pdo->prepare("DELETE FROM payment_methods WHERE id = ?");
            $stmt->execute([$id]);
            
            header('Location: admin.php?seccion=metodos_pago&msg=metodo_eliminado');
            exit;
            
        case 'toggle_metodo_pago':
            $id = (int)($_POST['id'] ?? 0);
            $field = $_POST['field'] ?? 'is_active';
            
            if ($id <= 0 || !in_array($field, ['is_active', 'allow_new_users'])) {
                throw new Exception('Parámetros inválidos');
            }
            
            $stmt = $pdo->prepare("UPDATE payment_methods SET {$field} = NOT {$field} WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($isAjax) {
                echo json_encode(['success' => true]);
                exit;
            }
            
            header('Location: admin.php?seccion=metodos_pago&msg=metodo_actualizado');
            exit;
        
        // ========================
        // PLANES DE MEMBRESÍA
        // ========================
        case 'comprar_membresia':
            if (!isset($_SESSION['user_id'])) {
                header('Location: store.php?error=no_sesion');
                exit;
            }
            $planId  = (int)($_POST['plan_id'] ?? 0);
            $userId  = (int)$_SESSION['user_id'];

            if ($planId <= 0) {
                throw new Exception('Plan inválido');
            }

            // Obtener plan
            $stmtPlan = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ? AND is_active = 1");
            $stmtPlan->execute([$planId]);
            $plan = $stmtPlan->fetch();
            if (!$plan) {
                throw new Exception('El plan no existe o no está disponible');
            }

            // Verificar créditos del usuario
            $stmtCred = $pdo->prepare("SELECT creditos FROM usuarios WHERE id = ?");
            $stmtCred->execute([$userId]);
            $credRow = $stmtCred->fetch();
            $creditos = $credRow ? (float)$credRow['creditos'] : 0;
            $precio   = (float)$plan['price'];

            if ($precio > 0 && $creditos < $precio) {
                header('Location: store.php?seccion=afiliados&error=creditos_insuficientes');
                exit;
            }

            // Buscar membresía activa actual para acumular días restantes
            $stmtActual = $pdo->prepare("
                SELECT fecha_inicio FROM usuario_membresias
                WHERE usuario_id = ? AND estado = 'activa'
                ORDER BY fecha_inicio DESC LIMIT 1
            ");
            $stmtActual->execute([$userId]);
            $memActual = $stmtActual->fetch();

            // Calcular la fecha de inicio de la nueva membresía:
            // Si hay una activa, la nueva arranca desde su fecha de vencimiento (acumula días)
            // Si no hay ninguna activa, arranca desde hoy
            if ($memActual) {
                $fechaVenceActual = (new DateTime($memActual['fecha_inicio']))->modify('+30 days');
                $hoy = new DateTime('today');
                if ($fechaVenceActual <= $hoy) {
                    // Ya venció — arranca desde hoy
                    $nuevaFechaInicio = $hoy->format('Y-m-d');
                } else {
                    $nuevaFechaInicio = $fechaVenceActual->format('Y-m-d');
                }
            } else {
                $nuevaFechaInicio = (new DateTime('today'))->format('Y-m-d');
            }

            // Cancelar membresía activa anterior
            $pdo->prepare("UPDATE usuario_membresias SET estado = 'cancelada' WHERE usuario_id = ? AND estado = 'activa'")
                ->execute([$userId]);

            // Descontar créditos si el plan tiene precio
            if ($precio > 0) {
                $pdo->prepare("UPDATE usuarios SET creditos = creditos - ? WHERE id = ?")
                    ->execute([$precio, $userId]);
            }

            // Registrar la nueva membresía con la fecha de inicio acumulada
            $stmtIns = $pdo->prepare("
                INSERT INTO usuario_membresias (usuario_id, plan_id, estado, creditos_usados, fecha_inicio)
                VALUES (?, ?, 'activa', ?, ?)
            ");
            $stmtIns->execute([$userId, $planId, $precio, $nuevaFechaInicio]);

            header('Location: store.php?seccion=afiliados&msg=membresia_activada');
            exit;

        case 'guardar_plan':
            $name = trim($_POST['name'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $points = (int)($_POST['points'] ?? 0);
            $descuento = max(0, min(100, (float)($_POST['descuento'] ?? 0)));
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $is_featured = isset($_POST['is_featured']) ? 1 : 0;
            
            if (empty($name)) {
                throw new Exception('El nombre del plan es requerido');
            }
            
            $stmt = $pdo->prepare("INSERT INTO membership_plans (name, price, points, descuento, description, is_active, is_featured) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $price, $points, $descuento, $description, $is_active, $is_featured]);
            
            header('Location: admin.php?seccion=membresias&msg=plan_guardado');
            exit;
            
        case 'editar_plan':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $price = (float)($_POST['price'] ?? 0);
            $points = (int)($_POST['points'] ?? 0);
            $descuento = max(0, min(100, (float)($_POST['descuento'] ?? 0)));
            $description = trim($_POST['description'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $is_featured = isset($_POST['is_featured']) ? 1 : 0;
            
            if ($id <= 0) {
                throw new Exception('ID de plan inválido');
            }
            
            $stmt = $pdo->prepare("UPDATE membership_plans SET name = ?, price = ?, points = ?, descuento = ?, description = ?, is_active = ?, is_featured = ? WHERE id = ?");
            $stmt->execute([$name, $price, $points, $descuento, $description, $is_active, $is_featured, $id]);
            
            header('Location: admin.php?seccion=membresias&msg=plan_actualizado');
            exit;
            
        case 'eliminar_plan':
            $id = (int)($_GET['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('ID de plan inválido');
            }
            
            $stmt = $pdo->prepare("DELETE FROM membership_plans WHERE id = ?");
            $stmt->execute([$id]);
            
            header('Location: admin.php?seccion=membresias&msg=plan_eliminado');
            exit;
            
        case 'toggle_plan':
            $id = (int)($_POST['id'] ?? 0);
            
            if ($id <= 0) {
                throw new Exception('ID inválido');
            }
            
            $stmt = $pdo->prepare("UPDATE membership_plans SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($isAjax) {
                echo json_encode(['success' => true]);
                exit;
            }
            
            header('Location: admin.php?seccion=membresias&msg=plan_actualizado');
            exit;
        
        // ========================
        // VERI PAGOS - RECARGAS
        // ========================
        case 'iniciar_recarga':
            // Verificar sesión
            if (!isset($_SESSION['user_id'])) {
                throw new Exception('Debes iniciar sesión');
            }
            
            $monto = (float)($_POST['monto'] ?? 0);
            
            if ($monto <= 0) {
                throw new Exception('El monto debe ser mayor a 0');
            }
            
            require_once 'veripagos.php';
            $veriPagos = new VeriPagos();
            
            // Datos extra para identificar la transacción en el webhook
            $dataExtra = [
                'usuario_id' => $_SESSION['user_id'],
                'tipo' => 'recarga_creditos'
            ];
            
            $methodKey = $_POST['method_key'] ?? 'veripagos';
            
            // Obtener tasa de cambio del método seleccionado
            $stmt = $pdo->prepare("SELECT exchange_rate FROM payment_methods WHERE method_key = ?");
            $stmt->execute([$methodKey]);
            $methodData = $stmt->fetch();
            $exchangeRate = $methodData ? (float)$methodData['exchange_rate'] : 6.96;
            
            // El monto recibido es en USD (según el input del usuario)
            $montoUsd = $monto;
            
            // Convertir a Bolivianos para la pasarela
            $montoBs = $montoUsd * $exchangeRate;
            
            // Calcular créditos (1 Crédito = 1 USD)
            $creditos = $montoUsd; 
            
            // Generar QR con el monto en BOLIVIANOS
            $result = $veriPagos->generarQR(
                $montoBs,
                $dataExtra,
                "0/00:15", // 15 minutos de vigencia
                true, // uso único
                "Recarga de créditos - {$_SESSION['user_nombre']}"
            );
            
            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'Error al generar QR');
            }
            
            // Guardar transacción en BD
            // Guardamos el monto en USD como base, y en dataExtra el detalle de conversión
            $dataExtra['exchange_rate'] = $exchangeRate;
            $dataExtra['monto_bs'] = $montoBs;
            $dataExtra['monto_usd'] = $montoUsd;
            
            $transaccionId = crearTransaccion(
                $_SESSION['user_id'],
                $result['data']['movimiento_id'],
                $montoUsd, // Guardamos USD en la tabla principal
                $creditos,
                $result['data']['qr'],
                $dataExtra
            );
            
            if (!$transaccionId) {
                throw new Exception('Error al guardar transacción');
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'transaccion_id' => $transaccionId,
                    'movimiento_id' => $result['data']['movimiento_id'],
                    'qr_image' => $result['data']['qr_image'],
                    'monto_bs' => $montoBs,
                    'monto_usd' => $montoUsd,
                    'exchange_rate' => $exchangeRate,
                    'creditos' => $creditos
                ]
            ]);
            exit;
        
        case 'verificar_pago':
            if (!isset($_SESSION['user_id'])) {
                throw new Exception('Debes iniciar sesión');
            }
            
            $movimientoId = $_POST['movimiento_id'] ?? '';
            
            if (empty($movimientoId)) {
                throw new Exception('ID de movimiento requerido');
            }
            
            // Primero verificar en nuestra BD
            $transaccion = getTransaccionPorMovimiento($movimientoId);
            
            if (!$transaccion) {
                throw new Exception('Transacción no encontrada');
            }
            
            // Si ya está completada, devolver éxito
            if ($transaccion['estado'] === 'completado') {
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'estado' => 'completado',
                        'creditos' => $transaccion['creditos_agregados'],
                        'creditos_actuales' => getCreditos($_SESSION['user_id'])
                    ]
                ]);
                exit;
            }
            
            // Si está pendiente, consultar a Veri Pagos
            require_once 'veripagos.php';
            $veriPagos = new VeriPagos();
            $result = $veriPagos->verificarEstado($movimientoId);
            
            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'Error al verificar pago');
            }
            
            $estado = strtolower($result['data']['estado']);
            
            // Si Veri Pagos dice que está completado, actualizar manualmente
            // (normalmente el webhook lo haría, pero por si acaso)
            if ($estado === 'completado' && $transaccion['estado'] === 'pendiente') {
                $stmt = $pdo->prepare("UPDATE transacciones SET estado = 'completado' WHERE id = ?");
                $stmt->execute([$transaccion['id']]);
                
                agregarCreditos($_SESSION['user_id'], $transaccion['creditos_agregados']);
            }
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'estado' => $estado,
                    'creditos' => $transaccion['creditos_agregados'],
                    'creditos_actuales' => getCreditos($_SESSION['user_id'])
                ]
            ]);
            exit;
        
        case 'get_creditos':
            if (!isset($_SESSION['user_id'])) {
                throw new Exception('Debes iniciar sesión');
            }
            
            echo json_encode([
                'success' => true,
                'creditos' => getCreditos($_SESSION['user_id'])
            ]);
            exit;
        
        // ========================
        // COMPRAS CON CRÉDITOS
        // ========================
        case 'comprar_cuenta':
            if (!isset($_SESSION['user_id'])) {
                throw new Exception('Debes iniciar sesión');
            }
            
            $cuentaId = (int)($_POST['cuenta_id'] ?? 0);
            $userId = $_SESSION['user_id'];
            $puntosACanjear = max(0, (int)($_POST['puntos_canjear'] ?? 0));
            // Los puntos deben ser múltiplo de 20
            $puntosACanjear = (int)(floor($puntosACanjear / 20) * 20);
            
            if ($cuentaId <= 0) {
                throw new Exception('ID de cuenta inválido');
            }
            
            // Obtener cuenta
            $stmt = $pdo->prepare("SELECT c.*, p.nombre as plataforma_nombre FROM cuentas c LEFT JOIN plataformas p ON c.plataforma_id = p.id WHERE c.id = ? AND c.estado = 'disponible'");
            $stmt->execute([$cuentaId]);
            $cuenta = $stmt->fetch();
            
            if (!$cuenta) {
                throw new Exception('Cuenta no disponible');
            }
            
            // Obtener créditos y puntos actuales del usuario
            $stmt = $pdo->prepare("SELECT creditos, puntos_recompensa FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch();
            $creditosUsuario = $userData ? (float)$userData['creditos'] : 0;
            $puntosDisponibles = $userData ? (int)$userData['puntos_recompensa'] : 0;

            // Verificar si el usuario tiene membresía activa con descuento
            $descuentoMembresia = 0.0;
            $stmtMemDesc = $pdo->prepare("
                SELECT mp.descuento
                FROM usuario_membresias um
                JOIN membership_plans mp ON um.plan_id = mp.id
                WHERE um.usuario_id = ? AND um.estado = 'activa'
                  AND DATE_ADD(um.fecha_inicio, INTERVAL 30 DAY) > CURDATE()
                ORDER BY um.fecha_inicio DESC
                LIMIT 1
            ");
            $stmtMemDesc->execute([$userId]);
            $memDescRow = $stmtMemDesc->fetch();
            if ($memDescRow && (float)$memDescRow['descuento'] > 0) {
                $descuentoMembresia = (float)$memDescRow['descuento'];
            }

            // Precio base con descuento de membresía aplicado
            $precioBase = (float)$cuenta['precio'];
            if ($descuentoMembresia > 0) {
                $precioBase = round($precioBase * (1 - $descuentoMembresia / 100), 2);
            }

            // Validar puntos a canjear (sobre el precio ya descontado)
            if ($puntosACanjear > $puntosDisponibles) {
                throw new Exception('No tienes suficientes puntos para canjear');
            }
            $descuentoPuntos = $puntosACanjear / 20; // 20 pts = 1 Bs
            $precioFinal = max(0, $precioBase - $descuentoPuntos);
            
            if ($creditosUsuario < $precioFinal) {
                throw new Exception('Créditos insuficientes. Tienes: ' . number_format($creditosUsuario, 2) . ', necesitas: ' . number_format($precioFinal, 2));
            }
            
            // Descontar créditos (precio final tras descuento)
            $stmt = $pdo->prepare("UPDATE usuarios SET creditos = creditos - ?, puntos_recompensa = puntos_recompensa - ? WHERE id = ? AND creditos >= ? AND puntos_recompensa >= ?");
            $stmt->execute([$precioFinal, $puntosACanjear, $userId, $precioFinal, $puntosACanjear]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Error al descontar créditos o puntos');
            }
            
            // Marcar cuenta como vendida o restar un perfil según tipo
            if ($cuenta['tipo_cuenta'] === 'perfil') {
                $perfilesActuales = (int)$cuenta['perfiles_disponibles'];
                if ($perfilesActuales <= 1) {
                    $stmt = $pdo->prepare("UPDATE cuentas SET perfiles_disponibles = 0, estado = 'vendida' WHERE id = ? AND estado = 'disponible'");
                    $stmt->execute([$cuentaId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE cuentas SET perfiles_disponibles = perfiles_disponibles - 1 WHERE id = ? AND estado = 'disponible'");
                    $stmt->execute([$cuentaId]);
                }
            } else {
                $stmt = $pdo->prepare("UPDATE cuentas SET estado = 'vendida' WHERE id = ? AND estado = 'disponible'");
                $stmt->execute([$cuentaId]);
            }

            if ($stmt->rowCount() === 0) {
                // Revertir créditos y puntos si la cuenta ya no está disponible
                $pdo->prepare("UPDATE usuarios SET creditos = creditos + ?, puntos_recompensa = puntos_recompensa + ? WHERE id = ?")
                    ->execute([$precioFinal, $puntosACanjear, $userId]);
                throw new Exception('La cuenta ya no está disponible');
            }
            
            // Registrar compra (guardamos el precio real pagado)
            $stmt = $pdo->prepare("INSERT INTO compras (usuario_id, cuenta_id, creditos_usados) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $cuentaId, $precioFinal]);
            
            // Obtener saldos restantes
            $stmt = $pdo->prepare("SELECT creditos, puntos_recompensa FROM usuarios WHERE id = ?");
            $stmt->execute([$userId]);
            $newData = $stmt->fetch();
            
            echo json_encode([
                'success' => true,
                'data' => [
                    'plataforma' => $cuenta['plataforma_nombre'],
                    'correo' => $cuenta['correo'],
                    'password' => $cuenta['password'],
                    'creditos_restantes' => (float)$newData['creditos'],
                    'puntos_restantes' => (int)$newData['puntos_recompensa'],
                    'descuento_membresia' => $descuentoMembresia,
                    'precio_original' => (float)$cuenta['precio'],
                    'precio_pagado' => $precioFinal
                ],
                'message' => '¡Compra exitosa!'
            ]);
            exit;

        // ========================
        // PUNTOS DE RECOMPENSA
        // ========================
        case 'reclamar_puntos':
            header('Content-Type: application/json');
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'error' => 'No autenticado']);
                exit;
            }
            $userId = (int)$_SESSION['user_id'];
            $hoy = date('Y-m-d');

            // Verificar que el usuario tiene membresía activa
            $stmtMem = $pdo->prepare("
                SELECT um.id, um.ultima_reclamacion, mp.points
                FROM usuario_membresias um
                JOIN membership_plans mp ON um.plan_id = mp.id
                WHERE um.usuario_id = ? AND um.estado = 'activa'
                ORDER BY um.fecha_inicio DESC LIMIT 1
            ");
            $stmtMem->execute([$userId]);
            $mem = $stmtMem->fetch();

            if (!$mem) {
                echo json_encode(['success' => false, 'error' => 'No tienes una membresía activa']);
                exit;
            }

            $ptsPerDay = (int)$mem['points'];
            if ($ptsPerDay <= 0) {
                echo json_encode(['success' => false, 'error' => 'Tu plan no otorga puntos diarios']);
                exit;
            }

            // Verificar si ya reclamó hoy
            if ($mem['ultima_reclamacion'] === $hoy) {
                echo json_encode(['success' => false, 'error' => 'ya_reclamado']);
                exit;
            }

            // Añadir puntos al usuario y actualizar última reclamación
            $pdo->prepare("UPDATE usuarios SET puntos_recompensa = puntos_recompensa + ? WHERE id = ?")
                ->execute([$ptsPerDay, $userId]);
            $pdo->prepare("UPDATE usuario_membresias SET ultima_reclamacion = ? WHERE id = ?")
                ->execute([$hoy, $mem['id']]);

            // Devolver nuevo saldo de puntos
            $stmtPts = $pdo->prepare("SELECT puntos_recompensa FROM usuarios WHERE id = ?");
            $stmtPts->execute([$userId]);
            $nuevoPts = (int)$stmtPts->fetchColumn();

            echo json_encode([
                'success' => true,
                'puntos_ganados' => $ptsPerDay,
                'puntos_total' => $nuevoPts
            ]);
            exit;

        default:
            header('Location: index.php');
            exit;
    }
} catch (Exception $e) {
    if ($isAjax || in_array($action, ['iniciar_recarga', 'verificar_pago', 'comprar_cuenta', 'get_creditos'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    header('Location: index.php?error=' . urlencode($e->getMessage()));
    exit;
}


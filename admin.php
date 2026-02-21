<?php
/**
 * Dashboard Streaming - Panel de Administración
 * Diseño basado en especificaciones del cliente
 */
require_once 'config.php';

$pdo = getConnection();

// Crear tabla usuario_membresias si no existe (sin FK para evitar errores si membership_plans no existe aún)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuario_membresias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            plan_id INT NOT NULL,
            fecha_inicio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            fecha_fin TIMESTAMP NULL DEFAULT NULL,
            estado ENUM('activa','expirada','cancelada') DEFAULT 'activa',
            creditos_usados DECIMAL(10,2) NOT NULL DEFAULT 0.00
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {
    // Ignorar si falla — la tabla probablemente ya existe
}

// Añadir columnas nuevas si no existen (puntos de recompensa y control de reclamo diario)
try { $pdo->exec("ALTER TABLE usuarios ADD COLUMN puntos_recompensa INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE usuario_membresias ADD COLUMN ultima_reclamacion DATE NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE membership_plans ADD COLUMN descuento DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Porcentaje de descuento en cuentas (0-100)'"); } catch (Exception $e) {}
// Nuevas columnas en cuentas
try { $pdo->exec("ALTER TABLE cuentas ADD COLUMN nombre_servicio VARCHAR(100) NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cuentas ADD COLUMN categoria VARCHAR(100) NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cuentas ADD COLUMN tipo_entrega ENUM('automatico','manual') NOT NULL DEFAULT 'automatico'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cuentas ADD COLUMN whatsapp_soporte VARCHAR(30) NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cuentas ADD COLUMN dias INT NOT NULL DEFAULT 30"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cuentas ADD COLUMN usuario_cuenta VARCHAR(255) NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cuentas ADD COLUMN pins TEXT NULL DEFAULT NULL COMMENT 'PINs separados por coma'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cuentas ADD COLUMN renovable TINYINT(1) NOT NULL DEFAULT 1"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cuentas ADD COLUMN terminos TEXT NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cuentas ADD COLUMN descripcion TEXT NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE cuentas ADD COLUMN imap_habilitado TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}

// Tabla productos (Licencias, Cursos, Software-Sistemas)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS productos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            categoria ENUM('Licencias','Cursos','Software-Sistemas') NOT NULL,
            nombre VARCHAR(150) NOT NULL,
            descripcion TEXT NULL,
            precio DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            precio_pro DECIMAL(10,2) NULL DEFAULT NULL,
            stock INT NOT NULL DEFAULT 0,
            estado ENUM('disponible','agotado') NOT NULL DEFAULT 'disponible',
            tipo_entrega ENUM('automatico','manual') NOT NULL DEFAULT 'manual',
            contenido_entrega TEXT NULL COMMENT 'Clave/link/instrucciones que se entregan al comprar',
            whatsapp_soporte VARCHAR(30) NULL,
            terminos TEXT NULL,
            oferta TINYINT(1) NOT NULL DEFAULT 0,
            creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Exception $e) {}

// Obtener datos existentes
$plataformas = getPlataformas();
$cuentas = getCuentas();

// Estadísticas de cuentas
$cuentasDisponibles = count(array_filter($cuentas, fn($c) => $c['estado'] == 'disponible'));
$cuentasVendidas = count(array_filter($cuentas, fn($c) => $c['estado'] == 'vendida'));

// Obtener usuarios (con total de compras y membresía activa)
$stmtUsuarios = $pdo->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM compras WHERE usuario_id = u.id) as total_compras,
           (SELECT mp.name FROM usuario_membresias um
            JOIN membership_plans mp ON um.plan_id = mp.id
            WHERE um.usuario_id = u.id AND um.estado = 'activa'
            ORDER BY um.fecha_inicio DESC LIMIT 1) as membresia_nombre,
           (SELECT um2.fecha_inicio FROM usuario_membresias um2
            WHERE um2.usuario_id = u.id AND um2.estado = 'activa'
            ORDER BY um2.fecha_inicio DESC LIMIT 1) as membresia_fecha
    FROM usuarios u
    ORDER BY u.creditos DESC
");
$usuarios = $stmtUsuarios->fetchAll();

// Obtener compras/ventas
$stmtCompras = $pdo->query("
    SELECT c.*, u.nombre as usuario_nombre, u.email as usuario_email, cu.correo as cuenta_correo, p.nombre as plataforma_nombre, p.imagen_url as plataforma_imagen
    FROM compras c
    LEFT JOIN usuarios u ON c.usuario_id = u.id
    LEFT JOIN cuentas cu ON c.cuenta_id = cu.id
    LEFT JOIN plataformas p ON cu.plataforma_id = p.id
    ORDER BY c.created_at DESC LIMIT 100
");
$compras = $stmtCompras->fetchAll();
$totalVentas = count($compras);
$totalIngresos = array_sum(array_column($compras, 'creditos_usados'));

// Obtener recargas
$stmtRecargas = $pdo->query("SELECT t.*, u.nombre as usuario_nombre, u.email as usuario_email FROM transacciones t LEFT JOIN usuarios u ON t.usuario_id = u.id ORDER BY t.created_at DESC LIMIT 100");
$recargas = $stmtRecargas->fetchAll();

// Obtener métodos de pago
try {
    $metodosPago = getMetodosPago();
} catch (Exception $e) {
    $metodosPago = [];
}

// Cursos (desde JSON)
$cursosPath = __DIR__ . '/cursos.json';
$cursosList = file_exists($cursosPath) ? json_decode(file_get_contents($cursosPath), true) : [];
if (!is_array($cursosList)) {
    $cursosList = [];
}

// Sección activa (se establece primero, luego se sobreescribe si hay edición)
$seccion = $_GET['seccion'] ?? 'finanzas';

// Método de pago para editar
$editMetodo = null;
if (isset($_GET['edit_metodo'])) {
    $editMetodo = getMetodoPagoById((int)$_GET['edit_metodo']);
    $seccion = 'metodos_pago';
}

// Obtener planes de membresía
try {
    $planes = getPlanes();
} catch (Exception $e) {
    $planes = [];
}

// Plan para editar
$editPlan = null;
if (isset($_GET['edit_plan'])) {
    $editPlan = getPlanById((int)$_GET['edit_plan']);
    $seccion = 'membresias';
}

// Mensajes
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

// Subsección de Apariencia: colores | branding | pages
$subApariencia = $_GET['sub'] ?? 'colores';
if (!in_array($subApariencia, ['colores', 'branding', 'pages'], true)) {
    $subApariencia = 'colores';
}

// Restaurar colores por defecto si se solicita
if (isset($_GET['restaurar']) && $seccion == 'apariencia') {
    $configPath = __DIR__ . '/config_marca.json';
    $config = file_exists($configPath) ? json_decode(file_get_contents($configPath), true) : [];
    if (!is_array($config)) {
        $config = [];
    }
    
    // Restaurar valores por defecto de colores
    $config['color_principal'] = '#22c55e';
    $config['fondo_principal'] = '#0a0a0a';
    $config['fondo_secundario'] = '#1a1a1a';
    $config['fondo_terciario'] = '#2a2a2a';
    $config['texto_principal'] = '#ffffff';
    $config['texto_secundario'] = '#a1a1aa';
    
    file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    header('Location: admin.php?seccion=apariencia&msg=colores_restaurados');
    exit;
}

// Cuenta para editar
$editCuenta = null;
if (isset($_GET['edit'])) {
    $editCuenta = getCuentaById((int)$_GET['edit']);
    // Si viene desde Productos > Streaming, quedarse en esa sección
    if (isset($_GET['seccion']) && $_GET['seccion'] === 'productos') {
        $seccion = 'productos';
        $subProducto = 'Streaming';
    } else {
        $seccion = 'cuentas';
    }
}

// Productos (Licencias, Cursos, Software-Sistemas)
$productos = [];
try {
    $stmtProd = $pdo->query("SELECT * FROM productos ORDER BY categoria, id DESC");
    $productos = $stmtProd->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $productos = []; }

// Producto para editar
$editProducto = null;
if (isset($_GET['edit_producto'])) {
    try {
        $stmtEP = $pdo->prepare("SELECT * FROM productos WHERE id = ?");
        $stmtEP->execute([(int)$_GET['edit_producto']]);
        $editProducto = $stmtEP->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($editProducto) $seccion = 'productos';
    } catch (Exception $e) {}
}
// Sub-categoría activa en sección Productos
$subProducto = $_GET['subp'] ?? 'Streaming';
if (!in_array($subProducto, ['Streaming','Licencias','Cursos','Software-Sistemas'], true)) {
    $subProducto = 'Streaming';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #1a1a2e; color: #fff; display: flex; min-height: 100vh; }
        
        /* ── SIDEBAR ICON-ONLY ── */
        .sidebar {
            width: 64px;
            background: #16213e;
            border-right: 1px solid #1f3460;
            display: flex;
            flex-direction: column;
            align-items: center;
            position: fixed;
            height: 100vh;
            top: 0; left: 0;
            z-index: 1000;
            overflow: visible;
        }
        .sidebar-ham {
            width: 64px; height: 64px;
            display: flex; align-items: center; justify-content: center;
            border-bottom: 1px solid #1f3460;
            flex-shrink: 0; cursor: pointer;
            transition: background 0.2s;
            color: #20d489; font-size: 1.25rem;
        }
        .sidebar-ham:hover { background: rgba(255,255,255,0.06); }
        .sidebar-icons {
            flex: 1; display: flex; flex-direction: column;
            align-items: center; padding: 8px 0; width: 100%;
            overflow-y: auto; scrollbar-width: none;
        }
        .sidebar-icons::-webkit-scrollbar { display: none; }
        .nav-icon-btn {
            width: 46px; height: 46px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; margin: 4px 0; background: transparent;
            transition: background 0.2s; color: #64748b;
            font-size: 1.1rem; border: none;
        }
        .nav-icon-btn:hover, .nav-icon-btn.active {
            background: #20d489; color: #000;
        }

        /* ── DRAWER ── */
        .admin-drawer {
            display: flex; position: fixed;
            top: 0; left: 64px;
            width: 240px; height: 100vh;
            background: #16213e;
            border-right: 1px solid #1f3460;
            z-index: 999; flex-direction: column;
            transform: translateX(-304px);
            transition: transform 0.28s ease;
        }
        .admin-drawer.open { transform: translateX(0); }
        .drawer-logo {
            padding: 16px 20px; border-bottom: 1px solid #1f3460;
            display: flex; align-items: center;
        }
        .drawer-logo img { height: 34px; }
        .drawer-nav { flex: 1; overflow-y: auto; padding: 8px 0; }
        .drawer-group-title {
            padding: 14px 20px 6px;
            font-size: 0.68rem; color: #64748b;
            text-transform: uppercase; letter-spacing: 1px; font-weight: 700;
        }
        .drawer-item {
            padding: 10px 20px; color: #94a3b8;
            display: flex; align-items: center;
            gap: 12px; transition: 0.2s; font-size: 0.92rem;
            text-decoration: none;
        }
        .drawer-item:hover { color: white; background: rgba(255,255,255,0.05); }
        .drawer-item.active { color: white; background: linear-gradient(90deg, rgba(32,212,137,0.15) 0%, transparent 100%); border-left: 3px solid #20d489; }
        .drawer-item i { width: 18px; text-align: center; color: #20d489; }

        /* Overlay */
        .admin-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.45); z-index: 997;
        }
        .admin-overlay.active { display: block; }

        /* Main Content */
        .main {
            flex: 1;
            margin-left: 64px;
            padding: 20px 30px;
            transition: margin-left 0.28s ease, width 0.28s ease;
            width: calc(100% - 64px);
        }
        .main.drawer-open {
            margin-left: 304px;
            width: calc(100% - 304px);
        }

        /* Responsive */
        @media (max-width: 900px) {
            .main { margin-left: 64px !important; width: calc(100% - 64px) !important; }
            .main.drawer-open { margin-left: 64px !important; width: calc(100% - 64px) !important; }
            .admin-drawer { left: 64px; width: 240px; transform: translateX(-240px); z-index: 1001; }
            .admin-overlay { z-index: 1000; }
        }
        @media (max-width: 768px) {
            .sidebar { width: 56px; }
            .sidebar-ham { width: 56px; height: 56px; }
            .main { margin-left: 56px !important; width: calc(100% - 56px) !important; padding: 12px 16px; }
            .main.drawer-open { margin-left: 56px !important; width: calc(100% - 56px) !important; }
            .admin-drawer { left: 56px; width: 220px; transform: translateX(-220px); z-index: 1001; }
        }

        .breadcrumb { color: #64748b; font-size: 0.85rem; margin-bottom: 10px; }
        .breadcrumb a { color: #818cf8; text-decoration: none; }
        .page-title { font-size: 1.5rem; font-weight: 600; margin-bottom: 25px; }
        
        /* Month Navigation */
        .month-nav { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .month-display { display: flex; align-items: center; gap: 10px; font-size: 1rem; }
        .month-display i { color: #64748b; }
        .month-buttons { display: flex; gap: 8px; }
        .month-btn { padding: 8px 16px; background: #1f3460; border: 1px solid #2d4a7c; color: #94a3b8; border-radius: 6px; cursor: pointer; font-size: 0.85rem; transition: all 0.2s; }
        .month-btn:hover, .month-btn.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
        
        /* Filter Section */
        .filter-section { background: #16213e; border: 1px solid #1f3460; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .filter-title { display: flex; align-items: center; gap: 8px; color: #94a3b8; font-size: 0.9rem; margin-bottom: 15px; }
        .filter-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
        .filter-group label { display: block; font-size: 0.8rem; color: #64748b; margin-bottom: 6px; }
        .filter-group select, .filter-group input { width: 100%; padding: 10px 12px; background: #1a1a2e; border: 1px solid #1f3460; border-radius: 6px; color: #fff; font-size: 0.9rem; }
        .btn-apply { background: #3b82f6; color: #fff; border: none; padding: 10px 30px; border-radius: 6px; cursor: pointer; font-weight: 500; }
        .btn-apply:hover { background: #2563eb; }
        
        /* Stat Cards */
        .stat-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-card { padding: 20px; border-radius: 8px; }
        .stat-card.blue { background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #f97316 0%, #ea580c 100%); }
        .stat-card.yellow { background: linear-gradient(135deg, #eab308 0%, #ca8a04 100%); }
        .stat-card.gray { background: #374151; }
        .stat-card.green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .stat-card.purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); }
        .stat-card .title { font-size: 0.85rem; opacity: 0.9; margin-bottom: 8px; }
        .stat-card .value { font-size: 1.8rem; font-weight: 700; margin-bottom: 8px; }
        .stat-card .subtitle { font-size: 0.75rem; opacity: 0.7; }
        
        /* Tables */
        .table-container { background: #16213e; border: 1px solid #1f3460; border-radius: 8px; overflow: hidden; }
        .table-header { padding: 15px 20px; border-bottom: 1px solid #1f3460; display: flex; align-items: center; gap: 10px; }
        .table-header h3 { font-size: 1rem; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: #1a1a2e; }
        th { padding: 12px 16px; text-align: left; font-size: 0.8rem; color: #64748b; font-weight: 500; text-transform: uppercase; }
        td { padding: 12px 16px; border-bottom: 1px solid #1f3460; font-size: 0.9rem; }
        tr:hover { background: rgba(99, 102, 241, 0.05); }
        
        /* Form Styles */
        .form-card { background: #16213e; border: 1px solid #1f3460; border-radius: 8px; padding: 25px; margin-bottom: 20px; }
        .form-card h3 { font-size: 1.1rem; margin-bottom: 20px; color: #818cf8; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 0.85rem; color: #94a3b8; margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; background: #1a1a2e; border: 1px solid #1f3460; border-radius: 6px; color: #fff; font-size: 0.9rem; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #818cf8; }
        .form-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; }
        .form-row-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; }
        
        .btn { padding: 12px 24px; border-radius: 6px; cursor: pointer; font-weight: 500; border: none; transition: all 0.2s; }
        .btn-primary { background: #3b82f6; color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-secondary { background: #374151; color: #fff; }
        .btn-secondary:hover { background: #4b5563; }
        
        /* Badges */
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.75rem; font-weight: 500; }
        .badge-success { background: rgba(16, 185, 129, 0.2); color: #10b981; }
        .badge-warning { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-danger { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-info { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        
        /* User Info */
        .user-info { display: flex; align-items: center; gap: 10px; }
        .user-info img { width: 32px; height: 32px; border-radius: 50%; }
        .user-info .name { font-weight: 500; }
        .user-info .email { font-size: 0.8rem; color: #64748b; }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 60px 20px; color: #64748b; }
        .empty-state i { font-size: 3rem; margin-bottom: 15px; opacity: 0.5; }
        .empty-state h3 { margin-bottom: 8px; color: #94a3b8; }
        
        /* Success Message */
        .alert { padding: 12px 20px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #10b981; }
        .alert-error { background: rgba(239, 68, 68, 0.2); border: 1px solid #ef4444; color: #ef4444; }
        
        /* Coming Soon */
        .coming-soon { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 400px; color: #64748b; }
        .coming-soon i { font-size: 4rem; margin-bottom: 20px; opacity: 0.3; }
        .coming-soon h2 { color: #94a3b8; margin-bottom: 10px; }
        
        /* Password blur */
        .password-blur { filter: blur(4px); transition: filter 0.2s; cursor: pointer; }
        .password-blur:hover { filter: none; }
        
        /* Chart placeholder */
        .chart-container { background: #16213e; border: 1px solid #1f3460; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .chart-title { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .chart-placeholder { height: 200px; display: flex; align-items: center; justify-content: center; color: #64748b; border: 2px dashed #1f3460; border-radius: 6px; }
        
        /* old sidebar-user overrides — kept empty, replaced above */
    </style>
</head>
<body>
    <!-- Overlay -->
    <div class="admin-overlay" id="adminOverlay"></div>

    <div class="dashboard">
    <!-- SIDEBAR: solo iconos -->
    <aside class="sidebar">
        <!-- Hamburger: abre el drawer completo -->
        <div class="sidebar-ham" onclick="toggleAdminDrawer()" title="Menú">
            <i class="fas fa-bars"></i>
        </div>
        <!-- Iconos: navegan directo a la primera sección del grupo -->
        <div class="sidebar-icons">
            <button class="nav-icon-btn <?= in_array($seccion,['finanzas','retiros']) ? 'active' : '' ?>" onclick="openDrawerSection('finanzas')" title="Principal"><i class="fas fa-chart-bar"></i></button>
            <button class="nav-icon-btn <?= in_array($seccion,['usuarios','membresias','recargas','cursos']) ? 'active' : '' ?>" onclick="openDrawerSection('usuarios')" title="Usuarios"><i class="fas fa-users"></i></button>
            <button class="nav-icon-btn <?= in_array($seccion,['productos','compras','estadisticas','soporte','logos']) ? 'active' : '' ?>" onclick="window.location.href='?seccion=productos&subp=Streaming'" title="Gestión"><i class="fas fa-layer-group"></i></button>
            <button class="nav-icon-btn <?= in_array($seccion,['apariencia','ajustes','metodos_pago']) ? 'active' : '' ?>" onclick="openDrawerSection('apariencia')" title="Configuración"><i class="fas fa-cog"></i></button>
        </div>
    </aside>

    <!-- DRAWER: menú completo con textos -->
    <div class="admin-drawer" id="adminDrawer">
        <div class="drawer-logo">
            <img src="https://gwoter.com/logo.png" alt="Logo">
        </div>
        <nav class="drawer-nav">
            <div class="drawer-group-title">Principal</div>
            <a href="?seccion=finanzas" class="drawer-item <?= $seccion=='finanzas' ? 'active' : '' ?>"><i class="fas fa-dollar-sign"></i> Finanzas</a>
            <a href="?seccion=retiros"  class="drawer-item <?= $seccion=='retiros'  ? 'active' : '' ?>"><i class="fas fa-money-bill-wave"></i> Retiros</a>

            <div class="drawer-group-title">Usuarios y Membresías</div>
            <a href="?seccion=usuarios"   class="drawer-item <?= $seccion=='usuarios'   ? 'active' : '' ?>"><i class="fas fa-user"></i> Usuarios</a>
            <a href="?seccion=membresias" class="drawer-item <?= $seccion=='membresias' ? 'active' : '' ?>"><i class="fas fa-id-card"></i> Membresías</a>
            <a href="?seccion=recargas"   class="drawer-item <?= $seccion=='recargas'   ? 'active' : '' ?>"><i class="fas fa-wallet"></i> Recargas</a>
            <a href="?seccion=cursos"     class="drawer-item <?= $seccion=='cursos'     ? 'active' : '' ?>"><i class="fas fa-graduation-cap"></i> Cursos</a>

            <div class="drawer-group-title">Gestión</div>
            <a href="?seccion=productos&subp=Streaming"         class="drawer-item <?= ($seccion=='productos' && $subProducto=='Streaming') ? 'active' : '' ?>"><i class="fas fa-play-circle"></i> Streaming</a>
            <a href="?seccion=productos&subp=Licencias"         class="drawer-item <?= ($seccion=='productos' && $subProducto=='Licencias') ? 'active' : '' ?>"><i class="fas fa-key"></i> Licencias</a>
            <a href="?seccion=productos&subp=Cursos"            class="drawer-item <?= ($seccion=='productos' && $subProducto=='Cursos') ? 'active' : '' ?>"><i class="fas fa-graduation-cap"></i> Cursos</a>
            <a href="?seccion=productos&subp=Software-Sistemas" class="drawer-item <?= ($seccion=='productos' && $subProducto=='Software-Sistemas') ? 'active' : '' ?>"><i class="fas fa-desktop"></i> Software-Sistemas</a>
            <a href="?seccion=compras"      class="drawer-item <?= $seccion=='compras'      ? 'active' : '' ?>"><i class="fas fa-shopping-bag"></i> Compras</a>
            <a href="?seccion=estadisticas" class="drawer-item <?= $seccion=='estadisticas' ? 'active' : '' ?>"><i class="fas fa-chart-line"></i> Estadísticas</a>
            <a href="?seccion=soporte"      class="drawer-item <?= $seccion=='soporte'      ? 'active' : '' ?>"><i class="fas fa-life-ring"></i> Soporte</a>
            <a href="?seccion=logos"        class="drawer-item <?= $seccion=='logos'        ? 'active' : '' ?>"><i class="fas fa-image"></i> Logos</a>

            <div class="drawer-group-title">Configuración</div>
            <a href="?seccion=apariencia"   class="drawer-item <?= $seccion=='apariencia'   ? 'active' : '' ?>"><i class="fas fa-palette"></i> Apariencia</a>
            <a href="?seccion=ajustes"      class="drawer-item <?= $seccion=='ajustes'      ? 'active' : '' ?>"><i class="fas fa-sliders-h"></i> Ajustes</a>
            <a href="?seccion=metodos_pago" class="drawer-item <?= $seccion=='metodos_pago' ? 'active' : '' ?>"><i class="fas fa-credit-card"></i> Métodos de Pago</a>
        </nav>
    </div>

    <!-- MAIN CONTENT -->
    <main class="main" id="adminMain">
        <?php if ($msg): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Operación realizada exitosamente
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <!-- ==================== FINANZAS ==================== -->
        <?php if ($seccion == 'finanzas'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Finanzas</div>
        <h1 class="page-title">Panel de Finanzas</h1>
        
        <div class="month-nav">
            <div class="month-display">
                <i class="fas fa-calendar"></i>
                <span><?= strftime('%B %Y', time()) ?></span>
            </div>
            <div class="month-buttons">
                <button class="month-btn">< Mes anterior</button>
                <button class="month-btn active">Mes actual</button>
                <button class="month-btn">Mes siguiente ></button>
            </div>
        </div>
        
        <div class="filter-section">
            <div class="filter-title"><i class="fas fa-filter"></i> Filtrar por periodo</div>
            <div class="filter-row">
                <div class="filter-group">
                    <label>Periodo</label>
                    <select>
                        <option>Mes completo</option>
                        <option>Última semana</option>
                        <option>Personalizado</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Mes</label>
                    <select>
                        <?php for($m=1; $m<=12; $m++): ?>
                        <option <?= $m == date('n') ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Año</label>
                    <select>
                        <option>2025</option>
                        <option>2024</option>
                    </select>
                </div>
                <div class="filter-group" style="display: flex; align-items: flex-end;">
                    <button class="btn-apply">Aplicar</button>
                </div>
            </div>
        </div>
        
        <div class="stat-cards">
            <div class="stat-card blue">
                <div class="title">Ingresos Totales</div>
                <div class="value">$ <?= number_format($totalIngresos, 2) ?> USD</div>
                <div class="subtitle">Periodo: <?= date('d/m/Y') ?> - <?= date('d/m/Y') ?></div>
            </div>
            <div class="stat-card orange">
                <div class="title">Gastos Totales</div>
                <div class="value">$ 0.00 USD</div>
                <div class="subtitle">Costos de proveedores</div>
            </div>
            <div class="stat-card yellow">
                <div class="title">Beneficio Neto</div>
                <div class="value">$ <?= number_format($totalIngresos, 2) ?> USD</div>
                <div class="subtitle">Margen: 100%</div>
            </div>
            <div class="stat-card gray">
                <div class="title">Órdenes Procesadas</div>
                <div class="value"><?= $totalVentas ?></div>
                <div class="subtitle">Ganancia promedio: <?= $totalVentas > 0 ? number_format($totalIngresos/$totalVentas, 2) : '0.00' ?></div>
            </div>
        </div>
        
        <div class="chart-container">
            <div class="chart-title"><i class="fas fa-chart-bar"></i> Ganancias Diarias (price - price_provider)</div>
            <div class="chart-placeholder">
                <span><i class="fas fa-chart-line"></i> Gráfico de ganancias diarias - próximamente</span>
            </div>
        </div>
        
        <div class="table-container">
            <div class="table-header">
                <i class="fas fa-table"></i>
                <h3>Desglose Diario de Ganancias</h3>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Órd</th>
                        <th>Ingresos</th>
                        <th>Gastos</th>
                        <th>Ganancia</th>
                        <th>%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($compras) > 0): ?>
                    <?php 
                    $comprasPorDia = [];
                    foreach($compras as $c) {
                        $fecha = date('d/m', strtotime($c['created_at']));
                        if (!isset($comprasPorDia[$fecha])) $comprasPorDia[$fecha] = ['count' => 0, 'total' => 0];
                        $comprasPorDia[$fecha]['count']++;
                        $comprasPorDia[$fecha]['total'] += $c['creditos_usados'];
                    }
                    foreach($comprasPorDia as $fecha => $data):
                    ?>
                    <tr>
                        <td><?= $fecha ?></td>
                        <td><?= $data['count'] ?></td>
                        <td style="color: #10b981;">$ <?= number_format($data['total'], 2) ?> USD</td>
                        <td>$ 0.00 USD</td>
                        <td style="color: #10b981;">$ <?= number_format($data['total'], 2) ?> USD</td>
                        <td style="color: #10b981;">100%</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="6" style="text-align: center; color: #64748b;">No hay datos</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ==================== RETIROS ==================== -->
        <?php if ($seccion == 'retiros'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Retiros</div>
        <h1 class="page-title">Gestión de Retiros</h1>
        
        <div class="coming-soon">
            <i class="fas fa-money-bill-wave"></i>
            <h2>Retiros</h2>
            <p>Esta sección estará disponible próximamente</p>
        </div>
        <?php endif; ?>

        <!-- ==================== USUARIOS ==================== -->
        <?php if ($seccion == 'usuarios'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Usuarios</div>
        <h1 class="page-title">Gestión de Usuarios</h1>
        
        <!-- Formulario agregar créditos -->
        <div class="form-card">
            <h3><i class="fas fa-plus-circle"></i> Agregar Créditos Manualmente</h3>
            <form action="process.php" method="POST">
                <input type="hidden" name="action" value="agregar_creditos_admin">
                <div class="form-row-3">
                    <div class="form-group">
                        <label>Usuario</label>
                        <select name="usuario_id" required>
                            <option value="">Selecciona un usuario</option>
                            <?php foreach ($usuarios as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['nombre']) ?> - <?= number_format($u['creditos'], 2) ?> Bs</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Monto (Bs)</label>
                        <input type="number" name="monto" step="0.01" min="0.01" required placeholder="100.00">
                    </div>
                    <div class="form-group">
                        <label>Motivo</label>
                        <div style="display: flex; gap: 10px;">
                            <input type="text" name="motivo" placeholder="Bono, promoción, etc.">
                            <button type="submit" class="btn btn-success">Agregar</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="table-container">
            <div class="table-header">
                <i class="fas fa-users"></i>
                <h3>Todos los Usuarios (<?= count($usuarios) ?>)</h3>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Email</th>
                        <th>Créditos</th>
                        <th>Compras</th>
                        <th>Membresía</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                    <tr>
                        <td>#<?= $u['id'] ?></td>
                        <td><strong><?= htmlspecialchars($u['nombre']) ?></strong></td>
                        <td style="color: #64748b;"><?= htmlspecialchars($u['email']) ?></td>
                        <td><span style="color: #10b981; font-weight: 600;"><?= number_format($u['creditos'], 2) ?> Bs</span></td>
                        <td><span class="badge badge-info"><?= $u['total_compras'] ?></span></td>
                        <td>
                            <?php if (!empty($u['membresia_nombre'])): ?>
                            <span style="display:inline-flex; align-items:center; gap:5px; background:#fef3c7; color:#92400e; border:1px solid #fcd34d; border-radius:20px; padding:3px 10px; font-size:0.78rem; font-weight:700;">
                                <i class="fas fa-crown" style="color:#f59e0b; font-size:0.72rem;"></i>
                                <?= htmlspecialchars($u['membresia_nombre']) ?>
                            </span>
                            <?php if (!empty($u['membresia_fecha'])): ?>
                            <div style="font-size:0.72rem; color:#94a3b8; margin-top:3px;">
                                desde <?= date('d/m/Y', strtotime($u['membresia_fecha'])) ?>
                            </div>
                            <?php endif; ?>
                            <?php else: ?>
                            <span style="color:#94a3b8; font-size:0.82rem;">Sin membresía</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn btn-success btn-sm" style="padding: 6px 12px; font-size: 0.8rem;">+ Créditos</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>


        <!-- ==================== RECARGAS ==================== -->
        <?php if ($seccion == 'recargas'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Recargas</div>
        <h1 class="page-title">Historial de Recargas</h1>
        
        <div class="stat-cards" style="grid-template-columns: repeat(3, 1fr);">
            <div class="stat-card green">
                <div class="title">Total Recargas</div>
                <div class="value"><?= count($recargas) ?></div>
            </div>
            <div class="stat-card blue">
                <div class="title">Monto Total</div>
                <div class="value">$ <?= number_format(array_sum(array_column($recargas, 'monto')), 2) ?></div>
            </div>
            <div class="stat-card purple">
                <div class="title">Completadas</div>
                <div class="value"><?= count(array_filter($recargas, fn($r) => $r['estado'] == 'completado')) ?></div>
            </div>
        </div>
        
        <div class="table-container">
            <div class="table-header">
                <i class="fas fa-wallet"></i>
                <h3>Todas las Recargas</h3>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Usuario</th>
                        <th>Monto</th>
                        <th>Créditos</th>
                        <th>Estado</th>
                        <th>Movimiento ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($recargas) > 0): ?>
                    <?php foreach ($recargas as $r): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                        <td>
                            <div class="user-info">
                                <div>
                                    <div class="name"><?= htmlspecialchars($r['usuario_nombre'] ?? 'N/A') ?></div>
                                    <div class="email"><?= htmlspecialchars($r['usuario_email'] ?? '') ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="font-weight: 600;">$ <?= number_format($r['monto'], 2) ?></td>
                        <td style="color: #10b981;"><?= number_format($r['creditos_agregados'], 2) ?> Bs</td>
                        <td>
                            <?php 
                            $badgeClass = $r['estado'] == 'completado' ? 'badge-success' : ($r['estado'] == 'pendiente' ? 'badge-warning' : 'badge-danger');
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= ucfirst($r['estado']) ?></span>
                        </td>
                        <td style="font-family: monospace; font-size: 0.8rem; color: #64748b;"><?= $r['movimiento_id'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="6" class="empty-state"><i class="fas fa-inbox"></i> No hay recargas registradas</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ==================== CURSOS ==================== -->
        <?php if ($seccion == 'cursos'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Cursos</div>
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h1 class="page-title" style="margin: 0;">Cursos</h1>
            <button type="button" onclick="openCursoModal()" class="btn btn-primary" style="display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-plus"></i> Nuevo Curso
            </button>
        </div>
        
        <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
            <input type="text" id="cursoSearch" placeholder="Buscar por título..." style="flex: 1; min-width: 200px; padding: 10px 14px; border: 1px solid #1f3460; border-radius: 6px; background: #1a1a2e; color: #fff;">
            <select id="cursoFilter" style="padding: 10px 14px; border: 1px solid #1f3460; border-radius: 6px; background: #1a1a2e; color: #fff;">
                <option value="">Todos</option>
                <option value="borrador">Borrador</option>
                <option value="publicado">Publicado</option>
            </select>
            <button type="button" onclick="filtrarCursos()" class="btn btn-primary">Buscar</button>
            <button type="button" onclick="limpiarFiltroCursos()" class="btn btn-secondary">Limpiar</button>
        </div>
        
        <div class="table-container">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align: left; padding: 12px; font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Curso</th>
                        <th style="text-align: left; padding: 12px; font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Precio</th>
                        <th style="text-align: left; padding: 12px; font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Módulos</th>
                        <th style="text-align: left; padding: 12px; font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Estudiantes</th>
                        <th style="text-align: left; padding: 12px; font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Estado</th>
                        <th style="text-align: left; padding: 12px; font-size: 0.75rem; color: #64748b; text-transform: uppercase;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="cursosTableBody">
                    <?php if (count($cursosList) === 0): ?>
                    <tr>
                        <td colspan="6" style="padding: 60px 20px; text-align: center; vertical-align: middle;">
                            <i class="fas fa-book-open" style="font-size: 3rem; color: #475569; margin-bottom: 16px; display: block;"></i>
                            <strong style="display: block; margin-bottom: 8px; color: #e2e8f0;">No hay cursos</strong>
                            <p style="color: #94a3b8; margin: 0;">Comienza creando un nuevo curso</p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($cursosList as $cur): ?>
                    <tr class="curso-row" data-titulo="<?= htmlspecialchars(strtolower($cur['titulo'] ?? '')) ?>" data-estado="<?= htmlspecialchars($cur['estado'] ?? '') ?>">
                        <td style="padding: 14px; border-bottom: 1px solid #1f3460;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <?php if (!empty($cur['imagen_url'])): ?>
                                <img src="<?= htmlspecialchars($cur['imagen_url']) ?>" alt="" style="width: 48px; height: 48px; object-fit: cover; border-radius: 6px;">
                                <?php else: ?>
                                <div style="width: 48px; height: 48px; background: #1f3460; border-radius: 6px; display: flex; align-items: center; justify-content: center;"><i class="fas fa-book" style="color: #64748b;"></i></div>
                                <?php endif; ?>
                                <div>
                                    <strong><?= htmlspecialchars($cur['titulo'] ?? 'Sin título') ?></strong>
                                </div>
                            </div>
                        </td>
                        <td style="padding: 14px; border-bottom: 1px solid #1f3460;">$<?= number_format($cur['precio'] ?? 0, 2) ?></td>
                        <td style="padding: 14px; border-bottom: 1px solid #1f3460;"><?= (int)($cur['modulos'] ?? 0) ?></td>
                        <td style="padding: 14px; border-bottom: 1px solid #1f3460;"><?= (int)($cur['estudiantes'] ?? 0) ?></td>
                        <td style="padding: 14px; border-bottom: 1px solid #1f3460;">
                            <span class="badge <?= ($cur['estado'] ?? '') === 'publicado' ? 'badge-success' : 'badge-warning' ?>"><?= ucfirst($cur['estado'] ?? 'borrador') ?></span>
                        </td>
                        <td style="padding: 14px; border-bottom: 1px solid #1f3460;">
                            <a href="?seccion=cursos&editar_curso=<?= (int)($cur['id'] ?? 0) ?>" style="color: #3b82f6; margin-right: 10px;">Editar</a>
                            <a href="process.php?action=eliminar_curso&id=<?= (int)($cur['id'] ?? 0) ?>" onclick="gEliminar(event,'¿Eliminar este curso?',this.href); return false;" style="color: #ef4444;">Eliminar</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Modal Crear Nuevo Curso -->
        <div id="cursoModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 1000; align-items: center; justify-content: center; padding: 20px;">
            <div style="background: #1a1a2e; border-radius: 12px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; border: 1px solid #1f3460;">
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 20px; border-bottom: 1px solid #1f3460;">
                    <h2 style="margin: 0; font-size: 1.25rem;">Crear Nuevo Curso</h2>
                    <button type="button" onclick="closeCursoModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
                </div>
                <form action="process.php" method="POST" enctype="multipart/form-data" style="padding: 20px;">
                    <input type="hidden" name="action" value="guardar_curso">
                    
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label>Título *</label>
                        <input type="text" name="titulo" required placeholder="Ej: Curso de JavaScript Avanzado" style="width: 100%; padding: 10px 12px; border: 1px solid #1f3460; border-radius: 6px; background: #0f172a; color: #fff;">
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label>Descripción *</label>
                        <textarea name="descripcion" required rows="4" placeholder="Descripción detallada del curso" style="width: 100%; padding: 10px 12px; border: 1px solid #1f3460; border-radius: 6px; background: #0f172a; color: #fff; resize: vertical;"></textarea>
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label>Precio ($) *</label>
                        <input type="number" name="precio" step="0.01" min="0" value="0" required style="width: 100%; padding: 10px 12px; border: 1px solid #1f3460; border-radius: 6px; background: #0f172a; color: #fff;">
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label>Imagen del curso</label>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="file" name="imagen_curso" accept="image/jpeg,image/png,image/jpg" id="imagenCursoInput" style="display: none;">
                            <button type="button" class="btn btn-secondary" onclick="document.getElementById('imagenCursoInput').click()" style="padding: 8px 14px;">Seleccionar archivo</button>
                            <span id="imagenCursoNombre" style="color: #64748b; font-size: 0.9rem;">Ningún archivo seleccionado</span>
                        </div>
                        <p style="color: #64748b; font-size: 0.8rem; margin-top: 6px;">Formatos: JPG, PNG (max 2MB)</p>
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label>URL del video de preview</label>
                        <input type="url" name="video_preview_url" placeholder="https://youtube.com/watch?v=..." style="width: 100%; padding: 10px 12px; border: 1px solid #1f3460; border-radius: 6px; background: #0f172a; color: #fff;">
                        <p style="color: #64748b; font-size: 0.8rem; margin-top: 6px;">YouTube, Vimeo, etc.</p>
                    </div>
                    <div class="form-group" style="margin-bottom: 20px;">
                        <label>Estado *</label>
                        <select name="estado" required style="width: 100%; padding: 10px 12px; border: 1px solid #1f3460; border-radius: 6px; background: #0f172a; color: #fff;">
                            <option value="borrador">Borrador</option>
                            <option value="publicado">Publicado</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" onclick="closeCursoModal()" class="btn btn-secondary">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Crear Curso</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
            function openCursoModal() {
                document.getElementById('cursoModal').style.display = 'flex';
            }
            function closeCursoModal() {
                document.getElementById('cursoModal').style.display = 'none';
            }
            document.getElementById('imagenCursoInput').addEventListener('change', function() {
                document.getElementById('imagenCursoNombre').textContent = this.files[0] ? this.files[0].name : 'Ningún archivo seleccionado';
            });
            function filtrarCursos() {
                var q = (document.getElementById('cursoSearch').value || '').toLowerCase();
                var estado = document.getElementById('cursoFilter').value;
                document.querySelectorAll('.curso-row').forEach(function(row) {
                    var titulo = (row.getAttribute('data-titulo') || '');
                    var rowEstado = row.getAttribute('data-estado') || '';
                    var matchTitulo = !q || titulo.indexOf(q) !== -1;
                    var matchEstado = !estado || rowEstado === estado;
                    row.style.display = (matchTitulo && matchEstado) ? '' : 'none';
                });
            }
            function limpiarFiltroCursos() {
                document.getElementById('cursoSearch').value = '';
                document.getElementById('cursoFilter').value = '';
                document.querySelectorAll('.curso-row').forEach(function(row) { row.style.display = ''; });
            }
        </script>
        <?php endif; ?>

        <!-- ==================== ESTADÍSTICAS (STREAMING GANANCIAS) ==================== -->
        <?php if ($seccion == 'estadisticas'): ?>
        <?php
        $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

        // ── TAB GENERAL ──────────────────────────────────────────────
        // KPIs globales (todo el historial)
        $stmtKpi = $pdo->query("
            SELECT COUNT(cp.id) as total_ventas,
                   COALESCE(SUM(cp.creditos_usados), 0) as ingresos_totales,
                   COUNT(DISTINCT cu.plataforma_id) as total_plataformas
            FROM compras cp
            JOIN cuentas cu ON cp.cuenta_id = cu.id
        ");
        $kpi = $stmtKpi->fetch();
        $totalVentas      = (int)$kpi['total_ventas'];
        $ingresosTotales  = (float)$kpi['ingresos_totales'];
        $totalPlataformas = (int)$kpi['total_plataformas'];
        $ticketPromedio   = $totalVentas > 0 ? $ingresosTotales / $totalVentas : 0;

        // Cuentas disponibles totales
        $stmtDisp = $pdo->query("SELECT COUNT(*) FROM cuentas WHERE estado = 'disponible'");
        $cuentasDisponibles = (int)$stmtDisp->fetchColumn();

        // Ventas por plataforma (global)
        $stmtPorPlat = $pdo->query("
            SELECT p.id, p.nombre, p.imagen_url,
                   COUNT(cp.id) as ventas,
                   COALESCE(SUM(cp.creditos_usados), 0) as ingresos,
                   (SELECT COUNT(*) FROM cuentas c2 WHERE c2.plataforma_id = p.id AND c2.estado = 'disponible') as disponibles
            FROM plataformas p
            LEFT JOIN cuentas cu ON cu.plataforma_id = p.id
            LEFT JOIN compras cp ON cp.cuenta_id = cu.id
            GROUP BY p.id, p.nombre, p.imagen_url
            ORDER BY ingresos DESC
        ");
        $platStats = $stmtPorPlat->fetchAll();

        // Ganancias diarias (últimos 30 días)
        $stmtDiario = $pdo->query("
            SELECT DATE(cp.created_at) as dia,
                   COUNT(cp.id) as ventas,
                   SUM(cp.creditos_usados) as ingresos
            FROM compras cp
            WHERE cp.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            GROUP BY DATE(cp.created_at)
            ORDER BY dia DESC
        ");
        $gananciasDiarias = $stmtDiario->fetchAll(PDO::FETCH_ASSOC);

        // ── TAB POR PLATAFORMA ────────────────────────────────────────
        // Cargar datos de TODAS las plataformas de una vez (para JS sin recarga)
        $stmtPlats = $pdo->query("SELECT id, nombre, imagen_url FROM plataformas ORDER BY nombre");
        $todasPlataformas = $stmtPlats->fetchAll();

        // KPIs por plataforma (una sola query agregada)
        $stmtKpiAll = $pdo->query("
            SELECT cu.plataforma_id,
                   COUNT(cp.id) as ventas,
                   COALESCE(SUM(cp.creditos_usados), 0) as ingresos
            FROM compras cp
            JOIN cuentas cu ON cp.cuenta_id = cu.id
            GROUP BY cu.plataforma_id
        ");
        $kpiAllRaw = $stmtKpiAll->fetchAll(PDO::FETCH_ASSOC);
        $kpiAll = [];
        foreach ($kpiAllRaw as $r) {
            $kpiAll[(int)$r['plataforma_id']] = [
                'ventas'   => (int)$r['ventas'],
                'ingresos' => (float)$r['ingresos'],
                'ticket'   => (int)$r['ventas'] > 0 ? (float)$r['ingresos'] / (int)$r['ventas'] : 0,
            ];
        }

        // Stock disponible por plataforma
        $stmtDispAll = $pdo->query("SELECT plataforma_id, COUNT(*) as disp FROM cuentas WHERE estado='disponible' GROUP BY plataforma_id");
        $dispAll = [];
        foreach ($stmtDispAll->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dispAll[(int)$r['plataforma_id']] = (int)$r['disp'];
        }

        // Ventas mes a mes por plataforma (últimos 12 meses)
        $stmtMesAll = $pdo->query("
            SELECT cu.plataforma_id,
                   YEAR(cp.created_at) as ano, MONTH(cp.created_at) as mes,
                   COUNT(cp.id) as ventas,
                   SUM(cp.creditos_usados) as ingresos
            FROM compras cp
            JOIN cuentas cu ON cp.cuenta_id = cu.id
            WHERE cp.created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
            GROUP BY cu.plataforma_id, ano, mes
            ORDER BY cu.plataforma_id, ano DESC, mes DESC
        ");
        $mesAllRaw = $stmtMesAll->fetchAll(PDO::FETCH_ASSOC);
        $mesAll = [];
        foreach ($mesAllRaw as $r) {
            $pid = (int)$r['plataforma_id'];
            if (!isset($mesAll[$pid])) $mesAll[$pid] = [];
            $mesAll[$pid][] = $r;
        }

        // Primera plataforma por defecto
        $platDefId = isset($todasPlataformas[0]) ? (int)$todasPlataformas[0]['id'] : 0;

        $activeTab = isset($_GET['etab']) ? $_GET['etab'] : 'general';
        ?>

        <style>
        .estad-tabs { display: flex; gap: 0; border-bottom: 2px solid #e5e7eb; margin-bottom: 28px; }
        .estad-tab-btn {
            padding: 10px 28px; font-size: 0.95rem; font-weight: 600;
            background: none; border: none; border-bottom: 3px solid transparent;
            cursor: pointer; color: #64748b; transition: color .2s, border-color .2s;
            margin-bottom: -2px;
        }
        .estad-tab-btn.active { color: #3b82f6; border-bottom-color: #3b82f6; }
        .estad-tab-btn:hover:not(.active) { color: #1e40af; }
        .estad-tab-panel { display: none; }
        .estad-tab-panel.active { display: block; }
        .estad-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 28px; }
        @media (max-width: 900px) { .estad-kpi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 500px) { .estad-kpi-grid { grid-template-columns: 1fr; } }
        .estad-kpi-card {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
            padding: 20px; display: flex; flex-direction: column; gap: 6px;
        }
        .estad-kpi-label { font-size: 0.82rem; font-weight: 600; color: #64748b; display: flex; justify-content: space-between; }
        .estad-kpi-value { font-size: 1.75rem; font-weight: 700; color: #111827; }
        .estad-kpi-sub { font-size: 0.78rem; color: #9ca3af; }
        .estad-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        .estad-table th { text-align: left; padding: 9px 10px; color: #64748b; font-weight: 600; border-bottom: 2px solid #e5e7eb; white-space: nowrap; }
        .estad-table th.r, .estad-table td.r { text-align: right; }
        .estad-table td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: middle; }
        .estad-table tr:last-child td { border-bottom: none; }
        .estad-table tr:hover td { background: #f8fafc; }
        .estad-plat-img { width: 24px; height: 24px; object-fit: contain; border-radius: 4px; vertical-align: middle; margin-right: 8px; }
        .estad-badge { display: inline-block; padding: 2px 9px; border-radius: 99px; font-size: 0.75rem; font-weight: 600; }
        .estad-badge-green { background: #dcfce7; color: #16a34a; }
        .estad-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 24px; }
        .estad-card-title { font-size: 0.95rem; font-weight: 700; color: #111827; margin: 0 0 18px 0; }
        .estad-two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 800px) { .estad-two-col { grid-template-columns: 1fr; } }
        .estad-select {
            padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 7px;
            background: #fff; color: #1e293b; font-size: 0.9rem; cursor: pointer;
            outline: none; min-width: 200px;
        }
        .estad-select:focus { border-color: #3b82f6; box-shadow: 0 0 0 2px #bfdbfe; }
        .estad-empty { color: #94a3b8; font-size: 0.9rem; text-align: center; padding: 24px 0; }
        </style>

        <div class="breadcrumb"><a href="?">Admin</a> > Streaming > Estadísticas</div>
        <h1 class="page-title" style="margin-bottom: 4px;">Estadísticas</h1>
        <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">Análisis de ventas e ingresos de la tienda de streaming</p>

        <div style="display:flex; align-items:center; border-bottom: 2px solid #e5e7eb; margin-bottom: 28px;">
            <!-- Tabs izquierda -->
            <div class="estad-tabs" style="border-bottom:none; margin-bottom:0; flex:1;">
                <button class="estad-tab-btn <?= $activeTab === 'general' ? 'active' : '' ?>"
                    onclick="estadSwitchTab('general')">
                    <i class="fas fa-chart-pie" style="margin-right:6px;"></i>General
                </button>
                <button class="estad-tab-btn <?= $activeTab === 'plataforma' ? 'active' : '' ?>"
                    onclick="estadSwitchTab('plataforma')">
                    <i class="fas fa-layer-group" style="margin-right:6px;"></i>Por Plataforma
                </button>
            </div>
            <!-- Dropdown derecha — solo visible en tab plataforma -->
            <div id="estad-plat-dropdown-wrap" style="display:<?= $activeTab === 'plataforma' ? 'flex' : 'none' ?>; align-items:center; gap:10px; padding-bottom:4px;">
                <i class="fas fa-filter" style="color:#94a3b8; font-size:0.85rem;"></i>
                <select class="estad-select" id="platSelector" onchange="estadMostrarPlat(this.value)" style="min-width:160px; padding:7px 12px;">
                    <?php foreach ($todasPlataformas as $tp): ?>
                    <option value="plat-<?= (int)$tp['id'] ?>">
                        <?= htmlspecialchars($tp['nombre']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- ═══════ TAB: GENERAL ═══════ -->
        <div id="estad-panel-general" class="estad-tab-panel <?= $activeTab === 'general' ? 'active' : '' ?>">

            <div class="estad-kpi-grid">
                <div class="estad-kpi-card">
                    <div class="estad-kpi-label">Ventas Totales <i class="fas fa-shopping-cart" style="color:#3b82f6;"></i></div>
                    <div class="estad-kpi-value"><?= number_format($totalVentas) ?></div>
                    <div class="estad-kpi-sub">Historial completo</div>
                </div>
                <div class="estad-kpi-card">
                    <div class="estad-kpi-label">Ingresos Totales <i class="fas fa-coins" style="color:#22c55e;"></i></div>
                    <div class="estad-kpi-value"><?= number_format($ingresosTotales, 2, ',', '.') ?> Bs</div>
                    <div class="estad-kpi-sub">Créditos acumulados</div>
                </div>
                <div class="estad-kpi-card">
                    <div class="estad-kpi-label">Ticket Promedio <i class="fas fa-receipt" style="color:#8b5cf6;"></i></div>
                    <div class="estad-kpi-value"><?= number_format($ticketPromedio, 2, ',', '.') ?> Bs</div>
                    <div class="estad-kpi-sub">Por compra</div>
                </div>
                <div class="estad-kpi-card">
                    <div class="estad-kpi-label">Stock Disponible <i class="fas fa-boxes" style="color:#f59e0b;"></i></div>
                    <div class="estad-kpi-value"><?= number_format($cuentasDisponibles) ?></div>
                    <div class="estad-kpi-sub">Cuentas listas para vender</div>
                </div>
            </div>

            <div class="estad-two-col" style="margin-bottom: 20px;">
                <!-- Tabla por plataforma -->
                <div class="estad-card" style="overflow-x:auto;">
                    <h3 class="estad-card-title"><i class="fas fa-table" style="margin-right:7px;color:#3b82f6;"></i>Ventas por Plataforma</h3>
                    <?php if (count($platStats) > 0): ?>
                    <table class="estad-table">
                        <thead>
                            <tr>
                                <th>Plataforma</th>
                                <th class="r">Ventas</th>
                                <th class="r">Ingresos</th>
                                <th class="r">Stock</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($platStats as $ps): ?>
                            <tr>
                                <td>
                                    <?php if ($ps['imagen_url']): ?>
                                    <img src="<?= htmlspecialchars($ps['imagen_url']) ?>" class="estad-plat-img" alt="">
                                    <?php endif; ?>
                                    <?= htmlspecialchars($ps['nombre']) ?>
                                </td>
                                <td class="r"><?= number_format((int)$ps['ventas']) ?></td>
                                <td class="r" style="font-weight:600;"><?= number_format((float)$ps['ingresos'], 2, ',', '.') ?> Bs</td>
                                <td class="r">
                                    <span class="estad-badge estad-badge-green"><?= (int)$ps['disponibles'] ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p class="estad-empty">No hay plataformas registradas</p>
                    <?php endif; ?>
                </div>

                <!-- Ganancias diarias últimos 30 días -->
                <div class="estad-card" style="overflow-x:auto;">
                    <h3 class="estad-card-title"><i class="fas fa-calendar-day" style="margin-right:7px;color:#22c55e;"></i>Últimos 30 días</h3>
                    <?php if (count($gananciasDiarias) > 0): ?>
                    <table class="estad-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th class="r">Ventas</th>
                                <th class="r">Ingresos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gananciasDiarias as $gd): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($gd['dia'])) ?></td>
                                <td class="r"><?= (int)$gd['ventas'] ?></td>
                                <td class="r" style="font-weight:600;"><?= number_format((float)$gd['ingresos'], 2, ',', '.') ?> Bs</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p class="estad-empty">Sin ventas en los últimos 30 días</p>
                    <?php endif; ?>
                </div>
            </div>

        </div><!-- /panel general -->

        <!-- ═══════ TAB: POR PLATAFORMA ═══════ -->
        <div id="estad-panel-plataforma" class="estad-tab-panel <?= $activeTab === 'plataforma' ? 'active' : '' ?>">

            <?php if (count($todasPlataformas) === 0): ?>
            <p class="estad-empty">No hay plataformas registradas</p>
            <?php else: ?>

            <?php foreach ($todasPlataformas as $idx => $tp):
                $pid  = (int)$tp['id'];
                $kp   = $kpiAll[$pid]   ?? ['ventas'=>0,'ingresos'=>0,'ticket'=>0];
                $disp = $dispAll[$pid]  ?? 0;
                $meses_plat = $mesAll[$pid] ?? [];
                $isFirst = ($idx === 0);
            ?>
            <div id="plat-<?= $pid ?>" class="estad-plat-block" style="display:<?= $isFirst ? 'block' : 'none' ?>;">

                <!-- KPIs -->
                <div class="estad-kpi-grid" style="margin-bottom:24px;">
                    <div class="estad-kpi-card">
                        <div class="estad-kpi-label">Ventas Totales <i class="fas fa-shopping-cart" style="color:#3b82f6;"></i></div>
                        <div class="estad-kpi-value"><?= number_format($kp['ventas']) ?></div>
                        <div class="estad-kpi-sub">Historial completo</div>
                    </div>
                    <div class="estad-kpi-card">
                        <div class="estad-kpi-label">Ingresos Totales <i class="fas fa-coins" style="color:#22c55e;"></i></div>
                        <div class="estad-kpi-value"><?= number_format($kp['ingresos'], 2, ',', '.') ?> Bs</div>
                        <div class="estad-kpi-sub">Créditos acumulados</div>
                    </div>
                    <div class="estad-kpi-card">
                        <div class="estad-kpi-label">Ticket Promedio <i class="fas fa-receipt" style="color:#8b5cf6;"></i></div>
                        <div class="estad-kpi-value"><?= number_format($kp['ticket'], 2, ',', '.') ?> Bs</div>
                        <div class="estad-kpi-sub">Por compra</div>
                    </div>
                    <div class="estad-kpi-card">
                        <div class="estad-kpi-label">Stock Disponible <i class="fas fa-boxes" style="color:#f59e0b;"></i></div>
                        <div class="estad-kpi-value"><?= number_format($disp) ?></div>
                        <div class="estad-kpi-sub">Cuentas listas para vender</div>
                    </div>
                </div>

                <!-- Tabla ventas mes a mes -->
                <div class="estad-card" style="overflow-x:auto;">
                    <h3 class="estad-card-title">
                        <?php if ($tp['imagen_url']): ?>
                        <img src="<?= htmlspecialchars($tp['imagen_url']) ?>" style="width:22px;height:22px;object-fit:contain;border-radius:4px;vertical-align:middle;margin-right:8px;" alt="">
                        <?php endif; ?>
                        <?= htmlspecialchars($tp['nombre']) ?> — ventas mes a mes (últimos 12 meses)
                    </h3>
                    <?php if (count($meses_plat) > 0): ?>
                    <table class="estad-table">
                        <thead>
                            <tr>
                                <th>Mes</th>
                                <th class="r">Ventas</th>
                                <th class="r">Ingresos</th>
                                <th class="r">Promedio/venta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($meses_plat as $pd):
                                $venMes  = (int)$pd['ventas'];
                                $ingMes  = (float)$pd['ingresos'];
                                $promMes = $venMes > 0 ? $ingMes / $venMes : 0;
                            ?>
                            <tr>
                                <td><?= $meses[(int)$pd['mes']] ?> <?= $pd['ano'] ?></td>
                                <td class="r"><?= $venMes ?></td>
                                <td class="r" style="font-weight:600;"><?= number_format($ingMes, 2, ',', '.') ?> Bs</td>
                                <td class="r"><?= number_format($promMes, 2, ',', '.') ?> Bs</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p class="estad-empty">Sin ventas en los últimos 12 meses</p>
                    <?php endif; ?>
                </div>

            </div><!-- /plat-<?= $pid ?> -->
            <?php endforeach; ?>

            <?php endif; ?>

        </div><!-- /panel plataforma -->

        <script>
        function estadSwitchTab(tab) {
            document.querySelectorAll('.estad-tab-btn').forEach(function(b){ b.classList.remove('active'); });
            document.querySelectorAll('.estad-tab-panel').forEach(function(p){ p.classList.remove('active'); });
            document.getElementById('estad-panel-' + tab).classList.add('active');
            // activar botón correcto
            document.querySelectorAll('.estad-tab-btn').forEach(function(b){
                var txt = b.textContent.trim().toLowerCase();
                if ((tab === 'general' && txt.indexOf('general') !== -1) ||
                    (tab === 'plataforma' && txt.indexOf('plataforma') !== -1)) {
                    b.classList.add('active');
                }
            });
            // mostrar/ocultar dropdown lateral
            var dw = document.getElementById('estad-plat-dropdown-wrap');
            if (dw) dw.style.display = (tab === 'plataforma') ? 'flex' : 'none';
            // update URL sin recargar
            var url = new URL(window.location.href);
            url.searchParams.set('etab', tab);
            history.replaceState(null, '', url.toString());
        }
        function estadMostrarPlat(id) {
            // Ocultar todos los bloques de plataforma
            document.querySelectorAll('.estad-plat-block').forEach(function(el){
                el.style.display = 'none';
            });
            // Mostrar el seleccionado
            var target = document.getElementById(id);
            if (target) target.style.display = 'block';
        }
        </script>
        <?php endif; ?>

        <!-- ==================== SERVICIOS SMM ==================== -->
        <?php if ($seccion == 'servicios'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > SMM Panel > Servicios</div>
        <h1 class="page-title">Servicios SMM</h1>
        
        <div class="coming-soon">
            <i class="fas fa-cogs"></i>
            <h2>Servicios SMM</h2>
            <p>Esta sección estará disponible próximamente</p>
        </div>
        <?php endif; ?>

        <!-- ==================== ÓRDENES SMM ==================== -->
        <?php if ($seccion == 'ordenes'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > SMM Panel > Órdenes</div>
        <h1 class="page-title">Órdenes SMM</h1>
        
        <div class="coming-soon">
            <i class="fas fa-shopping-cart"></i>
            <h2>Órdenes SMM</h2>
            <p>Esta sección estará disponible próximamente</p>
        </div>
        <?php endif; ?>

        <!-- ==================== TICKETS SMM ==================== -->
        <?php if ($seccion == 'tickets_smm'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > SMM Panel > Tickets</div>
        <h1 class="page-title">Tickets SMM</h1>
        
        <div class="coming-soon">
            <i class="fas fa-ticket-alt"></i>
            <h2>Tickets SMM</h2>
            <p>Esta sección estará disponible próximamente</p>
        </div>
        <?php endif; ?>

        <!-- ==================== CUENTAS STREAMING ==================== -->
        <?php if ($seccion == 'cuentas'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Streaming > Cuentas</div>
        <h1 class="page-title"><?= $editCuenta ? 'Editar Cuenta' : 'Gestión de Cuentas' ?></h1>

        <!-- Botón abrir modal (solo en modo agregar) -->
        <?php if (!$editCuenta): ?>
        <div style="margin-bottom:20px;">
            <button onclick="abrirModalCuenta()" class="btn btn-primary"><i class="fas fa-plus"></i> Agregar Cuenta</button>
        </div>
        <?php endif; ?>

        <!-- ===== MODAL AGREGAR CUENTA ===== -->
        <div id="modalAgregarCuenta" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:500; align-items:flex-start; justify-content:center; overflow-y:auto; padding:30px 16px;">
            <div style="background:#1a1a2e; border:1px solid #1f3460; border-radius:14px; width:100%; max-width:780px; margin:auto;">
                <!-- Header -->
                <div style="display:flex; justify-content:space-between; align-items:center; padding:20px 24px; border-bottom:1px solid #1f3460;">
                    <h2 style="margin:0; font-size:1.2rem; color:#fff;"><i class="fas fa-plus-circle" style="color:#3b82f6; margin-right:8px;"></i> Agregar Cuenta</h2>
                    <button onclick="cerrarModalCuenta()" style="background:none; border:none; color:#94a3b8; font-size:1.4rem; cursor:pointer; line-height:1;">&times;</button>
                </div>
                <!-- Body -->
                <form action="process.php" method="POST" style="padding:24px;">
                    <input type="hidden" name="action" value="guardar_cuenta">

                    <!-- Fila 1: Nombre del Servicio + Categoría -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nombre del Servicio</label>
                            <input type="text" name="nombre_servicio" maxlength="50" placeholder="Ej: NETFLIX" value="">
                            <small style="color:#64748b;">Max 50 characters</small>
                        </div>
                        <div class="form-group">
                            <label>Categoría</label>
                            <select name="categoria">
                                <option value="">— Seleccionar —</option>
                                <option value="Todos">Todos</option>
                                <option value="Streaming">Streaming</option>
                                <option value="Licencias">Licencias</option>
                                <option value="Cursos">Cursos</option>
                                <option value="Software-Sistemas">Software-Sistemas</option>
                            </select>
                        </div>
                    </div>

                    <!-- Fila 2: Tipo + Estado -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Tipo</label>
                            <select name="tipo_entrega" id="modalTipoEntrega" onchange="toggleEntregaModal(this)">
                                <option value="automatico">Automático</option>
                                <option value="manual">Manual</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="estado">
                                <option value="disponible">Activo</option>
                                <option value="reservada">Reservada</option>
                                <option value="vendida">Vendida</option>
                            </select>
                        </div>
                    </div>

                    <!-- Fila 3: Precio + WhatsApp Soporte + Perfiles + Días -->
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:14px;">
                        <div class="form-group">
                            <label>Precio (Bs)</label>
                            <input type="number" name="precio" step="0.01" min="0" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label>WhatsApp Soporte</label>
                            <input type="text" name="whatsapp_soporte" maxlength="30" placeholder="59172454420">
                        </div>
                        <div class="form-group" id="modalPerfilesGroup">
                            <label>Perfiles</label>
                            <input type="number" name="perfiles_disponibles" min="1" max="99" value="1" id="modalPerfilesInput">
                        </div>
                        <div class="form-group" id="modalDiasGroup">
                            <label>Días</label>
                            <input type="number" name="dias" min="1" value="30" id="modalDiasInput">
                        </div>
                    </div>

                    <!-- Fila 4: Email + Usuario -->
                    <div class="form-row">
                        <div class="form-group" id="modalCorreoGroup">
                            <label>Email</label>
                            <input type="email" name="correo" placeholder="netflix@gmail.com" required id="modalCorreoInput">
                        </div>
                        <div class="form-group" id="modalUsuarioGroup">
                            <label>Usuario</label>
                            <input type="text" name="usuario_cuenta" placeholder="Nombre de usuario (opcional)" id="modalUsuarioInput">
                        </div>
                    </div>

                    <!-- Fila 5: Contraseña + PINs -->
                    <div class="form-row">
                        <div class="form-group" style="position:relative;" id="modalPasswordGroup">
                            <label>Contraseña</label>
                            <input type="password" name="password" placeholder="••••••••••" id="modalPasswordInput" required>
                            <button type="button" onclick="toggleModalPassword()" style="position:absolute; right:10px; bottom:10px; background:none; border:none; color:#64748b; cursor:pointer; font-size:0.9rem;"><i class="fas fa-key"></i></button>
                        </div>
                        <div class="form-group" id="modalPinsGroup">
                            <label>PINs <small style="color:#64748b; font-weight:400;">(separados por coma)</small></label>
                            <input type="text" name="pins" placeholder="1234,5678,9999,4444" id="modalPinsInput">
                        </div>
                    </div>

                    <!-- Fila 6: Plataforma + Tipo de Cuenta -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Plataforma</label>
                            <select name="plataforma_id" required>
                                <option value="">Selecciona plataforma</option>
                                <?php foreach ($plataformas as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Tipo de Cuenta</label>
                            <select name="tipo_cuenta" id="modalTipoCuentaSelect" onchange="toggleModalPerfiles(this)">
                                <option value="completa">Cuenta Completa</option>
                                <option value="perfil">Perfil / Pantalla</option>
                            </select>
                        </div>
                    </div>

                    <!-- ¿Es Renovable? -->
                    <div class="form-group">
                        <label>¿Es Renovable?</label>
                        <select name="renovable">
                            <option value="1">Sí</option>
                            <option value="0">No</option>
                        </select>
                    </div>

                    <!-- Fila 7: Términos + Descripción -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Términos</label>
                            <textarea name="terminos" rows="3" placeholder="Puede cambiar el nombre del perfil.&#10;Es para 1 dispositivo."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Descripción</label>
                            <textarea name="descripcion" rows="3" placeholder="Es perfil privado.."></textarea>
                        </div>
                    </div>

                    <!-- Opciones extra -->
                    <div style="display:flex; gap:24px; flex-wrap:wrap; margin:10px 0;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.9rem;">
                            <input type="checkbox" name="oferta" value="1" style="width:auto;"> Oferta
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.9rem;">
                            <input type="checkbox" name="imap_habilitado" value="1" id="modalImapCheck" style="width:auto;">
                            <span>Configuración IMAP — Habilitar</span>
                        </label>
                    </div>

                    <!-- Botones -->
                    <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px; border-top:1px solid #1f3460; padding-top:20px;">
                        <button type="button" onclick="cerrarModalCuenta()" class="btn btn-secondary">Cancelar</button>
                        <button type="submit" class="btn btn-primary" style="min-width:100px;">Guardar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ===== FORMULARIO EDITAR CUENTA (inline, solo en modo edición) ===== -->
        <?php if ($editCuenta): ?>
        <div class="form-card">
            <h3><i class="fas fa-edit"></i> Editar Cuenta</h3>
            <form action="process.php" method="POST">
                <input type="hidden" name="action" value="editar_cuenta">
                <input type="hidden" name="id" value="<?= $editCuenta['id'] ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label>Nombre del Servicio</label>
                        <input type="text" name="nombre_servicio" maxlength="50" value="<?= htmlspecialchars($editCuenta['nombre_servicio'] ?? '') ?>" placeholder="Ej: NETFLIX">
                    </div>
                    <div class="form-group">
                        <label>Categoría</label>
                        <select name="categoria">
                            <option value="">— Seleccionar —</option>
                            <?php
                            $cats = ['Todos','Streaming','Licencias','Cursos','Software-Sistemas'];
                            $catActual = $editCuenta['categoria'] ?? '';
                            foreach ($cats as $cat):
                                $sel = ($catActual === $cat) ? 'selected' : '';
                            ?>
                            <option value="<?= $cat ?>" <?= $sel ?>><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Tipo de Entrega</label>
                        <select name="tipo_entrega" id="editTipoEntrega" onchange="toggleEntregaEdit(this)">
                            <option value="automatico" <?= ($editCuenta['tipo_entrega'] ?? 'automatico') === 'automatico' ? 'selected' : '' ?>>Automático</option>
                            <option value="manual" <?= ($editCuenta['tipo_entrega'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado">
                            <option value="disponible" <?= $editCuenta['estado'] === 'disponible' ? 'selected' : '' ?>>Activo / Disponible</option>
                            <option value="reservada" <?= $editCuenta['estado'] === 'reservada' ? 'selected' : '' ?>>Reservada</option>
                            <option value="vendida" <?= $editCuenta['estado'] === 'vendida' ? 'selected' : '' ?>>Vendida</option>
                        </select>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:14px;">
                    <div class="form-group">
                        <label>Precio (Bs)</label>
                        <input type="number" name="precio" step="0.01" min="0" required value="<?= $editCuenta['precio'] ?>">
                    </div>
                    <div class="form-group">
                        <label>WhatsApp Soporte</label>
                        <input type="text" name="whatsapp_soporte" maxlength="30" value="<?= htmlspecialchars($editCuenta['whatsapp_soporte'] ?? '') ?>">
                    </div>
                    <div class="form-group" id="editPerfilesGroup">
                        <label>Perfiles</label>
                        <input type="number" name="perfiles_disponibles" min="1" max="99" value="<?= (int)($editCuenta['perfiles_disponibles'] ?? 1) ?>" id="editPerfilesInput">
                    </div>
                    <div class="form-group" id="editDiasGroup">
                        <label>Días</label>
                        <input type="number" name="dias" min="1" value="<?= (int)($editCuenta['dias'] ?? 30) ?>" id="editDiasInput">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" id="editCorreoGroup">
                        <label>Email</label>
                        <input type="email" name="correo" required value="<?= htmlspecialchars($editCuenta['correo']) ?>" id="editCorreoInput">
                    </div>
                    <div class="form-group" id="editUsuarioGroup">
                        <label>Usuario</label>
                        <input type="text" name="usuario_cuenta" value="<?= htmlspecialchars($editCuenta['usuario_cuenta'] ?? '') ?>" id="editUsuarioInput">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" id="editPasswordGroup">
                        <label>Contraseña</label>
                        <input type="text" name="password" required value="<?= htmlspecialchars($editCuenta['password']) ?>" id="editPasswordInput">
                    </div>
                    <div class="form-group" id="editPinsGroup">
                        <label>PINs <small style="color:#64748b; font-weight:400;">(separados por coma)</small></label>
                        <input type="text" name="pins" value="<?= htmlspecialchars($editCuenta['pins'] ?? '') ?>" id="editPinsInput">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Plataforma</label>
                        <select name="plataforma_id" required>
                            <option value="">Selecciona plataforma</option>
                            <?php foreach ($plataformas as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $editCuenta['plataforma_id'] == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tipo de Cuenta</label>
                        <select name="tipo_cuenta">
                            <option value="completa" <?= ($editCuenta['tipo_cuenta'] ?? 'completa') === 'completa' ? 'selected' : '' ?>>Cuenta Completa</option>
                            <option value="perfil" <?= ($editCuenta['tipo_cuenta'] ?? '') === 'perfil' ? 'selected' : '' ?>>Perfil / Pantalla</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>¿Es Renovable?</label>
                    <select name="renovable">
                        <option value="1" <?= ($editCuenta['renovable'] ?? 1) == 1 ? 'selected' : '' ?>>Sí</option>
                        <option value="0" <?= ($editCuenta['renovable'] ?? 1) == 0 ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Términos</label>
                        <textarea name="terminos" rows="3"><?= htmlspecialchars($editCuenta['terminos'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Descripción</label>
                        <textarea name="descripcion" rows="3"><?= htmlspecialchars($editCuenta['descripcion'] ?? '') ?></textarea>
                    </div>
                </div>
                <div style="display:flex; gap:24px; flex-wrap:wrap; margin:10px 0;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="oferta" value="1" <?= !empty($editCuenta['oferta']) ? 'checked' : '' ?> style="width:auto;"> Oferta
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="imap_habilitado" value="1" <?= !empty($editCuenta['imap_habilitado']) ? 'checked' : '' ?> style="width:auto;"> Configuración IMAP — Habilitar
                    </label>
                </div>
                <div style="display:flex; gap:10px; margin-top:16px;">
                    <button type="submit" class="btn btn-primary">Actualizar</button>
                    <a href="?seccion=cuentas" class="btn btn-secondary">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <script>
        function abrirModalCuenta() {
            document.getElementById('modalAgregarCuenta').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        function cerrarModalCuenta() {
            document.getElementById('modalAgregarCuenta').style.display = 'none';
            document.body.style.overflow = '';
        }
        function toggleModalPassword() {
            const inp = document.getElementById('modalPasswordInput');
            inp.type = inp.type === 'password' ? 'text' : 'password';
        }
        function toggleModalPerfiles(sel) {
            // Legacy: called by tipo_cuenta select — no action needed here
        }

        // Called by tipo_entrega select in Agregar Cuenta modal
        function toggleEntregaModal(sel) {
            const isManual = sel.value === 'manual';
            const fields = [
                { groupId: 'modalPerfilesGroup', inputId: 'modalPerfilesInput',  req: false },
                { groupId: 'modalDiasGroup',     inputId: 'modalDiasInput',      req: false },
                { groupId: 'modalCorreoGroup',   inputId: 'modalCorreoInput',    req: true  },
                { groupId: 'modalUsuarioGroup',  inputId: 'modalUsuarioInput',   req: false },
                { groupId: 'modalPasswordGroup', inputId: 'modalPasswordInput',  req: true  },
                { groupId: 'modalPinsGroup',     inputId: 'modalPinsInput',      req: false },
            ];
            fields.forEach(function(f) {
                const grp = document.getElementById(f.groupId);
                const inp = document.getElementById(f.inputId);
                if (!grp || !inp) return;
                inp.disabled = isManual;
                if (f.req) inp.required = !isManual;
                grp.style.opacity     = isManual ? '0.45' : '';
                grp.style.pointerEvents = isManual ? 'none' : '';
            });
        }

        // Called by tipo_entrega select in Editar Cuenta form
        function toggleEntregaEdit(sel) {
            const isManual = sel.value === 'manual';
            const fields = [
                { groupId: 'editPerfilesGroup', inputId: 'editPerfilesInput',  req: false },
                { groupId: 'editDiasGroup',     inputId: 'editDiasInput',      req: false },
                { groupId: 'editCorreoGroup',   inputId: 'editCorreoInput',    req: true  },
                { groupId: 'editUsuarioGroup',  inputId: 'editUsuarioInput',   req: false },
                { groupId: 'editPasswordGroup', inputId: 'editPasswordInput',  req: true  },
                { groupId: 'editPinsGroup',     inputId: 'editPinsInput',      req: false },
            ];
            fields.forEach(function(f) {
                const grp = document.getElementById(f.groupId);
                const inp = document.getElementById(f.inputId);
                if (!grp || !inp) return;
                inp.disabled = isManual;
                if (f.req) inp.required = !isManual;
                grp.style.opacity       = isManual ? '0.45' : '';
                grp.style.pointerEvents = isManual ? 'none' : '';
            });
        }

        // Cerrar al hacer click fuera del contenido
        document.getElementById('modalAgregarCuenta').addEventListener('click', function(e) {
            if (e.target === this) cerrarModalCuenta();
        });

        // Apply edit form state on page load (in case tipo=manual is pre-selected)
        (function() {
            var editSel = document.getElementById('editTipoEntrega');
            if (editSel) toggleEntregaEdit(editSel);
        })();
        </script>
        
        <!-- Agregar plataforma -->
        <div class="form-card">
            <h3><i class="fas fa-tv"></i> Nueva Plataforma</h3>
            <form action="process.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="guardar_plataforma">
                <div class="form-row">
                    <div class="form-group">
                        <label>Nombre</label>
                        <input type="text" name="nombre" required placeholder="Netflix, Disney+, etc.">
                    </div>
                    <div class="form-group">
                        <label>Logo de la Plataforma</label>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="file" name="logo_plataforma" accept="image/*" required id="logoPlataformaInput" style="display: none;">
                            <label for="logoPlataformaInput" class="btn btn-secondary" style="cursor: pointer;">
                                <i class="fas fa-upload"></i> Seleccionar Logo
                            </label>
                            <span id="logoPlataformaFileName" style="color: #64748b;"></span>
                            <button type="submit" class="btn btn-primary">Agregar</button>
                        </div>
                    </div>
                </div>
            </form>
            <script>
                document.getElementById('logoPlataformaInput').addEventListener('change', function() {
                    document.getElementById('logoPlataformaFileName').textContent = this.files[0] ? this.files[0].name : '';
                });
            </script>
        </div>

        <script>
            (function() {
                var sel = document.getElementById('tipoCuentaSelect');
                var grp = document.getElementById('perfilesDisponiblesGroup');
                var inp = document.getElementById('perfilesDisponiblesInput');
                if (!sel || !grp) return;
                function toggle() {
                    var isPerfil = sel.value === 'perfil';
                    grp.style.display = isPerfil ? 'block' : 'none';
                    if (inp) inp.required = isPerfil;
                }
                sel.addEventListener('change', toggle);
                toggle();
            })();
        </script>

        <!-- Stats -->
        <?php
        // Pre-calcular contadores globales y por plataforma para las stat-cards
        $invStats = [
            'todas' => [
                'disponibles' => $cuentasDisponibles,
                'vendidas'    => $cuentasVendidas,
                'total'       => count($cuentas),
            ]
        ];
        foreach ($cuentas as $_c) {
            $_pid = (int)($_c['plataforma_id'] ?? 0);
            $key = 'inv-plat-' . $_pid;
            if (!isset($invStats[$key])) {
                $invStats[$key] = ['disponibles'=>0,'vendidas'=>0,'total'=>0];
            }
            $invStats[$key]['total']++;
            if ($_c['estado'] === 'disponible') $invStats[$key]['disponibles']++;
            if ($_c['estado'] === 'vendida')    $invStats[$key]['vendidas']++;
        }
        ?>
        <div class="stat-cards" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 20px;">
            <div class="stat-card green">
                <div class="title">Disponibles</div>
                <div class="value" id="inv-stat-disponibles"><?= $cuentasDisponibles ?></div>
            </div>
            <div class="stat-card orange">
                <div class="title">Vendidas</div>
                <div class="value" id="inv-stat-vendidas"><?= $cuentasVendidas ?></div>
            </div>
            <div class="stat-card purple">
                <div class="title">Total</div>
                <div class="value" id="inv-stat-total"><?= count($cuentas) ?></div>
            </div>
        </div>
        
        <!-- Tabla de cuentas -->
        <div class="table-container">
            <div class="table-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-key"></i>
                    <h3>Inventario de Cuentas</h3>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-filter" style="color:#94a3b8; font-size:0.85rem;"></i>
                    <select id="invPlatSelect" onchange="invMostrarPlat(this.value)"
                        style="padding:7px 12px; border:1px solid #cbd5e1; border-radius:7px; background:#fff; color:#1e293b; font-size:0.88rem; cursor:pointer; outline:none; min-width:160px;">
                        <option value="todas">Todas las plataformas</option>
                        <?php
                        // Agrupar cuentas por plataforma
                        $cuentasPorPlat = [];
                        foreach ($cuentas as $c) {
                            $pid  = (int)($c['plataforma_id'] ?? 0);
                            $pnom = $c['plataforma_nombre'] ?? 'Sin plataforma';
                            if (!isset($cuentasPorPlat[$pid])) {
                                $cuentasPorPlat[$pid] = [
                                    'nombre'    => $pnom,
                                    'imagen'    => $c['imagen_url'] ?? '',
                                    'perfiles'  => [],
                                    'completas' => [],
                                ];
                            }
                            if (($c['tipo_cuenta'] ?? 'completa') === 'perfil') {
                                $cuentasPorPlat[$pid]['perfiles'][]  = $c;
                            } else {
                                $cuentasPorPlat[$pid]['completas'][] = $c;
                            }
                        }
                        foreach ($cuentasPorPlat as $pid => $pd): ?>
                        <option value="inv-plat-<?= $pid ?>">
                            <?= htmlspecialchars($pd['nombre']) ?>
                            (<?= count($pd['perfiles']) + count($pd['completas']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php /* ── BLOQUE "TODAS" ── */ ?>
            <div id="inv-plat-todas" class="inv-plat-block">
                <table>
                    <thead>
                        <tr>
                            <th>Plataforma</th>
                            <th>Correo</th>
                            <th>Contraseña</th>
                            <th>Precio</th>
                            <th>Tipo</th>
                            <th>Perfiles</th>
                            <th>Oferta</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cuentas as $c): ?>
                        <tr>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <img src="<?= htmlspecialchars($c['imagen_url'] ?? '') ?>" style="width:24px; height:24px; object-fit:contain;" onerror="this.style.display='none'">
                                    <span><?= htmlspecialchars($c['plataforma_nombre'] ?? 'N/A') ?></span>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($c['correo']) ?></td>
                            <td><span class="password-blur"><?= htmlspecialchars($c['password']) ?></span></td>
                            <td style="color:#10b981; font-weight:600;"><?= number_format($c['precio'], 2) ?> Bs</td>
                            <td>
                                <?php if (($c['tipo_cuenta'] ?? 'completa') == 'perfil'): ?>
                                    <span class="badge badge-info"><i class="fas fa-user"></i> Perfil</span>
                                <?php else: ?>
                                    <span class="badge badge-success"><i class="fas fa-cube"></i> Completa</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($c['tipo_cuenta'] ?? 'completa') == 'perfil'): ?>
                                    <span><?= (int)($c['perfiles_disponibles'] ?? 1) ?></span>
                                <?php else: ?>
                                    <span style="color:#64748b;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['oferta'])): ?>
                                    <span class="badge badge-warning"><i class="fas fa-tag"></i> Sí</span>
                                <?php else: ?>
                                    <span style="color:#64748b;">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $bc = $c['estado']=='disponible' ? 'badge-success' : ($c['estado']=='vendida' ? 'badge-danger' : 'badge-warning'); ?>
                                <span class="badge <?= $bc ?>"><?= ucfirst($c['estado']) ?></span>
                            </td>
                            <td>
                                <a href="?edit=<?= $c['id'] ?>" style="color:#3b82f6; margin-right:10px;">Editar</a>
                                <a href="process.php?action=eliminar_cuenta&id=<?= $c['id'] ?>" onclick="gEliminar(event,'¿Eliminar esta cuenta?',this.href); return false;" style="color:#ef4444;">Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php /* ── BLOQUES POR PLATAFORMA ── */ ?>
            <?php foreach ($cuentasPorPlat as $pid => $pd): ?>
            <div id="inv-plat-<?= $pid ?>" class="inv-plat-block" style="display:none;">

                <?php /* Sub-tabla: Perfiles */ ?>
                <?php if (count($pd['perfiles']) > 0): ?>
                <div style="padding:14px 20px 6px; display:flex; align-items:center; gap:8px;">
                    <?php if ($pd['imagen']): ?>
                    <img src="<?= htmlspecialchars($pd['imagen']) ?>" style="width:22px;height:22px;object-fit:contain;border-radius:4px;" onerror="this.style.display='none'">
                    <?php endif; ?>
                    <span style="font-weight:700; font-size:0.92rem; color:#1e293b;"><?= htmlspecialchars($pd['nombre']) ?></span>
                    <span class="badge badge-info" style="margin-left:4px;"><i class="fas fa-user" style="margin-right:4px;"></i>Perfiles (<?= count($pd['perfiles']) ?>)</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Correo</th>
                            <th>Contraseña</th>
                            <th>Precio</th>
                            <th>Perfiles disp.</th>
                            <th>Oferta</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pd['perfiles'] as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['correo']) ?></td>
                            <td><span class="password-blur"><?= htmlspecialchars($c['password']) ?></span></td>
                            <td style="color:#10b981; font-weight:600;"><?= number_format($c['precio'], 2) ?> Bs</td>
                            <td style="text-align:center;"><?= (int)($c['perfiles_disponibles'] ?? 1) ?></td>
                            <td>
                                <?php if (!empty($c['oferta'])): ?>
                                    <span class="badge badge-warning"><i class="fas fa-tag"></i> Sí</span>
                                <?php else: ?>
                                    <span style="color:#64748b;">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $bc = $c['estado']=='disponible' ? 'badge-success' : ($c['estado']=='vendida' ? 'badge-danger' : 'badge-warning'); ?>
                                <span class="badge <?= $bc ?>"><?= ucfirst($c['estado']) ?></span>
                            </td>
                            <td>
                                <a href="?edit=<?= $c['id'] ?>" style="color:#3b82f6; margin-right:10px;">Editar</a>
                                <a href="process.php?action=eliminar_cuenta&id=<?= $c['id'] ?>" onclick="gEliminar(event,'¿Eliminar esta cuenta?',this.href); return false;" style="color:#ef4444;">Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php /* Sub-tabla: Completas */ ?>
                <?php if (count($pd['completas']) > 0): ?>
                <div style="padding:<?= count($pd['perfiles']) > 0 ? '18px' : '14px' ?> 20px 6px; display:flex; align-items:center; gap:8px; <?= count($pd['perfiles']) > 0 ? 'border-top:2px solid #e5e7eb; margin-top:8px;' : '' ?>">
                    <?php if ($pd['imagen']): ?>
                    <img src="<?= htmlspecialchars($pd['imagen']) ?>" style="width:22px;height:22px;object-fit:contain;border-radius:4px;" onerror="this.style.display='none'">
                    <?php endif; ?>
                    <span style="font-weight:700; font-size:0.92rem; color:#1e293b;"><?= htmlspecialchars($pd['nombre']) ?></span>
                    <span class="badge badge-success" style="margin-left:4px;"><i class="fas fa-cube" style="margin-right:4px;"></i>Cuentas Completas (<?= count($pd['completas']) ?>)</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Correo</th>
                            <th>Contraseña</th>
                            <th>Precio</th>
                            <th>Precio Pro</th>
                            <th>Oferta</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pd['completas'] as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['correo']) ?></td>
                            <td><span class="password-blur"><?= htmlspecialchars($c['password']) ?></span></td>
                            <td style="color:#10b981; font-weight:600;"><?= number_format($c['precio'], 2) ?> Bs</td>
                            <td style="color:#64748b;"><?= !empty($c['precio_pro']) ? number_format($c['precio_pro'], 2).' Bs' : '—' ?></td>
                            <td>
                                <?php if (!empty($c['oferta'])): ?>
                                    <span class="badge badge-warning"><i class="fas fa-tag"></i> Sí</span>
                                <?php else: ?>
                                    <span style="color:#64748b;">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $bc = $c['estado']=='disponible' ? 'badge-success' : ($c['estado']=='vendida' ? 'badge-danger' : 'badge-warning'); ?>
                                <span class="badge <?= $bc ?>"><?= ucfirst($c['estado']) ?></span>
                            </td>
                            <td>
                                <a href="?edit=<?= $c['id'] ?>" style="color:#3b82f6; margin-right:10px;">Editar</a>
                                <a href="process.php?action=eliminar_cuenta&id=<?= $c['id'] ?>" onclick="gEliminar(event,'¿Eliminar esta cuenta?',this.href); return false;" style="color:#ef4444;">Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (count($pd['perfiles']) === 0 && count($pd['completas']) === 0): ?>
                <p style="color:#94a3b8; text-align:center; padding:24px 0; font-size:0.9rem;">Sin cuentas para esta plataforma</p>
                <?php endif; ?>

            </div><!-- /inv-plat-<?= $pid ?> -->
            <?php endforeach; ?>

        </div><!-- /table-container -->

        <script>
        var invStats = <?= json_encode($invStats, JSON_UNESCAPED_UNICODE) ?>;

        function invMostrarPlat(val) {
            // Mostrar/ocultar tablas
            document.querySelectorAll('.inv-plat-block').forEach(function(el){
                el.style.display = 'none';
            });
            var target = val === 'todas'
                ? document.getElementById('inv-plat-todas')
                : document.getElementById(val);
            if (target) target.style.display = 'block';

            // Actualizar stat-cards
            var s = invStats[val] || invStats['todas'];
            invAnimStat('inv-stat-disponibles', s.disponibles);
            invAnimStat('inv-stat-vendidas',    s.vendidas);
            invAnimStat('inv-stat-total',        s.total);
        }

        function invAnimStat(id, newVal) {
            var el = document.getElementById(id);
            if (!el) return;
            el.style.transition = 'opacity .15s';
            el.style.opacity = '0';
            setTimeout(function(){
                el.textContent = newVal;
                el.style.opacity = '1';
            }, 150);
        }
        </script>
        <?php endif; ?>

        <!-- ==================== PRODUCTOS (Licencias / Cursos / Software-Sistemas) ==================== -->
        <?php if ($seccion == 'productos'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Gestión > <?= htmlspecialchars($subProducto) ?></div>
        <h1 class="page-title"><i class="fas fa-layer-group" style="margin-right:8px;"></i> Gestión</h1>

        <!-- Tabs de sub-categoría -->
        <div style="display:flex; gap:8px; margin-bottom:24px; flex-wrap:wrap;">
            <?php foreach(['Streaming'=>'fa-play-circle','Licencias'=>'fa-key','Cursos'=>'fa-graduation-cap','Software-Sistemas'=>'fa-desktop'] as $tab=>$tabIcon):
                $isActive = $subProducto === $tab;
            ?>
            <a href="?seccion=productos&subp=<?= $tab ?>" style="
                display:inline-flex; align-items:center; gap:7px;
                padding:9px 20px; border-radius:8px; font-weight:600; font-size:0.9rem;
                text-decoration:none; transition:all 0.2s;
                background:<?= $isActive ? 'var(--primary-color,#3b82f6)' : '#1e293b' ?>;
                color:<?= $isActive ? '#fff' : '#94a3b8' ?>;
                border:1px solid <?= $isActive ? 'var(--primary-color,#3b82f6)' : '#334155' ?>;
            "><i class="fas <?= $tabIcon ?>"></i> <?= $tab ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($subProducto === 'Streaming'): ?>
        <!-- ===== SUB-SECCIÓN STREAMING: reutiliza tabla de cuentas ===== -->

        <?php if (!$editCuenta): ?>
        <div style="margin-bottom:20px;">
            <button onclick="abrirModalCuenta()" class="btn btn-primary"><i class="fas fa-plus"></i> Agregar Cuenta</button>
        </div>
        <?php endif; ?>

        <!-- Modal Agregar Cuenta (Gestión > Streaming) -->
        <div id="modalAgregarCuenta" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:500; align-items:flex-start; justify-content:center; overflow-y:auto; padding:30px 16px;">
            <div style="background:#1a1a2e; border:1px solid #1f3460; border-radius:14px; width:100%; max-width:780px; margin:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center; padding:20px 24px; border-bottom:1px solid #1f3460;">
                    <h2 style="margin:0; font-size:1.2rem; color:#fff;"><i class="fas fa-plus-circle" style="color:#3b82f6; margin-right:8px;"></i> Agregar Cuenta</h2>
                    <button onclick="cerrarModalCuenta()" style="background:none; border:none; color:#94a3b8; font-size:1.4rem; cursor:pointer; line-height:1;">&times;</button>
                </div>
                <form action="process.php" method="POST" style="padding:24px;">
                    <input type="hidden" name="action" value="guardar_cuenta">
                    <input type="hidden" name="_redirect_seccion" value="productos&subp=Streaming">

                    <!-- Fila 1: Nombre del Servicio + Categoría -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Nombre del Servicio</label>
                            <input type="text" name="nombre_servicio" maxlength="50" placeholder="Ej: NETFLIX" value="">
                            <small style="color:#64748b;">Max 50 characters</small>
                        </div>
                        <div class="form-group">
                            <label>Categoría</label>
                            <select name="categoria">
                                <option value="">— Seleccionar —</option>
                                <option value="Todos">Todos</option>
                                <option value="Streaming" selected>Streaming</option>
                                <option value="Licencias">Licencias</option>
                                <option value="Cursos">Cursos</option>
                                <option value="Software-Sistemas">Software-Sistemas</option>
                            </select>
                        </div>
                    </div>

                    <!-- Fila 2: Tipo + Estado -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Tipo</label>
                            <select name="tipo_entrega" id="p2modalTipoEntrega" onchange="toggleEntregaModal2(this)">
                                <option value="automatico">Automático</option>
                                <option value="manual">Manual</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="estado">
                                <option value="disponible">Activo</option>
                                <option value="reservada">Reservada</option>
                                <option value="vendida">Vendida</option>
                            </select>
                        </div>
                    </div>

                    <!-- Fila 3: Precio + WhatsApp + Perfiles + Días -->
                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:14px;">
                        <div class="form-group">
                            <label>Precio (Bs)</label>
                            <input type="number" name="precio" step="0.01" min="0" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label>WhatsApp Soporte</label>
                            <input type="text" name="whatsapp_soporte" maxlength="30" placeholder="59172454420">
                        </div>
                        <div class="form-group" id="p2modalPerfilesGroup">
                            <label>Perfiles</label>
                            <input type="number" name="perfiles_disponibles" min="1" max="99" value="1" id="p2modalPerfilesInput">
                        </div>
                        <div class="form-group" id="p2modalDiasGroup">
                            <label>Días</label>
                            <input type="number" name="dias" min="1" value="30" id="p2modalDiasInput">
                        </div>
                    </div>

                    <!-- Fila 4: Email + Usuario -->
                    <div class="form-row">
                        <div class="form-group" id="p2modalCorreoGroup">
                            <label>Email</label>
                            <input type="email" name="correo" placeholder="netflix@gmail.com" required id="p2modalCorreoInput">
                        </div>
                        <div class="form-group" id="p2modalUsuarioGroup">
                            <label>Usuario</label>
                            <input type="text" name="usuario_cuenta" placeholder="Nombre de usuario (opcional)" id="p2modalUsuarioInput">
                        </div>
                    </div>

                    <!-- Fila 5: Contraseña + PINs -->
                    <div class="form-row">
                        <div class="form-group" style="position:relative;" id="p2modalPasswordGroup">
                            <label>Contraseña</label>
                            <input type="password" name="password" placeholder="••••••••••" id="p2modalPasswordInput" required>
                            <button type="button" onclick="toggleP2ModalPassword()" style="position:absolute; right:10px; bottom:10px; background:none; border:none; color:#64748b; cursor:pointer; font-size:0.9rem;"><i class="fas fa-key"></i></button>
                        </div>
                        <div class="form-group" id="p2modalPinsGroup">
                            <label>PINs <small style="color:#64748b; font-weight:400;">(separados por coma)</small></label>
                            <input type="text" name="pins" placeholder="1234,5678,9999,4444" id="p2modalPinsInput">
                        </div>
                    </div>

                    <!-- Fila 6: Plataforma + Tipo de Cuenta -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Plataforma</label>
                            <select name="plataforma_id" required>
                                <option value="">Selecciona plataforma</option>
                                <?php foreach ($plataformas as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Tipo de Cuenta</label>
                            <select name="tipo_cuenta" id="p2modalTipoCuentaSelect" onchange="toggleP2ModalPerfiles(this)">
                                <option value="completa">Cuenta Completa</option>
                                <option value="perfil">Perfil / Pantalla</option>
                            </select>
                        </div>
                    </div>

                    <!-- ¿Es Renovable? -->
                    <div class="form-group">
                        <label>¿Es Renovable?</label>
                        <select name="renovable">
                            <option value="1">Sí</option>
                            <option value="0">No</option>
                        </select>
                    </div>

                    <!-- Fila 7: Términos + Descripción -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Términos</label>
                            <textarea name="terminos" rows="3" placeholder="Puede cambiar el nombre del perfil.&#10;Es para 1 dispositivo."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Descripción</label>
                            <textarea name="descripcion" rows="3" placeholder="Es perfil privado.."></textarea>
                        </div>
                    </div>

                    <!-- Opciones extra -->
                    <div style="display:flex; gap:24px; flex-wrap:wrap; margin:10px 0;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.9rem;">
                            <input type="checkbox" name="oferta" value="1" style="width:auto;"> Oferta
                        </label>
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.9rem;">
                            <input type="checkbox" name="imap_habilitado" value="1" id="p2modalImapCheck" style="width:auto;">
                            <span>Configuración IMAP — Habilitar</span>
                        </label>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px; border-top:1px solid #1f3460; padding-top:20px;">
                        <button type="button" onclick="cerrarModalCuenta()" class="btn btn-secondary">Cancelar</button>
                        <button type="submit" class="btn btn-primary" style="min-width:100px;">Guardar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Agregar plataforma -->
        <div class="form-card">
            <h3><i class="fas fa-tv"></i> Nueva Plataforma</h3>
            <form action="process.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="guardar_plataforma">
                <div class="form-row">
                    <div class="form-group">
                        <label>Nombre</label>
                        <input type="text" name="nombre" required placeholder="Netflix, Disney+, etc.">
                    </div>
                    <div class="form-group">
                        <label>Logo de la Plataforma</label>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <input type="file" name="logo_plataforma" accept="image/*" required id="p2logoPlataformaInput" style="display: none;">
                            <label for="p2logoPlataformaInput" class="btn btn-secondary" style="cursor: pointer;">
                                <i class="fas fa-upload"></i> Seleccionar Logo
                            </label>
                            <span id="p2logoPlataformaFileName" style="color: #64748b;"></span>
                            <button type="submit" class="btn btn-primary">Agregar</button>
                        </div>
                    </div>
                </div>
            </form>
            <script>
                document.getElementById('p2logoPlataformaInput').addEventListener('change', function() {
                    document.getElementById('p2logoPlataformaFileName').textContent = this.files[0] ? this.files[0].name : '';
                });
            </script>
        </div>

        <?php
        // Pre-calcular contadores globales y por plataforma para las stat-cards
        $p2invStats = [
            'todas' => [
                'disponibles' => $cuentasDisponibles,
                'vendidas'    => $cuentasVendidas,
                'total'       => count($cuentas),
            ]
        ];
        $p2cuentasPorPlat = [];
        foreach ($cuentas as $_c) {
            $_pid = (int)($_c['plataforma_id'] ?? 0);
            $key = 'p2inv-plat-' . $_pid;
            if (!isset($p2invStats[$key])) {
                $p2invStats[$key] = ['disponibles'=>0,'vendidas'=>0,'total'=>0];
            }
            $p2invStats[$key]['total']++;
            if ($_c['estado'] === 'disponible') $p2invStats[$key]['disponibles']++;
            if ($_c['estado'] === 'vendida')    $p2invStats[$key]['vendidas']++;

            if (!isset($p2cuentasPorPlat[$_pid])) {
                $p2cuentasPorPlat[$_pid] = [
                    'nombre'    => $_c['plataforma_nombre'] ?? 'Sin plataforma',
                    'imagen'    => $_c['imagen_url'] ?? '',
                    'perfiles'  => [],
                    'completas' => [],
                ];
            }
            if (($_c['tipo_cuenta'] ?? 'completa') === 'perfil') {
                $p2cuentasPorPlat[$_pid]['perfiles'][]  = $_c;
            } else {
                $p2cuentasPorPlat[$_pid]['completas'][] = $_c;
            }
        }
        ?>

        <!-- Stat-cards -->
        <div class="stat-cards" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 20px;">
            <div class="stat-card green">
                <div class="title">Disponibles</div>
                <div class="value" id="p2inv-stat-disponibles"><?= $cuentasDisponibles ?></div>
            </div>
            <div class="stat-card orange">
                <div class="title">Vendidas</div>
                <div class="value" id="p2inv-stat-vendidas"><?= $cuentasVendidas ?></div>
            </div>
            <div class="stat-card purple">
                <div class="title">Total</div>
                <div class="value" id="p2inv-stat-total"><?= count($cuentas) ?></div>
            </div>
        </div>

        <!-- Tabla de cuentas con filtro por plataforma -->
        <div class="table-container">
            <div class="table-header" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-key"></i>
                    <h3>Inventario de Cuentas</h3>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-filter" style="color:#94a3b8; font-size:0.85rem;"></i>
                    <select id="p2invPlatSelect" onchange="p2invMostrarPlat(this.value)"
                        style="padding:7px 12px; border:1px solid #cbd5e1; border-radius:7px; background:#fff; color:#1e293b; font-size:0.88rem; cursor:pointer; outline:none; min-width:160px;">
                        <option value="todas">Todas las plataformas</option>
                        <?php foreach ($p2cuentasPorPlat as $pid => $pd): ?>
                        <option value="p2inv-plat-<?= $pid ?>">
                            <?= htmlspecialchars($pd['nombre']) ?>
                            (<?= count($pd['perfiles']) + count($pd['completas']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php /* ── BLOQUE "TODAS" ── */ ?>
            <div id="p2inv-plat-todas" class="p2inv-plat-block">
                <table>
                    <thead>
                        <tr>
                            <th>Plataforma</th>
                            <th>Correo</th>
                            <th>Contraseña</th>
                            <th>Precio</th>
                            <th>Tipo</th>
                            <th>Perfiles</th>
                            <th>Oferta</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cuentas as $c): ?>
                        <tr>
                            <td>
                                <div style="display:flex; align-items:center; gap:8px;">
                                    <img src="<?= htmlspecialchars($c['imagen_url'] ?? '') ?>" style="width:24px; height:24px; object-fit:contain;" onerror="this.style.display='none'">
                                    <span><?= htmlspecialchars($c['plataforma_nombre'] ?? 'N/A') ?></span>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($c['correo']) ?></td>
                            <td><span class="password-blur"><?= htmlspecialchars($c['password']) ?></span></td>
                            <td style="color:#10b981; font-weight:600;"><?= number_format($c['precio'], 2) ?> Bs</td>
                            <td>
                                <?php if (($c['tipo_cuenta'] ?? 'completa') == 'perfil'): ?>
                                    <span class="badge badge-info"><i class="fas fa-user"></i> Perfil</span>
                                <?php else: ?>
                                    <span class="badge badge-success"><i class="fas fa-cube"></i> Completa</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (($c['tipo_cuenta'] ?? 'completa') == 'perfil'): ?>
                                    <span><?= (int)($c['perfiles_disponibles'] ?? 1) ?></span>
                                <?php else: ?>
                                    <span style="color:#64748b;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['oferta'])): ?>
                                    <span class="badge badge-warning"><i class="fas fa-tag"></i> Sí</span>
                                <?php else: ?>
                                    <span style="color:#64748b;">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $bc = $c['estado']=='disponible' ? 'badge-success' : ($c['estado']=='vendida' ? 'badge-danger' : 'badge-warning'); ?>
                                <span class="badge <?= $bc ?>"><?= ucfirst($c['estado']) ?></span>
                            </td>
                            <td>
                                <a href="?seccion=productos&subp=Streaming&edit=<?= $c['id'] ?>" style="color:#3b82f6; margin-right:10px;">Editar</a>
                                <a href="process.php?action=eliminar_cuenta&id=<?= $c['id'] ?>" onclick="gEliminar(event,'¿Eliminar esta cuenta?',this.href); return false;" style="color:#ef4444;">Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php /* ── BLOQUES POR PLATAFORMA ── */ ?>
            <?php foreach ($p2cuentasPorPlat as $pid => $pd): ?>
            <div id="p2inv-plat-<?= $pid ?>" class="p2inv-plat-block" style="display:none;">

                <?php if (count($pd['perfiles']) > 0): ?>
                <div style="padding:14px 20px 6px; display:flex; align-items:center; gap:8px;">
                    <?php if ($pd['imagen']): ?>
                    <img src="<?= htmlspecialchars($pd['imagen']) ?>" style="width:22px;height:22px;object-fit:contain;border-radius:4px;" onerror="this.style.display='none'">
                    <?php endif; ?>
                    <span style="font-weight:700; font-size:0.92rem; color:#1e293b;"><?= htmlspecialchars($pd['nombre']) ?></span>
                    <span class="badge badge-info" style="margin-left:4px;"><i class="fas fa-user" style="margin-right:4px;"></i>Perfiles (<?= count($pd['perfiles']) ?>)</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Correo</th>
                            <th>Contraseña</th>
                            <th>Precio</th>
                            <th>Perfiles disp.</th>
                            <th>Oferta</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pd['perfiles'] as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['correo']) ?></td>
                            <td><span class="password-blur"><?= htmlspecialchars($c['password']) ?></span></td>
                            <td style="color:#10b981; font-weight:600;"><?= number_format($c['precio'], 2) ?> Bs</td>
                            <td style="text-align:center;"><?= (int)($c['perfiles_disponibles'] ?? 1) ?></td>
                            <td>
                                <?php if (!empty($c['oferta'])): ?>
                                    <span class="badge badge-warning"><i class="fas fa-tag"></i> Sí</span>
                                <?php else: ?>
                                    <span style="color:#64748b;">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $bc = $c['estado']=='disponible' ? 'badge-success' : ($c['estado']=='vendida' ? 'badge-danger' : 'badge-warning'); ?>
                                <span class="badge <?= $bc ?>"><?= ucfirst($c['estado']) ?></span>
                            </td>
                            <td>
                                <a href="?seccion=productos&subp=Streaming&edit=<?= $c['id'] ?>" style="color:#3b82f6; margin-right:10px;">Editar</a>
                                <a href="process.php?action=eliminar_cuenta&id=<?= $c['id'] ?>" onclick="gEliminar(event,'¿Eliminar esta cuenta?',this.href); return false;" style="color:#ef4444;">Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (count($pd['completas']) > 0): ?>
                <div style="padding:<?= count($pd['perfiles']) > 0 ? '18px' : '14px' ?> 20px 6px; display:flex; align-items:center; gap:8px; <?= count($pd['perfiles']) > 0 ? 'border-top:2px solid #e5e7eb; margin-top:8px;' : '' ?>">
                    <?php if ($pd['imagen']): ?>
                    <img src="<?= htmlspecialchars($pd['imagen']) ?>" style="width:22px;height:22px;object-fit:contain;border-radius:4px;" onerror="this.style.display='none'">
                    <?php endif; ?>
                    <span style="font-weight:700; font-size:0.92rem; color:#1e293b;"><?= htmlspecialchars($pd['nombre']) ?></span>
                    <span class="badge badge-success" style="margin-left:4px;"><i class="fas fa-cube" style="margin-right:4px;"></i>Cuentas Completas (<?= count($pd['completas']) ?>)</span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Correo</th>
                            <th>Contraseña</th>
                            <th>Precio</th>
                            <th>Precio Pro</th>
                            <th>Oferta</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pd['completas'] as $c): ?>
                        <tr>
                            <td><?= htmlspecialchars($c['correo']) ?></td>
                            <td><span class="password-blur"><?= htmlspecialchars($c['password']) ?></span></td>
                            <td style="color:#10b981; font-weight:600;"><?= number_format($c['precio'], 2) ?> Bs</td>
                            <td style="color:#64748b;"><?= !empty($c['precio_pro']) ? number_format($c['precio_pro'], 2).' Bs' : '—' ?></td>
                            <td>
                                <?php if (!empty($c['oferta'])): ?>
                                    <span class="badge badge-warning"><i class="fas fa-tag"></i> Sí</span>
                                <?php else: ?>
                                    <span style="color:#64748b;">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php $bc = $c['estado']=='disponible' ? 'badge-success' : ($c['estado']=='vendida' ? 'badge-danger' : 'badge-warning'); ?>
                                <span class="badge <?= $bc ?>"><?= ucfirst($c['estado']) ?></span>
                            </td>
                            <td>
                                <a href="?seccion=productos&subp=Streaming&edit=<?= $c['id'] ?>" style="color:#3b82f6; margin-right:10px;">Editar</a>
                                <a href="process.php?action=eliminar_cuenta&id=<?= $c['id'] ?>" onclick="gEliminar(event,'¿Eliminar esta cuenta?',this.href); return false;" style="color:#ef4444;">Eliminar</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (count($pd['perfiles']) === 0 && count($pd['completas']) === 0): ?>
                <p style="color:#94a3b8; text-align:center; padding:24px 0; font-size:0.9rem;">Sin cuentas para esta plataforma</p>
                <?php endif; ?>

            </div><!-- /p2inv-plat-<?= $pid ?> -->
            <?php endforeach; ?>

        </div><!-- /table-container -->

        <script>
        function abrirModalCuenta() {
            document.getElementById('modalAgregarCuenta').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        function cerrarModalCuenta() {
            document.getElementById('modalAgregarCuenta').style.display = 'none';
            document.body.style.overflow = '';
        }
        function toggleEntregaModal2(sel) {
            const isManual = sel.value === 'manual';
            const fields = [
                { groupId: 'p2modalPerfilesGroup', inputId: 'p2modalPerfilesInput',  req: false },
                { groupId: 'p2modalDiasGroup',     inputId: 'p2modalDiasInput',      req: false },
                { groupId: 'p2modalCorreoGroup',   inputId: 'p2modalCorreoInput',    req: true  },
                { groupId: 'p2modalUsuarioGroup',  inputId: 'p2modalUsuarioInput',   req: false },
                { groupId: 'p2modalPasswordGroup', inputId: 'p2modalPasswordInput',  req: true  },
                { groupId: 'p2modalPinsGroup',     inputId: 'p2modalPinsInput',      req: false },
            ];
            fields.forEach(function(f) {
                const grp = document.getElementById(f.groupId);
                const inp = document.getElementById(f.inputId);
                if (!grp || !inp) return;
                inp.disabled = isManual;
                if (f.req) inp.required = !isManual;
                grp.style.opacity = isManual ? '0.45' : '';
                grp.style.pointerEvents = isManual ? 'none' : '';
            });
        }
        document.getElementById('modalAgregarCuenta').addEventListener('click', function(e) {
            if (e.target === this) cerrarModalCuenta();
        });
        function toggleP2ModalPassword() {
            var inp = document.getElementById('p2modalPasswordInput');
            if (inp) inp.type = inp.type === 'password' ? 'text' : 'password';
        }
        function toggleP2ModalPerfiles(sel) {
            var grp = document.getElementById('p2modalPerfilesGroup');
            if (grp) grp.style.display = sel.value === 'perfil' ? '' : '';
        }
        function toggleEntregaP2Edit(sel) {
            const isManual = sel.value === 'manual';
            const fields = [
                { groupId: 'p2editPerfilesGroup', inputId: 'p2editPerfilesInput',  req: false },
                { groupId: 'p2editDiasGroup',     inputId: 'p2editDiasInput',      req: false },
                { groupId: 'p2editCorreoGroup',   inputId: 'p2editCorreoInput',    req: true  },
                { groupId: 'p2editUsuarioGroup',  inputId: 'p2editUsuarioInput',   req: false },
                { groupId: 'p2editPasswordGroup', inputId: 'p2editPasswordInput',  req: true  },
                { groupId: 'p2editPinsGroup',     inputId: 'p2editPinsInput',      req: false },
            ];
            fields.forEach(function(f) {
                const grp = document.getElementById(f.groupId);
                const inp = document.getElementById(f.inputId);
                if (!grp || !inp) return;
                inp.disabled = isManual;
                if (f.req) inp.required = !isManual;
                grp.style.opacity = isManual ? '0.45' : '';
                grp.style.pointerEvents = isManual ? 'none' : '';
            });
        }
        function toggleP2EditPerfiles(sel) {
            // Placeholder: comportamiento igual a la sección original si aplica
        }

        // Filtro por plataforma + stats (Gestión > Streaming)
        var p2invStats = <?= json_encode($p2invStats, JSON_UNESCAPED_UNICODE) ?>;

        function p2invMostrarPlat(val) {
            document.querySelectorAll('.p2inv-plat-block').forEach(function(el){
                el.style.display = 'none';
            });
            var target = val === 'todas'
                ? document.getElementById('p2inv-plat-todas')
                : document.getElementById(val);
            if (target) target.style.display = 'block';

            var s = p2invStats[val] || p2invStats['todas'];
            p2invAnimStat('p2inv-stat-disponibles', s.disponibles);
            p2invAnimStat('p2inv-stat-vendidas',    s.vendidas);
            p2invAnimStat('p2inv-stat-total',        s.total);
        }

        function p2invAnimStat(id, newVal) {
            var el = document.getElementById(id);
            if (!el) return;
            el.style.transition = 'opacity .15s';
            el.style.opacity = '0';
            setTimeout(function(){
                el.textContent = newVal;
                el.style.opacity = '1';
            }, 150);
        }

        // Aplicar estado del form edición al cargar (si tipo=manual está pre-seleccionado)
        (function() {
            var editSel = document.getElementById('p2editTipoEntrega');
            if (editSel) toggleEntregaP2Edit(editSel);
        })();
        </script>

        <!-- Formulario edición de cuenta inline (cuando viene desde Gestión > Streaming) -->
        <?php if ($editCuenta): ?>
        <div class="form-card" style="margin-top:16px;">
            <h3><i class="fas fa-edit"></i> Editar Cuenta — <?= htmlspecialchars($editCuenta['nombre_servicio'] ?: 'Cuenta') ?></h3>
            <form action="process.php" method="POST">
                <input type="hidden" name="action" value="editar_cuenta">
                <input type="hidden" name="id" value="<?= $editCuenta['id'] ?>">
                <input type="hidden" name="_redirect_seccion" value="productos&subp=Streaming">

                <div class="form-row">
                    <div class="form-group">
                        <label>Nombre del Servicio</label>
                        <input type="text" name="nombre_servicio" maxlength="50" value="<?= htmlspecialchars($editCuenta['nombre_servicio'] ?? '') ?>" placeholder="Ej: NETFLIX">
                    </div>
                    <div class="form-group">
                        <label>Categoría</label>
                        <select name="categoria">
                            <option value="">— Seleccionar —</option>
                            <?php
                            $cats = ['Todos','Streaming','Licencias','Cursos','Software-Sistemas'];
                            $catActual = $editCuenta['categoria'] ?? '';
                            foreach ($cats as $cat):
                                $sel = ($catActual === $cat) ? 'selected' : '';
                            ?>
                            <option value="<?= $cat ?>" <?= $sel ?>><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Tipo de Entrega</label>
                        <select name="tipo_entrega" id="p2editTipoEntrega" onchange="toggleEntregaP2Edit(this)">
                            <option value="automatico" <?= ($editCuenta['tipo_entrega'] ?? 'automatico') === 'automatico' ? 'selected' : '' ?>>Automático</option>
                            <option value="manual" <?= ($editCuenta['tipo_entrega'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado">
                            <option value="disponible" <?= $editCuenta['estado'] === 'disponible' ? 'selected' : '' ?>>Activo / Disponible</option>
                            <option value="reservada" <?= $editCuenta['estado'] === 'reservada' ? 'selected' : '' ?>>Reservada</option>
                            <option value="vendida" <?= $editCuenta['estado'] === 'vendida' ? 'selected' : '' ?>>Vendida</option>
                        </select>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:14px;">
                    <div class="form-group">
                        <label>Precio (Bs)</label>
                        <input type="number" name="precio" step="0.01" min="0" required value="<?= $editCuenta['precio'] ?>">
                    </div>
                    <div class="form-group">
                        <label>WhatsApp Soporte</label>
                        <input type="text" name="whatsapp_soporte" maxlength="30" value="<?= htmlspecialchars($editCuenta['whatsapp_soporte'] ?? '') ?>">
                    </div>
                    <div class="form-group" id="p2editPerfilesGroup">
                        <label>Perfiles</label>
                        <input type="number" name="perfiles_disponibles" min="1" max="99" value="<?= (int)($editCuenta['perfiles_disponibles'] ?? 1) ?>" id="p2editPerfilesInput">
                    </div>
                    <div class="form-group" id="p2editDiasGroup">
                        <label>Días</label>
                        <input type="number" name="dias" min="1" value="<?= (int)($editCuenta['dias'] ?? 30) ?>" id="p2editDiasInput">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" id="p2editCorreoGroup">
                        <label>Email</label>
                        <input type="email" name="correo" required value="<?= htmlspecialchars($editCuenta['correo']) ?>" id="p2editCorreoInput">
                    </div>
                    <div class="form-group" id="p2editUsuarioGroup">
                        <label>Usuario</label>
                        <input type="text" name="usuario_cuenta" value="<?= htmlspecialchars($editCuenta['usuario_cuenta'] ?? '') ?>" id="p2editUsuarioInput">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group" id="p2editPasswordGroup">
                        <label>Contraseña</label>
                        <input type="text" name="password" required value="<?= htmlspecialchars($editCuenta['password']) ?>" id="p2editPasswordInput">
                    </div>
                    <div class="form-group" id="p2editPinsGroup">
                        <label>PINs <small style="color:#64748b; font-weight:400;">(separados por coma)</small></label>
                        <input type="text" name="pins" value="<?= htmlspecialchars($editCuenta['pins'] ?? '') ?>" id="p2editPinsInput">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Plataforma</label>
                        <select name="plataforma_id" required>
                            <option value="">Selecciona plataforma</option>
                            <?php foreach ($plataformas as $p): ?>
                            <option value="<?= $p['id'] ?>" <?= $editCuenta['plataforma_id'] == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tipo de Cuenta</label>
                        <select name="tipo_cuenta" id="p2editTipoCuentaSelect" onchange="toggleP2EditPerfiles(this)">
                            <option value="completa" <?= ($editCuenta['tipo_cuenta'] ?? 'completa') === 'completa' ? 'selected' : '' ?>>Cuenta Completa</option>
                            <option value="perfil" <?= ($editCuenta['tipo_cuenta'] ?? '') === 'perfil' ? 'selected' : '' ?>>Perfil / Pantalla</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>¿Es Renovable?</label>
                    <select name="renovable">
                        <option value="1" <?= ($editCuenta['renovable'] ?? 1) == 1 ? 'selected' : '' ?>>Sí</option>
                        <option value="0" <?= ($editCuenta['renovable'] ?? 1) == 0 ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Términos</label>
                        <textarea name="terminos" rows="3"><?= htmlspecialchars($editCuenta['terminos'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Descripción</label>
                        <textarea name="descripcion" rows="3"><?= htmlspecialchars($editCuenta['descripcion'] ?? '') ?></textarea>
                    </div>
                </div>
                <div style="display:flex; gap:24px; flex-wrap:wrap; margin:10px 0;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="oferta" value="1" <?= !empty($editCuenta['oferta']) ? 'checked' : '' ?> style="width:auto;"> Oferta
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="imap_habilitado" value="1" <?= !empty($editCuenta['imap_habilitado']) ? 'checked' : '' ?> style="width:auto;"> Configuración IMAP — Habilitar
                    </label>
                </div>
                <div style="display:flex; gap:10px; margin-top:16px;">
                    <button type="submit" class="btn btn-primary">Actualizar</button>
                    <a href="?seccion=productos&subp=Streaming" class="btn btn-secondary">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- ===== SUB-SECCIONES: Licencias / Cursos / Software-Sistemas ===== -->

        <?php if (!$editProducto): ?>
        <div style="margin-bottom:20px;">
            <button onclick="abrirModalProducto()" class="btn btn-primary"><i class="fas fa-plus"></i> Agregar <?= htmlspecialchars($subProducto) ?></button>
        </div>
        <?php endif; ?>

        <div id="modalAgregarProducto" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:500; align-items:flex-start; justify-content:center; overflow-y:auto; padding:30px 16px;">
            <div style="background:#1a1a2e; border:1px solid #1f3460; border-radius:14px; width:100%; max-width:720px; margin:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center; padding:20px 24px; border-bottom:1px solid #1f3460;">
                    <h2 style="margin:0; font-size:1.2rem; color:#fff;"><i class="fas fa-plus-circle" style="color:#3b82f6; margin-right:8px;"></i> Agregar <?= htmlspecialchars($subProducto) ?></h2>
                    <button onclick="cerrarModalProducto()" style="background:none; border:none; color:#94a3b8; font-size:1.4rem; cursor:pointer; line-height:1;">&times;</button>
                </div>
                <form action="process.php" method="POST" style="padding:24px;">
                    <input type="hidden" name="action" value="guardar_producto">
                    <input type="hidden" name="categoria" value="<?= htmlspecialchars($subProducto) ?>">
                    <input type="hidden" name="subp" value="<?= htmlspecialchars($subProducto) ?>">

                    <div class="form-row">
                        <div class="form-group">
                            <label>Nombre del Producto</label>
                            <input type="text" name="nombre" maxlength="150" placeholder="Ej: Adobe Photoshop 2024" required>
                        </div>
                        <div class="form-group">
                            <label>Tipo de Entrega</label>
                            <select name="tipo_entrega">
                                <option value="manual">Manual (WhatsApp/instrucción)</option>
                                <option value="automatico">Automático (clave/link)</option>
                            </select>
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;">
                        <div class="form-group">
                            <label>Precio (Bs)</label>
                            <input type="number" name="precio" step="0.01" min="0" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label>Precio Pro (Bs) <small style="color:#64748b;">(opcional)</small></label>
                            <input type="number" name="precio_pro" step="0.01" min="0" placeholder="0.00">
                        </div>
                        <div class="form-group">
                            <label>Stock</label>
                            <input type="number" name="stock" min="0" value="1">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>WhatsApp Soporte</label>
                            <input type="text" name="whatsapp_soporte" maxlength="30" placeholder="59172454420">
                        </div>
                        <div class="form-group">
                            <label>Estado</label>
                            <select name="estado">
                                <option value="disponible">Disponible</option>
                                <option value="agotado">Agotado</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Contenido de Entrega <small style="color:#64748b;">(clave, link o instrucciones)</small></label>
                        <textarea name="contenido_entrega" rows="3" placeholder="Ej: Clave: ABC-123-XYZ&#10;Instrucciones: Ingresar en adobe.com..."></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Descripción</label>
                            <textarea name="descripcion" rows="3" placeholder="Descripción del producto..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Términos</label>
                            <textarea name="terminos" rows="3" placeholder="Condiciones de uso..."></textarea>
                        </div>
                    </div>

                    <div style="display:flex; gap:24px; flex-wrap:wrap; margin:10px 0;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.9rem;">
                            <input type="checkbox" name="oferta" value="1" style="width:auto;"> En oferta
                        </label>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px; border-top:1px solid #1f3460; padding-top:20px;">
                        <button type="button" onclick="cerrarModalProducto()" class="btn btn-secondary">Cancelar</button>
                        <button type="submit" class="btn btn-primary" style="min-width:100px;">Guardar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Formulario Editar Producto (inline) -->
        <?php if ($editProducto): ?>
        <div class="form-card">
            <h3><i class="fas fa-edit"></i> Editar Producto — <?= htmlspecialchars($editProducto['categoria']) ?></h3>
            <form action="process.php" method="POST">
                <input type="hidden" name="action" value="editar_producto">
                <input type="hidden" name="id" value="<?= $editProducto['id'] ?>">
                <input type="hidden" name="subp" value="<?= htmlspecialchars($editProducto['categoria']) ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label>Nombre del Producto</label>
                        <input type="text" name="nombre" maxlength="150" required value="<?= htmlspecialchars($editProducto['nombre']) ?>">
                    </div>
                    <div class="form-group">
                        <label>Tipo de Entrega</label>
                        <select name="tipo_entrega">
                            <option value="manual" <?= $editProducto['tipo_entrega']==='manual' ? 'selected' : '' ?>>Manual</option>
                            <option value="automatico" <?= $editProducto['tipo_entrega']==='automatico' ? 'selected' : '' ?>>Automático</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px;">
                    <div class="form-group">
                        <label>Precio (Bs)</label>
                        <input type="number" name="precio" step="0.01" min="0" required value="<?= $editProducto['precio'] ?>">
                    </div>
                    <div class="form-group">
                        <label>Precio Pro (Bs)</label>
                        <input type="number" name="precio_pro" step="0.01" min="0" value="<?= $editProducto['precio_pro'] ?? '' ?>">
                    </div>
                    <div class="form-group">
                        <label>Stock</label>
                        <input type="number" name="stock" min="0" value="<?= (int)$editProducto['stock'] ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>WhatsApp Soporte</label>
                        <input type="text" name="whatsapp_soporte" maxlength="30" value="<?= htmlspecialchars($editProducto['whatsapp_soporte'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <select name="estado">
                            <option value="disponible" <?= $editProducto['estado']==='disponible' ? 'selected' : '' ?>>Disponible</option>
                            <option value="agotado" <?= $editProducto['estado']==='agotado' ? 'selected' : '' ?>>Agotado</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Contenido de Entrega</label>
                    <textarea name="contenido_entrega" rows="3"><?= htmlspecialchars($editProducto['contenido_entrega'] ?? '') ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Descripción</label>
                        <textarea name="descripcion" rows="3"><?= htmlspecialchars($editProducto['descripcion'] ?? '') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Términos</label>
                        <textarea name="terminos" rows="3"><?= htmlspecialchars($editProducto['terminos'] ?? '') ?></textarea>
                    </div>
                </div>

                <div style="display:flex; gap:24px; flex-wrap:wrap; margin:10px 0;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="oferta" value="1" <?= !empty($editProducto['oferta']) ? 'checked' : '' ?> style="width:auto;"> En oferta
                    </label>
                </div>

                <div style="display:flex; gap:10px; margin-top:16px;">
                    <button type="submit" class="btn btn-primary">Actualizar</button>
                    <a href="?seccion=productos&subp=<?= htmlspecialchars($editProducto['categoria']) ?>" class="btn btn-secondary">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Tabla de productos de la sub-categoría activa -->
        <?php
        $productosFiltrados = array_filter($productos, fn($p) => $p['categoria'] === $subProducto);
        $productosFiltrados = array_values($productosFiltrados);
        ?>
        <div class="form-card" style="margin-top:24px;">
            <h3><i class="fas fa-list"></i> <?= htmlspecialchars($subProducto) ?> 
                <span style="background:#1e293b; color:#94a3b8; border-radius:20px; padding:2px 10px; font-size:0.8rem; font-weight:400; margin-left:8px;"><?= count($productosFiltrados) ?> registros</span>
            </h3>
            <?php if (empty($productosFiltrados)): ?>
            <p style="color:#64748b; text-align:center; padding:30px 0;">No hay productos en esta categoría aún.</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:0.88rem;">
                    <thead>
                        <tr style="border-bottom:1px solid #1f3460; color:#94a3b8; text-transform:uppercase; font-size:0.75rem;">
                            <th style="padding:10px 12px; text-align:left;">Nombre</th>
                            <th style="padding:10px 12px; text-align:center;">Precio</th>
                            <th style="padding:10px 12px; text-align:center;">Stock</th>
                            <th style="padding:10px 12px; text-align:center;">Tipo</th>
                            <th style="padding:10px 12px; text-align:center;">Estado</th>
                            <th style="padding:10px 12px; text-align:center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($productosFiltrados as $prod): ?>
                        <tr style="border-bottom:1px solid #1f3460;">
                            <td style="padding:12px; color:#e2e8f0; font-weight:600;">
                                <?= htmlspecialchars($prod['nombre']) ?>
                                <?php if($prod['oferta']): ?><span style="background:#dc2626; color:#fff; border-radius:4px; padding:1px 6px; font-size:0.7rem; margin-left:6px;">OFERTA</span><?php endif; ?>
                            </td>
                            <td style="padding:12px; text-align:center;">
                                <span style="color:#00ff88; font-weight:700;"><?= number_format($prod['precio'],2) ?> Bs</span>
                                <?php if($prod['precio_pro']): ?>
                                <br><small style="color:#94a3b8;">Pro: <?= number_format($prod['precio_pro'],2) ?> Bs</small>
                                <?php endif; ?>
                            </td>
                            <td style="padding:12px; text-align:center; font-weight:700; color:<?= $prod['stock']>0 ? '#00ff88' : '#ef4444' ?>;">
                                <?= (int)$prod['stock'] ?>
                            </td>
                            <td style="padding:12px; text-align:center;">
                                <span style="background:<?= $prod['tipo_entrega']==='automatico' ? 'rgba(0,255,136,0.12)' : 'rgba(148,163,184,0.12)' ?>; color:<?= $prod['tipo_entrega']==='automatico' ? '#00ff88' : '#94a3b8' ?>; border-radius:6px; padding:2px 8px; font-size:0.75rem;">
                                    <?= $prod['tipo_entrega'] === 'automatico' ? 'Auto' : 'Manual' ?>
                                </span>
                            </td>
                            <td style="padding:12px; text-align:center;">
                                <span style="background:<?= $prod['estado']==='disponible' ? 'rgba(0,255,136,0.12)' : 'rgba(239,68,68,0.12)' ?>; color:<?= $prod['estado']==='disponible' ? '#00ff88' : '#ef4444' ?>; border-radius:6px; padding:2px 8px; font-size:0.75rem;">
                                    <?= ucfirst($prod['estado']) ?>
                                </span>
                            </td>
                            <td style="padding:12px; text-align:center; white-space:nowrap;">
                                <a href="?seccion=productos&subp=<?= urlencode($prod['categoria']) ?>&edit_producto=<?= $prod['id'] ?>" class="btn btn-secondary" style="padding:5px 12px; font-size:0.8rem;"><i class="fas fa-edit"></i></a>
                                <a href="#" onclick="gEliminar(event,'¿Eliminar este producto?','process.php?action=eliminar_producto&id=<?= $prod['id'] ?>&subp=<?= urlencode($prod['categoria']) ?>')" class="btn btn-danger" style="padding:5px 12px; font-size:0.8rem;"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function abrirModalProducto() {
            document.getElementById('modalAgregarProducto').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        function cerrarModalProducto() {
            document.getElementById('modalAgregarProducto').style.display = 'none';
            document.body.style.overflow = '';
        }
        document.getElementById('modalAgregarProducto').addEventListener('click', function(e) {
            if (e.target === this) cerrarModalProducto();
        });
        </script>
        <?php endif; // endif subProducto !== Streaming ?>
        <?php endif; // endif seccion == productos ?>

        <!-- ==================== COMPRAS STREAMING ==================== -->
        <?php if ($seccion == 'compras'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Streaming > Compras</div>
        <h1 class="page-title">Historial de Compras</h1>
        
        <div class="stat-cards" style="grid-template-columns: repeat(3, 1fr);">
            <div class="stat-card blue">
                <div class="title">Total Ventas</div>
                <div class="value"><?= $totalVentas ?></div>
            </div>
            <div class="stat-card green">
                <div class="title">Total Recaudado</div>
                <div class="value"><?= number_format($totalIngresos, 2) ?> Bs</div>
            </div>
            <div class="stat-card purple">
                <div class="title">Promedio</div>
                <div class="value"><?= $totalVentas > 0 ? number_format($totalIngresos/$totalVentas, 2) : '0.00' ?> Bs</div>
            </div>
        </div>
        
        <div class="table-container">
            <div class="table-header">
                <i class="fas fa-shopping-bag"></i>
                <h3>Todas las Compras</h3>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Comprador</th>
                        <th>Plataforma</th>
                        <th>Cuenta</th>
                        <th>Monto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($compras as $c): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></td>
                        <td>
                            <div class="user-info">
                                <div>
                                    <div class="name"><?= htmlspecialchars($c['usuario_nombre'] ?? 'N/A') ?></div>
                                    <div class="email"><?= htmlspecialchars($c['usuario_email'] ?? '') ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <?php if ($c['plataforma_imagen']): ?>
                                <img src="<?= htmlspecialchars($c['plataforma_imagen']) ?>" style="width: 20px; height: 20px;">
                                <?php endif; ?>
                                <?= htmlspecialchars($c['plataforma_nombre'] ?? 'N/A') ?>
                            </div>
                        </td>
                        <td style="font-family: monospace; color: #64748b;"><?= htmlspecialchars($c['cuenta_correo'] ?? 'N/A') ?></td>
                        <td style="color: #10b981; font-weight: 600;"><?= number_format($c['creditos_usados'], 2) ?> Bs</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ==================== CENTRO DE SOPORTE ==================== -->
        <?php if ($seccion == 'soporte'): ?>
        <?php
        $soporteFiltro = $_GET['soporte_estado'] ?? 'todos';
        $soporteTickets = []; // placeholder: listado de tickets (luego desde BD)
        $countTodos = count($soporteTickets);
        $countPendientes = count(array_filter($soporteTickets, fn($t) => ($t['estado'] ?? '') === 'pendiente'));
        $countRespondidos = count(array_filter($soporteTickets, fn($t) => ($t['estado'] ?? '') === 'respondido'));
        $countCerrados = count(array_filter($soporteTickets, fn($t) => ($t['estado'] ?? '') === 'cerrado'));
        ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Soporte</div>
        
        <div style="display: flex; gap: 0; min-height: 500px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
            <!-- Columna izquierda: lista de tickets -->
            <div style="width: 320px; flex-shrink: 0; border-right: 1px solid #e5e7eb; display: flex; flex-direction: column;">
                <div style="padding: 20px; border-bottom: 1px solid #e5e7eb;">
                    <h2 style="margin: 0 0 16px 0; font-size: 1.25rem; font-weight: 700; color: #111827;">Centro de Soporte</h2>
                    <input type="text" id="soporteSearch" placeholder="Buscar tickets..." style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.9rem; box-sizing: border-box;">
                </div>
                <div style="flex: 1; overflow-y: auto; padding: 8px;">
                    <!-- Lista de tickets (vacía por ahora) -->
                    <?php if (count($soporteTickets) === 0): ?>
                    <p style="color: #6b7280; font-size: 0.9rem; padding: 20px; margin: 0; text-align: center;">No hay tickets</p>
                    <?php else: ?>
                    <?php foreach ($soporteTickets as $t): ?>
                    <a href="?seccion=soporte&ticket=<?= (int)($t['id'] ?? 0) ?>" style="display: block; padding: 12px; border-radius: 6px; margin-bottom: 4px; text-decoration: none; color: #111827; border: 1px solid transparent;" class="soporte-ticket-item">
                        <strong style="font-size: 0.9rem;">#<?= (int)($t['id'] ?? 0) ?></strong>
                        <span style="font-size: 0.85rem; color: #6b7280;"><?= htmlspecialchars($t['asunto'] ?? '') ?></span>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Columna derecha: detalle / estado vacío -->
            <div style="flex: 1; display: flex; flex-direction: column; background: #f9fafb;">
                <div style="padding: 16px 24px; border-bottom: 1px solid #e5e7eb; display: flex; gap: 8px; flex-wrap: wrap;">
                    <a href="?seccion=soporte&soporte_estado=todos" style="padding: 8px 16px; border-radius: 6px; font-size: 0.9rem; font-weight: 500; text-decoration: none; <?= $soporteFiltro === 'todos' ? 'background: #3b82f6; color: #fff;' : 'background: #f3f4f6; color: #374151;' ?>">Todos (<?= $countTodos ?>)</a>
                    <a href="?seccion=soporte&soporte_estado=pendientes" style="padding: 8px 16px; border-radius: 6px; font-size: 0.9rem; font-weight: 500; text-decoration: none; <?= $soporteFiltro === 'pendientes' ? 'background: #3b82f6; color: #fff;' : 'background: #f3f4f6; color: #374151;' ?>">Pendientes (<?= $countPendientes ?>)</a>
                    <a href="?seccion=soporte&soporte_estado=respondidos" style="padding: 8px 16px; border-radius: 6px; font-size: 0.9rem; font-weight: 500; text-decoration: none; <?= $soporteFiltro === 'respondidos' ? 'background: #3b82f6; color: #fff;' : 'background: #f3f4f6; color: #374151;' ?>">Respondidos (<?= $countRespondidos ?>)</a>
                    <a href="?seccion=soporte&soporte_estado=cerrados" style="padding: 8px 16px; border-radius: 6px; font-size: 0.9rem; font-weight: 500; text-decoration: none; <?= $soporteFiltro === 'cerrados' ? 'background: #3b82f6; color: #fff;' : 'background: #f3f4f6; color: #374151;' ?>">Cerrados (<?= $countCerrados ?>)</a>
                </div>
                <div style="flex: 1; display: flex; align-items: center; justify-content: center; padding: 40px;">
                    <div style="text-align: center;">
                        <h3 style="margin: 0 0 8px 0; font-size: 1.1rem; font-weight: 600; color: #111827;">Centro de Soporte</h3>
                        <p style="margin: 0; font-size: 0.95rem; color: #6b7280;">Selecciona un ticket para ver la conversación</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ==================== LOGOS ==================== -->
        <?php if ($seccion == 'logos'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Streaming > Logos</div>
        <h1 class="page-title">Gestión de Logos</h1>
        
        <div class="form-card">
            <h3><i class="fas fa-upload"></i> Subir Logo</h3>
            <p style="color: #64748b; margin-bottom: 15px;">Sube los logos de las plataformas de streaming</p>
            <div class="coming-soon" style="min-height: 150px;">
                <i class="fas fa-cloud-upload-alt"></i>
                <p>Funcionalidad de subida próximamente</p>
            </div>
        </div>
        
        <!-- Logos actuales -->
        <div class="table-container">
            <div class="table-header">
                <i class="fas fa-image"></i>
                <h3>Logos de Plataformas</h3>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Logo</th>
                        <th>Plataforma</th>
                        <th>URL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plataformas as $p): ?>
                    <tr>
                        <td><img src="<?= htmlspecialchars($p['imagen_url']) ?>" style="width: 40px; height: 40px; object-fit: contain;"></td>
                        <td><strong><?= htmlspecialchars($p['nombre']) ?></strong></td>
                        <td style="font-family: monospace; font-size: 0.8rem; color: #64748b;"><?= htmlspecialchars($p['imagen_url']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ==================== APARIENCIA ==================== -->
        <?php if ($seccion == 'apariencia'): ?>
        <?php 
        // Cargar configuración actual
        $configPathMarca = file_exists(__DIR__ . '/config_marca.json') ? __DIR__ . '/config_marca.json' : null;
        $configMarca = $configPathMarca ? json_decode(file_get_contents($configPathMarca), true) : [];
        $configMarca = array_merge([
            'nombre_tienda' => 'Mi Tienda',
            'color_principal' => '#22c55e',
            'fondo_principal' => '#0a0a0a',
            'fondo_secundario' => '#1a1a1a',
            'fondo_terciario' => '#2a2a2a',
            'texto_principal' => '#ffffff',
            'texto_secundario' => '#a1a1aa',
            'logo_url' => '',
            'favicon_url' => '',
            'whatsapp_numero' => '',
            'whatsapp_grupo' => '',
            'facebook_url' => '',
            'twitter_url' => '',
            'instagram_url' => '',
            'youtube_url' => '',
            'tiktok_url' => '',
            'comunicado_mensaje' => '',
            'comunicado_activo' => false
        ], $configMarca);
        ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Configuración > Apariencia</div>
        
        <div style="display: flex; gap: 0; margin-top: 20px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; min-height: 400px;">
            <!-- Submenú izquierdo Apariencia -->
            <nav style="width: 200px; flex-shrink: 0; background: #f8f9fb; border-right: 1px solid #e5e7eb;">
                <a href="?seccion=apariencia&sub=colores" style="display: block; padding: 12px 16px; text-decoration: none; color: #374151; font-size: 0.9rem; <?= $subApariencia === 'colores' ? 'background: #495057; color: #fff;' : '' ?>">Colores</a>
                <a href="?seccion=apariencia&sub=branding" style="display: block; padding: 12px 16px; text-decoration: none; color: #374151; font-size: 0.9rem; <?= $subApariencia === 'branding' ? 'background: #495057; color: #fff;' : '' ?>">Branding</a>
                <a href="?seccion=apariencia&sub=pages" style="display: block; padding: 12px 16px; text-decoration: none; color: #374151; font-size: 0.9rem; <?= $subApariencia === 'pages' ? 'background: #495057; color: #fff;' : '' ?>">Pages</a>
            </nav>
            
            <!-- Contenido según subsección -->
            <div style="flex: 1; padding: 24px; overflow-y: auto;">
        
        <?php if ($subApariencia === 'colores'): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <h1 class="page-title" style="margin-bottom: 5px;">Colores</h1>
                <p style="color: #64748b; font-size: 0.9rem; margin: 0;">Personaliza la paleta de colores</p>
            </div>
            <a href="?seccion=apariencia&restaurar=1" style="color: #3b82f6; text-decoration: none; font-size: 0.9rem;" onclick="gEliminar(event,'¿Restaurar colores por defecto?',this.href); return false;">Restaurar</a>
        </div>
        
        <div class="form-card" style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px;">
            <form action="process.php" method="POST" enctype="multipart/form-data" id="aparienciaForm">
                <input type="hidden" name="action" value="guardar_apariencia">
                
                <!-- Color Principal -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #111827;">Color Principal</label>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 40px; height: 40px; border-radius: 6px; background: <?= htmlspecialchars($configMarca['color_principal']) ?>; border: 2px solid #e5e7eb; flex-shrink: 0;"></div>
                        <input type="text" name="color_principal" value="<?= htmlspecialchars($configMarca['color_principal']) ?>" 
                               pattern="#[0-9A-Fa-f]{6}" 
                               style="flex: 1; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-family: monospace; font-size: 0.9rem;"
                               placeholder="#22c55e">
                    </div>
                </div>
                
                <!-- Fondos -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 12px; color: #111827;">Fondos</label>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 0.85rem; color: #6b7280; margin-bottom: 6px;">Principal</label>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 32px; height: 32px; border-radius: 4px; background: <?= htmlspecialchars($configMarca['fondo_principal']) ?>; border: 1px solid #d1d5db; flex-shrink: 0;"></div>
                            <input type="text" name="fondo_principal" value="<?= htmlspecialchars($configMarca['fondo_principal']) ?>" 
                                   pattern="#[0-9A-Fa-f]{6}"
                                   style="flex: 1; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: monospace; font-size: 0.85rem;"
                                   placeholder="#0a0a0a">
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 0.85rem; color: #6b7280; margin-bottom: 6px;">Secundario</label>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 32px; height: 32px; border-radius: 4px; background: <?= htmlspecialchars($configMarca['fondo_secundario']) ?>; border: 1px solid #d1d5db; flex-shrink: 0;"></div>
                            <input type="text" name="fondo_secundario" value="<?= htmlspecialchars($configMarca['fondo_secundario']) ?>" 
                                   pattern="#[0-9A-Fa-f]{6}"
                                   style="flex: 1; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: monospace; font-size: 0.85rem;"
                                   placeholder="#1a1a1a">
                        </div>
                    </div>
                    
                    <div>
                        <label style="display: block; font-size: 0.85rem; color: #6b7280; margin-bottom: 6px;">Terciario</label>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 32px; height: 32px; border-radius: 4px; background: <?= htmlspecialchars($configMarca['fondo_terciario']) ?>; border: 1px solid #d1d5db; flex-shrink: 0;"></div>
                            <input type="text" name="fondo_terciario" value="<?= htmlspecialchars($configMarca['fondo_terciario']) ?>" 
                                   pattern="#[0-9A-Fa-f]{6}"
                                   style="flex: 1; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: monospace; font-size: 0.85rem;"
                                   placeholder="#2a2a2a">
                        </div>
                    </div>
                </div>
                
                <!-- Textos -->
                <div style="margin-bottom: 24px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 12px; color: #111827;">Textos</label>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 0.85rem; color: #6b7280; margin-bottom: 6px;">Principal</label>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 32px; height: 32px; border-radius: 4px; background: <?= htmlspecialchars($configMarca['texto_principal']) ?>; border: 1px solid #d1d5db; flex-shrink: 0;"></div>
                            <input type="text" name="texto_principal" value="<?= htmlspecialchars($configMarca['texto_principal']) ?>" 
                                   pattern="#[0-9A-Fa-f]{6}"
                                   style="flex: 1; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: monospace; font-size: 0.85rem;"
                                   placeholder="#ffffff">
                        </div>
                    </div>
                    
                    <div>
                        <label style="display: block; font-size: 0.85rem; color: #6b7280; margin-bottom: 6px;">Secundario</label>
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 32px; height: 32px; border-radius: 4px; background: <?= htmlspecialchars($configMarca['texto_secundario']) ?>; border: 1px solid #d1d5db; flex-shrink: 0;"></div>
                            <input type="text" name="texto_secundario" value="<?= htmlspecialchars($configMarca['texto_secundario']) ?>" 
                                   pattern="#[0-9A-Fa-f]{6}"
                                   style="flex: 1; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: monospace; font-size: 0.85rem;"
                                   placeholder="#a1a1aa">
                        </div>
                    </div>
                </div>
                
                <!-- Ver JSON (colapsable) -->
                <div style="margin-bottom: 24px; border-top: 1px solid #e5e7eb; padding-top: 16px;">
                    <details style="cursor: pointer;">
                        <summary style="font-weight: 500; color: #374151; user-select: none; list-style: none; display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 0.75rem;">▶</span> Ver JSON
                        </summary>
                        <pre id="jsonPreview" style="margin-top: 12px; padding: 12px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 0.8rem; overflow-x: auto; color: #111827;"></pre>
                    </details>
                </div>
                
                <!-- Botón Guardar -->
                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary" style="background: #3b82f6; color: white; padding: 10px 20px; border-radius: 6px; border: none; font-weight: 600; cursor: pointer;">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
        
        <script>
            // Actualizar preview de colores cuando cambian los inputs
            document.querySelectorAll('input[type="text"][pattern]').forEach(input => {
                input.addEventListener('input', function() {
                    const swatch = this.previousElementSibling;
                    if (swatch && /^#[0-9A-Fa-f]{6}$/.test(this.value)) {
                        swatch.style.background = this.value;
                    }
                });
            });
            
            // Generar JSON preview
            function updateJSONPreview() {
                const form = document.getElementById('aparienciaForm');
                const formData = new FormData(form);
                const json = {
                    color_principal: formData.get('color_principal'),
                    fondo_principal: formData.get('fondo_principal'),
                    fondo_secundario: formData.get('fondo_secundario'),
                    fondo_terciario: formData.get('fondo_terciario'),
                    texto_principal: formData.get('texto_principal'),
                    texto_secundario: formData.get('texto_secundario')
                };
                document.getElementById('jsonPreview').textContent = JSON.stringify(json, null, 2);
            }
            
            document.getElementById('aparienciaForm').addEventListener('input', updateJSONPreview);
            updateJSONPreview();
        </script>
        <?php endif; ?>
        
        <?php if ($subApariencia === 'branding'): ?>
        <h1 class="page-title" style="margin-bottom: 5px;">Branding</h1>
        <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 24px;">Configura logo, enlaces y comunicados.</p>
        
        <form action="process.php" method="POST" enctype="multipart/form-data" id="brandingForm">
            <input type="hidden" name="action" value="guardar_branding">
            
            <!-- Logo y Favicon -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
                <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 12px; color: #111827;">Logo</label>
                    <div style="border: 2px dashed #d1d5db; border-radius: 8px; height: 120px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; background: #f9fafb;">
                        <?php if (!empty($configMarca['logo_url'])): ?>
                        <img src="<?= htmlspecialchars($configMarca['logo_url']) ?>" alt="Logo" style="max-height: 100px; max-width: 100%; object-fit: contain;" id="logoPreview">
                        <?php else: ?>
                        <i class="fas fa-image" style="font-size: 2.5rem; color: #9ca3af;" id="logoPreviewIcon"></i>
                        <img src="" alt="" id="logoPreview" style="display: none; max-height: 100px; max-width: 100%; object-fit: contain;">
                        <?php endif; ?>
                    </div>
                    <input type="file" name="logo" accept="image/*" id="logoFile" style="display: none;">
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('logoFile').click()" style="padding: 8px 14px;">Seleccionar</button>
                        <span style="align-self: center; font-size: 0.85rem; color: #6b7280;" id="logoFileName"></span>
                    </div>
                </div>
                <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 12px; color: #111827;">Favicon</label>
                    <div style="border: 2px dashed #d1d5db; border-radius: 8px; height: 120px; display: flex; align-items: center; justify-content: center; margin-bottom: 12px; background: #f9fafb; position: relative;">
                        <?php if (!empty($configMarca['favicon_url'])): ?>
                        <img src="<?= htmlspecialchars($configMarca['favicon_url']) ?>" alt="Favicon" style="max-height: 64px; max-width: 64px; object-fit: contain;" id="faviconPreview">
                        <a href="process.php?action=eliminar_favicon" style="position: absolute; top: 8px; right: 8px; color: #dc2626; font-size: 1.1rem;" title="Eliminar favicon" onclick="gEliminar(event,'¿Eliminar favicon?',this.href); return false;"><i class="fas fa-trash-alt"></i></a>
                        <?php else: ?>
                        <i class="fas fa-image" style="font-size: 2rem; color: #9ca3af;" id="faviconPreviewIcon"></i>
                        <img src="" alt="" id="faviconPreview" style="display: none; max-height: 64px; max-width: 64px; object-fit: contain;">
                        <input type="hidden" name="eliminar_favicon" value="0" id="eliminarFaviconInput">
                        <?php endif; ?>
                    </div>
                    <input type="file" name="favicon" accept="image/*" id="faviconFile" style="display: none;">
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('faviconFile').click()" style="padding: 8px 14px;">Seleccionar</button>
                        <span style="align-self: center; font-size: 0.85rem; color: #6b7280;" id="faviconFileName"></span>
                    </div>
                </div>
            </div>
            
            <!-- WhatsApp -->
            <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                <label style="display: block; font-weight: 600; margin-bottom: 12px; color: #111827;">WhatsApp</label>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 0.85rem; color: #6b7280; margin-bottom: 6px;">Número</label>
                        <input type="text" name="whatsapp_numero" value="<?= htmlspecialchars($configMarca['whatsapp_numero']) ?>" placeholder="+1234567890" style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.85rem; color: #6b7280; margin-bottom: 6px;">Grupo</label>
                        <input type="url" name="whatsapp_grupo" value="<?= htmlspecialchars($configMarca['whatsapp_grupo']) ?>" placeholder="https://chat.whatsapp.com/..." style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                </div>
            </div>
            
            <!-- Redes Sociales -->
            <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                <label style="display: block; font-weight: 600; margin-bottom: 12px; color: #111827;">Redes Sociales</label>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="fab fa-facebook" style="color: #1877f2; width: 24px; text-align: center;"></i>
                        <input type="url" name="facebook_url" value="<?= htmlspecialchars($configMarca['facebook_url']) ?>" placeholder="https://facebook.com/..." style="flex: 1; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="fab fa-twitter" style="color: #1da1f2; width: 24px; text-align: center;"></i>
                        <input type="url" name="twitter_url" value="<?= htmlspecialchars($configMarca['twitter_url']) ?>" placeholder="https://twitter.com/..." style="flex: 1; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="fab fa-instagram" style="color: #e4405f; width: 24px; text-align: center;"></i>
                        <input type="url" name="instagram_url" value="<?= htmlspecialchars($configMarca['instagram_url']) ?>" placeholder="https://instagram.com/..." style="flex: 1; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="fab fa-youtube" style="color: #ff0000; width: 24px; text-align: center;"></i>
                        <input type="url" name="youtube_url" value="<?= htmlspecialchars($configMarca['youtube_url']) ?>" placeholder="https://youtube.com/..." style="flex: 1; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <i class="fab fa-tiktok" style="color: #000; width: 24px; text-align: center;"></i>
                        <input type="url" name="tiktok_url" value="<?= htmlspecialchars($configMarca['tiktok_url']) ?>" placeholder="https://tiktok.com/..." style="flex: 1; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px;">
                    </div>
                </div>
            </div>
            
            <!-- Comunicado Global -->
            <div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 24px;">
                <label style="display: block; font-weight: 600; margin-bottom: 12px; color: #111827;">Comunicado Global</label>
                <textarea name="comunicado_mensaje" rows="4" placeholder="Mensaje para los usuarios..." style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; resize: vertical;"><?= htmlspecialchars($configMarca['comunicado_mensaje']) ?></textarea>
                <label style="display: flex; align-items: center; gap: 10px; margin-top: 12px; cursor: pointer;">
                    <input type="checkbox" name="comunicado_activo" value="1" <?= !empty($configMarca['comunicado_activo']) ? 'checked' : '' ?> style="width: auto;">
                    <span>Activar comunicado</span>
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; background: #3b82f6; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">
                Guardar
            </button>
        </form>
        
        <script>
            document.getElementById('logoFile').addEventListener('change', function() {
                var f = this.files[0];
                document.getElementById('logoFileName').textContent = f ? f.name : '';
                if (f && f.type.indexOf('image') !== -1) {
                    var r = new FileReader();
                    r.onload = function() {
                        var img = document.getElementById('logoPreview');
                        var icon = document.getElementById('logoPreviewIcon');
                        if (img) { img.src = r.result; img.style.display = 'block'; }
                        if (icon) icon.style.display = 'none';
                    };
                    r.readAsDataURL(f);
                }
            });
            document.getElementById('faviconFile').addEventListener('change', function() {
                var f = this.files[0];
                document.getElementById('faviconFileName').textContent = f ? f.name : '';
                if (f && f.type.indexOf('image') !== -1) {
                    var r = new FileReader();
                    r.onload = function() {
                        var img = document.getElementById('faviconPreview');
                        var icon = document.getElementById('faviconPreviewIcon');
                        if (img) { img.src = r.result; img.style.display = 'block'; }
                        if (icon) icon.style.display = 'none';
                    };
                    r.readAsDataURL(f);
                }
            });
        </script>
        <?php endif; ?>
        
        <?php if ($subApariencia === 'pages'): ?>
        <h1 class="page-title" style="margin-bottom: 5px;">Pages</h1>
        <p style="color: #64748b; font-size: 0.9rem; margin-bottom: 20px;">Configuración de páginas del sitio.</p>
        <div class="form-card" style="background: #f9fafb; border: 1px dashed #d1d5db; border-radius: 8px; padding: 40px; text-align: center; color: #6b7280;">
            Contenido de Pages — lo agregarás después.
        </div>
        <?php endif; ?>
        
            </div>
        </div>
        <?php endif; ?>

        <!-- ==================== AJUSTES / MÓDULOS ==================== -->
        <?php if ($seccion == 'ajustes'): ?>
        <?php
        $configModPath = __DIR__ . '/config_modulos.json';
        $configModulos = file_exists($configModPath) ? json_decode(file_get_contents($configModPath), true) : [];
        $googleConfig = $configModulos['google_signin'] ?? [
            'enabled' => false,
            'client_id' => '',
            'client_secret' => '',
            'authorized_origins' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'tudominio.com'),
            'redirect_uri' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'tudominio.com') . '/auth/google/callback'
        ];
        ?>
        <div class="breadcrumb"><a href=\"?\">Admin</a> > Configuración > Ajustes</div>
        <h1 class="page-title">Configuración de Módulos</h1>

        <div class="form-card">
            <h3><i class="fab fa-google" style="color:#ea4335;"></i> Google Sign In</h3>
            <p style="color:#9ca3af; font-size:0.9rem; margin-bottom:15px;">
                Configura el inicio de sesión con Google OAuth 2.0 para que tus usuarios puedan entrar con su cuenta de Google.
            </p>

            <form action="process.php" method="POST">
                <input type="hidden" name="action" value="guardar_modulos">

                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <div style="font-size:0.9rem; color:#9ca3af;">Estado del módulo</div>
                    <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                        <span style="font-size:0.85rem; color:<?= !empty($googleConfig['enabled']) ? '#22c55e' : '#9ca3af' ?>;">
                            <?= !empty($googleConfig['enabled']) ? 'Activo' : 'Inactivo' ?>
                        </span>
                        <input type="checkbox" name="google_enabled" <?= !empty($googleConfig['enabled']) ? 'checked' : '' ?> style="display:none;">
                        <span style="position:relative; width:44px; height:24px; background:<?= !empty($googleConfig['enabled']) ? '#22c55e' : '#374151' ?>; border-radius:999px; transition:0.2s;">
                            <span style="position:absolute; top:2px; <?= !empty($googleConfig['enabled']) ? 'right:2px;' : 'left:2px;' ?> width:20px; height:20px; border-radius:999px; background:#fff; transition:0.2s;"></span>
                        </span>
                    </label>
                </div>

                <div class="form-group">
                    <label>Client ID</label>
                    <input type="text" name="google_client_id" value="<?= htmlspecialchars($googleConfig['client_id']) ?>" placeholder="1234567890-abc.apps.googleusercontent.com">
                </div>

                <div class="form-group">
                    <label>Client Secret</label>
                    <input type="text" name="google_client_secret" value="<?= htmlspecialchars($googleConfig['client_secret']) ?>" placeholder="Tu client secret de Google">
                </div>

                <div class="form-group">
                    <label>Authorized JavaScript origins</label>
                    <input type="text" name="google_authorized_origins" value="<?= htmlspecialchars($googleConfig['authorized_origins']) ?>">
                </div>

                <div class="form-group">
                    <label>Authorized redirect URI</label>
                    <input type="text" name="google_redirect_uri" value="<?= htmlspecialchars($googleConfig['redirect_uri']) ?>">
                    <p style="color:#9ca3af; font-size:0.8rem; margin-top:6px;">
                        Copia esta URL en Google Cloud Console como Redirect URI.
                    </p>
                </div>

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Guardar Configuración
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- ==================== MÉTODOS DE PAGO ==================== -->
        <?php if ($seccion == 'metodos_pago'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Configuración > Métodos de Pago</div>
        <h1 class="page-title">Configuración de Métodos de Pago</h1>
        
        <!-- Tabs -->
        <div style="display: flex; gap: 0; margin-bottom: 20px; border-bottom: 1px solid #1f3460;">
            <a href="?seccion=apariencia" style="padding: 12px 24px; color: #64748b; text-decoration: none; border-bottom: 2px solid transparent;">General</a>
            <a href="#" style="padding: 12px 24px; color: #64748b; text-decoration: none; border-bottom: 2px solid transparent;">API Providers</a>
            <a href="?seccion=metodos_pago" style="padding: 12px 24px; color: #3b82f6; text-decoration: none; border-bottom: 2px solid #3b82f6; font-weight: 500;">Payment Methods</a>
        </div>
        
        <!-- Header con botón Add Method -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2 style="font-size: 1.1rem; font-weight: 600;">Payment Methods</h2>
            <button onclick="openMethodModal()" class="btn btn-primary" style="display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-plus"></i> Add Method
            </button>
        </div>
        
        <!-- Formulario editar (si hay método para editar) -->
        <?php if ($editMetodo): ?>
        <div class="form-card">
            <h3><i class="fas fa-edit"></i> Editar Método de Pago</h3>
            <form action="process.php" method="POST">
                <input type="hidden" name="action" value="editar_metodo_pago">
                <input type="hidden" name="id" value="<?= $editMetodo['id'] ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Identificador (key)</label>
                        <input type="text" name="method_key" value="<?= htmlspecialchars($editMetodo['method_key']) ?>" required placeholder="stripe, paypal, etc.">
                    </div>
                    <div class="form-group">
                        <label>Nombre</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($editMetodo['name']) ?>" required placeholder="Stripe Checkout">
                    </div>
                </div>
                <div class="form-group">
                    <label>Descripción</label>
                    <textarea name="description" rows="2" placeholder="Descripción del método de pago..."><?= htmlspecialchars($editMetodo['description']) ?></textarea>
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label>Monto Mínimo ($)</label>
                        <input type="number" name="min_amount" step="0.01" value="<?= $editMetodo['min_amount'] ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label>Monto Máximo ($)</label>
                        <input type="number" name="max_amount" step="0.01" value="<?= $editMetodo['max_amount'] ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label style="color: #fbbf24;"><i class="fas fa-exchange-alt" style="margin-right: 4px;"></i> Tipo de Cambio (1 USD = ? Bs)</label>
                        <input type="number" name="exchange_rate" step="0.01" min="0.01" value="<?= $editMetodo['exchange_rate'] ?? 6.96 ?>" 
                               style="color: #fbbf24; font-weight: 600;">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="allow_new_users" <?= $editMetodo['allow_new_users'] ? 'checked' : '' ?> style="width: auto;">
                            Permitir para nuevos usuarios
                        </label>
                    </div>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="is_active" <?= $editMetodo['is_active'] ? 'checked' : '' ?> style="width: auto;">
                            Activo
                        </label>
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">Actualizar</button>
                    <a href="?seccion=metodos_pago" class="btn btn-secondary">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Tabla de métodos -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>METHOD</th>
                        <th>NAME</th>
                        <th>MIN</th>
                        <th>MAX</th>
                        <th>RATE</th>
                        <th>NEW USERS</th>
                        <th>STATUS</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($metodosPago) > 0): ?>
                    <?php foreach ($metodosPago as $m): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($m['method_key']) ?></strong></td>
                        <td>
                            <div>
                                <div style="font-weight: 500;"><?= htmlspecialchars($m['name']) ?></div>
                                <div style="font-size: 0.8rem; color: #64748b; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?= htmlspecialchars($m['description'] ?? '') ?></div>
                            </div>
                        </td>
                        <td>$<?= number_format($m['min_amount'], 2) ?></td>
                        <td>$<?= number_format($m['max_amount'], 2) ?></td>
                        <td style="color: #fbbf24; font-weight: 600;"><?= number_format($m['exchange_rate'] ?? 6.96, 2) ?></td>
                        <td>
                            <span class="badge <?= $m['allow_new_users'] ? 'badge-success' : 'badge-warning' ?>">
                                <?= $m['allow_new_users'] ? 'Allowed' : 'Restricted' ?>
                            </span>
                        </td>
                        <td>
                            <label class="toggle-switch" style="cursor: pointer;">
                                <input type="checkbox" <?= $m['is_active'] ? 'checked' : '' ?> 
                                       onchange="toggleMethod(<?= $m['id'] ?>, 'is_active')" 
                                       style="display: none;">
                                <span style="display: inline-block; width: 44px; height: 24px; background: <?= $m['is_active'] ? '#3b82f6' : '#374151' ?>; border-radius: 12px; position: relative; transition: all 0.2s;">
                                    <span style="display: block; width: 20px; height: 20px; background: #fff; border-radius: 50%; position: absolute; top: 2px; <?= $m['is_active'] ? 'right: 2px;' : 'left: 2px;' ?> transition: all 0.2s;"></span>
                                </span>
                            </label>
                        </td>
                        <td>
                            <a href="?edit_metodo=<?= $m['id'] ?>" style="color: #3b82f6; margin-right: 15px;">Edit</a>
                            <a href="process.php?action=eliminar_metodo_pago&id=<?= $m['id'] ?>" 
                               onclick="gEliminar(event,'¿Eliminar este método de pago?',this.href); return false;" 
                               style="color: #ef4444;">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #64748b;">
                            <i class="fas fa-credit-card" style="font-size: 2rem; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                            No hay métodos de pago configurados.<br>
                            <small>Ejecuta el archivo SQL para agregar los métodos por defecto.</small>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Modal para agregar método -->
        <div id="methodModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 100; align-items: center; justify-content: center; overflow-y: auto;">
            <div style="background: #16213e; border-radius: 12px; padding: 25px; width: 100%; max-width: 550px; margin: 20px; border: 1px solid #1f3460;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="font-size: 1.2rem;">Add Payment Method</h3>
                    <button onclick="closeMethodModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
                </div>
                <form action="process.php" method="POST">
                    <input type="hidden" name="action" value="guardar_metodo_pago">
                    
                    <!-- Select Payment Provider -->
                    <div class="form-group">
                        <label>Select Payment Provider</label>
                        <select name="method_key" id="paymentProviderSelect" required onchange="onProviderChange(this)" style="width: 100%; padding: 12px; background: #0a0f1c; border: 1px solid #1f3460; border-radius: 6px; color: #fff;">
                            <option value="">-- Selecciona un proveedor --</option>
                            <option value="stripe" data-name="Stripe Checkout">Stripe</option>
                            <option value="paypal" data-name="PayPal Express Checkout">PayPal</option>
                            <option value="mercadopago" data-name="MercadoPago">MercadoPago</option>
                            <option value="binance_pay" data-name="Binance Pay">Binance Pay</option>
                            <option value="binance_gateway" data-name="Binance Pay Gateway">Binance Pay Gateway</option>
                            <option value="binance_usdt" data-name="Binance Pay (USDT)">Binance Pay (USDT)</option>
                            <option value="yape" data-name="Yape">Yape</option>
                            <option value="cryptomus" data-name="Cryptomus">Cryptomus</option>
                            <option value="hotmart" data-name="Hotmart Checkout">Hotmart</option>
                            <option value="veripagos" data-name="Veripagos QR">Veripagos QR</option>
                            <option value="manual" data-name="Manual Payment">Manual Payment</option>
                        </select>
                    </div>
                    
                    <!-- Method Name -->
                    <div class="form-group">
                        <label>Method Name</label>
                        <input type="text" name="name" id="methodNameInput" required placeholder="Nombre personalizado del método">
                    </div>
                    
                    <!-- Min/Max Amount -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Minimum Amount</label>
                            <input type="number" name="min_amount" step="0.01" value="10.00" min="0">
                        </div>
                        <div class="form-group">
                            <label>Maximum Amount</label>
                            <input type="number" name="max_amount" step="0.01" value="1000.00" min="0">
                        </div>
                    </div>
                    
                    <!-- Exchange Rate -->
                    <div class="form-group">
                        <label style="color: #fbbf24;"><i class="fas fa-exchange-alt" style="margin-right: 4px;"></i> Tipo de Cambio (1 USD = ? Bs)</label>
                        <input type="number" name="exchange_rate" step="0.01" min="0.01" value="6.96" 
                               style="color: #fbbf24; font-weight: 600;" placeholder="Ej: 6.96">
                        <small style="color: #64748b; display: block; margin-top: 5px;">Tasa de conversión USD a Bolivianos para este método.</small>
                    </div>
                    
                    <!-- New Users -->
                    <div class="form-group">
                        <label>New Users</label>
                        <select name="allow_new_users" style="width: 100%; padding: 12px; background: #0a0f1c; border: 1px solid #1f3460; border-radius: 6px; color: #fff;">
                            <option value="1">Allowed</option>
                            <option value="0">Restricted</option>
                        </select>
                    </div>
                    
                    <!-- Enable Processing Fee -->
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="processing_fee" style="width: auto;">
                            Enable Processing Fee
                        </label>
                    </div>
                    
                    <!-- VeriPagos-specific config -->
                    <div id="veripagosConfig" style="display: none; background: #0d1524; border: 1px solid #1e3a5f; border-radius: 8px; padding: 20px; margin-bottom: 15px;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px; padding-bottom: 12px; border-bottom: 1px solid #1e3a5f;">
                            <i class="fas fa-qrcode" style="color: #32cd32; font-size: 1.2rem;"></i>
                            <h4 style="margin: 0; font-size: 0.95rem; color: #32cd32;">Configuracion VeriPagos</h4>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user" style="margin-right: 6px; color: #64748b;"></i> Username VeriPagos</label>
                            <input type="text" name="vp_username" placeholder="Tu usuario de VeriPagos" value="<?= defined('VERIPAGOS_USERNAME') ? VERIPAGOS_USERNAME : '' ?>">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-lock" style="margin-right: 6px; color: #64748b;"></i> Password VeriPagos</label>
                            <input type="password" name="vp_password" placeholder="Tu contraseña de VeriPagos" value="<?= defined('VERIPAGOS_PASSWORD') ? VERIPAGOS_PASSWORD : '' ?>">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-key" style="margin-right: 6px; color: #64748b;"></i> Secret Key</label>
                            <input type="text" name="vp_secret_key" placeholder="Tu secret key de VeriPagos" value="<?= defined('VERIPAGOS_SECRET_KEY') ? VERIPAGOS_SECRET_KEY : '' ?>">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-link" style="margin-right: 6px; color: #64748b;"></i> URL del Webhook</label>
                            <input type="text" value="<?= 'https://' . ($_SERVER['HTTP_HOST'] ?? 'tudominio.com') . '/webhook_veripagos.php' ?>" readonly style="background: #0a0f1c; color: #64748b; cursor: not-allowed;">
                            <small style="color: #64748b; display: block; margin-top: 5px;"><i class="fas fa-info-circle"></i> Configura esta URL en tu panel de VeriPagos para recibir notificaciones.</small>
                        </div>
                    </div>
                    
                    <!-- Generic provider config (non-veripagos) -->
                    <div id="genericProviderConfig">
                        <!-- API Key -->
                        <div class="form-group">
                            <label>API Key</label>
                            <input type="text" name="api_key" placeholder="Enter API Key (optional)">
                        </div>
                        
                        <!-- Secret Key -->
                        <div class="form-group">
                            <label>Secret Key</label>
                            <input type="password" name="secret_key" placeholder="Enter Secret Key (optional)">
                        </div>
                    </div>
                    
                    <!-- Instructions -->
                    <div class="form-group">
                        <label>Instructions</label>
                        <textarea name="description" rows="3" placeholder="Instrucciones para el usuario al pagar..."></textarea>
                    </div>
                    
                    <!-- Active -->
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="is_active" style="width: auto;">
                            Activo
                        </label>
                    </div>
                    
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="button" onclick="closeMethodModal()" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="flex: 1;">Save</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
            function openMethodModal() {
                document.getElementById('methodModal').style.display = 'flex';
                // Reset form
                document.getElementById('paymentProviderSelect').value = '';
                document.getElementById('methodNameInput').value = '';
                document.getElementById('veripagosConfig').style.display = 'none';
                document.getElementById('genericProviderConfig').style.display = 'block';
            }
            function closeMethodModal() {
                document.getElementById('methodModal').style.display = 'none';
            }
            function onProviderChange(select) {
                const selectedOption = select.options[select.selectedIndex];
                const methodName = selectedOption.dataset.name || '';
                const provider = select.value;
                document.getElementById('methodNameInput').value = methodName;
                
                // Toggle VeriPagos-specific fields
                if (provider === 'veripagos') {
                    document.getElementById('veripagosConfig').style.display = 'block';
                    document.getElementById('genericProviderConfig').style.display = 'none';
                } else {
                    document.getElementById('veripagosConfig').style.display = 'none';
                    document.getElementById('genericProviderConfig').style.display = 'block';
                }
            }
            function toggleMethod(id, field) {
                fetch('process.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'action=toggle_metodo_pago&id=' + id + '&field=' + field
                }).then(r => r.json()).then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
            }
            // Cerrar modal al hacer clic fuera
            document.getElementById('methodModal').addEventListener('click', function(e) {
                if (e.target === this) closeMethodModal();
            });
        </script>
        <?php endif; ?>

        <!-- ==================== MEMBRESÍAS ==================== -->
        <?php if ($seccion == 'membresias'): ?>
        <div class="breadcrumb"><a href="?">Admin</a> > Configuración > Membresías</div>
        <h1 class="page-title">Planes de Membresía</h1>
        
        <!-- Header con botón Crear Plan -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <p style="color: #64748b;">Gestiona los planes que verán los usuarios en la sección de Afiliados</p>
            <button onclick="openPlanModal()" class="btn btn-primary" style="display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-plus"></i> Crear Plan
            </button>
        </div>
        
        <!-- Formulario editar plan -->
        <?php if ($editPlan): ?>
        <div class="form-card">
            <h3><i class="fas fa-edit"></i> Editar Plan</h3>
            <form action="process.php" method="POST">
                <input type="hidden" name="action" value="editar_plan">
                <input type="hidden" name="id" value="<?= $editPlan['id'] ?>">
                
                <div class="form-group">
                    <label>Nombre del Plan</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($editPlan['name']) ?>" required>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Precio ($)</label>
                        <input type="number" name="price" step="0.01" value="<?= $editPlan['price'] ?>" min="0">
                    </div>
                    <div class="form-group">
                        <label>Descuento en cuentas <small style="color:#64748b; font-weight:400;">(%)</small></label>
                        <input type="number" name="descuento" step="0.01" value="<?= $editPlan['descuento'] ?? 0 ?>" min="0" max="100" placeholder="ej: 10">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Puntos por día <small style="color:#64748b; font-weight:400;">(puntos de recompensa que acumula el usuario cada día con este plan)</small></label>
                        <input type="number" name="points" value="<?= $editPlan['points'] ?>" min="0" placeholder="ej: 10">
                    </div>
                </div>
                <div class="form-group">
                    <label>Descripción (usa | para separar beneficios)</label>
                    <textarea name="description" rows="4"><?= htmlspecialchars($editPlan['description']) ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="is_featured" <?= $editPlan['is_featured'] ? 'checked' : '' ?> style="width: auto;">
                            Plan Destacado
                        </label>
                    </div>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox" name="is_active" <?= $editPlan['is_active'] ? 'checked' : '' ?> style="width: auto;">
                            Plan Activo
                        </label>
                    </div>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button type="submit" class="btn btn-primary">Actualizar</button>
                    <a href="?seccion=membresias" class="btn btn-secondary">Cancelar</a>
                </div>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Tabla de planes -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>PLAN</th>
                        <th>PRECIO</th>
                        <th>DESCUENTO</th>
                        <th>PUNTOS</th>
                        <th>DESTACADO</th>
                        <th>ESTADO</th>
                        <th>ACCIONES</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($planes) > 0): ?>
                    <?php foreach ($planes as $plan): ?>
                    <tr>
                        <td>
                            <div>
                                <div style="font-weight: 600;"><?= htmlspecialchars($plan['name']) ?></div>
                                <div style="font-size: 0.8rem; color: #64748b; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?= htmlspecialchars(str_replace(['|', "\n"], ', ', $plan['description'] ?? '')) ?>
                                </div>
                            </div>
                        </td>
                        <td><span style="color: #22c55e; font-weight: 600;"><?= number_format($plan['price'], 2) ?> Bs</span></td>
                        <td>
                            <?php if (($plan['descuento'] ?? 0) > 0): ?>
                                <span class="badge badge-warning"><?= number_format($plan['descuento'], 0) ?>% off</span>
                            <?php else: ?>
                                <span style="color:#64748b;">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $plan['points'] ?> <small style="color:#64748b;">pts/día</small></td>
                        <td>
                            <span class="badge <?= $plan['is_featured'] ? 'badge-warning' : 'badge-secondary' ?>">
                                <?= $plan['is_featured'] ? '⭐ Destacado' : 'Normal' ?>
                            </span>
                        </td>
                        <td>
                            <label style="cursor: pointer;">
                                <input type="checkbox" <?= $plan['is_active'] ? 'checked' : '' ?> 
                                       onchange="togglePlan(<?= $plan['id'] ?>)" 
                                       style="display: none;">
                                <span style="display: inline-block; width: 44px; height: 24px; background: <?= $plan['is_active'] ? '#3b82f6' : '#374151' ?>; border-radius: 12px; position: relative; transition: all 0.2s;">
                                    <span style="display: block; width: 20px; height: 20px; background: #fff; border-radius: 50%; position: absolute; top: 2px; <?= $plan['is_active'] ? 'right: 2px;' : 'left: 2px;' ?> transition: all 0.2s;"></span>
                                </span>
                            </label>
                        </td>
                        <td>
                            <a href="?edit_plan=<?= $plan['id'] ?>" style="color: #3b82f6; margin-right: 15px;">Edit</a>
                            <a href="process.php?action=eliminar_plan&id=<?= $plan['id'] ?>" 
                               onclick="gEliminar(event,'¿Eliminar este plan?',this.href); return false;" 
                               style="color: #ef4444;">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #64748b;">
                            <i class="fas fa-crown" style="font-size: 2rem; margin-bottom: 10px; display: block; opacity: 0.5;"></i>
                            No hay planes configurados.<br>
                            <small>Haz clic en "Crear Plan" para agregar uno.</small>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Modal crear plan -->
        <div id="planModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 100; align-items: center; justify-content: center; overflow-y: auto;">
            <div style="background: #16213e; border-radius: 12px; padding: 25px; width: 100%; max-width: 500px; margin: 20px; border: 1px solid #1f3460;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h3 style="font-size: 1.2rem;">Crear Nuevo Plan de Membresía</h3>
                    <button onclick="closePlanModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.5rem; cursor: pointer;">&times;</button>
                </div>
                <form action="process.php" method="POST">
                    <input type="hidden" name="action" value="guardar_plan">
                    
                    <div class="form-group">
                        <label>Nombre del Plan</label>
                        <input type="text" name="name" required placeholder="Ej: Pro, Premium, etc.">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Precio</label>
                            <input type="number" name="price" step="0.01" value="0.00" min="0" placeholder="$">
                        </div>
                        <div class="form-group">
                            <label>Descuento en cuentas <small style="color:#64748b; font-weight:400;">(%)</small></label>
                            <input type="number" name="descuento" step="0.01" value="0" min="0" max="100" placeholder="ej: 10">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Puntos por día <small style="color:#64748b; font-weight:400;">(recompensa diaria para el usuario)</small></label>
                            <input type="number" name="points" value="0" min="0" placeholder="ej: 10">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Descripción</label>
                        <textarea name="description" rows="4" placeholder="Beneficios del plan (usa | para separar)..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="is_featured" style="width: auto;">
                                Plan Destacado
                            </label>
                        </div>
                        <div class="form-group">
                            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                <input type="checkbox" name="is_active" checked style="width: auto;">
                                Plan Activo
                            </label>
                        </div>
                    </div>
                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button type="button" onclick="closePlanModal()" class="btn btn-secondary">Cancelar</button>
                        <button type="submit" class="btn btn-primary" style="flex: 1;">Crear Plan</button>
                    </div>
                </form>
            </div>
        </div>
        
        <script>
            function openPlanModal() {
                document.getElementById('planModal').style.display = 'flex';
            }
            function closePlanModal() {
                document.getElementById('planModal').style.display = 'none';
            }
            function togglePlan(id) {
                fetch('process.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'action=toggle_plan&id=' + id
                }).then(r => r.json()).then(data => {
                    if (data.success) {
                        location.reload();
                    }
                });
            }
            document.getElementById('planModal').addEventListener('click', function(e) {
                if (e.target === this) closePlanModal();
            });
        </script>
        <?php endif; ?>

    </main>
    </div><!-- end .dashboard -->

    <script>
    function closeAdminDrawer() {
        document.getElementById('adminDrawer').classList.remove('open');
        document.getElementById('adminMain').classList.remove('drawer-open');
        document.getElementById('adminOverlay').classList.remove('active');
    }
    function toggleAdminDrawer() {
        var drawer  = document.getElementById('adminDrawer');
        var main    = document.getElementById('adminMain');
        var overlay = document.getElementById('adminOverlay');
        var isOpen  = drawer.classList.contains('open');
        var isMobile = window.innerWidth <= 900;
        drawer.classList.toggle('open', !isOpen);
        if (isMobile) {
            main.classList.remove('drawer-open');
            overlay.classList.toggle('active', !isOpen);
        } else {
            main.classList.toggle('drawer-open', !isOpen);
            overlay.classList.remove('active');
        }
    }
    // Navega directo a una sección (los iconos del sidebar)
    function openDrawerSection(seccion) {
        window.location.href = '?seccion=' + seccion;
    }
    document.getElementById('adminOverlay').addEventListener('click', closeAdminDrawer);
    window.addEventListener('resize', function() {
        var drawer  = document.getElementById('adminDrawer');
        var main    = document.getElementById('adminMain');
        var overlay = document.getElementById('adminOverlay');
        if (!drawer.classList.contains('open')) return;
        if (window.innerWidth <= 900) {
            main.classList.remove('drawer-open');
            overlay.classList.add('active');
        } else {
            main.classList.add('drawer-open');
            overlay.classList.remove('active');
        }
    });
    </script>

    <!-- ===== MODAL GLOBAL ADMIN ===== -->
    <div id="gModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
        <div style="background:#1a1a2e; border:1px solid #1f3460; border-radius:16px; padding:32px 28px 24px; max-width:420px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.6); text-align:center; animation:gModalIn .18s ease;">
            <div id="gModalIcon" style="font-size:2.2rem; margin-bottom:14px;"></div>
            <div id="gModalTitle" style="font-size:1.1rem; font-weight:700; color:#fff; margin-bottom:8px;"></div>
            <div id="gModalMsg" style="font-size:0.92rem; color:#94a3b8; line-height:1.5; margin-bottom:24px;"></div>
            <div id="gModalBtns" style="display:flex; gap:10px; justify-content:center;"></div>
        </div>
    </div>
    <style>
        @keyframes gModalIn { from { opacity:0; transform:scale(.93) translateY(10px); } to { opacity:1; transform:scale(1) translateY(0); } }
        #gModal { display:none; }
        #gModal.active { display:flex !important; }
    </style>
    <script>
        let _gModalResolve = null;

        function gConfirm(msg, title) {
            return new Promise(resolve => {
                _gModalResolve = resolve;
                document.getElementById('gModalIcon').innerHTML = '<i class="fas fa-question-circle" style="color:#f59e0b;"></i>';
                document.getElementById('gModalTitle').textContent = title || '¿Confirmar acción?';
                document.getElementById('gModalMsg').textContent = msg;
                document.getElementById('gModalBtns').innerHTML = `
                    <button onclick="_gModalResolve(true); document.getElementById('gModal').classList.remove('active');"
                        style="background:#22c55e; color:#fff; border:none; border-radius:8px; padding:10px 28px; font-weight:700; font-size:0.95rem; cursor:pointer; min-width:100px;">
                        Aceptar
                    </button>
                    <button onclick="_gModalResolve(false); document.getElementById('gModal').classList.remove('active');"
                        style="background:#1f3460; color:#fff; border:1px solid #2e4a7a; border-radius:8px; padding:10px 28px; font-weight:700; font-size:0.95rem; cursor:pointer; min-width:100px;">
                        Cancelar
                    </button>`;
                document.getElementById('gModal').classList.add('active');
            });
        }

        // Función helper para los enlaces de eliminar/restaurar
        async function gEliminar(e, msg, url) {
            e.preventDefault();
            const ok = await gConfirm(msg);
            if (ok) window.location.href = url;
        }
    </script>
</body>
</html>

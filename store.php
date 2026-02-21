<?php
/**
 * Tienda Completa - Diseño Virtu Mall LLC
 * Los usuarios logueados pueden hacer compras aquí
 */
session_start();
require_once 'config.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: index.html');
    exit;
}

$userName = $_SESSION['user_nombre'] ?? 'Usuario';
$userId = $_SESSION['user_id'];
$pdo = getConnection();
$creditosUsuario = getCreditos($userId);
$plataformas = getPlataformas();
$cuentas = getCuentas();

// Filtrar solo cuentas disponibles
$cuentasDisponibles = array_filter($cuentas, fn($c) => $c['estado'] == 'disponible');

// Separar por tipo
$cuentasCompletas  = array_filter($cuentasDisponibles, fn($c) => ($c['tipo_cuenta'] ?? 'completa') === 'completa');
$cuentasPerfiles   = array_filter($cuentasDisponibles, fn($c) => ($c['tipo_cuenta'] ?? '') === 'perfil');

// Obtener compras del usuario
$stmtCompras = $pdo->prepare("
    SELECT c.id as compra_id, c.creditos_usados, c.created_at,
           cu.id as cuenta_id, cu.correo, cu.password, cu.usuario_cuenta, cu.pins,
           cu.dias, cu.whatsapp_soporte, cu.terminos, cu.descripcion,
           cu.tipo_cuenta, cu.nombre_servicio, cu.renovable,
           p.nombre as plataforma_nombre, p.imagen_url as plataforma_imagen,
           u.nombre as cliente_nombre, u.email as cliente_email
    FROM compras c
    LEFT JOIN cuentas cu ON c.cuenta_id = cu.id
    LEFT JOIN plataformas p ON cu.plataforma_id = p.id
    LEFT JOIN usuarios u ON c.usuario_id = u.id
    WHERE c.usuario_id = ?
    ORDER BY c.created_at DESC
");
$stmtCompras->execute([$userId]);
$misCompras = $stmtCompras->fetchAll();

// Obtener métodos de pago activos
try {
    $metodosPagoActivos = getMetodosPagoActivos();
} catch (Exception $e) {
    $metodosPagoActivos = [];
}

// Obtener planes de membresía activos
try {
    $planesActivos = getPlanesActivos();
} catch (Exception $e) {
    $planesActivos = [];
}

// Membresía activa del usuario actual
$membresiaActiva = null;
$diasRestantes = 0;
try {
    $stmtMem = $pdo->prepare("
        SELECT um.*, mp.name as plan_nombre, mp.price as plan_precio, mp.points as plan_points, mp.descuento as plan_descuento
        FROM usuario_membresias um
        JOIN membership_plans mp ON um.plan_id = mp.id
        WHERE um.usuario_id = ? AND um.estado = 'activa'
        ORDER BY um.fecha_inicio DESC
        LIMIT 1
    ");
    $stmtMem->execute([$userId]);
    $membresiaActiva = $stmtMem->fetch() ?: null;
    if ($membresiaActiva) {
        $fechaInicio = new DateTime($membresiaActiva['fecha_inicio']);
        $fechaVence  = (clone $fechaInicio)->modify('+30 days');
        $hoyDt       = new DateTime('today');
        $diff = $hoyDt->diff($fechaVence);
        $diasRestantes = $diff->invert ? 0 : (int)$diff->days;
        // Si expiró, marcarla como expirada
        if ($diasRestantes <= 0) {
            try {
                $pdo->prepare("UPDATE usuario_membresias SET estado='expirada' WHERE id=?")
                    ->execute([$membresiaActiva['id']]);
            } catch (Exception $e) {}
            $membresiaActiva = null;
            $diasRestantes = 0;
        }
    }
} catch (Exception $e) {
    $membresiaActiva = null;
    $diasRestantes = 0;
}

// Puntos de recompensa del usuario
$puntosUsuario = 0;
try {
    $stmtPts = $pdo->prepare("SELECT puntos_recompensa FROM usuarios WHERE id = ?");
    $stmtPts->execute([$userId]);
    $puntosUsuario = (int)($stmtPts->fetchColumn() ?: 0);
} catch (Exception $e) {
    $puntosUsuario = 0;
}

// ¿Ya reclamó puntos hoy?
$yaReclamoHoy = false;
if ($membresiaActiva && !empty($membresiaActiva['ultima_reclamacion'])) {
    $yaReclamoHoy = ($membresiaActiva['ultima_reclamacion'] === date('Y-m-d'));
}

// Cursos publicados (creados en admin, para sección Classroom)
$cursosPath = __DIR__ . '/cursos.json';
$cursosStore = file_exists($cursosPath) ? json_decode(file_get_contents($cursosPath), true) : [];
$cursosStore = is_array($cursosStore) ? array_values(array_filter($cursosStore, fn($c) => ($c['estado'] ?? '') === 'publicado')) : [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Tienda - Cuentas Premium</title>
    <link rel="icon" id="faviconLink" href="" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-dark: #0a0a0a;
            --bg-sidebar: #0f0f0f;
            --bg-card: #151515;
            --primary-red: #ff0000;
            --text-main: #ffffff;
            --text-gray: #a0a0a0;
            --border-color: #2a2a2a;
            --green-accent: #00ff88;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            overflow-x: hidden;
            width: 100%;
            max-width: 100%;
        }

        body {
            background-color: var(--bg-dark);
            color: var(--text-main);
            font-family: 'Segoe UI', sans-serif;
            overflow-x: hidden;
            width: 100%;
            max-width: 100%;
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        /* ── SIDEBAR ICON-ONLY ── */
        .sidebar {
            width: 64px;
            background-color: var(--bg-sidebar);
            border-right: 1px solid var(--border-color);
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
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0; cursor: pointer;
            transition: background 0.2s;
            color: var(--green-accent); font-size: 1.25rem;
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
            background: var(--green-accent); color: #000;
        }

        /* ── DRAWER (texto + submenús) ── */
        .store-drawer {
            display: flex; position: fixed;
            top: 0; left: 64px;
            width: 230px; height: 100vh;
            background-color: var(--bg-sidebar);
            border-right: 1px solid var(--border-color);
            z-index: 999; flex-direction: column;
            transform: translateX(-294px);
            transition: transform 0.28s ease;
        }
        .store-drawer.open { transform: translateX(0); }
        .drawer-logo {
            padding: 16px 20px; border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center;
        }
        .drawer-logo img { height: 34px; }
        .drawer-nav { flex: 1; overflow-y: auto; padding: 8px 0; }
        .drawer-item {
            padding: 12px 20px; color: var(--text-gray);
            cursor: pointer; display: flex; align-items: center;
            gap: 14px; transition: 0.2s; font-size: 0.92rem;
        }
        .drawer-item:hover { color: white; background: rgba(255,255,255,0.05); }
        .drawer-item.active-category { color: white; background: linear-gradient(90deg, rgba(32,212,137,0.15) 0%, transparent 100%); }
        .drawer-item i { width: 18px; text-align: center; color: var(--green-accent); }
        .drawer-item.has-submenu::after {
            content: '›'; margin-left: auto; font-size: 1.3rem;
            line-height: 1; opacity: 0.4;
            transition: opacity 0.2s, transform 0.2s; display: inline-block;
        }
        .drawer-item.has-submenu.open::after { transform: rotate(90deg); opacity: 1; color: var(--green-accent); }
        .drawer-item.has-submenu:hover::after { opacity: 1; }
        .drawer-submenu {
            list-style: none; padding: 4px 0 4px 52px; display: none;
        }
        .drawer-item.open + .drawer-submenu { display: block; }
        .drawer-submenu li {
            padding: 8px 15px; font-size: 0.88rem; color: var(--text-gray);
            border-radius: 4px; margin-right: 12px; cursor: pointer; transition: 0.2s;
        }
        .drawer-submenu li:hover { color: #fff; background: rgba(255,255,255,0.05); }
        .drawer-submenu li.active { background: var(--green-accent); color: #000; font-weight: 600; }

        /* Overlay */
        .sidebar-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.45); z-index: 997;
        }
        .sidebar-overlay.active { display: block; }

        /* Main Content — siempre a 64px del sidebar de iconos */
        .main-content {
            flex: 1; padding: 20px 40px;
            margin-left: 64px; width: calc(100% - 64px);
            max-width: 100%; overflow-x: hidden;
            transition: margin-left 0.28s ease, width 0.28s ease;
        }
        .main-content.drawer-open {
            margin-left: 294px; width: calc(100% - 294px);
        }

        /* En pantallas <= 900px (incluye zoom 125% en monitores normales):
           el drawer actúa como overlay — NO empuja el main-content */
        @media (max-width: 900px) {
            .main-content {
                margin-left: 64px !important;
                width: calc(100% - 64px) !important;
                padding: 12px 16px;
                padding-bottom: 64px;
            }
            .main-content.drawer-open {
                margin-left: 64px !important;
                width: calc(100% - 64px) !important;
            }
            .store-drawer {
                left: 64px;
                width: 220px;
                transform: translateX(-284px);
                z-index: 1001;
            }
            .sidebar-overlay { z-index: 1000; }
        }

        @media (max-width: 768px) {
            .main-content { margin-left: 56px !important; width: calc(100% - 56px) !important; padding: 12px 14px; }
            .main-content.drawer-open { margin-left: 56px !important; width: calc(100% - 56px) !important; }
            .sidebar { width: 56px; }
            .sidebar-ham { width: 56px; height: 56px; }
            .store-drawer { left: 56px; width: 210px; transform: translateX(-266px); z-index: 1001; }
        }

        .header-top {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            padding-bottom: 20px;
            width: 100%;
            box-sizing: border-box;
            overflow: hidden;
        }

        .header-top .header-right {
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .balance-box {
            background: #1a1a1a;
            padding: 8px 15px;
            border-radius: 5px;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-add {
            background: var(--primary-red);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
            text-transform: uppercase;
        }

        .btn-add:hover {
            background: #cc0000;
        }

        .user-pill {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-gray);
        }

        .btn-logout {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-gray);
            padding: 8px 15px;
            border-radius: 5px;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-logout:hover {
            background: var(--primary-red);
            color: white;
            border-color: var(--primary-red);
        }

        /* Titles */
        h1 {
            margin-top: 0;
            font-size: 1.8rem;
        }

        .subtitle {
            color: var(--text-gray);
            margin-bottom: 20px;
        }

        .alert-safe {
            background: rgba(0, 255, 136, 0.1);
            border: 1px solid var(--green-accent);
            padding: 12px 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 25px;
        }

        .alert-safe b {
            color: var(--green-accent);
        }

        /* Search */
        .search-container {
            position: relative;
            margin-bottom: 20px;
        }

        .search-bar {
            width: 100%;
            background: #1e1e1e;
            border: 1px solid var(--border-color);
            padding: 15px;
            border-radius: 6px;
            color: white;
            box-sizing: border-box;
            font-size: 1rem;
        }

        .search-bar:focus {
            outline: none;
            border-color: var(--primary-red);
        }

        /* Category Icons — responsive: compactos y scroll horizontal en móvil */
        .category-scroll {
            display: flex;
            flex-wrap: nowrap;
            gap: 10px;
            padding-bottom: 12px;
            margin-bottom: 20px;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
            scrollbar-color: var(--border-color) transparent;
        }
        .category-scroll::-webkit-scrollbar { height: 4px; }
        .category-scroll::-webkit-scrollbar-track { background: transparent; }
        .category-scroll::-webkit-scrollbar-thumb { background: var(--border-color); border-radius: 4px; }

        .cat-icon {
            flex: 0 0 auto;
            min-width: 48px;
            max-width: 48px;
            width: 48px;
            height: 48px;
            display: flex;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: transform 0.15s, outline 0.15s;
            background: none;
            border: none;
            border-radius: 12px;
            padding: 0;
        }

        .cat-icon:hover {
            transform: scale(1.06);
        }

        .cat-icon.active {
            transform: scale(1.04);
            outline: 2px solid white;
            outline-offset: 2px;
        }

        .cat-icon i {
            font-size: 1.35rem;
            color: #fff;
        }

        .cat-icon img {
            width: 48px;
            height: 48px;
            object-fit: cover;
            border-radius: 12px;
        }

        .cat-icon.active i {
            color: white;
        }

        /* Tablet: iconos un poco más chicos */
        @media (max-width: 900px) {
            .category-scroll { gap: 8px; padding-bottom: 10px; margin-bottom: 16px; }
            .cat-icon { min-width: 44px; max-width: 44px; width: 44px; height: 44px; border-radius: 10px; }
            .cat-icon img { width: 44px; height: 44px; border-radius: 10px; }
            .cat-icon i { font-size: 1.2rem; }
        }

        /* Móvil: iconos pequeños, scroll horizontal (estilo referencia) */
        @media (max-width: 768px) {
            .category-scroll {
                display: flex;
                flex-wrap: nowrap;
                gap: 8px;
                padding: 8px 0 10px;
                margin-bottom: 14px;
                margin-left: -2px;
                margin-right: -2px;
            }
            .cat-icon {
                flex: 0 0 auto;
                min-width: 40px;
                max-width: 40px;
                width: 40px;
                height: 40px;
                border-radius: 10px;
            }
            .cat-icon img {
                width: 40px;
                height: 40px;
                border-radius: 10px;
            }
            .cat-icon i { font-size: 1.1rem; }
        }

        @media (max-width: 480px) {
            .category-scroll { gap: 6px; padding: 6px 0 8px; margin-bottom: 12px; }
            .cat-icon { min-width: 36px; max-width: 36px; width: 36px; height: 36px; border-radius: 8px; }
            .cat-icon img { width: 36px; height: 36px; border-radius: 8px; }
            .cat-icon i { font-size: 1rem; }
        }

        /* Products Table */
        .products-table {
            width: 100%;
            background: var(--bg-card);
            border-collapse: collapse;
            border-radius: 8px;
            overflow: hidden;
            margin-top: 20px;
        }

        .products-table th {
            background: #1a1a1a;
            text-align: left;
            padding: 15px;
            font-size: 0.75rem;
            color: var(--text-gray);
            text-transform: uppercase;
        }

        .products-table td {
            padding: 20px 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .products-table tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        /* ---- Seller cell ---- */
        .seller-cell {
            min-width: 140px;
        }
        .seller-avatar-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .seller-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: #1e3a5f;
            color: #4aa3ff;
            font-weight: 700;
            font-size: 0.95rem;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            border: 2px solid #2a4a7f;
        }
        .seller-name {
            font-weight: 700;
            color: #e2e8f0;
            font-size: 0.88rem;
            display: flex; align-items: center; gap: 4px;
        }
        .seller-name .verified {
            color: #4aa3ff;
            font-size: 0.75rem;
        }
        .seller-stats {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 4px;
            font-size: 0.75rem;
            color: var(--text-gray);
        }
        .seller-stats .dot { color: #444; }

        /* ---- Service cell ---- */
        .service-title {
            font-weight: 700;
            color: #f1f5f9;
            font-size: 0.9rem;
            line-height: 1.4;
            margin-bottom: 3px;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .service-subtitle {
            font-size: 0.78rem;
            color: var(--text-gray);
            margin-bottom: 8px;
            word-wrap: break-word;
        }
        .service-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }
        .badge-manual {
            background: rgba(255,180,0,0.12);
            color: #f59e0b;
            border: 1px solid rgba(255,180,0,0.3);
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 0.72rem;
            font-weight: 600;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .badge-automatico {
            background: rgba(0,255,136,0.08);
            color: #00ff88;
            border: 1px solid rgba(0,255,136,0.25);
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 0.72rem;
            font-weight: 600;
            display: inline-flex; align-items: center; gap: 4px;
        }
        .badge-gray {
            background: #1e293b;
            color: #94a3b8;
            border: 1px solid #334155;
            border-radius: 20px;
            padding: 3px 10px;
            font-size: 0.72rem;
            display: inline-flex; align-items: center; gap: 4px;
        }

        /* ---- Price cell ---- */
        .price-row-label {
            font-size: 0.8rem;
            color: var(--text-gray);
            margin-bottom: 1px;
        }
        .price-row-value {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--green-accent);
            margin-bottom: 8px;
        }
        .price-pro-row-label {
            font-size: 0.8rem;
            color: var(--text-gray);
            margin-bottom: 1px;
        }
        .price-pro-row-value {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--green-accent);
        }

        /* ---- Stock cell ---- */
        .stock-cell-num {
            font-size: 1.1rem;
            font-weight: 700;
            color: #e2e8f0;
        }
        .stock-cell-label {
            font-size: 0.78rem;
            color: var(--text-gray);
            margin-left: 3px;
        }

        .service-id {
            color: var(--text-gray);
            font-size: 0.8rem;
            margin-right: 5px;
        }

        .tag {
            font-size: 0.7rem;
            padding: 3px 8px;
            border-radius: 4px;
            margin-top: 8px;
            display: inline-block;
        }

        .tag-manual {
            background: rgba(255, 215, 0, 0.1);
            color: #ffd700;
            border: 1px solid #ffd70033;
        }

        .tag-gray {
            background: #333;
            color: #ccc;
        }

        .tag-green {
            background: rgba(0, 255, 136, 0.1);
            color: var(--green-accent);
            border: 1px solid rgba(0, 255, 136, 0.3);
        }

        .price-normal {
            display: block;
            font-size: 0.85rem;
            color: var(--text-gray);
        }

        .price-pro {
            display: block;
            color: var(--green-accent);
            font-weight: bold;
        }

        .stock-count {
            color: var(--green-accent);
            font-weight: bold;
        }

        .btn-buy-red {
            background: #a3ff00;
            color: #000;
            border: none;
            padding: 12px 28px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.25s ease;
            text-align: center;
            line-height: 1.4;
        }

        .btn-buy-red:hover {
            background: #8ee600;
            box-shadow: 0 4px 18px rgba(163, 255, 0, 0.35);
            transform: translateY(-1px);
        }

        .btn-terms {
            background: transparent;
            border: 1px solid #444;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-terms:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .btn-info-detail {
            background: transparent;
            border: 1px solid #444;
            color: #e2e8f0;
            padding: 5px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.78rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.18s, border-color 0.18s;
            white-space: nowrap;
        }
        .btn-info-detail:hover {
            background: rgba(255,255,255,0.08);
            border-color: #666;
        }
        .detalles-btns {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            align-items: center;
        }
        @media (max-width: 480px) {
            .detalles-btns { gap: 6px; }
            .btn-info-detail, .btn-terms { padding: 6px 10px; font-size: 0.75rem; }
        }

        /* Modal info cuenta */
        #infoModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.65);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        #infoModal.open { display: flex; }
        #infoModalBox {
            background: #1e293b;
            border: 1px solid #334155;
            border-radius: 14px;
            padding: 28px 30px;
            max-width: 480px;
            width: 92%;
            position: relative;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        #infoModalTitle {
            font-size: 1.05rem;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0 0 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        #infoModalBody {
            color: #cbd5e1;
            font-size: 0.9rem;
            line-height: 1.65;
            white-space: pre-line;
        }
        #infoModalClose {
            position: absolute;
            top: 14px; right: 16px;
            background: none; border: none;
            color: #94a3b8; font-size: 1.2rem;
            cursor: pointer;
        }
        #infoModalClose:hover { color: #f1f5f9; }

        /* ===== TIENDA TABS ===== */
        .tienda-tabs {
            display: flex;
            gap: 10px;
            margin: 20px 0 0;
            border-bottom: 2px solid #1e1e1e;
            padding-bottom: 0;
            flex-wrap: wrap;
        }

        .tienda-tab {
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--text-gray);
            padding: 10px 20px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: -2px;
            transition: color 0.2s, border-color 0.2s;
        }
        @media (max-width: 768px) {
            .tienda-tabs { margin-top: 14px; gap: 8px; }
            .tienda-tab { padding: 8px 14px; font-size: 0.85rem; }
        }

        .tienda-tab.active {
            color: #fff;
            border-bottom-color: var(--green-accent);
        }

        .tienda-tab:not(.active):hover {
            color: #ccc;
        }

        #tab-perfiles.active {
            border-bottom-color: #4aa3ff;
        }

        .tab-badge {
            background: rgba(0, 255, 136, 0.15);
            color: var(--green-accent);
            border: 1px solid rgba(0, 255, 136, 0.3);
            border-radius: 20px;
            padding: 1px 8px;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .tab-badge-blue {
            background: rgba(74, 163, 255, 0.15);
            color: #4aa3ff;
            border-color: rgba(74, 163, 255, 0.3);
        }

        /* ===== TIENDA PANELS ===== */
        .tienda-panel {
            display: none;
            margin-top: 18px;
        }

        .tienda-panel.active {
            display: block;
        }

        .tienda-panel-header {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: rgba(0, 255, 136, 0.05);
            border: 1px solid rgba(0, 255, 136, 0.15);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 16px;
        }

        .tienda-panel-header i {
            font-size: 1.5rem;
            margin-top: 2px;
        }

        .tienda-panel-header h3 {
            margin: 0 0 4px;
            font-size: 1rem;
            color: #fff;
        }

        .tienda-panel-header p {
            margin: 0;
            font-size: 0.8rem;
            color: var(--text-gray);
        }

        .tienda-panel-header-blue {
            background: rgba(74, 163, 255, 0.05);
            border-color: rgba(74, 163, 255, 0.2);
        }

        /* ===== TAGS DE TIPO CUENTA ===== */
        .tag-cuenta-completa {
            background: rgba(0, 255, 136, 0.12);
            color: var(--green-accent);
            border: 1px solid rgba(0, 255, 136, 0.25);
        }

        .tag-cuenta-perfil {
            background: rgba(74, 163, 255, 0.12);
            color: #4aa3ff;
            border: 1px solid rgba(74, 163, 255, 0.25);
        }

        /* ===== PRECIO AZUL PARA PERFILES ===== */
        .price-pro-blue {
            color: #4aa3ff;
        }

        /* Precio Normal / Pro apilados */
        .price-label {
            display: block;
            font-size: 0.82rem;
            color: var(--text-gray);
            line-height: 1.7;
        }

        .price-label .price-pro {
            display: inline;
        }

        .price-highlight-pro {
            color: #a3ff00 !important;
        }

        /* ===== BOTÓN COMPRAR PERFILES ===== */
        .btn-buy-blue {
            background: #4aa3ff;
            color: #000;
            border: none;
            padding: 12px 28px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.25s ease;
            text-align: center;
            line-height: 1.4;
        }

        .btn-buy-blue:hover {
            background: #2d8fe0;
            box-shadow: 0 4px 18px rgba(74, 163, 255, 0.35);
            transform: translateY(-1px);
        }

        /* ===== PERFILES SLOT ===== */
        .perfiles-slot {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(74, 163, 255, 0.1);
            color: #4aa3ff;
            border: 1px solid rgba(74, 163, 255, 0.2);
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* WhatsApp Float — no superponer contenido: más pequeño en móvil y con safe area */
        .wa-float {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #25d366;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 24px;
            color: white;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.2s;
            z-index: 800;
        }
        @media (max-width: 768px) {
            .wa-float {
                width: 44px;
                height: 44px;
                font-size: 22px;
                bottom: 16px;
                right: 16px;
                bottom: max(16px, env(safe-area-inset-bottom));
                right: max(16px, env(safe-area-inset-right));
            }
        }
        @media (max-width: 480px) {
            .wa-float {
                width: 40px;
                height: 40px;
                font-size: 20px;
                bottom: max(12px, env(safe-area-inset-bottom));
                right: max(12px, env(safe-area-inset-right));
            }
        }
        .wa-float:hover {
            transform: scale(1.1);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-gray);
        }

        /* ===== MIS COMPRAS TABLE ===== */
        .mc-table-wrap {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }
        .mc-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-card);
            font-size: 0.88rem;
        }
        .mc-table thead tr {
            background: #1a1a1a;
            border-bottom: 1px solid var(--border-color);
        }
        .mc-table thead th {
            padding: 12px 14px;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-gray);
            letter-spacing: 0.05em;
            white-space: nowrap;
        }
        .mc-row {
            border-bottom: 1px solid var(--border-color);
        }
        .mc-row:last-child { border-bottom: none; }
        .mc-row td {
            padding: 14px 14px;
            vertical-align: top;
        }
        /* ID */
        .mc-id {
            font-weight: 700;
            color: var(--text-gray);
            font-size: 0.95rem;
            white-space: nowrap;
        }
        /* PROVEEDOR */
        .mc-prov-id {
            font-weight: 700;
            font-size: 0.88rem;
            color: #fff;
            margin-bottom: 4px;
        }
        .mc-prov-user {
            font-size: 0.8rem;
            color: var(--text-gray);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .mc-dot-green {
            display: inline-block;
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #22c55e;
        }
        /* CLIENTE */
        .mc-cli-nombre {
            font-weight: 700;
            font-size: 0.92rem;
            color: #fff;
        }
        .mc-cli-email {
            font-size: 0.8rem;
            color: var(--text-gray);
            margin: 2px 0;
        }
        .mc-cli-wa {
            font-size: 0.8rem;
            color: #22c55e;
            margin-bottom: 6px;
        }
        .mc-cli-btns {
            display: flex;
            gap: 6px;
            margin-top: 4px;
        }
        .mc-btn-wa {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px; height: 30px;
            border-radius: 6px;
            background: #22c55e;
            color: #000;
            font-size: 0.9rem;
            text-decoration: none;
        }
        .mc-btn-copy {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px; height: 30px;
            border-radius: 6px;
            background: #2a2a2a;
            border: 1px solid #444;
            color: #fff;
            font-size: 0.85rem;
            cursor: pointer;
        }
        /* PERFIL / credenciales */
        .mc-cred-box {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.8rem;
            line-height: 1.7;
            min-width: 200px;
        }
        .mc-cred-label { color: var(--text-gray); }
        .mc-cred-val   { color: #fff; font-weight: 600; }
        .mc-cred-highlight { color: #60a5fa; font-weight: 600; }
        .mc-cred-yellow    { color: #fbbf24; font-weight: 600; }
        .mc-cred-actions {
            display: flex;
            gap: 6px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .mc-btn-terms {
            padding: 5px 10px;
            border-radius: 5px;
            border: 1px solid #f97316;
            background: transparent;
            color: #f97316;
            font-size: 0.75rem;
            cursor: pointer;
        }
        .mc-btn-desc {
            padding: 5px 10px;
            border-radius: 5px;
            border: 1px solid #a78bfa;
            background: transparent;
            color: #a78bfa;
            font-size: 0.75rem;
            cursor: pointer;
        }
        /* ESTADO */
        .mc-badge-active {
            display: inline-block;
            background: rgba(34,197,94,0.15);
            color: #22c55e;
            border: 1px solid #22c55e;
            border-radius: 20px;
            padding: 3px 12px;
            font-size: 0.78rem;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .mc-badge-expired {
            display: inline-block;
            background: rgba(248,113,113,0.15);
            color: #f87171;
            border: 1px solid #f87171;
            border-radius: 20px;
            padding: 3px 12px;
            font-size: 0.78rem;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .mc-dias-rest {
            font-size: 0.82rem;
            color: #22c55e;
        }
        /* FECHAS */
        .mc-fechas {
            font-size: 0.82rem;
            color: var(--text-gray);
            white-space: nowrap;
            line-height: 1.8;
        }
        .mc-fechas b { color: #fff; }
        /* PRECIO */
        .mc-precio {
            font-weight: 800;
            font-size: 1rem;
            color: #22c55e;
            white-space: nowrap;
        }
        /* ACCIONES */
        .mc-acciones {
            display: flex;
            flex-direction: column;
            gap: 8px;
            white-space: nowrap;
        }
        .mc-btn-renovar {
            padding: 7px 14px;
            border-radius: 6px;
            background: #3b82f6;
            border: none;
            color: #fff;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .mc-btn-soporte {
            padding: 7px 14px;
            border-radius: 6px;
            background: #f97316;
            border: none;
            color: #fff;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        /* ============================= */

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                width: 64px;
            }
            .main-content {
                margin-left: 64px;
                padding: 20px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 12px 14px;
                padding-bottom: 72px; /* espacio para FAB WhatsApp, no tapa Comprar */
            }
            h1, .subtitle, .alert-safe, .search-bar {
                max-width: 100%;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }
            .header-top {
                flex-direction: row;
                flex-wrap: nowrap;
                justify-content: flex-end;
                align-items: center;
                width: 100%;
                box-sizing: border-box;
                overflow: hidden;
                padding-bottom: 12px;
            }
            .header-top .header-right {
                flex-wrap: nowrap;
                gap: 6px;
                align-items: center;
            }
            .balance-box {
                padding: 5px 8px;
                font-size: 0.78rem;
                white-space: nowrap;
            }
            .btn-add {
                padding: 6px 10px;
                font-size: 0.75rem;
                white-space: nowrap;
            }
            .user-pill span {
                display: none;
            }
            .user-pill {
                padding: 5px 8px;
                font-size: 0.85rem;
            }
            .btn-logout span,
            .btn-logout .btn-text {
                display: none;
            }
            .btn-logout {
                padding: 6px 10px;
                font-size: 0.8rem;
            }
            h1 {
                font-size: 1.4rem;
            }
            .subtitle {
                font-size: 0.9rem;
                margin-bottom: 14px;
            }
            .alert-safe {
                padding: 10px 12px;
                font-size: 0.85rem;
                margin-bottom: 18px;
            }
            .search-container {
                margin-bottom: 14px;
            }
            .search-bar {
                padding: 10px 12px;
                font-size: 0.9rem;
            }
            .stats-row {
                flex-direction: column;
            }
            .affiliate-banner {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            .plans-grid {
                max-width: 100%;
            }
            /* Tabla de productos / compras como tarjetas en móvil */
            .products-table {
                width: 100%;
                border-collapse: separate;
                border-spacing: 0;
                background: transparent;
            }
            .products-table thead {
                display: none;
            }
            .products-table tbody {
                display: block;
                width: 100%;
            }
            .products-table tr {
                display: flex;
                flex-direction: column;
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: 10px;
                margin-bottom: 12px;
                padding: 12px 14px 10px 14px;
                gap: 2px;
            }
            .products-table td {
                display: block;
                border-bottom: none;
                padding: 2px 0;
                width: auto;
                min-width: 0;
            }
            /* Botón abajo a la derecha */
            .products-table td:last-child {
                display: flex;
                justify-content: flex-end;
                margin-top: 8px;
                padding: 0;
            }
            .btn-buy-red,
            .btn-buy-blue {
                width: auto;
                padding: 7px 16px;
                font-size: 0.78rem;
                border-radius: 4px;
                white-space: nowrap;
                line-height: 1.2;
            }
            .seller-cell strong {
                font-size: 0.95rem;
            }
            .seller-cell span {
                font-size: 0.8rem;
            }
            .service-id {
                font-size: 0.78rem;
            }
            .price-pro {
                font-size: 1rem;
            }
            .stock-count {
                font-size: 0.9rem;
            }
        }

        /* Ajustes extra para pantallas muy pequeñas (teléfonos chicos) */
        @media (max-width: 480px) {
            html, body {
                font-size: 14px;
                width: 100%;
                max-width: 100vw;
                overflow-x: hidden;
            }
            .main-content {
                padding: 10px 10px;
                padding-bottom: 68px;
                margin-left: 56px !important;
                width: calc(100% - 56px) !important;
                max-width: calc(100% - 56px);
                overflow-x: hidden;
            }
            .header-top {
                justify-content: flex-end;
                width: 100%;
                box-sizing: border-box;
            }
            .header-top .header-right {
                gap: 6px;
            }
            .balance-box {
                padding: 4px 6px;
                font-size: 0.75rem;
                max-width: calc(50% - 4px);
            }
            .btn-add {
                padding: 6px 8px;
                font-size: 0.7rem;
                max-width: calc(50% - 4px);
            }
            .user-pill {
                font-size: 0.8rem;
                max-width: calc(50% - 4px);
            }
            .btn-logout {
                padding: 6px 8px;
                font-size: 0.7rem;
                max-width: calc(50% - 4px);
            }
            h1 {
                font-size: 1.25rem;
                word-wrap: break-word;
            }
            .subtitle {
                font-size: 0.85rem;
                word-wrap: break-word;
            }
            /* Cards de producto en una columna: contenido arriba, botón abajo (sin superposición) */
            .products-table tr {
                grid-template-columns: 1fr;
                grid-template-rows: auto auto;
                padding: 12px;
                gap: 10px;
            }
            .products-table td:last-child {
                grid-column: 1;
                grid-row: 2;
                justify-content: stretch;
                border-top: 1px solid var(--border-color);
                padding-top: 10px;
                margin-top: 2px;
            }
            .btn-buy-red,
            .btn-buy-blue {
                width: 100%;
                min-width: 0;
            }
            .tienda-tabs {
                flex-wrap: wrap;
                gap: 8px;
            }
            .tienda-tab {
                padding: 8px 12px;
                font-size: 0.82rem;
            }
            .combo-panel {
                width: calc(100vw - 20px);
                max-width: calc(100vw - 20px);
                right: 10px;
                left: 10px;
                bottom: 10px;
            }
        }

        /* Afiliados Section */
        .section-content {
            display: none;
            max-width: 100%;
            overflow-x: hidden;
        }
        .section-content.active {
            display: block;
            animation: fadeIn 0.3s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Combo Builder Styles */
        .combo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 80px; /* Space for fixed bottom bar on mobile */
        }
        .combo-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 15px;
            position: relative;
            cursor: pointer;
            transition: all 0.2s;
            overflow: hidden;
        }
        .combo-card:hover {
            transform: translateY(-5px);
            border-color: #444;
        }
        .combo-card.selected {
            border-color: var(--primary-red);
            background: rgba(255, 0, 0, 0.05);
        }
        .combo-card .check-circle {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            border: 2px solid #555;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: 0.2s;
            background: rgba(0,0,0,0.5);
        }
        .combo-card.selected .check-circle {
            background: var(--primary-red);
            border-color: var(--primary-red);
            color: white;
        }
        .combo-card img {
            width: 100%;
            height: 120px;
            object-fit: contain;
            margin-bottom: 10px;
        }
        .combo-card h4 {
            font-size: 0.95rem;
            margin-bottom: 5px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .combo-card .price {
            font-weight: bold;
            color: var(--green-accent);
            font-size: 1.1rem;
        }
        
        /* Combo Panel Sticky */
        .combo-panel {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 320px;
            max-width: calc(100vw - 40px);
            background: #1a1a1a;
            border: 1px solid var(--primary-red);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            z-index: 900;
            transform: translateY(120%);
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .combo-panel.visible {
            transform: translateY(0);
        }
        .combo-panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
        }
        .combo-panel-items {
            max-height: 200px;
            overflow-y: auto;
            margin-bottom: 15px;
        }
        .combo-item-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.9rem;
            color: #ccc;
        }
        .combo-total {
            font-size: 1.5rem;
            font-weight: bold;
            color: white;
            text-align: right;
            margin-bottom: 15px;
        }
        .btn-combo-buy {
            width: 100%;
            background: linear-gradient(135deg, #ff0000 0%, #cc0000 100%);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 4px 15px rgba(255,0,0,0.3);
            transition: 0.2s;
        }
        .btn-combo-buy:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255,0,0,0.4);
        }

        .affiliate-banner {
            background: linear-gradient(135deg, #1a0000 0%, #330000 100%);
            border: 1px solid var(--primary-red);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .affiliate-banner h2 {
            color: var(--primary-red);
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .affiliate-banner p {
            color: var(--text-gray);
        }

        .affiliate-banner .crown {
            font-size: 4rem;
            color: var(--primary-red);
        }

        .stats-row {
            display: flex;
            gap: 30px;
            margin-bottom: 30px;
        }

        .stat-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 20px;
            flex: 1;
        }

        .stat-box .icon {
            color: var(--primary-red);
            font-size: 1.5rem;
            margin-bottom: 10px;
        }

        .stat-box .label {
            color: var(--text-gray);
            font-size: 0.85rem;
        }

        .stat-box .value {
            font-size: 1.5rem;
            font-weight: bold;
            color: white;
        }

        .referral-section {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 30px;
        }

        .referral-section h3 {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .referral-section h3 i {
            color: var(--primary-red);
        }

        .referral-row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .referral-field {
            flex: 1;
            min-width: 250px;
        }

        .referral-field label {
            display: block;
            color: var(--text-gray);
            font-size: 0.85rem;
            margin-bottom: 8px;
        }

        .referral-input-group {
            display: flex;
            gap: 10px;
        }

        .referral-input-group input {
            flex: 1;
            background: #1a1a1a;
            border: 1px solid var(--border-color);
            padding: 12px 15px;
            border-radius: 6px;
            color: white;
            font-size: 0.9rem;
        }

        .referral-input-group button {
            background: var(--primary-red);
            border: none;
            padding: 12px 15px;
            border-radius: 6px;
            color: white;
            cursor: pointer;
        }

        .plans-section h3 {
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .plans-section h3 i {
            color: var(--primary-red);
        }

        .plans-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            max-width: 100%;
        }

        @media (max-width: 900px) {
            .plans-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 560px) {
            .plans-grid { grid-template-columns: 1fr; }
        }

        .plan-card {
            background: #111;
            border: 1px solid #2a2a2a;
            border-radius: 16px;
            padding: 28px 24px 20px;
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 0;
        }

        .plan-card.featured {
            border-color: var(--primary-red);
        }

        .classroom-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 24px;
            margin-top: 24px;
        }

        .curso-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            transition: 0.2s;
        }

        .curso-card:hover {
            border-color: var(--primary-red);
            box-shadow: 0 4px 20px rgba(255, 0, 0, 0.1);
        }

        .curso-card-img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            background: #1a1a1a;
        }

        .curso-card-body {
            padding: 20px;
        }

        .curso-card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            margin-bottom: 10px;
            line-height: 1.3;
        }

        .curso-card-desc {
            font-size: 0.9rem;
            color: var(--text-gray);
            margin-bottom: 16px;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .curso-card-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .curso-card-price {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--green-accent);
        }

        .plan-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 6px;
        }

        .plan-header h4 {
            font-size: 1.3rem;
            font-weight: 800;
            color: white;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .plan-header .price {
            text-align: right;
        }

        .plan-header .price .amount {
            font-size: 2rem;
            font-weight: 800;
            color: white;
            line-height: 1;
        }

        .plan-header .price .period {
            font-size: 0.78rem;
            color: var(--text-gray);
            text-align: right;
        }

        .plan-vence-badge {
            display: inline-block;
            background: #2a2200;
            color: #fbbf24;
            border: 1px solid #f59e0b88;
            border-radius: 20px;
            font-size: 0.78rem;
            font-weight: 700;
            padding: 3px 12px;
            margin-bottom: 18px;
        }

        .plan-benefits-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 8px;
        }

        .plan-benefits {
            list-style: none;
            padding: 0;
            margin: 0 0 20px 0;
        }

        .plan-benefits li {
            padding: 5px 0;
            color: #ccc;
            font-size: 0.88rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.5;
            word-break: break-word;
        }

        .plan-benefits li:last-child {
            border-bottom: none;
        }

        .plan-benefits li i {
            color: #22c55e;
            flex-shrink: 0;
            margin-top: 3px;
            font-size: 0.8rem;
        }

        .btn-activate {
            width: 100%;
            background: var(--primary-red);
            border: none;
            padding: 14px;
            border-radius: 8px;
            color: white;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
        }

        .btn-activate:hover:not(:disabled) {
            background: #cc0000;
        }

        .btn-renovar {
            width: 100%;
            background: #22c55e;
            border: none;
            padding: 14px;
            border-radius: 8px;
            color: white;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
        }

        .btn-renovar:hover {
            background: #16a34a;
        }

        .bottom-stats {
            display: flex;
            justify-content: center;
            gap: 60px;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid var(--border-color);
        }

        .bottom-stat {
            text-align: center;
        }

        .bottom-stat .icon {
            color: var(--green-accent);
            margin-bottom: 5px;
        }

        .bottom-stat .label {
            color: var(--text-gray);
            font-size: 0.85rem;
        }

        .bottom-stat .value {
            color: white;
            font-weight: bold;
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            max-width: 450px;
            width: 90%;
            text-align: center;
            animation: modalSlide 0.3s ease;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-content.no-padding {
            padding: 0 !important;
            overflow: hidden; /* rounded corners clip content */
            border: none;
        }
        @keyframes modalSlide {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-content h2 {
            margin-bottom: 20px;
            color: white;
        }
        .modal-content h2 i {
            color: var(--primary-red);
            margin-right: 10px;
        }
        .modal-input {
            width: 100%;
            background: #1e1e1e;
            border: 1px solid var(--border-color);
            padding: 15px;
            border-radius: 8px;
            color: white;
            font-size: 1.2rem;
            text-align: center;
            margin-bottom: 20px;
        }
        .modal-input:focus {
            outline: none;
            border-color: var(--primary-red);
        }
        .modal-info {
            background: rgba(0, 255, 136, 0.1);
            border: 1px solid var(--green-accent);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .modal-info b {
            color: var(--green-accent);
        }
        .qr-container {
            background: white;
            padding: 20px;
            border-radius: 12px;
            display: inline-block;
            margin: 20px 0;
        }
        .qr-container img {
            max-width: 200px;
            height: auto;
        }
        .qr-timer {
            color: var(--text-gray);
            margin-top: 10px;
            font-size: 0.9rem;
        }
        .qr-timer span {
            color: var(--primary-red);
            font-weight: bold;
        }
        .btn-modal {
            background: var(--primary-red);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 1rem;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
        }
        .btn-modal:hover {
            background: #cc0000;
        }
        .btn-modal.secondary {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-gray);
        }
        .btn-modal.secondary:hover {
            background: rgba(255,255,255,0.1);
        }
        .btn-modal.success {
            background: var(--green-accent);
            color: #0a0a0a;
        }
        .success-icon {
            font-size: 4rem;
            color: var(--green-accent);
            margin-bottom: 20px;
        }

        /* ===== Recharge Modal - Professional Design ===== */
        .recharge-field {
            margin-bottom: 22px;
            text-align: left;
        }
        .recharge-label {
            display: block;
            color: #e0e0e0;
            font-size: 0.92rem;
            font-weight: 500;
            margin-bottom: 10px;
        }
        .recharge-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 8px;
            overflow: hidden;
            transition: border-color 0.2s;
        }
        .recharge-input-wrapper:focus-within {
            border-color: #555;
        }
        .recharge-input-prefix {
            padding: 0 0 0 16px;
            color: #888;
            font-size: 1.1rem;
            font-weight: 500;
            pointer-events: none;
        }
        .recharge-amount-input {
            flex: 1;
            background: transparent;
            border: none;
            color: #fff;
            font-size: 1.1rem;
            padding: 14px 16px 14px 8px;
            outline: none;
            width: 100%;
        }
        .recharge-amount-input::placeholder {
            color: #555;
        }

        /* Payment method dropdown */
        .recharge-select-wrapper {
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 10px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 14px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .recharge-select-wrapper:hover {
            border-color: #555;
        }
        .recharge-select-wrapper.selected {
            border-color: var(--primary-red);
        }
        .recharge-select-icon {
            width: 38px;
            height: 38px;
            background: #252525;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .recharge-select-wrapper.selected .recharge-select-icon {
            background: rgba(255, 0, 0, 0.1);
            color: var(--primary-red);
        }
        .recharge-select-text {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .recharge-select-title {
            color: #ccc;
            font-size: 0.95rem;
            font-weight: 500;
        }
        .recharge-select-wrapper.selected .recharge-select-title {
            color: #fff;
        }
        .recharge-select-subtitle {
            color: #666;
            font-size: 0.78rem;
        }
        .recharge-select-arrow {
            color: #666;
            font-size: 0.8rem;
            transition: transform 0.2s;
        }
        .recharge-select-arrow.open {
            transform: rotate(180deg);
        }

        /* Dropdown menu */
        .recharge-dropdown {
            display: none;
            background: #1a1a1a;
            border: 1px solid #333;
            border-radius: 10px;
            margin-top: 6px;
            overflow: hidden;
            animation: dropdownSlide 0.2s ease;
        }
        .recharge-dropdown.open {
            display: block;
        }
        @keyframes dropdownSlide {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .recharge-dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 16px;
            cursor: pointer;
            transition: background 0.15s;
            border-bottom: 1px solid #222;
        }
        .recharge-dropdown-item:last-child {
            border-bottom: none;
        }
        .recharge-dropdown-item:hover {
            background: #222;
        }
        .recharge-dropdown-item.active {
            background: rgba(255, 0, 0, 0.08);
        }
        .recharge-dropdown-icon {
            width: 34px;
            height: 34px;
            background: #252525;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #888;
            font-size: 0.85rem;
            flex-shrink: 0;
        }
        .recharge-dropdown-item.active .recharge-dropdown-icon {
            background: rgba(255, 0, 0, 0.15);
            color: var(--primary-red);
        }
        .recharge-dropdown-info {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .recharge-dropdown-name {
            color: #ddd;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .recharge-dropdown-desc {
            color: #666;
            font-size: 0.75rem;
        }
        .recharge-dropdown-check {
            color: transparent;
            font-size: 0.85rem;
            transition: color 0.15s;
        }
        .recharge-dropdown-item.active .recharge-dropdown-check {
            color: var(--primary-red);
        }

        /* Credits preview */
        .recharge-credits-preview {
            background: rgba(0, 255, 136, 0.08);
            border: 1px solid rgba(0, 255, 136, 0.2);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--green-accent);
            font-size: 0.88rem;
            text-align: left;
        }
        .recharge-credits-preview b {
            font-weight: 700;
        }

        /* Instructions */
        .recharge-instructions {
            background: rgba(255, 0, 0, 0.06);
            border: 1px solid rgba(255, 0, 0, 0.2);
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
            font-size: 0.88rem;
            color: #aaa;
            text-align: left;
        }

        /* Terms & conditions */
        .recharge-terms {
            text-align: left;
            margin-bottom: 20px;
        }
        .recharge-checkbox-wrapper {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            cursor: pointer;
            font-size: 0.88rem;
            color: #ccc;
            margin-bottom: 12px;
        }
        .recharge-checkbox-wrapper input[type="checkbox"] {
            display: none;
        }
        .recharge-checkbox-custom {
            width: 18px;
            height: 18px;
            min-width: 18px;
            border: 2px solid #555;
            border-radius: 4px;
            margin-top: 1px;
            transition: all 0.2s;
            position: relative;
        }
        .recharge-checkbox-wrapper input:checked + .recharge-checkbox-custom {
            background: var(--primary-red);
            border-color: var(--primary-red);
        }
        .recharge-checkbox-wrapper input:checked + .recharge-checkbox-custom::after {
            content: '✓';
            position: absolute;
            top: -1px;
            left: 2px;
            font-size: 12px;
            color: white;
            font-weight: bold;
        }
        .recharge-terms-link {
            color: var(--primary-red);
            text-decoration: none;
            font-weight: 500;
        }
        .recharge-terms-link:hover {
            text-decoration: underline;
        }
        .recharge-disclaimer {
            color: #777;
            font-size: 0.78rem;
            line-height: 1.55;
            margin: 0;
        }

        /* Continue button */
        .recharge-continue-btn {
            width: 100%;
            background: var(--primary-red);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            float: right;
            width: auto;
            padding: 16px 40px;
            margin-left: auto;
        }
        .recharge-continue-btn:hover {
            background: #cc0000;
            transform: translateY(-1px);
        }
        .recharge-continue-btn:disabled {
            background: #333;
            color: #666;
            cursor: not-allowed;
            transform: none;
        }

        /* When QR step is active, remove modal padding via JS class toggle */
        #recargaStep2 {
            width: 100%;
            height: 100%;
        }
        /* ===== VeriPagos QR Payment Redesign ===== */
        .vp-payment-card {
            background: #0b0f0b;
            border-radius: 20px;
            overflow: hidden;
            max-width: 440px;
            width: 100%;
            margin: 0 auto;
            border: 1px solid rgba(50, 205, 50, 0.15);
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.5);
        }
        .vp-header {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 60%, #0b4d1c 100%);
            padding: 28px 25px;
            text-align: center;
        }
        .vp-header h2 {
            color: #ffffff;
            font-size: 1.5rem;
            font-weight: 800;
            margin: 0;
            text-shadow: 0 2px 8px rgba(0,0,0,0.3);
            letter-spacing: -0.02em;
        }
        .vp-body {
            padding: 22px 20px 10px;
        }
        .vp-info-alert {
            background: rgba(34, 197, 94, 0.08);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 25px;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 22px;
        }
        .vp-info-alert i {
            color: #22c55e;
            font-size: 1rem;
            flex-shrink: 0;
        }
        .vp-info-alert p {
            color: #c0d4c0;
            font-size: 0.82rem;
            margin: 0;
            line-height: 1.4;
        }
        .vp-step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            background: #22c55e;
            color: #0b0f0b;
            border-radius: 50%;
            font-size: 0.85rem;
            font-weight: 800;
            flex-shrink: 0;
        }
        .vp-step {
            margin-bottom: 15px;
        }
        .vp-step-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        .vp-step-header h3 {
            color: #ffffff;
            font-size: 1.05rem;
            font-weight: 700;
            margin: 0;
        }
        .vp-step-text {
            color: #8a9a8a;
            font-size: 0.82rem;
            margin: 0 0 18px 40px;
            line-height: 1.55;
        }
        .vp-qr-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 16px;
            margin: 5px 0 10px 0;
        }
        .vp-qr-box {
            background: #ffffff;
            padding: 14px;
            border-radius: 16px;
            display: inline-block;
            box-shadow: 0 6px 32px rgba(34, 197, 94, 0.15), 0 4px 24px rgba(0,0,0,0.4);
        }
        .vp-qr-box img {
            width: 240px;
            max-width: 100%;
            height: auto;
            display: block;
            border-radius: 6px;
        }
        .vp-amount-box {
            flex: 1;
            min-width: 130px;
            text-align: center;
        }
        .vp-amount-value {
            color: #22c55e;
            font-size: 2.2rem;
            font-weight: 900;
            line-height: 1.1;
        }
        .vp-amount-value span.vp-currency {
            font-size: 1.4rem;
            font-weight: 700;
        }
        .vp-amount-equiv {
            color: #6b8a6b;
            font-size: 0.8rem;
            margin-top: 6px;
        }
        .vp-footer {
            border-top: 1px solid rgba(34, 197, 94, 0.12);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }
        .vp-footer-left {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            flex: 1;
        }
        .vp-footer-left i {
            color: #6b8a6b;
            font-size: 0.85rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .vp-footer-left p {
            color: #6b8a6b;
            font-size: 0.75rem;
            margin: 0;
            line-height: 1.4;
        }
        .vp-footer-right {
            text-align: right;
            flex-shrink: 0;
        }
        .vp-footer-right .timer-label {
            color: #6b8a6b;
            font-size: 0.72rem;
            display: block;
            margin-bottom: 2px;
        }
        .vp-footer-right .timer-value {
            color: #ffffff;
            font-size: 1.8rem;
            font-weight: 800;
            letter-spacing: 1px;
        }
        .vp-actions {
            padding: 8px 20px 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .vp-btn-verify {
            background: #22c55e;
            color: #0b0f0b;
            border: none;
            padding: 15px;
            border-radius: 12px;
            font-weight: 800;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 4px 15px rgba(34, 197, 94, 0.3);
        }
        /* ===== Veripagos QR Redesign V2 (Side-by-Side Green) ===== */
        .vp-header-v2 {
            background: #a3ff00;
            padding: 20px 25px;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        .vp-header-v2 h2 {
            margin: 0;
            font-size: 1.3rem;
            color: #000;
            font-weight: 800;
            text-align: left;
        }

        /* Blue Info Alert */
        .vp-info-alert-v2 {
            background: rgba(59, 130, 246, 0.15);
            border-left: 4px solid #3b82f6;
            margin: 0 25px 25px 25px;
            padding: 15px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .vp-alert-icon {
            width: 24px;
            height: 24px;
            background: #3b82f6;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
            flex-shrink: 0;
        }
        .vp-info-alert-v2 p {
            color: #ccc; /* Lighter text on dark bg */
            font-size: 0.9rem;
            margin: 0;
            line-height: 1.4;
            text-align: left;
        }

        /* V2 Step */
        .vp-step-v2 {
            padding: 0 25px 25px 25px;
        }
        .vp-step-header-v2 {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        .vp-step-number-v2 {
            width: 28px;
            height: 28px;
            background: #a3ff00;
            color: #000;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.9rem;
        }
        .vp-step-header-v2 h3 {
            color: white;
            font-size: 1.1rem;
            margin: 0;
        }
        .vp-step-text-v2 {
            color: #aaa;
            font-size: 0.9rem;
            margin: 0 0 20px 40px;
            text-align: left;
            line-height: 1.5;
        }

        /* Side-by-Side Wrapper */
        .vp-sbs-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 30px;
            margin-top: 20px;
        }
        .vp-qr-col {
            flex: 0 0 auto;
        }
        .vp-qr-box-v2 {
            background: white;
            padding: 0;
            border-radius: 12px;
            position: relative;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            overflow: hidden;
            display: inline-block;
        }
        .vp-qr-box-v2 img {
            width: 200px;
            height: 200px;
            display: block;
        }
        .vp-center-logo {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 40px;
            height: 40px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .vp-center-logo i {
            color: #000;
            font-size: 1.2rem;
        }

        /* Amount Box (Right Side) */
        .vp-amount-col {
            flex: 0 0 auto;
        }
        .vp-amount-card {
            background: #a3ff00;
            border-radius: 12px;
            padding: 20px;
            color: #000;
            min-width: 200px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(163, 255, 0, 0.2);
        }
        .vp-amount-label {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 5px;
            opacity: 0.8;
        }
        .vp-amount-value {
            font-size: 1.8rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 5px;
        }
        .vp-amount-equiv {
            font-size: 0.85rem;
            opacity: 0.7;
            font-weight: 500;
        }

        /* Footer Alert (Yellow) */
        .vp-footer-alert-v2 {
            background: rgba(234, 179, 8, 0.15); /* Yellow-500 tint */
            border-left: 4px solid #eab308;
            margin: 0 25px 25px 25px;
            padding: 15px;
            border-radius: 4px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }
        .vp-footer-icon {
            width: 24px;
            height: 24px;
            background: #eab308; /* Yellow-500 */
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #000;
            font-size: 0.8rem;
            flex-shrink: 0;
        }
        .vp-footer-alert-v2 p {
            color: #ccc;
            font-size: 0.85rem;
            margin: 0;
            line-height: 1.4;
            text-align: left;
        }

        /* Mobile Response */
        @media (max-width: 600px) {
            .vp-sbs-wrapper {
                flex-direction: column;
                gap: 20px;
            }
            .vp-amount-card {
                width: 100%;
                min-width: unset;
            }
            .vp-header-v2 h2 {
                font-size: 1.1rem;
            }
        }

        .vp-btn-cancel {
            background: transparent;
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.3);
            padding: 13px;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .vp-btn-cancel:hover {
            background: rgba(34, 197, 94, 0.08);
            border-color: #22c55e;
        }
        .vp-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 10px;
            color: #8a9a8a;
        }
        .vp-spinner {
            width: 22px;
            height: 22px;
            border: 2px solid rgba(34, 197, 94, 0.2);
            border-top-color: #22c55e;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        .vp-status {
            text-align: center;
            padding: 8px;
            font-size: 0.85rem;
            color: #6b8a6b;
        }
        @media (max-width: 480px) {
            .vp-qr-wrapper {
                align-items: center;
            }
            .vp-qr-box img {
                width: 200px;
                height: 200px;
            }
            .vp-amount-box {
                width: 100%;
            }
            .vp-footer {
                flex-direction: column;
                align-items: flex-start;
            }
            .vp-footer-right {
                text-align: left;
            }
        }
        .credential-box {
            background: #1a1a1a;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            text-align: left;
        }
        .credential-box label {
            color: var(--text-gray);
            font-size: 0.8rem;
            display: block;
            margin-bottom: 5px;
        }
        .credential-box .value {
            color: white;
            font-family: monospace;
            font-size: 1rem;
            word-break: break-all;
        }
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid var(--border-color);
            border-top-color: var(--primary-red);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        /* Toast Notifications */
        .vp-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1a1a1a;
            color: #fff;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            gap: 15px;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 10000;
            border-left: 4px solid #3b82f6;
        }
        .vp-toast.show {
            transform: translateY(0);
            opacity: 1;
        }
        .vp-toast-content {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .vp-toast-content i {
            font-size: 1.2rem;
        }
        .vp-toast-warning { border-color: #eab308; }
        .vp-toast-warning i { color: #eab308; }
        .vp-toast-success { border-color: #22c55e; }
        .vp-toast-success i { color: #22c55e; }
        .vp-toast-error { border-color: #ef4444; }
        .vp-toast-error i { color: #ef4444; }
    </style>
</head>
<body>
    <div id="comunicadoBannerStore" style="display: none; background: #166534; color: #fff; padding: 10px 16px; text-align: center; position: relative;">
        <span id="comunicadoMensajeStore"></span>
        <button type="button" onclick="this.parentElement.style.display='none'" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; color: #fff; cursor: pointer; font-size: 1.2rem;">&times;</button>
    </div>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- SIDEBAR: solo iconos -->
    <div class="dashboard">
        <aside class="sidebar" id="mainSidebar">
            <!-- Hamburger -->
            <div class="sidebar-ham" onclick="toggleDrawer()" title="Menú">
                <i class="fas fa-bars"></i>
            </div>
            <!-- Iconos -->
            <div class="sidebar-icons">
                <button class="nav-icon-btn" data-drawer-section="afiliados" title="Afiliados"><i class="fas fa-users"></i></button>
                <button class="nav-icon-btn" title="Comunidades"><i class="fas fa-globe"></i></button>
                <button class="nav-icon-btn" data-drawer-section="classroom" title="Classroom"><i class="fas fa-chalkboard"></i></button>
                <button class="nav-icon-btn" data-drawer-section="tienda" title="Streaming"><i class="fas fa-tv"></i></button>
                <button class="nav-icon-btn" title="Finanzas"><i class="fas fa-wallet"></i></button>
            </div>
        </aside>

        <!-- DRAWER: menú completo con textos -->
        <div class="store-drawer" id="storeDrawer">
            <div class="drawer-logo">
                <img src="https://gwoter.com/logo.png" alt="Logo">
            </div>
            <nav class="drawer-nav">
                <div class="drawer-item" data-section="afiliados"><i class="fas fa-users"></i> Afiliados</div>
                <div class="drawer-item"><i class="fas fa-globe"></i> Comunidades</div>
                <div class="drawer-item" data-section="classroom"><i class="fas fa-chalkboard"></i> Classroom</div>

                <div class="drawer-item has-submenu" data-section="tienda">
                    <i class="fas fa-tv"></i> Streaming
                </div>
                <ul class="drawer-submenu">
                    <li class="active" data-section="tienda">Tienda</li>
                    <li data-section="combos">Armar Combo <span class="tag-manual" style="font-size:0.6rem;vertical-align:middle;margin-left:4px">NEW</span></li>
                    <li data-section="proveedor">Zona proveedor</li>
                    <li data-section="miscompras">Mis compras</li>
                </ul>

                <div class="drawer-item"><i class="fas fa-wallet"></i> Finanzas</div>
            </nav>
        </div>

        <main class="main-content" id="mainContent">
            <header class="header-top">
                <div class="header-right">
                    <div class="balance-box">
                        <span style="color:var(--green-accent)">💰</span> 
                        <span id="userCreditos"><?= number_format($creditosUsuario, 2) ?></span> Bs
                    </div>
                    <button class="btn-add" onclick="abrirModalRecarga()">+ AGREGAR SALDO</button>
                    <div class="user-pill">
                        <i class="fas fa-user-circle fa-lg"></i>
                        <span><?= htmlspecialchars($userName) ?></span>
                    </div>
                    <button class="btn-logout" onclick="logout()">
                        <i class="fas fa-sign-out-alt"></i><span class="btn-text"> Salir</span>
                    </button>
                </div>
            </header>

            <!-- SECCIÓN TIENDA -->
            <section id="seccion-tienda" class="section-content active">
                <h1>Mercado de Cuentas Streaming</h1>
                <p class="subtitle">Encuentra las mejores ofertas de streaming ordenadas por precio</p>

                <div class="alert-safe">
                    <i class="fas fa-check-circle" style="color:var(--green-accent)"></i>
                    <span>Para compras Seguras y Garantizadas: <b>NO compres por WhatsApp.</b></span>
                </div>

                <div class="search-container">
                    <input type="text" class="search-bar" id="searchInput" placeholder="Buscar por nombre o categoría...">
                </div>

                <div class="category-scroll">
                    <div class="cat-icon active" data-filter="all"><i class="fas fa-bars"></i></div>
                    <?php
                    // Solo plataformas que tienen al menos una cuenta disponible
                    $plataformasConStock = [];
                    foreach ($cuentasDisponibles as $c) {
                        $plataformasConStock[$c['plataforma_id']] = [
                            'id'         => $c['plataforma_id'],
                            'nombre'     => $c['plataforma_nombre'],
                            'imagen_url' => $c['plataforma_imagen'],
                        ];
                    }
                    foreach ($plataformasConStock as $p): ?>
                    <div class="cat-icon" data-filter="<?= $p['id'] ?>" title="<?= htmlspecialchars($p['nombre']) ?>">
                        <img src="<?= htmlspecialchars($p['imagen_url']) ?>" alt="<?= htmlspecialchars($p['nombre']) ?>" onerror="this.style.display='none'; this.parentElement.innerHTML='<span style=\'color:white;font-weight:bold;font-size:1rem\'><?= substr($p['nombre'], 0, 2) ?></span>';">
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- TABS: Perfiles / Cuentas Completas -->
                <?php
                $totalPerfilesBadge = 0;
                foreach ($cuentasPerfiles as $c) {
                    $totalPerfilesBadge += (int)($c['perfiles_disponibles'] ?? 0);
                }
                ?>
                <div class="tienda-tabs">
                    <button class="tienda-tab active" id="tab-perfiles" onclick="switchTiendaTab('perfiles')">
                        <i class="fas fa-user"></i> Cuentas Perfiles
                        <span class="tab-badge tab-badge-blue"><?= $totalPerfilesBadge ?></span>
                    </button>
                    <button class="tienda-tab" id="tab-completas" onclick="switchTiendaTab('completas')">
                        <i class="fas fa-user-shield"></i> Cuentas Completas
                        <span class="tab-badge"><?= count($cuentasCompletas) ?></span>
                    </button>
                </div>

                <!-- SUB-SECCIÓN: CUENTAS COMPLETAS -->
                <div id="panel-completas" class="tienda-panel">
                    <div class="tienda-panel-header">
                        <i class="fas fa-user-shield" style="color:var(--green-accent)"></i>
                        <div>
                            <h3>Cuentas Completas</h3>
                            <p>Acceso total a la cuenta — email y contraseña propios</p>
                        </div>
                    </div>

                    <?php if (count($cuentasCompletas) > 0): ?>
                    <table class="products-table">
                        <thead>
                            <tr>
                                <th>Vendedor</th>
                                <th>Servicio</th>
                                <th>Detalles</th>
                                <th>Precio</th>
                                <th>Stock</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="productsBody">
                            <?php
                            // Agrupar por plataforma: precio mínimo, su ID y precio_pro, conteo de stock
                            $grupoCompletas = [];
                            foreach ($cuentasCompletas as $c) {
                                $pid = $c['plataforma_id'];
                                if (!isset($grupoCompletas[$pid])) {
                                    $grupoCompletas[$pid] = [
                                        'info'      => $c,
                                        'stock'     => 0,
                                        'min_precio'=> $c['precio'],
                                        'min_id'    => $c['id'],
                                        'min_precio_pro' => $c['precio_pro'] ?? null,
                                    ];
                                }
                                $grupoCompletas[$pid]['stock']++;
                                // Si este tiene precio menor, usarlo para el botón
                                if ($c['precio'] < $grupoCompletas[$pid]['min_precio']) {
                                    $grupoCompletas[$pid]['min_precio']     = $c['precio'];
                                    $grupoCompletas[$pid]['min_id']         = $c['id'];
                                    $grupoCompletas[$pid]['min_precio_pro'] = $c['precio_pro'] ?? null;
                                }
                            }
                            foreach ($grupoCompletas as $pid => $g):
                                $cuenta    = $g['info'];
                                $stock     = $g['stock'];
                                $precio    = $g['min_precio'];
                                $cid       = $g['min_id'];
                                $precio_pro = $g['min_precio_pro'];
                            ?>
                            <tr class="product-row" data-plataforma="<?= $pid ?>" data-nombre="<?= strtolower($cuenta['plataforma_nombre']) ?>" data-tipo="completa" data-stock="<?= $stock ?>">
                                <!-- VENDEDOR -->
                                <td class="seller-cell">
                                    <div class="seller-avatar-row">
                                        <div class="seller-avatar">S</div>
                                        <div>
                                            <div class="seller-name">StreamStore <i class="fas fa-check-circle verified"></i></div>
                                            <div class="seller-stats">
                                                <span><?= $stock ?> cuentas</span>
                                                <span class="dot">•</span>
                                                <span>verificado</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <!-- SERVICIO -->
                                <td>
                                    <div class="service-title">
                                        ID: <?= $cid ?>
                                        <?php if (!empty($cuenta['nombre_servicio'])): ?>
                                        — <?= htmlspecialchars($cuenta['nombre_servicio']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="service-subtitle"><?= htmlspecialchars($cuenta['plataforma_nombre']) ?></div>
                                    <div class="service-badges">
                                        <?php if (($cuenta['tipo_entrega'] ?? 'automatico') === 'manual'): ?>
                                        <span class="badge-manual"><i class="fas fa-hand-paper"></i> Entrega Manual</span>
                                        <?php else: ?>
                                        <span class="badge-automatico"><i class="fas fa-bolt"></i> Automático</span>
                                        <?php endif; ?>
                                        <?php if (empty($cuenta['renovable']) || $cuenta['renovable'] == 0): ?>
                                        <span class="badge-gray"><i class="fas fa-times"></i> No Renovable</span>
                                        <?php else: ?>
                                        <span class="badge-gray"><i class="fas fa-sync-alt"></i> Renovable</span>
                                        <?php endif; ?>
                                        <span class="badge-gray"><i class="fas fa-clock"></i> <?= (int)($cuenta['dias'] ?? 30) ?> días</span>
                                        <span class="tag tag-cuenta-completa">Cuenta Completa</span>
                                    </div>
                                </td>
                                <!-- DETALLES -->
                                <td>
                                    <div class="detalles-btns">
                                        <?php if (!empty($cuenta['descripcion'])): ?>
                                        <button class="btn-info-detail" data-titulo="Descripción" data-texto="<?= htmlspecialchars($cuenta['descripcion'], ENT_QUOTES) ?>"><i class="fas fa-info-circle"></i> Descripción</button>
                                        <?php endif; ?>
                                        <?php if (!empty($cuenta['terminos'])): ?>
                                        <button class="btn-info-detail" data-titulo="Términos" data-texto="<?= htmlspecialchars($cuenta['terminos'], ENT_QUOTES) ?>"><i class="fas fa-clipboard-list"></i> Términos</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <!-- PRECIO -->
                                <td>
                                    <?php if (!empty($precio_pro)): ?>
                                    <div class="price-row-label">Precio Normal:</div>
                                    <div class="price-row-value">Bs <?= number_format($precio, 2) ?></div>
                                    <div class="price-pro-row-label">Precio Pro:</div>
                                    <div class="price-pro-row-value">Bs <?= number_format($precio_pro, 2) ?></div>
                                    <?php else: ?>
                                    <div class="price-row-label">Precio:</div>
                                    <div class="price-row-value">Bs <?= number_format($precio, 2) ?></div>
                                    <?php endif; ?>
                                </td>
                                <!-- STOCK -->
                                <td>
                                    <span class="stock-cell-num"><?= $stock ?></span>
                                    <span class="stock-cell-label">stock</span>
                                </td>
                                <!-- ACCIÓN -->
                                <td>
                                    <?php
                                    $descPct = (float)($membresiaActiva['plan_descuento'] ?? 0);
                                    $precioConDesc = $descPct > 0 ? round($precio * (1 - $descPct / 100), 2) : null;
                                    ?>
                                    <button class="btn-buy-red" onclick="comprar(<?= $cid ?>, <?= $precio ?>)">Comprar por Bs <?= number_format($precioConDesc ?? $precio, 2) ?></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h2>No hay cuentas completas disponibles</h2>
                        <p>Vuelve pronto, estamos reabasteciendo el inventario.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- SUB-SECCIÓN: CUENTAS PERFILES -->
                <div id="panel-perfiles" class="tienda-panel active">
                    <div class="tienda-panel-header tienda-panel-header-blue">
                        <i class="fas fa-user" style="color:#4aa3ff"></i>
                        <div>
                            <h3>Cuentas Perfiles</h3>
                            <p>Perfil individual dentro de una cuenta compartida — más económico</p>
                        </div>
                    </div>

                    <?php if (count($cuentasPerfiles) > 0): ?>
                    <table class="products-table">
                        <thead>
                            <tr>
                                <th>Vendedor</th>
                                <th>Servicio</th>
                                <th>Detalles</th>
                                <th>Precio</th>
                                <th>Stock</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="productsBodyPerfiles">
                            <?php
                            // Agrupar por plataforma: precio mínimo, suma total de perfiles_disponibles
                            $grupoPerfiles = [];
                            foreach ($cuentasPerfiles as $c) {
                                $pid = $c['plataforma_id'];
                                if (!isset($grupoPerfiles[$pid])) {
                                    $grupoPerfiles[$pid] = [
                                        'info'            => $c,
                                        'total_perfiles'  => 0,
                                        'min_precio'      => $c['precio'],
                                        'min_id'          => $c['id'],
                                        'min_precio_pro'  => $c['precio_pro'] ?? null,
                                    ];
                                }
                                // Sumar los perfiles de cada cuenta
                                $grupoPerfiles[$pid]['total_perfiles'] += (int)($c['perfiles_disponibles'] ?? 0);
                                if ($c['precio'] < $grupoPerfiles[$pid]['min_precio']) {
                                    $grupoPerfiles[$pid]['min_precio']    = $c['precio'];
                                    $grupoPerfiles[$pid]['min_id']        = $c['id'];
                                    $grupoPerfiles[$pid]['min_precio_pro']= $c['precio_pro'] ?? null;
                                }
                            }
                            foreach ($grupoPerfiles as $pid => $g):
                                $cuenta         = $g['info'];
                                $totalPerfiles  = $g['total_perfiles'];
                                $precio         = $g['min_precio'];
                                $cid            = $g['min_id'];
                                $precio_pro     = $g['min_precio_pro'];
                            ?>
                            <tr class="product-row" data-plataforma="<?= $pid ?>" data-nombre="<?= strtolower($cuenta['plataforma_nombre']) ?>" data-tipo="perfil" data-perfiles="<?= $totalPerfiles ?>">
                                <!-- VENDEDOR -->
                                <td class="seller-cell">
                                    <div class="seller-avatar-row">
                                        <div class="seller-avatar">S</div>
                                        <div>
                                            <div class="seller-name">StreamStore <i class="fas fa-check-circle verified"></i></div>
                                            <div class="seller-stats">
                                                <span><?= $totalPerfiles ?> perfiles</span>
                                                <span class="dot">•</span>
                                                <span>verificado</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <!-- SERVICIO -->
                                <td>
                                    <div class="service-title">
                                        ID: <?= $cid ?>
                                        <?php if (!empty($cuenta['nombre_servicio'])): ?>
                                        — <?= htmlspecialchars($cuenta['nombre_servicio']) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="service-subtitle"><?= htmlspecialchars($cuenta['plataforma_nombre']) ?></div>
                                    <div class="service-badges">
                                        <?php if (($cuenta['tipo_entrega'] ?? 'automatico') === 'manual'): ?>
                                        <span class="badge-manual"><i class="fas fa-hand-paper"></i> Entrega Manual</span>
                                        <?php else: ?>
                                        <span class="badge-automatico"><i class="fas fa-bolt"></i> Automático</span>
                                        <?php endif; ?>
                                        <?php if (empty($cuenta['renovable']) || $cuenta['renovable'] == 0): ?>
                                        <span class="badge-gray"><i class="fas fa-times"></i> No Renovable</span>
                                        <?php else: ?>
                                        <span class="badge-gray"><i class="fas fa-sync-alt"></i> Renovable</span>
                                        <?php endif; ?>
                                        <span class="badge-gray"><i class="fas fa-clock"></i> <?= (int)($cuenta['dias'] ?? 30) ?> días</span>
                                        <span class="tag tag-cuenta-perfil">Perfil</span>
                                    </div>
                                </td>
                                <!-- DETALLES -->
                                <td>
                                    <div class="detalles-btns">
                                        <?php if (!empty($cuenta['descripcion'])): ?>
                                        <button class="btn-info-detail" data-titulo="Descripción" data-texto="<?= htmlspecialchars($cuenta['descripcion'], ENT_QUOTES) ?>"><i class="fas fa-info-circle"></i> Descripción</button>
                                        <?php endif; ?>
                                        <?php if (!empty($cuenta['terminos'])): ?>
                                        <button class="btn-info-detail" data-titulo="Términos" data-texto="<?= htmlspecialchars($cuenta['terminos'], ENT_QUOTES) ?>"><i class="fas fa-clipboard-list"></i> Términos</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <!-- PRECIO -->
                                <td>
                                    <?php if (!empty($precio_pro)): ?>
                                    <div class="price-row-label">Precio Normal:</div>
                                    <div class="price-row-value">Bs <?= number_format($precio, 2) ?></div>
                                    <div class="price-pro-row-label">Precio Pro:</div>
                                    <div class="price-pro-row-value">Bs <?= number_format($precio_pro, 2) ?></div>
                                    <?php else: ?>
                                    <div class="price-row-label">Precio:</div>
                                    <div class="price-row-value">Bs <?= number_format($precio, 2) ?></div>
                                    <?php endif; ?>
                                </td>
                                <!-- STOCK -->
                                <td>
                                    <span class="stock-cell-num"><?= $totalPerfiles ?></span>
                                    <span class="stock-cell-label">stock</span>
                                </td>
                                <!-- ACCIÓN -->
                                <td>
                                    <?php
                                    $descPct2 = (float)($membresiaActiva['plan_descuento'] ?? 0);
                                    $precioConDesc2 = $descPct2 > 0 ? round($precio * (1 - $descPct2 / 100), 2) : null;
                                    ?>
                                    <button class="btn-buy-blue" onclick="comprar(<?= $cid ?>, <?= $precio ?>)">Comprar por Bs <?= number_format($precioConDesc2 ?? $precio, 2) ?></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-box-open"></i>
                        <h2>No hay perfiles disponibles</h2>
                        <p>Vuelve pronto, estamos reabasteciendo el inventario.</p>
                    </div>
                    <?php endif; ?>
                </div>

            </section>

            <!-- SECCIÓN COMBOS (BUILDER) -->
            <section id="seccion-combos" class="section-content">
                <h1><i class="fas fa-cubes" style="color: var(--primary-red);"></i> Armar Combo Personalizado</h1>
                <p class="subtitle">Selecciona múltiples cuentas y compra tu pack ideal en un solo click.</p>
                
                <div class="alert-safe">
                    <i class="fas fa-info-circle" style="color:#4aa3ff"></i>
                    <span>Toca las tarjetas para agregar o quitar productos de tu combo.</span>
                </div>

                <div class="combo-grid">
                    <?php 
                    // Agrupar productos: mostrar uno por plataforma (el primero disponible)
                    $uniquePlatforms = [];
                    foreach ($cuentasDisponibles as $c) {
                        if (!isset($uniquePlatforms[$c['plataforma_id']])) {
                            $uniquePlatforms[$c['plataforma_id']] = $c;
                        }
                    }
                    foreach ($uniquePlatforms as $c): 
                    ?>
                    <div class="combo-card" id="card-<?= $c['plataforma_id'] ?>" 
                         onclick="toggleComboItem(<?= $c['plataforma_id'] ?>, <?= $c['id'] ?>, '<?= htmlspecialchars($c['plataforma_nombre']) ?>', <?= $c['precio'] ?>, '<?= $c['plataforma_imagen'] ?>')">
                        <div class="check-circle" id="check-<?= $c['plataforma_id'] ?>">
                            <i class="fas fa-check"></i>
                        </div>
                        <img src="<?= $c['plataforma_imagen'] ?>" alt="<?= htmlspecialchars($c['plataforma_nombre']) ?>">
                        <h4><?= htmlspecialchars($c['plataforma_nombre']) ?></h4>
                        <div class="price">$<?= number_format($c['precio'], 2) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div id="comboPanel" class="combo-panel">
                    <div class="combo-panel-header">
                        <h3><i class="fas fa-shopping-basket"></i> Tu Combo</h3>
                        <span class="tag tag-green" id="comboCount">0 items</span>
                    </div>
                    <div class="combo-panel-items" id="comboItemsList"></div>
                    <div class="text-right text-sm text-gray-400 mb-1">Total a pagar:</div>
                    <div class="combo-total">$<span id="comboTotal">0.00</span></div>
                    <button class="btn-combo-buy" onclick="comprarCombo()">
                        CONFIRMAR COMPRA
                    </button>
                    <div id="comboStatus" style="display:none; margin-top:10px; font-size:0.8rem; color:#aaa; text-align:center"></div>
                </div>
            </section>

            <!-- SECCIÓN AFILIADOS -->
            <section id="seccion-afiliados" class="section-content">
                <div class="affiliate-banner">
                    <div>
                        <span style="background: var(--primary-red); padding: 5px 15px; border-radius: 20px; font-size: 0.8rem;">PROGRAMA DE AFILIADOS</span>
                        <h2>¡Gana comisiones por tus referidos!</h2>
                        <p>Recibe un <b style="color: var(--green-accent);">30% de cada movimiento</b> y un <b style="color: var(--green-accent);">5% por cada compra</b> que realicen.</p>
                        <p style="margin-top: 10px;">Invita a tus amigos y comienza a ganar dinero.</p>
                    </div>
                    <div class="crown">👑</div>
                </div>

                <div class="stats-row">
                    <div class="stat-box">
                        <div class="icon">👥</div>
                        <div class="label">Referidos Directos</div>
                        <div class="value">0</div>
                    </div>
                    <div class="stat-box">
                        <div class="icon">💰</div>
                        <div class="label">Comisiones Generadas</div>
                        <div class="value">$0.00</div>
                        <button class="btn-add" style="margin-top: 10px; width: 100%;">RETIRAR</button>
                    </div>
                </div>

                <div class="referral-section">
                    <h3><i class="fas fa-link"></i> Información de referido</h3>
                    <div class="referral-row">
                        <div class="referral-field">
                            <label>Tu código de referido</label>
                            <div class="referral-input-group">
                                <input type="text" value="<?= strtoupper(substr(md5($_SESSION['user_id']), 0, 8)) ?>" readonly id="refCode">
                                <button onclick="copyCode()"><i class="fas fa-copy"></i></button>
                            </div>
                        </div>
                        <div class="referral-field">
                            <label>Tu enlace de referido</label>
                            <div class="referral-input-group">
                                <input type="text" value="<?= 'https://' . ($_SERVER['HTTP_HOST'] ?? 'tudominio.com') . '/index.html?ref=' . strtoupper(substr(md5($_SESSION['user_id']), 0, 8)) ?>" readonly id="refLink">
                                <button onclick="copyLink()"><i class="fas fa-copy"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="plans-section">
                    <h3><i class="fas fa-crown"></i> Planes de Membresía Activos</h3>

                    <?php if ($membresiaActiva): ?>
                    <div style="background:linear-gradient(135deg,#1a2e1a,#0f2010); border:1px solid #22c55e55; border-radius:10px; padding:14px 18px; margin-bottom:16px; display:flex; align-items:center; gap:12px;">
                        <i class="fas fa-check-circle" style="color:#22c55e; font-size:1.3rem; flex-shrink:0;"></i>
                        <div>
                            <div style="font-weight:700; color:#22c55e; font-size:0.95rem;">Plan activo: <?= htmlspecialchars($membresiaActiva['plan_nombre']) ?></div>
                            <div style="font-size:0.78rem; color:#86efac;">Activado el <?= date('d/m/Y', strtotime($membresiaActiva['fecha_inicio'])) ?></div>
                        </div>
                    </div>

                    <!-- Widget puntos de recompensa -->
                    <div style="background:linear-gradient(135deg,#1a1a2e,#16213e); border:1px solid #f59e0b55; border-radius:12px; padding:18px 20px; margin-bottom:20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
                        <div style="display:flex; align-items:center; gap:14px;">
                            <div style="width:44px; height:44px; background:linear-gradient(135deg,#f59e0b,#d97706); border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                <i class="fas fa-coins" style="color:#fff; font-size:1.1rem;"></i>
                            </div>
                            <div>
                                <div style="font-size:0.75rem; color:#fcd34d; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:2px;">Tus Puntos de Recompensa</div>
                                <div style="font-size:1.6rem; font-weight:800; color:#fbbf24; line-height:1;" id="puntosDisplay"><?= number_format($puntosUsuario) ?></div>
                                <div style="font-size:0.72rem; color:#78716c; margin-top:2px;">20 pts = 1 Bs de descuento · <?= (int)$membresiaActiva['plan_points'] ?> pts/día con tu plan</div>
                            </div>
                        </div>
                        <div>
                            <?php if ($yaReclamoHoy): ?>
                            <button class="btn-activate" disabled style="background:#1c1c1c; border:1px solid #444; cursor:default; opacity:0.7; min-width:160px;">
                                <i class="fas fa-check"></i> Reclamado hoy
                            </button>
                            <?php else: ?>
                            <button onclick="reclamarPuntos(this)" style="background:linear-gradient(135deg,#f59e0b,#d97706); color:#000; border:none; border-radius:8px; padding:10px 22px; font-weight:700; cursor:pointer; font-size:0.9rem; display:flex; align-items:center; gap:8px; min-width:160px; justify-content:center;">
                                <i class="fas fa-gift"></i> Reclamar +<?= (int)$membresiaActiva['plan_points'] ?> pts
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="plans-grid">
                        <?php if (count($planesActivos) > 0): ?>
                        <?php foreach ($planesActivos as $plan):
                            $esPlanActivo = $membresiaActiva && (int)$membresiaActiva['plan_id'] === (int)$plan['id'];
                            $precioLabel  = (float)$plan['price'] > 0 ? number_format($plan['price'], 2).' Bs' : 'GRATIS';
                        ?>
                        <div class="plan-card <?= $plan['is_featured'] ? 'featured' : '' ?>" style="<?= $esPlanActivo ? 'border-color:#22c55e55;' : '' ?>">

                            <!-- Cabecera: nombre + precio -->
                            <div class="plan-header">
                                <h4><?= htmlspecialchars($plan['name']) ?></h4>
                                <div class="price">
                                    <div class="amount"><?= $precioLabel ?></div>
                                    <div class="period">Bs/mes</div>
                                </div>
                            </div>

                            <!-- Badge días restantes (solo si es el plan activo) -->
                            <?php if ($esPlanActivo): ?>
                            <div class="plan-vence-badge">
                                <i class="fas fa-clock"></i>
                                <?php if ($diasRestantes > 1): ?>
                                    Vence en <?= $diasRestantes ?> días
                                <?php elseif ($diasRestantes === 1): ?>
                                    Vence mañana
                                <?php else: ?>
                                    Vence hoy
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Beneficios -->
                            <div class="plan-benefits-title">Beneficios:</div>
                            <ul class="plan-benefits">
                                <?php
                                $desc = $plan['description'] ?? '';
                                $beneficios = preg_split('/\||\n/', $desc);
                                foreach ($beneficios as $beneficio):
                                    $b = trim($beneficio);
                                    if ($b):
                                ?>
                                <li><i class="fas fa-check"></i> <?= htmlspecialchars($b) ?></li>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </ul>

                            <!-- Botón -->
                            <?php if ($esPlanActivo): ?>
                            <form id="form-plan-<?= (int)$plan['id'] ?>" action="process.php" method="POST">
                                <input type="hidden" name="action" value="comprar_membresia">
                                <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                                <button type="button" class="btn-renovar" onclick="confirmarPlan(<?= (int)$plan['id'] ?>, '<?= htmlspecialchars(addslashes($plan['name'])) ?>', '<?= $precioLabel ?>', true)">
                                    <i class="fas fa-sync-alt"></i> Renovar Plan
                                </button>
                            </form>
                            <?php else: ?>
                            <form id="form-plan-<?= (int)$plan['id'] ?>" action="process.php" method="POST">
                                <input type="hidden" name="action" value="comprar_membresia">
                                <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                                <button type="button" class="btn-activate" onclick="confirmarPlan(<?= (int)$plan['id'] ?>, '<?= htmlspecialchars(addslashes($plan['name'])) ?>', '<?= $precioLabel ?>', false)">
                                    <i class="fas fa-star"></i> Activar Plan
                                </button>
                            </form>
                            <?php endif; ?>

                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #64748b;">
                            <i class="fas fa-info-circle" style="font-size: 2rem; margin-bottom: 10px;"></i><br>
                            No hay planes disponibles en este momento.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="bottom-stats">
                    <div class="bottom-stat">
                        <div class="icon"><i class="fas fa-dollar-sign"></i></div>
                        <div class="label">Comisiones Devueltas</div>
                        <div class="value">Habilitado</div>
                    </div>
                    <div class="bottom-stat">
                        <div class="icon"><i class="fas fa-users"></i></div>
                        <div class="label">Referidos Directos</div>
                        <div class="value">Activo</div>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN CLASSROOM (Cursos) -->
            <section id="seccion-classroom" class="section-content">
                <h1><i class="fas fa-chalkboard" style="color: var(--primary-red);"></i> Classroom</h1>
                <p class="subtitle">Cursos disponibles. Los que añadas en Admin se muestran aquí al publicarlos.</p>
                <div class="classroom-grid">
                    <?php if (count($cursosStore) === 0): ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 60px 20px; color: var(--text-gray);">
                        <i class="fas fa-book-open" style="font-size: 3rem; margin-bottom: 16px; display: block; opacity: 0.5;"></i>
                        <strong style="display: block; margin-bottom: 8px; color: var(--text-main);">No hay cursos publicados</strong>
                        <p style="margin: 0;">Crea y publica cursos desde el panel de administración.</p>
                    </div>
                    <?php else: ?>
                    <?php foreach ($cursosStore as $cur): ?>
                    <div class="curso-card">
                        <?php if (!empty($cur['imagen_url'])): ?>
                        <img class="curso-card-img" src="<?= htmlspecialchars($cur['imagen_url']) ?>" alt="<?= htmlspecialchars($cur['titulo'] ?? '') ?>">
                        <?php else: ?>
                        <div class="curso-card-img" style="display: flex; align-items: center; justify-content: center; background: #1a1a1a;"><i class="fas fa-graduation-cap" style="font-size: 3rem; color: var(--text-gray);"></i></div>
                        <?php endif; ?>
                        <div class="curso-card-body">
                            <h3 class="curso-card-title"><?= htmlspecialchars($cur['titulo'] ?? 'Sin título') ?></h3>
                            <p class="curso-card-desc"><?= htmlspecialchars($cur['descripcion'] ?? '') ?></p>
                            <div class="curso-card-footer">
                                <span class="curso-card-price">$<?= number_format($cur['precio'] ?? 0, 2) ?></span>
                                <button type="button" class="btn-buy-red" style="padding: 8px 16px; font-size: 0.85rem;">Ver curso</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- SECCIÓN MIS COMPRAS -->
            <section id="seccion-miscompras" class="section-content">
                <h1><i class="fas fa-shopping-bag" style="color: var(--primary-red);"></i> Mis Compras de Streaming</h1>
                <p class="subtitle">Gestiona tus suscripciones y perfiles activos</p>

                <!-- Advertencia de seguridad -->
                <div class="alert-safe" style="margin-bottom:22px;">
                    <div style="font-weight:700; color:#fbbf24; margin-bottom:4px;"><i class="fas fa-exclamation-triangle"></i> ADVERTENCIA DE SEGURIDAD</div>
                    <div style="font-size:0.85rem;"><strong>NO hagas compras, tratos o envíes dinero</strong> a ningún número de WhatsApp que aparezca en esta página (términos, descripción, soporte, etc.). <strong>Todos los números son únicamente para soporte técnico.</strong> Compras seguras únicamente en nuestra tienda oficial.</div>
                </div>

                <?php if (count($misCompras) > 0): ?>
                <div class="mc-table-wrap">
                <table class="mc-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>PROVEEDOR</th>
                            <th>CLIENTE</th>
                            <th>PERFIL</th>
                            <th>ESTADO</th>
                            <th>FECHAS</th>
                            <th>PRECIO</th>
                            <th>ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($misCompras as $i => $compra):
                            $fechaInicio = new DateTime($compra['created_at']);
                            $dias        = (int)($compra['dias'] ?? 30);
                            $fechaFin    = (clone $fechaInicio)->modify("+{$dias} days");
                            $hoy         = new DateTime();
                            $diasRest    = (int)$hoy->diff($fechaFin)->days;
                            $expirado    = $hoy > $fechaFin;
                            $diasRest    = $expirado ? 0 : $diasRest;
                            $pins        = array_filter(array_map('trim', explode(',', $compra['pins'] ?? '')));
                            $primerPin   = $pins ? reset($pins) : null;
                            $numPerfil   = count($misCompras) - $i;
                            $wa          = $compra['whatsapp_soporte'] ?? '';
                        ?>
                        <tr class="mc-row">
                            <!-- ID -->
                            <td class="mc-id"><?= $compra['compra_id'] ?></td>

                            <!-- PROVEEDOR -->
                            <td class="mc-proveedor">
                                <div class="mc-prov-id"><?= $compra['cuenta_id'] ?> — <?= htmlspecialchars($compra['plataforma_nombre'] ?? 'N/A') ?></div>
                                <div class="mc-prov-user"><i class="fas fa-user"></i> <?= htmlspecialchars($compra['cliente_nombre']) ?> <span class="mc-dot-green"></span></div>
                            </td>

                            <!-- CLIENTE -->
                            <td class="mc-cliente">
                                <div class="mc-cli-nombre"><?= htmlspecialchars($compra['cliente_nombre']) ?></div>
                                <div class="mc-cli-email"><?= htmlspecialchars($compra['cliente_email']) ?></div>
                                <?php if ($wa): ?>
                                <div class="mc-cli-wa"><i class="fab fa-whatsapp"></i> +<?= htmlspecialchars($wa) ?></div>
                                <?php endif; ?>
                                <div class="mc-cli-btns">
                                    <?php if ($wa): ?>
                                    <a href="https://wa.me/<?= $wa ?>" target="_blank" class="mc-btn-wa" title="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                                    <?php endif; ?>
                                    <button class="mc-btn-copy" title="Copiar credenciales" onclick="copiarCredencialesCompra('<?= htmlspecialchars($compra['plataforma_nombre']) ?>','<?= htmlspecialchars($compra['correo']) ?>','<?= htmlspecialchars($compra['password']) ?>')"><i class="fas fa-copy"></i></button>
                                </div>
                            </td>

                            <!-- PERFIL -->
                            <td class="mc-perfil">
                                <div class="mc-cred-box">
                                    <div><span class="mc-cred-label">Perfil:</span> <span class="mc-cred-val"><?= $numPerfil ?></span></div>
                                    <div><span class="mc-cred-label">Email:</span> <span class="mc-cred-highlight"><?= htmlspecialchars($compra['correo']) ?></span></div>
                                    <?php if (!empty($compra['usuario_cuenta'])): ?>
                                    <div><span class="mc-cred-label">User:</span> <span class="mc-cred-yellow"><?= htmlspecialchars($compra['usuario_cuenta']) ?></span></div>
                                    <?php endif; ?>
                                    <div><span class="mc-cred-label">Pass:</span> <span class="mc-cred-highlight"><?= htmlspecialchars($compra['password']) ?></span></div>
                                    <?php if ($primerPin): ?>
                                    <div><span class="mc-cred-label">PIN:</span> <span class="mc-cred-val"><?= htmlspecialchars($primerPin) ?></span></div>
                                    <?php endif; ?>
                                </div>
                                <div class="mc-cred-actions">
                                    <?php if (!empty($compra['terminos'])): ?>
                                    <button class="mc-btn-terms" onclick="gModal('Terms','<?= htmlspecialchars(addslashes($compra['terminos'])) ?>')"><i class="fas fa-clipboard-list"></i> Terms</button>
                                    <?php endif; ?>
                                    <?php if (!empty($compra['descripcion'])): ?>
                                    <button class="mc-btn-desc" onclick="gModal('Description','<?= htmlspecialchars(addslashes($compra['descripcion'])) ?>')"><i class="fas fa-info-circle"></i> Description</button>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- ESTADO -->
                            <td class="mc-estado">
                                <?php if ($expirado): ?>
                                <span class="mc-badge-expired">Expirado</span>
                                <div class="mc-dias-rest" style="color:#f87171;">0 días restantes</div>
                                <?php else: ?>
                                <span class="mc-badge-active">Active</span>
                                <div class="mc-dias-rest"><?= $diasRest ?> días restantes</div>
                                <?php endif; ?>
                            </td>

                            <!-- FECHAS -->
                            <td class="mc-fechas">
                                <div>Inicia: <b><?= $fechaInicio->format('d/m/Y') ?></b></div>
                                <div>Fin: <b><?= $fechaFin->format('d/m/Y') ?></b></div>
                            </td>

                            <!-- PRECIO -->
                            <td class="mc-precio">Bs <?= number_format($compra['creditos_usados'], 2) ?></td>

                            <!-- ACCIONES -->
                            <td class="mc-acciones">
                                <?php if (!empty($compra['renovable'])): ?>
                                <button class="mc-btn-renovar"><i class="fas fa-sync-alt"></i> Renovar</button>
                                <?php endif; ?>
                                <?php if ($wa): ?>
                                <a href="https://wa.me/<?= $wa ?>?text=<?= urlencode('Hola, necesito soporte para mi compra #' . $compra['compra_id'] . ' - ' . ($compra['plataforma_nombre'] ?? '')) ?>" target="_blank" class="mc-btn-soporte"><i class="fas fa-headset"></i> Soporte</a>
                                <?php else: ?>
                                <button class="mc-btn-soporte" onclick="document.querySelector('[data-section=soporte]')?.click()"><i class="fas fa-headset"></i> Soporte</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-shopping-cart"></i>
                    <h2>No tienes compras aún</h2>
                    <p>Cuando compres una cuenta, aparecerá aquí con todos los detalles.</p>
                    <button class="btn-add" onclick="document.querySelector('[data-section=tienda]').click()" style="margin-top: 20px;">
                        <i class="fas fa-store"></i> Ir a la Tienda
                    </button>
                </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

    <a href="#" id="waFloatLink" class="wa-float" target="_blank" rel="noopener" style="display: none;">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Modal de Recarga -->
    <div class="modal-overlay" id="modalRecarga">
        <div class="modal-content" style="max-width: 560px;">
            <!-- Step 0: Formulario unificado de recarga -->
            <div id="recargaStep0">
                <!-- Monto -->
                <div class="recharge-field">
                    <label class="recharge-label">Monto a agregar (USD)</label>
                    <div class="recharge-input-wrapper">
                        <span class="recharge-input-prefix">$</span>
                        <input type="number" class="recharge-amount-input" id="montoRecarga" placeholder="10" min="1" step="0.01">
                    </div>
                </div>

                <!-- Método de pago dropdown -->
                <div class="recharge-field">
                    <label class="recharge-label">Método de pago</label>
                    <div class="recharge-select-wrapper" id="paymentMethodSelector" onclick="togglePaymentDropdown()">
                        <div class="recharge-select-icon">
                            <i class="fas fa-plus"></i>
                        </div>
                        <div class="recharge-select-text">
                            <span class="recharge-select-title" id="selectedMethodName">Selecciona un método de pago</span>
                            <span class="recharge-select-subtitle" id="selectedMethodDesc">Elige tu método preferido</span>
                        </div>
                        <i class="fas fa-chevron-down recharge-select-arrow" id="dropdownArrow"></i>
                    </div>
                    <!-- Dropdown options -->
                    <div class="recharge-dropdown" id="paymentDropdown">
                        <?php if (count($metodosPagoActivos) > 0): ?>
                        <?php foreach ($metodosPagoActivos as $mp): ?>
                        <?php 
                        $icons = [
                            'stripe' => 'fa-credit-card',
                            'yape' => 'fa-mobile-alt',
                            'paypal' => 'fa-paypal',
                            'binance_pay' => 'fa-coins',
                            'binance_gateway' => 'fa-coins',
                            'binance_usdt' => 'fa-coins',
                            'mercadopago' => 'fa-shopping-bag',
                            'cryptomus' => 'fa-bitcoin',
                            'manual' => 'fa-hand-holding-usd',
                            'hotmart' => 'fa-fire',
                            'veripagos' => 'fa-qrcode'
                        ];
                        $icon = $icons[$mp['method_key']] ?? 'fa-wallet';
                        ?>
                        <div class="recharge-dropdown-item" 
                             data-method="<?= htmlspecialchars($mp['method_key']) ?>"
                             data-name="<?= htmlspecialchars($mp['name']) ?>"
                             data-desc="<?= htmlspecialchars($mp['description'] ?? '') ?>"
                             data-min="<?= $mp['min_amount'] ?>"
                             data-max="<?= $mp['max_amount'] ?>"
                             data-rate="<?= $mp['exchange_rate'] ?? 6.96 ?>"
                             data-icon="<?= $icon ?>"
                             onclick="selectPaymentMethod(this)">
                            <div class="recharge-dropdown-icon">
                                <i class="fas <?= $icon ?>"></i>
                            </div>
                            <div class="recharge-dropdown-info">
                                <span class="recharge-dropdown-name"><?= htmlspecialchars($mp['name']) ?></span>
                                <span class="recharge-dropdown-desc">Mín: $<?= number_format($mp['min_amount'], 2) ?> - Máx: $<?= number_format($mp['max_amount'], 2) ?></span>
                            </div>
                            <i class="fas fa-check recharge-dropdown-check"></i>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div style="text-align: center; padding: 20px; color: #666;">
                            No hay métodos de pago disponibles.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Créditos preview -->
                <div class="recharge-credits-preview" id="creditsPreviewBox" style="display: none;">
                    <i class="fas fa-coins"></i>
                    <span>Recibirás <b id="creditosPreview">0</b> créditos</span>
                </div>

                <!-- Instrucciones para métodos no-veripagos -->
                <div id="metodoInstrucciones" class="recharge-instructions" style="display: none;"></div>

                <!-- Términos y condiciones -->
                <div class="recharge-terms">
                    <label class="recharge-checkbox-wrapper">
                        <input type="checkbox" id="termsCheckbox">
                        <span class="recharge-checkbox-custom"></span>
                        <span>Además de aceptar los <a href="#" class="recharge-terms-link">Términos y Condiciones</a></span>
                    </label>
                    <p class="recharge-disclaimer">
                        Reconozco que los fondos agregados son NO reembolsables y todo el saldo introducido solo podrá ser utilizado dentro de la plataforma. Me comprometo a no presentar disputas fraudulentas ni solicitudes de devolución de cargo. Entiendo que cualquier intento de fraude o práctica deshonesta resultará en la suspensión permanente de la cuenta, acciones legales y será denunciado ante las autoridades correspondientes.
                    </p>
                </div>

                <!-- Botón continuar -->
                <button class="recharge-continue-btn" id="btnContinuarRecarga" onclick="continuarRecarga()">
                    Continuar al pago
                </button>
            </div>
            
            <!-- Step 1 removed - unified into Step 0 -->
            
            <!-- Step 2: Mostrar QR (Veripagos) - Diseño Profesional V2 (Side-by-Side) -->
            <div id="recargaStep2" style="display: none;">
                <div class="vp-payment-card">
                    <!-- Header verde brillante -->
                    <div class="vp-header-v2">
                        <h2>Pago con QR Bolivia automático</h2>
                    </div>

                    <div class="vp-body">
                        <!-- Alerta informativa azul -->
                        <div class="vp-info-alert-v2">
                            <div class="vp-alert-icon"><i class="fas fa-info-circle"></i></div>
                            <p>Para recargar tu saldo automáticamente, sigue estos pasos cuidadosamente.</p>
                        </div>

                        <!-- Paso 1: Escanear QR -->
                        <div class="vp-step-v2">
                            <div class="vp-step-header-v2">
                                <span class="vp-step-number-v2">1</span>
                                <h3>Escanea el código QR</h3>
                            </div>
                            <p class="vp-step-text-v2">Abre tu app bancaria (BCP, Yape, etc.) y escanea el siguiente código QR para iniciar el pago.</p>
                            
                            <!-- Side-by-Side Layout Wrapper -->
                            <div class="vp-sbs-wrapper">
                                <!-- Columna Izquierda: QR -->
                                <div class="vp-qr-col">
                                    <div class="vp-qr-box-v2">
                                        <img src="" alt="Código QR" id="qrImage">
                                        <div class="vp-center-logo"><i class="fas fa-dollar-sign"></i></div>
                                    </div>
                                </div>
                                
                                <!-- Columna Derecha: Amount Box -->
                                <div class="vp-amount-col">
                                    <div class="vp-amount-card">
                                        <div class="vp-amount-label">Monto a pagar:</div>
                                        <div class="vp-amount-value" id="montoAPagarBs">0.00 Bs</div>
                                        <div class="vp-amount-equiv" id="montoAPagarUSD">(Equivalente a $0.00 USD)</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer Alert: Yellow -->
                    <div class="vp-footer-alert-v2">
                        <div class="vp-footer-icon"><i class="fas fa-clock"></i></div>
                        <p>Tu saldo se actualizará una vez que verifiques tu pago correctamente. Si tienes dudas, contacta a soporte.</p>
                    </div>

                    <!-- Elementos ocultos para la lógica -->
                    <div class="vp-footer-right" style="display:none;">
                         <span class="timer-value" id="timerQR">15:00</span>
                    </div>

                    <!-- Loading y estado -->
                    <div class="vp-loading" id="loadingVerify" style="display: flex;">
                        <div class="vp-spinner"></div>
                        <span>Verificando pago automáticamente...</span>
                    </div>

                    <!-- Botones -->
                    <div class="vp-actions">
                        <button class="vp-btn-cancel" onclick="cerrarModal('modalRecarga'); resetRecarga();">
                            Cancelar
                        </button>
                    </div>
                </div>
                <!-- Hidden field for compatibility -->
                <span id="montoAPagar" style="display:none;">0</span>
            </div>
            
            <!-- Step 3: Éxito -->
            <div id="recargaStep3" style="display: none;">
                <div class="success-icon"><i class="fas fa-check-circle"></i></div>
                <h2>¡Recarga Exitosa!</h2>
                <p style="color: var(--text-gray);">Se han agregado <b id="creditosAgregados" style="color: var(--green-accent);">0</b> créditos a tu cuenta</p>
                <p style="margin-top: 10px;">Saldo actual: <b id="nuevoSaldo" style="color: var(--green-accent);">0</b> Bs</p>
                <button class="btn-modal success" onclick="cerrarModal('modalRecarga'); resetRecarga(); location.reload();">Continuar</button>
            </div>
        </div>
    </div>

    <!-- Modal de Compra -->
    <div class="modal-overlay" id="modalCompra">
        <div class="modal-content">
            <div id="compraConfirm">
                <h2><i class="fas fa-shopping-cart"></i> Confirmar Compra</h2>
                <p style="color: var(--text-gray);">¿Deseas comprar esta cuenta?</p>

                <!-- Badge descuento membresía (solo si tiene plan con descuento) -->
                <?php if ($membresiaActiva && ($membresiaActiva['plan_descuento'] ?? 0) > 0): ?>
                <div style="background:linear-gradient(135deg,#065f46,#047857); border:1px solid #10b98155; border-radius:8px; padding:8px 14px; margin-bottom:10px; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-crown" style="color:#34d399; font-size:1rem;"></i>
                    <span style="font-size:0.85rem; color:#d1fae5; font-weight:600;">
                        Plan <?= htmlspecialchars($membresiaActiva['plan_nombre']) ?>:
                        <strong style="color:#6ee7b7;"><?= number_format((float)$membresiaActiva['plan_descuento'], 0) ?>% de descuento aplicado</strong>
                    </span>
                </div>
                <?php endif; ?>

                <div class="credential-box" style="text-align: center; margin-top: 10px;">
                    <?php if ($membresiaActiva && ($membresiaActiva['plan_descuento'] ?? 0) > 0): ?>
                    <label>Precio original</label>
                    <div style="font-size:0.95rem; color:#64748b; text-decoration:line-through;" id="precioOriginalCompra">0 Bs</div>
                    <label style="margin-top:6px;">Precio con descuento</label>
                    <?php else: ?>
                    <label>Precio</label>
                    <?php endif; ?>
                    <div class="value" style="font-size: 1.5rem; color: var(--green-accent);" id="precioCompra">0 Bs</div>
                </div>
                <p style="color: var(--text-gray); font-size: 0.9rem;">Tu saldo: <span id="saldoActualCompra">0</span> Bs</p>

                <!-- Descuento por puntos -->
                <?php if ($puntosUsuario > 0): ?>
                <div id="descuentoPuntosBox" style="background:#1a1a2e; border:1px solid #f59e0b55; border-radius:8px; padding:12px 14px; margin:12px 0; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                    <i class="fas fa-coins" style="color:#f59e0b; font-size:1.1rem; flex-shrink:0;"></i>
                    <div style="flex:1; min-width:140px;">
                        <div style="font-size:0.78rem; color:#fcd34d;">Tienes <strong id="puntosDisp"><?= number_format($puntosUsuario) ?></strong> pts · 20 pts = 1 Bs</div>
                        <div style="font-size:0.72rem; color:#78716c; margin-top:2px;">Máx. canjeable: <span id="maxDescuento">0.00</span> Bs</div>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <input type="number" id="puntosACanjear" min="0" step="20" value="0"
                            style="width:80px; background:#0d0d0d; border:1px solid #444; color:#fff; border-radius:6px; padding:6px 8px; font-size:0.85rem;"
                            oninput="actualizarDescuento()">
                        <span style="color:#f59e0b; font-size:0.8rem;">pts</span>
                    </div>
                    <div style="font-size:0.85rem; color:#fbbf24; font-weight:700; white-space:nowrap;">- <span id="descuentoValor">0.00</span> Bs</div>
                </div>
                <p style="font-size:0.82rem; color:#a0a0a0; margin:-4px 0 8px 0;">Precio final: <strong style="color:#fff;" id="precioFinalCompra">0.00</strong> Bs</p>
                <?php endif; ?>

                <div class="loading-spinner" id="loadingCompra" style="display: none;"></div>
                <button class="btn-modal" id="btnConfirmarCompra" onclick="confirmarCompra()">Confirmar Compra</button>
                <button class="btn-modal secondary" onclick="cerrarModal('modalCompra')">Cancelar</button>
            </div>
            <div id="compraExitosa" style="display: none;">
                <div class="success-icon"><i class="fas fa-check-circle"></i></div>
                <h2>¡Compra Exitosa!</h2>
                <p style="color: var(--text-gray);">Aquí están tus credenciales:</p>
                <div class="credential-box">
                    <label>Plataforma</label>
                    <div class="value" id="credPlataforma"></div>
                </div>
                <div class="credential-box">
                    <label>Correo</label>
                    <div class="value" id="credCorreo"></div>
                </div>
                <div class="credential-box">
                    <label>Contraseña</label>
                    <div class="value" id="credPassword"></div>
                </div>
                <button class="btn-modal" onclick="copiarCredenciales()"><i class="fas fa-copy"></i> Copiar Credenciales</button>
                <button class="btn-modal success" onclick="cerrarModal('modalCompra'); location.reload();">Cerrar</button>
            </div>
            <div id="compraError" style="display: none;">
                <div style="font-size: 4rem; color: var(--primary-red); margin-bottom: 20px;"><i class="fas fa-times-circle"></i></div>
                <h2>Error en la Compra</h2>
                <p style="color: var(--text-gray);" id="errorCompraMsg">Ha ocurrido un error</p>
                <button class="btn-modal secondary" onclick="cerrarModal('modalCompra'); resetCompra();">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- ===== MODAL ÉXITO COMPRA ===== -->
    <div id="modalExitoCompra" style="display:none; position:fixed; inset:0; z-index:99998; background:rgba(0,0,0,0.65); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:16px; padding:44px 36px 36px; max-width:460px; width:92%; text-align:center; box-shadow:0 24px 64px rgba(0,0,0,0.45); animation:exitoIn .22s ease;">
            <div style="width:72px; height:72px; border-radius:50%; border:2px solid #4ade80; display:flex; align-items:center; justify-content:center; margin:0 auto 24px;">
                <i class="fas fa-check" style="font-size:2rem; color:#4ade80;"></i>
            </div>
            <h2 id="exitoCompraTitle" style="font-size:1.5rem; font-weight:800; color:#111; margin:0 0 10px;">¡Cuenta contratada!</h2>
            <p style="color:#6b7280; font-size:0.95rem; margin:0 0 30px;">Tu compra se ha procesado correctamente</p>
            <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
                <button onclick="verMisCompras()" style="background:#3b82f6; color:#fff; border:none; padding:12px 24px; border-radius:8px; font-size:0.95rem; font-weight:600; cursor:pointer;">Ver mis compras</button>
                <button onclick="cerrarExitoCompra()" style="background:#e5e7eb; color:#374151; border:none; padding:12px 24px; border-radius:8px; font-size:0.95rem; font-weight:600; cursor:pointer;">Cerrar</button>
            </div>
        </div>
    </div>
    <style>
    @keyframes exitoIn { from { opacity:0; transform:scale(.9) translateY(16px); } to { opacity:1; transform:scale(1) translateY(0); } }
    </style>

    <!-- ===== MODAL INFO: Descripción / Términos ===== -->
    <div id="infoModal" onclick="if(event.target===this)cerrarInfoModal()">
        <div id="infoModalBox">
            <button id="infoModalClose" onclick="cerrarInfoModal()"><i class="fas fa-times"></i></button>
            <div id="infoModalTitle"></div>
            <div id="infoModalBody"></div>
        </div>
    </div>

    <!-- ===== MODAL GLOBAL: Confirmación / Notificación ===== -->
    <div id="gModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,0.7); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
        <div style="background:#1a1a1a; border:1px solid #2e2e2e; border-radius:16px; padding:32px 28px 24px; max-width:420px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.6); text-align:center; animation:gModalIn .18s ease;">
            <div id="gModalIcon" style="font-size:2.2rem; margin-bottom:14px;"></div>
            <div id="gModalTitle" style="font-size:1.1rem; font-weight:700; color:#fff; margin-bottom:8px;"></div>
            <div id="gModalMsg" style="font-size:0.92rem; color:#a0a0a0; line-height:1.5; margin-bottom:24px;"></div>
            <div id="gModalBtns" style="display:flex; gap:10px; justify-content:center;"></div>
        </div>
    </div>
    <style>
        @keyframes gModalIn { from { opacity:0; transform:scale(.93) translateY(10px); } to { opacity:1; transform:scale(1) translateY(0); } }
        #gModal { display:none; }
        #gModal.active { display:flex !important; }
    </style>

    <script>
        // ===== MODAL GLOBAL =====
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
                        style="background:#2a2a2a; color:#fff; border:1px solid #444; border-radius:8px; padding:10px 28px; font-weight:700; font-size:0.95rem; cursor:pointer; min-width:100px;">
                        Cancelar
                    </button>`;
                document.getElementById('gModal').classList.add('active');
            });
        }

        function gAlert(msg, title, type) {
            return new Promise(resolve => {
                _gModalResolve = resolve;
                const icons = { success:'<i class="fas fa-check-circle" style="color:#22c55e;"></i>', error:'<i class="fas fa-times-circle" style="color:#ef4444;"></i>', info:'<i class="fas fa-info-circle" style="color:#3b82f6;"></i>', warning:'<i class="fas fa-exclamation-triangle" style="color:#f59e0b;"></i>' };
                document.getElementById('gModalIcon').innerHTML = icons[type||'info'] || icons.info;
                document.getElementById('gModalTitle').textContent = title || 'Aviso';
                document.getElementById('gModalMsg').textContent = msg;
                document.getElementById('gModalBtns').innerHTML = `
                    <button onclick="_gModalResolve(true); document.getElementById('gModal').classList.remove('active');"
                        style="background:#22c55e; color:#fff; border:none; border-radius:8px; padding:10px 36px; font-weight:700; font-size:0.95rem; cursor:pointer;">
                        Aceptar
                    </button>`;
                document.getElementById('gModal').classList.add('active');
            });
        }

        // Toast pequeño (no bloquea, desaparece solo)
        function gToast(msg, type) {
            const colors = { success:'#22c55e', error:'#ef4444', info:'#3b82f6', warning:'#f59e0b' };
            const t = document.createElement('div');
            t.style.cssText = `position:fixed;top:24px;right:24px;background:${colors[type||'info']};color:#fff;padding:11px 20px;border-radius:10px;font-weight:700;font-size:0.9rem;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,0.4);display:flex;align-items:center;gap:8px;max-width:320px;`;
            t.innerHTML = msg;
            document.body.appendChild(t);
            setTimeout(() => { t.style.opacity='0'; t.style.transition='opacity .4s'; setTimeout(()=>t.remove(),400); }, 2800);
        }
    </script>

    <script>
        // Variables globales
        let movimientoIdActual = null;
        let timerInterval = null;
        let cuentaIdActual = null;
        let verificacionInterval = null;
        let precioActual = 0;     // precio original de la cuenta (sin ningún descuento)
        let precioBase   = 0;     // precio tras aplicar descuento de membresía
        // Descuento de membresía activa del usuario (% entre 0 y 100)
        const descuentoMembresia = <?= ($membresiaActiva && ($membresiaActiva['plan_descuento'] ?? 0) > 0)
            ? (float)$membresiaActiva['plan_descuento']
            : 0 ?>;

        // ---- Info modal (Descripción / Términos) ----
        function mostrarInfoCuenta(titulo, texto) {
            document.getElementById('infoModalTitle').innerHTML =
                '<i class="fas ' + (titulo === 'Términos' ? 'fa-clipboard-list' : 'fa-info-circle') + '"></i> ' + titulo;
            document.getElementById('infoModalBody').textContent = texto;
            document.getElementById('infoModal').classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        // Delegación: captura clics en cualquier .btn-info-detail (evita problemas con onclick + caracteres especiales)
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-info-detail');
            if (!btn) return;
            const titulo = btn.getAttribute('data-titulo') || '';
            const texto  = btn.getAttribute('data-texto')  || '';
            mostrarInfoCuenta(titulo, texto);
        });
        function cerrarInfoModal() {
            document.getElementById('infoModal').classList.remove('open');
            document.body.style.overflow = '';
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cerrarInfoModal();
        });

        // Logout
        async function logout() {
            const formData = new FormData();
            formData.append('action', 'logout');
            await fetch('auth.php', { method: 'POST', body: formData });
            window.location.href = 'index.html';
        }

        // ==========================================
        // LOGICA DE COMBOS
        // ==========================================
        let comboItems = {};

        function toggleComboItem(pid, cid, nombre, precio, imagen) {
            const card = document.getElementById('card-' + pid);
            
            if (comboItems[pid]) {
                delete comboItems[pid];
                card.classList.remove('selected');
            } else {
                comboItems[pid] = { cid, nombre, precio };
                card.classList.add('selected');
            }
            actualizarResumenCombo();
        }

        function actualizarResumenCombo() {
            const panel = document.getElementById('comboPanel');
            const list = document.getElementById('comboItemsList');
            const totalEl = document.getElementById('comboTotal');
            const countEl = document.getElementById('comboCount');
            
            list.innerHTML = '';
            let total = 0;
            let count = 0;
            
            for (const [pid, item] of Object.entries(comboItems)) {
                count++;
                total += item.precio;
                const div = document.createElement('div');
                div.className = 'combo-item-row';
                div.innerHTML = `<span>${item.nombre}</span><span style="color:var(--green-accent)">$${item.precio.toFixed(2)}</span>`;
                list.appendChild(div);
            }
            
            totalEl.innerText = total.toFixed(2);
            countEl.innerText = count + (count === 1 ? ' item' : ' items');
            
            if (count > 0) panel.classList.add('visible');
            else panel.classList.remove('visible');
        }

        async function comprarCombo() {
            const items = Object.values(comboItems);
            if (items.length === 0) return;
            
            const total = document.getElementById('comboTotal').innerText;
            const ok = await gConfirm(`¿Confirmas la compra del combo por ${total} Bs?`, 'Confirmar Combo');
            if (!ok) return;
            
            const btn = document.querySelector('.btn-combo-buy');
            const statusDiv = document.getElementById('comboStatus');
            btn.disabled = true;
            btn.innerText = 'Procesando...';
            statusDiv.style.display = 'block';
            statusDiv.innerHTML = 'Iniciando compra...';

            let bought = [];
            let errors = [];

            for (let i = 0; i < items.length; i++) {
                const item = items[i];
                statusDiv.innerHTML = `Procesando ${i+1}/${items.length}: ${item.nombre}...`;
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'comprar_cuenta');
                    formData.append('cuenta_id', item.cid);

                    const res = await fetch('process.php', {
                        method: 'POST',
                        body: formData,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    const data = await res.json();
                    
                    if (data.success) {
                        bought.push(item.nombre);
                    } else {
                        errors.push(`${item.nombre}: ${data.error}`);
                    }
                } catch (e) {
                    errors.push(`${item.nombre}: Error de conexión`);
                }
            }

            statusDiv.innerHTML = 'Finalizado.';
            
            let msg = '';
            if (bought.length > 0) msg += `Se compraron: ${bought.join(', ')}`;
            if (errors.length > 0 && bought.length > 0) msg += '\n';
            if (errors.length > 0) msg += `Errores: ${errors.join(', ')}`;
            
            if (bought.length > 0) {
                await gAlert(msg, '¡Compra exitosa!', 'success');
                window.location.reload();
            } else {
                await gAlert(msg, 'Hubo errores', 'error');
                btn.disabled = false;
                btn.innerText = 'CONFIRMAR COMPRA';
            }
        }

        // Navegación drawer — items principales
        document.querySelectorAll('.drawer-item[data-section]').forEach(item => {
            item.addEventListener('click', function() {
                const section = this.dataset.section;
                if (this.classList.contains('has-submenu')) {
                    const isOpen = this.classList.contains('open');
                    document.querySelectorAll('.drawer-item.has-submenu').forEach(m => m.classList.remove('open', 'active-category'));
                    if (!isOpen) this.classList.add('open', 'active-category');
                    return;
                }
                document.querySelectorAll('.drawer-item').forEach(m => m.classList.remove('active-category'));
                this.classList.add('active-category');
                document.querySelectorAll('.section-content').forEach(s => s.classList.remove('active'));
                document.getElementById('seccion-' + section)?.classList.add('active');
                closeDrawer();
            });
        });

        // Navegación drawer — sub-items
        document.querySelectorAll('.drawer-submenu li[data-section]').forEach(item => {
            item.addEventListener('click', function() {
                const section = this.dataset.section;
                document.querySelectorAll('.drawer-submenu li').forEach(li => li.classList.remove('active'));
                this.classList.add('active');
                const padre = this.closest('ul').previousElementSibling;
                if (padre) {
                    document.querySelectorAll('.drawer-item').forEach(m => m.classList.remove('active-category'));
                    padre.classList.add('active-category');
                }
                document.querySelectorAll('.section-content').forEach(s => s.classList.remove('active'));
                const targetSection = document.getElementById('seccion-' + section);
                if (targetSection) targetSection.classList.add('active');
                closeDrawer();
            });
        });

        // Click en iconos del sidebar — navega directo sin abrir drawer
        document.querySelectorAll('.nav-icon-btn[data-drawer-section]').forEach(btn => {
            btn.addEventListener('click', function() {
                const section = this.dataset.drawerSection;
                document.querySelectorAll('.section-content').forEach(s => s.classList.remove('active'));
                document.getElementById('seccion-' + section)?.classList.add('active');
                document.querySelectorAll('.nav-icon-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // Búsqueda
        // ===== TIENDA TABS =====
        function switchTiendaTab(tipo) {
            document.querySelectorAll('.tienda-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tienda-panel').forEach(p => p.classList.remove('active'));
            document.getElementById('tab-' + tipo).classList.add('active');
            document.getElementById('panel-' + tipo).classList.add('active');
        }

        // Ir a sección al cargar si viene ?goto=...
        (function() {
            const params = new URLSearchParams(window.location.search);
            const goto = params.get('goto');
            if (goto) {
                const target = document.getElementById('seccion-' + goto);
                if (target) {
                    document.querySelectorAll('.section-content').forEach(s => s.classList.remove('active'));
                    target.classList.add('active');
                    // Marcar el nav item como activo
                    const navItem = document.querySelector('.drawer-item[data-section="' + goto + '"]');
                    if (navItem) {
                        document.querySelectorAll('.drawer-item').forEach(m => m.classList.remove('active-category'));
                        navItem.classList.add('active-category');
                    }
                }
                // Limpiar el parámetro de la URL sin recargar
                window.history.replaceState({}, '', window.location.pathname);
            }
        })();

        document.getElementById('searchInput')?.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase();
            document.querySelectorAll('.product-row').forEach(row => {
                const nombre = row.dataset.nombre;
                row.style.display = nombre.includes(query) ? '' : 'none';
            });
        });

        // Filtro por categoría
        document.querySelectorAll('.cat-icon').forEach(icon => {
            icon.addEventListener('click', function() {
                document.querySelectorAll('.cat-icon').forEach(i => i.classList.remove('active'));
                this.classList.add('active');
                const filter = this.dataset.filter;

                document.querySelectorAll('.product-row').forEach(row => {
                    row.style.display = (filter === 'all' || row.dataset.plataforma === filter) ? '' : 'none';
                });

                // Recalcular badges según filas visibles
                actualizarBadgesTabs(filter);
            });
        });

        function actualizarBadgesTabs(filter) {
            let totalPerfiles  = 0;
            let totalCompletas = 0;

            document.querySelectorAll('.product-row').forEach(row => {
                const visible = (filter === 'all' || row.dataset.plataforma === filter);
                if (!visible) return;

                if (row.dataset.tipo === 'perfil') {
                    totalPerfiles += parseInt(row.dataset.perfiles || 0);
                } else if (row.dataset.tipo === 'completa') {
                    totalCompletas += parseInt(row.dataset.stock || 0);
                }
            });

            const badgePerfiles  = document.querySelector('#tab-perfiles .tab-badge');
            const badgeCompletas = document.querySelector('#tab-completas .tab-badge');
            if (badgePerfiles)  badgePerfiles.textContent  = totalPerfiles;
            if (badgeCompletas) badgeCompletas.textContent = totalCompletas;
        }


        // ==================== RECARGA ====================
        let metodoSeleccionado = null;
        let metodoMinAmount = 1;
        let metodoMaxAmount = 1000;
        let metodoExchangeRate = 6.96;
        
        function abrirModalRecarga() {
            // Reset al formulario unificado
            document.getElementById('recargaStep0').style.display = 'block';
            document.getElementById('recargaStep2').style.display = 'none';
            document.getElementById('recargaStep3').style.display = 'none';
            document.getElementById('modalRecarga').classList.add('active');
            
            // Reset form
            resetRechargeForm();
        }

        function resetRechargeForm() {
            metodoSeleccionado = null;
            metodoMinAmount = 1;
            metodoMaxAmount = 1000;
            metodoExchangeRate = 6.96;
            
            // Restore modal padding
            document.querySelector('#modalRecarga .modal-content').classList.remove('no-padding');
            
            document.getElementById('montoRecarga').value = '';
            document.getElementById('creditosPreview').textContent = '0';
            document.getElementById('creditsPreviewBox').style.display = 'none';
            document.getElementById('termsCheckbox').checked = false;
            
            // Reset dropdown
            document.getElementById('selectedMethodName').textContent = 'Selecciona un método de pago';
            document.getElementById('selectedMethodDesc').textContent = 'Elige tu método preferido';
            document.getElementById('paymentMethodSelector').classList.remove('selected');
            document.getElementById('paymentMethodSelector').querySelector('.recharge-select-icon i').className = 'fas fa-plus';
            document.getElementById('paymentDropdown').classList.remove('open');
            document.getElementById('dropdownArrow').classList.remove('open');
            
            // Reset all dropdown items
            document.querySelectorAll('.recharge-dropdown-item').forEach(item => item.classList.remove('active'));
            
            // Reset instructions
            document.getElementById('metodoInstrucciones').style.display = 'none';
        }
        
        function togglePaymentDropdown() {
            const dropdown = document.getElementById('paymentDropdown');
            const arrow = document.getElementById('dropdownArrow');
            dropdown.classList.toggle('open');
            arrow.classList.toggle('open');
        }
        
        function selectPaymentMethod(element) {
            // Set method data
            metodoSeleccionado = element.dataset.method;
            metodoMinAmount = parseFloat(element.dataset.min) || 1;
            metodoMaxAmount = parseFloat(element.dataset.max) || 1000;
            metodoExchangeRate = parseFloat(element.dataset.rate) || 6.96;
            
            const nombre = element.dataset.name;
            const icon = element.dataset.icon;
            
            // Update selector display
            document.getElementById('selectedMethodName').textContent = nombre;
            document.getElementById('selectedMethodDesc').textContent = `Mín: $${metodoMinAmount} - Máx: $${metodoMaxAmount}`;
            document.getElementById('paymentMethodSelector').classList.add('selected');
            document.getElementById('paymentMethodSelector').querySelector('.recharge-select-icon i').className = 'fas ' + icon;
            
            // Mark active item
            document.querySelectorAll('.recharge-dropdown-item').forEach(item => item.classList.remove('active'));
            element.classList.add('active');
            
            // Close dropdown
            document.getElementById('paymentDropdown').classList.remove('open');
            document.getElementById('dropdownArrow').classList.remove('open');
            
            // Update amount input placeholder
            const montoInput = document.getElementById('montoRecarga');
            montoInput.min = metodoMinAmount;
            montoInput.max = metodoMaxAmount;
            montoInput.placeholder = metodoMinAmount;
            
            // Show instructions for non-veripagos methods
            const instrucciones = document.getElementById('metodoInstrucciones');
            if (metodoSeleccionado !== 'veripagos') {
                instrucciones.innerHTML = `<i class="fas fa-info-circle"></i> <b>${nombre}</b>: La integración completa de este método está en desarrollo. Por ahora puedes usar Veripagos QR.`;
                instrucciones.style.display = 'block';
            } else {
                instrucciones.style.display = 'none';
            }
        }
        
        async function continuarRecarga() {
            if (!metodoSeleccionado) {
                gAlert('Selecciona un método de pago', 'Aviso', 'warning');
                return;
            }
            
            const monto = parseFloat(document.getElementById('montoRecarga').value);
            if (!monto || monto <= 0) {
                gAlert('Ingresa un monto válido', 'Monto inválido', 'warning');
                return;
            }
            if (monto < metodoMinAmount) {
                gAlert(`El monto mínimo es ${metodoMinAmount} Bs`, 'Monto inválido', 'warning');
                return;
            }
            if (monto > metodoMaxAmount) {
                gAlert(`El monto máximo es ${metodoMaxAmount} Bs`, 'Monto inválido', 'warning');
                return;
            }
            
            if (!document.getElementById('termsCheckbox').checked) {
                gAlert('Debes aceptar los Términos y Condiciones para continuar', 'Aviso', 'warning');
                return;
            }
            
            if (metodoSeleccionado === 'veripagos') {
                generarQR();
            } else {
                gAlert('Este método de pago está en desarrollo. Por favor, usa Veripagos QR por ahora.', 'En desarrollo', 'info');
            }
        }

        document.getElementById('montoRecarga')?.addEventListener('input', function() {
            const monto = parseFloat(this.value) || 0;
            document.getElementById('creditosPreview').textContent = monto.toFixed(2);
            // Show/hide credits preview
            document.getElementById('creditsPreviewBox').style.display = monto > 0 ? 'flex' : 'none';
        });

        async function generarQR() {
            const monto = parseFloat(document.getElementById('montoRecarga').value);
            if (!monto || monto <= 0) {
                gAlert('Ingresa un monto válido', 'Monto inválido', 'warning');
                return;
            }

            try {
                const formData = new FormData();
                formData.append('action', 'iniciar_recarga');
                formData.append('monto', monto);
                if (metodoSeleccionado) {
                    formData.append('method_key', metodoSeleccionado);
                }

                const response = await fetch('process.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Error al generar QR');
                }

                // Mostrar QR con diseño nuevo
                movimientoIdActual = data.data.movimiento_id;
                document.getElementById('qrImage').src = data.data.qr_image;
                
                // Actualizar campos con los datos del backend (que ya hizo la conversión)
                const montoBs = parseFloat(data.data.monto_bs); // Backend should return this
                const montoUsd = parseFloat(data.data.monto_usd); // Backend should return this
                
                document.getElementById('montoAPagarBs').textContent = montoBs.toFixed(2) + ' Bs';
                document.getElementById('montoAPagarUSD').textContent = '(Equivalente a $' + montoUsd.toFixed(2) + ' USD)';
                
                document.getElementById('recargaStep0').style.display = 'none';
                document.getElementById('recargaStep2').style.display = 'block';
                
                // Remove padding from modal for full-width QR card
                document.querySelector('#modalRecarga .modal-content').classList.add('no-padding');

                // Iniciar timer de 15 minutos
                iniciarTimer(15 * 60);
                
                // Iniciar verificación automática cada 5 segundos
                verificacionInterval = setInterval(verificarPagoSilencioso, 5000);

            } catch (error) {
                gAlert('Error: ' + error.message, 'Error', 'error');
            }
        }

        function iniciarTimer(segundos) {
            let remaining = segundos;
            timerInterval = setInterval(() => {
                remaining--;
                const mins = Math.floor(remaining / 60);
                const secs = remaining % 60;
                document.getElementById('timerQR').textContent = 
                    `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
                
                if (remaining <= 0) {
                    clearInterval(timerInterval);
                    clearInterval(verificacionInterval);
                    // Use a non-blocking notification instead of alert
                    showToast('El tiempo ha expirado. Genera un nuevo QR.', 'warning');
                    cerrarModal('modalRecarga');
                    resetRecarga();
                }
            }, 1000);
        }

        async function verificarPagoSilencioso() {
            if (!movimientoIdActual) return;
            
            try {
                const formData = new FormData();
                formData.append('action', 'verificar_pago');
                formData.append('movimiento_id', movimientoIdActual);

                const response = await fetch('process.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await response.json();

                if (data.success && data.data.estado === 'completado') {
                    clearInterval(timerInterval);
                    clearInterval(verificacionInterval);
                    mostrarRecargaExitosa(data.data);
                }
            } catch (error) {
                console.error('Error verificando pago:', error);
            }
        }



        function mostrarRecargaExitosa(data) {
            document.getElementById('creditosAgregados').textContent = data.creditos;
            document.getElementById('nuevoSaldo').textContent = data.creditos_actuales;
            document.getElementById('recargaStep2').style.display = 'none';
            document.getElementById('recargaStep3').style.display = 'block';
            document.getElementById('userCreditos').textContent = data.creditos_actuales;
        }

        function resetRecarga() {
            clearInterval(timerInterval);
            clearInterval(verificacionInterval);
            movimientoIdActual = null;
            
            document.getElementById('recargaStep2').style.display = 'none';
            document.getElementById('recargaStep3').style.display = 'none';
            
            document.getElementById('timerQR').textContent = '15:00';
            
            // Show step 0 and reset form
            document.getElementById('recargaStep0').style.display = 'block';
            resetRechargeForm();
        }

        // ==================== COMPRAS ====================
        function comprar(cuentaId, precio) {
            cuentaIdActual = cuentaId;
            precioActual   = precio;

            // Aplicar descuento de membresía si existe
            if (descuentoMembresia > 0) {
                precioBase = Math.round(precio * (1 - descuentoMembresia / 100) * 100) / 100;
                const origEl = document.getElementById('precioOriginalCompra');
                if (origEl) origEl.textContent = precio.toFixed(2) + ' Bs';
            } else {
                precioBase = precio;
            }

            const saldoActual = parseFloat(document.getElementById('userCreditos').textContent);
            
            document.getElementById('precioCompra').textContent = precioBase.toFixed(2) + ' Bs';
            document.getElementById('saldoActualCompra').textContent = saldoActual.toFixed(2);

            // Reiniciar selector de puntos si existe
            const puntosInput = document.getElementById('puntosACanjear');
            if (puntosInput) {
                puntosInput.value = 0;
                const ptsDisp = parseInt(document.getElementById('puntosDisp').textContent.replace(/,/g,'')) || 0;
                puntosInput.max = Math.floor(ptsDisp / 20) * 20;
                const maxDesc = Math.min(ptsDisp / 20, precioBase);
                document.getElementById('maxDescuento').textContent = maxDesc.toFixed(2);
                document.getElementById('descuentoValor').textContent = '0.00';
                document.getElementById('precioFinalCompra').textContent = precioBase.toFixed(2);
            }
            
            if (saldoActual < precioBase) {
                document.getElementById('btnConfirmarCompra').disabled = true;
                document.getElementById('btnConfirmarCompra').textContent = 'Saldo Insuficiente';
                document.getElementById('saldoActualCompra').style.color = 'var(--primary-red)';
            } else {
                document.getElementById('btnConfirmarCompra').disabled = false;
                document.getElementById('btnConfirmarCompra').textContent = 'Confirmar Compra';
                document.getElementById('saldoActualCompra').style.color = 'var(--green-accent)';
            }
            
            document.getElementById('compraConfirm').style.display = 'block';
            document.getElementById('compraExitosa').style.display = 'none';
            document.getElementById('compraError').style.display = 'none';
            document.getElementById('modalCompra').classList.add('active');
        }

        function actualizarDescuento() {
            const puntosInput = document.getElementById('puntosACanjear');
            if (!puntosInput) return;
            let pts = parseInt(puntosInput.value) || 0;
            const ptsDisp = parseInt(document.getElementById('puntosDisp').textContent.replace(/,/g,'')) || 0;
            // Redondear a múltiplo de 20
            pts = Math.floor(pts / 20) * 20;
            pts = Math.max(0, Math.min(pts, ptsDisp));
            puntosInput.value = pts;
            const descuento = pts / 20;
            // Calcular sobre precioBase (ya incluye descuento membresía)
            const precioFinal = Math.max(0, precioBase - descuento);
            document.getElementById('descuentoValor').textContent = descuento.toFixed(2);
            document.getElementById('precioFinalCompra').textContent = precioFinal.toFixed(2);
            const saldoActual = parseFloat(document.getElementById('userCreditos').textContent);
            const btn = document.getElementById('btnConfirmarCompra');
            if (saldoActual < precioFinal) {
                btn.disabled = true;
                btn.textContent = 'Saldo Insuficiente';
            } else {
                btn.disabled = false;
                btn.textContent = 'Confirmar Compra';
            }
        }

        async function confirmarCompra() {
            if (!cuentaIdActual) return;
            
            document.getElementById('loadingCompra').style.display = 'block';
            document.getElementById('btnConfirmarCompra').disabled = true;

            const puntosInput = document.getElementById('puntosACanjear');
            const puntosACanjear = puntosInput ? (parseInt(puntosInput.value) || 0) : 0;

            try {
                const formData = new FormData();
                formData.append('action', 'comprar_cuenta');
                formData.append('cuenta_id', cuentaIdActual);
                if (puntosACanjear > 0) {
                    formData.append('puntos_canjear', puntosACanjear);
                }

                const response = await fetch('process.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.error || 'Error en la compra');
                }

                // Mostrar credenciales
                document.getElementById('credPlataforma').textContent = data.data.plataforma;
                document.getElementById('credCorreo').textContent = data.data.correo;
                document.getElementById('credPassword').textContent = data.data.password;
                document.getElementById('userCreditos').textContent = data.data.creditos_restantes.toFixed(2);
                // Actualizar puntos si se canjearon
                if (data.data.puntos_restantes !== undefined) {
                    const dispEl = document.getElementById('puntosDisp');
                    const mainEl = document.getElementById('puntosDisplay');
                    if (dispEl) dispEl.textContent = data.data.puntos_restantes.toLocaleString();
                    if (mainEl) mainEl.textContent = data.data.puntos_restantes.toLocaleString();
                }
                
                document.getElementById('compraConfirm').style.display = 'none';
                document.getElementById('compraExitosa').style.display = 'block';

                // Cerrar modal de compra y mostrar modal de éxito centrado
                cerrarModal('modalCompra');
                const tipoNombre = document.getElementById('credPlataforma').textContent || 'Cuenta';
                const esPerfil = tipoNombre.toLowerCase().includes('perfil');
                document.getElementById('exitoCompraTitle').textContent = esPerfil ? '¡Perfil contratado!' : '¡Cuenta contratada!';
                const m = document.getElementById('modalExitoCompra');
                m.style.display = 'flex';

            } catch (error) {
                document.getElementById('errorCompraMsg').textContent = error.message;
                document.getElementById('compraConfirm').style.display = 'none';
                document.getElementById('compraError').style.display = 'block';
            } finally {
                document.getElementById('loadingCompra').style.display = 'none';
            }
        }

        function copiarCredenciales() {
            const plat = document.getElementById('credPlataforma').textContent;
            const correo = document.getElementById('credCorreo').textContent;
            const pass = document.getElementById('credPassword').textContent;
            const texto = `${plat}\nCorreo: ${correo}\nContraseña: ${pass}`;
            navigator.clipboard.writeText(texto).then(() => {
                gToast('<i class="fas fa-check"></i> ¡Credenciales copiadas!', 'success');
            });
        }

        function resetCompra() {
            cuentaIdActual = null;
            document.getElementById('compraConfirm').style.display = 'block';
            document.getElementById('compraExitosa').style.display = 'none';
            document.getElementById('compraError').style.display = 'none';
        }

        function cerrarExitoCompra() {
            document.getElementById('modalExitoCompra').style.display = 'none';
            document.body.style.overflow = '';
            location.reload();
        }

        function verMisCompras() {
            document.getElementById('modalExitoCompra').style.display = 'none';
            document.body.style.overflow = '';
            // Recargar la página e ir directo a Mis Compras (para que aparezca la compra nueva)
            window.location.href = window.location.pathname + '?goto=miscompras';
        }

        // ==================== UTILIDADES ====================
        function resetRecarga() {
            metodoSeleccionado = null;
            movimientoIdActual = null;
            if (timerInterval) clearInterval(timerInterval);
            if (verificacionInterval) clearInterval(verificacionInterval);
            document.getElementById('montoRecarga').value = '';
            document.getElementById('creditosPreview').textContent = '0';
            document.getElementById('recargaStep0').style.display = 'block';
            document.getElementById('recargaStep1').style.display = 'none';
            document.getElementById('recargaStep2').style.display = 'none';
            document.getElementById('recargaStep3').style.display = 'none';
        }
        
        function cerrarModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Cerrar modal al hacer clic fuera
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                    if (this.id === 'modalRecarga') resetRecarga();
                    if (this.id === 'modalCompra') resetCompra();
                }


            });
        });

        // Copiar código de referido
        function copyCode() {
            const input = document.getElementById('refCode');
            input.select();
            document.execCommand('copy');
            gToast('<i class="fas fa-check"></i> ¡Código copiado!', 'success');
        }

        // Confirmar activar/renovar plan de membresía
        async function confirmarPlan(planId, nombre, precio, esRenovar) {
            const accion = esRenovar ? 'Renovar' : 'Activar';
            const msg = precio && precio !== 'GRATIS'
                ? `¿${accion} el plan ${nombre} por ${precio}? Se descontará de tu saldo.`
                : `¿${accion} el plan ${nombre}?`;
            const ok = await gConfirm(msg, `${accion} Plan`);
            if (ok) document.getElementById('form-plan-' + planId).submit();
        }
        // Copiar enlace de referido
        function copyLink() {
            const input = document.getElementById('refLink');
            input.select();
            document.execCommand('copy');
            gToast('<i class="fas fa-check"></i> ¡Enlace copiado!', 'success');
        }

        // Reclamar puntos de recompensa diarios
        async function reclamarPuntos(btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Reclamando...';
            try {
                const fd = new FormData();
                fd.append('action', 'reclamar_puntos');
                const res = await fetch('process.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (data.success) {
                    // Actualizar display de puntos
                    const display = document.getElementById('puntosDisplay');
                    if (display) display.textContent = data.puntos_total.toLocaleString();
                    btn.style.background = 'linear-gradient(135deg,#1c1c1c,#1c1c1c)';
                    btn.style.border = '1px solid #444';
                    btn.style.color = '#aaa';
                    btn.style.cursor = 'default';
                    btn.innerHTML = '<i class="fas fa-check"></i> Reclamado hoy';
                    // Mini toast de éxito
                    const toast = document.createElement('div');
                    toast.style.cssText = 'position:fixed;top:24px;right:24px;background:#f59e0b;color:#000;padding:10px 20px;border-radius:8px;font-weight:700;z-index:9999;box-shadow:0 4px 20px rgba(245,158,11,0.4);';
                    toast.innerHTML = '<i class="fas fa-coins"></i> +' + data.puntos_ganados + ' puntos reclamados';
                    document.body.appendChild(toast);
                    setTimeout(() => toast.remove(), 3000);
                } else if (data.error === 'ya_reclamado') {
                    btn.innerHTML = '<i class="fas fa-check"></i> Reclamado hoy';
                } else {
                    gAlert(data.error || 'Error al reclamar', 'Error', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-gift"></i> Reclamar puntos';
                }
            } catch (e) {
                gAlert('Error de conexión', 'Error', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-gift"></i> Reclamar puntos';
            }
        }



        // Copiar credenciales desde Mis Compras
        function copiarCredencialesCompra(plataforma, correo, password) {
            const texto = `${plataforma}\nCorreo: ${correo}\nContraseña: ${password}`;
            navigator.clipboard.writeText(texto).then(() => {
                gToast('<i class="fas fa-check"></i> ¡Credenciales copiadas!', 'success');
            });
        }

        // Cargar configuración de marca (Apariencia + Branding desde admin)
        async function cargarConfigMarca() {
            try {
                const response = await fetch('config_marca.json?t=' + Date.now());
                const config = await response.json();
                
                // Favicon
                const faviconLink = document.getElementById('faviconLink');
                if (faviconLink && config.favicon_url) {
                    faviconLink.href = config.favicon_url;
                }
                
                // Logo en header
                const logoImg = document.querySelector('.logo-container img');
                if (logoImg && config.logo_url) {
                    logoImg.src = config.logo_url;
                }
                
                // Título
                if (config.nombre_tienda) {
                    document.title = config.nombre_tienda + ' - Tienda';
                }
                
                // Color principal
                if (config.color_principal) {
                    document.documentElement.style.setProperty('--primary-red', config.color_principal);
                }
                
                // Colores de apariencia
                if (config.fondo_principal) document.documentElement.style.setProperty('--bg-dark', config.fondo_principal);
                if (config.fondo_secundario) document.documentElement.style.setProperty('--bg-sidebar', config.fondo_secundario);
                if (config.fondo_terciario) document.documentElement.style.setProperty('--bg-card', config.fondo_terciario);
                if (config.texto_principal) document.documentElement.style.setProperty('--text-main', config.texto_principal);
                if (config.texto_secundario) document.documentElement.style.setProperty('--text-gray', config.texto_secundario);
                
                // WhatsApp (flotante)
                const waLink = document.getElementById('waFloatLink');
                if (waLink) {
                    if (config.whatsapp_grupo) {
                        waLink.href = config.whatsapp_grupo;
                        waLink.style.display = 'flex';
                    } else if (config.whatsapp_numero) {
                        const num = String(config.whatsapp_numero).replace(/\D/g, '');
                        if (num) {
                            waLink.href = 'https://wa.me/' + num;
                            waLink.style.display = 'flex';
                        }
                    }
                }
                
                // Comunicado global
                if (config.comunicado_activo && config.comunicado_mensaje) {
                    const banner = document.getElementById('comunicadoBannerStore');
                    const msg = document.getElementById('comunicadoMensajeStore');
                    if (banner && msg) {
                        msg.textContent = config.comunicado_mensaje;
                        banner.style.display = 'block';
                    }
                }
            } catch (error) {
                console.log('Config marca no disponible');
            }
        }
        cargarConfigMarca();
        // Toast Notification System
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `vp-toast vp-toast-${type}`;
            toast.innerHTML = `
                <div class="vp-toast-content">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-times-circle' : 'fa-exclamation-circle')}"></i>
                    <span>${message}</span>
                </div>
            `;
            document.body.appendChild(toast);
            
            // Trigger reflow
            toast.offsetHeight;
            
            toast.classList.add('show');
            
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }

        // Auto-select combo items from URL (redirect from index.html)
        window.addEventListener('load', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const comboParam = urlParams.get('combo');
            if (comboParam) {
                // Switch to combo section
                const comboTab = document.querySelector('.submenu li[data-section="combos"]');
                if(comboTab) comboTab.click();

                // Select items with small delay to ensure DOM is ready/rendered
                setTimeout(() => {
                    const pids = comboParam.split(',');
                    pids.forEach(pid => {
                        const card = document.getElementById('card-' + pid);
                        if (card && !card.classList.contains('selected')) {
                            card.click();
                        }
                    });
                }, 500);
            }
        });

        // ── Drawer toggle ──
        function closeDrawer() {
            document.getElementById('storeDrawer').classList.remove('open');
            document.getElementById('mainContent').classList.remove('drawer-open');
            document.getElementById('sidebarOverlay').classList.remove('active');
        }
        function toggleDrawer() {
            var drawer  = document.getElementById('storeDrawer');
            var content = document.getElementById('mainContent');
            var overlay = document.getElementById('sidebarOverlay');
            var isOpen  = drawer.classList.contains('open');
            drawer.classList.toggle('open', !isOpen);
            content.classList.toggle('drawer-open', !isOpen);
            overlay.classList.toggle('active', !isOpen);
        }
        document.getElementById('sidebarOverlay').addEventListener('click', closeDrawer);

        // Al cambiar tamaño de ventana (o zoom), reajustar el estado del drawer
        window.addEventListener('resize', function() {
            var drawer  = document.getElementById('storeDrawer');
            var content = document.getElementById('mainContent');
            var overlay = document.getElementById('sidebarOverlay');
            if (!drawer.classList.contains('open')) return;
            if (window.innerWidth <= 900) {
                // Modo overlay: quitar push del contenido
                content.classList.remove('drawer-open');
                overlay.classList.add('active');
            } else {
                // Modo push: quitar overlay
                content.classList.add('drawer-open');
                overlay.classList.remove('active');
            }
        });
    </script>
</body>
</html>

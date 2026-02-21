-- =============================================
-- MIGRACIÓN COMPLETA - programm_tienda
-- Ejecutar en Adminer: programm_tienda
-- =============================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ---------------------------------------------
-- TABLA: usuarios
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    creditos DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    puntos_recompensa INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Columnas extra por si la tabla ya existía sin ellas
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS creditos DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS puntos_recompensa INT NOT NULL DEFAULT 0;

-- ---------------------------------------------
-- TABLA: plataformas
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS plataformas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    imagen_url VARCHAR(500) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- TABLA: cuentas
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS cuentas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plataforma_id INT NOT NULL,
    correo VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    precio DECIMAL(10,2) NOT NULL,
    precio_pro DECIMAL(10,2) NULL DEFAULT NULL COMMENT 'Precio especial Pro (opcional)',
    estado ENUM('disponible','vendida','reservada') DEFAULT 'disponible',
    tipo_cuenta ENUM('completa','perfil') NOT NULL DEFAULT 'completa',
    perfiles_disponibles INT NULL DEFAULT NULL COMMENT 'Solo para tipo_cuenta=perfil',
    nombre_servicio VARCHAR(100) NULL DEFAULT NULL,
    categoria VARCHAR(100) NULL DEFAULT NULL,
    tipo_entrega ENUM('automatico','manual') NOT NULL DEFAULT 'automatico',
    whatsapp_soporte VARCHAR(30) NULL DEFAULT NULL,
    dias INT NOT NULL DEFAULT 30,
    usuario_cuenta VARCHAR(255) NULL DEFAULT NULL,
    pins TEXT NULL DEFAULT NULL COMMENT 'PINs separados por coma',
    renovable TINYINT(1) NOT NULL DEFAULT 1,
    terminos TEXT NULL DEFAULT NULL,
    descripcion TEXT NULL DEFAULT NULL,
    imap_habilitado TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plataforma_id) REFERENCES plataformas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- TABLA: membership_plans
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS membership_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    points INT NOT NULL DEFAULT 0,
    description TEXT,
    descuento DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Porcentaje de descuento en cuentas (0-100)',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Planes por defecto
INSERT IGNORE INTO membership_plans (id, name, price, points, description, is_active, is_featured, display_order) VALUES
(1, 'Proveedor de STREAMING', 50.00, 500, '40% de descuento en todas las cuentas de tienda|Acceso ilimitado al Marketplace Oficial Proveedor|Alta prioridad para soporte técnico y asistencia|Puedes vender Cuentas de Streaming|Puedes crear comunidades', 1, 1, 1),
(2, 'Pro', 4.99, 50, '20% de descuento en todas las cuentas|Soporte VIP directo a tu correo|20% de comisión mensual con tu enlace de referido|Bot de notificación automática por WhatsApp|Insignia verificada', 1, 0, 2),
(3, 'Curso de Importación', 5.00, 0, 'Acceso al documento de importación|Lista para calcular gastos y ajustar las taras presupuestos|Comunidad de varios emprendedores|Grupo de Telegram para precio importación', 1, 0, 3);

-- ---------------------------------------------
-- TABLA: usuario_membresias
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS usuario_membresias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    plan_id INT NOT NULL,
    estado ENUM('activa','expirada','cancelada') DEFAULT 'activa',
    creditos_usados DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    fecha_inicio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    fecha_fin TIMESTAMP NULL DEFAULT NULL,
    ultima_reclamacion DATE NULL DEFAULT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES membership_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- TABLA: payment_methods
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    method_key VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    min_amount DECIMAL(10,2) DEFAULT 1.00,
    max_amount DECIMAL(10,2) DEFAULT 1000.00,
    exchange_rate DECIMAL(10,4) DEFAULT 6.96,
    allow_new_users TINYINT(1) DEFAULT 1,
    is_active TINYINT(1) DEFAULT 0,
    display_order INT DEFAULT 0,
    config_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO payment_methods (method_key, name, description, min_amount, max_amount, allow_new_users, is_active, display_order) VALUES
('stripe',           'Stripe Checkout',         'Pago con tarjeta de crédito/débito via Stripe',                                                    10.00, 1000.00, 1, 0, 1),
('yape',             'Yape',                    'Solo de Yape a Yape (No Plin, BCP, etc.) | Min $10 USD',                                           1.00,  100.00,  1, 0, 2),
('paypal',           'PayPal Express Checkout', 'Paypal | Desde $10 USD [Automático]',                                                              10.00, 1000.00, 1, 0, 3),
('binance_pay',      'Binance Pay',             'Binance Pay | Desde $10 USDT [Automático] USDT',                                                   10.00, 1000.00, 1, 0, 4),
('binance_gateway',  'Binance Pay Gateway',     'Binance Pay Gateway | Pagos directos',                                                             10.00, 1000.00, 1, 0, 5),
('binance_usdt',     'Binance Pay (USDT)',       'Binance Pay | Solo USDT | [Automático]',                                                           10.00, 1000.00, 1, 0, 6),
('mercadopago',      'MercadoPago',             '[Automático] YAPE | BCP | PAGO EFECTIVO | Solo Perú | Debit/Credit Card',                         10.00, 1000.00, 1, 0, 7),
('cryptomus',        'Cryptomus',               'Cryptomus | USDT, BTC, ETH, etc. [Automático] Min $1',                                            10.00, 1000.00, 1, 0, 8),
('manual',           'Manual Payment',          'Pagos Manuales | Wsp: +51 913742547 | Min $10 USD',                                               10.00, 100.00,  1, 0, 9),
('hotmart',          'Hotmart Checkout',        '[Automático] Tarjeta, Transferencias, Pago Efectivo, etc. (Mundial)',                              10.00, 10.00,   1, 0, 10),
('veripagos',        'Veripagos QR',            'Veripagos QR | Pago con QR Bolivia',                                                              10.00, 1000.00, 1, 0, 11);

-- ---------------------------------------------
-- TABLA: transacciones
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS transacciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    movimiento_id VARCHAR(100) NOT NULL COMMENT 'ID de Veri Pagos',
    monto DECIMAL(10,2) NOT NULL COMMENT 'Monto en Bs',
    creditos_agregados DECIMAL(10,2) NOT NULL,
    estado ENUM('pendiente','completado','fallido','expirado') DEFAULT 'pendiente',
    qr_base64 LONGTEXT COMMENT 'QR en base64',
    remitente_nombre VARCHAR(255) DEFAULT NULL,
    remitente_banco VARCHAR(100) DEFAULT NULL,
    remitente_documento VARCHAR(50) DEFAULT NULL,
    remitente_cuenta VARCHAR(50) DEFAULT NULL,
    data_extra JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_movimiento_id ON transacciones(movimiento_id);

-- ---------------------------------------------
-- TABLA: compras
-- ---------------------------------------------
CREATE TABLE IF NOT EXISTS compras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    cuenta_id INT NOT NULL,
    creditos_usados DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (cuenta_id) REFERENCES cuentas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------
-- TABLA: productos (Licencias, Cursos, Software)
-- ---------------------------------------------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
-- FIN MIGRACIÓN

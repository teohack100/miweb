-- =============================================
-- Actualizaciones para Veri Pagos Integration
-- =============================================

-- Agregar créditos a usuarios (ejecutar una sola vez)
-- Si ya tienes la columna, ignora el error o comenta esta línea
ALTER TABLE usuarios ADD COLUMN creditos DECIMAL(10,2) DEFAULT 0.00;

-- Tabla de transacciones de recarga con Veri Pagos
CREATE TABLE IF NOT EXISTS transacciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    movimiento_id VARCHAR(100) NOT NULL COMMENT 'ID de Veri Pagos',
    monto DECIMAL(10,2) NOT NULL COMMENT 'Monto en Bs',
    creditos_agregados DECIMAL(10,2) NOT NULL,
    estado ENUM('pendiente', 'completado', 'fallido', 'expirado') DEFAULT 'pendiente',
    qr_base64 LONGTEXT COMMENT 'QR en base64',
    remitente_nombre VARCHAR(255) DEFAULT NULL,
    remitente_banco VARCHAR(100) DEFAULT NULL,
    remitente_documento VARCHAR(50) DEFAULT NULL,
    remitente_cuenta VARCHAR(50) DEFAULT NULL,
    data_extra JSON DEFAULT NULL COMMENT 'Datos adicionales enviados a Veri Pagos',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla de compras realizadas con créditos
CREATE TABLE IF NOT EXISTS compras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    cuenta_id INT NOT NULL,
    creditos_usados DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (cuenta_id) REFERENCES cuentas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índice para búsqueda rápida por movimiento_id
CREATE INDEX idx_movimiento_id ON transacciones(movimiento_id);


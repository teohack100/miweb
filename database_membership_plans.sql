-- ========================================
-- Tabla de Planes de Membresía
-- ========================================

CREATE TABLE IF NOT EXISTS membership_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    points INT NOT NULL DEFAULT 0,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    is_featured TINYINT(1) DEFAULT 0,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Planes por defecto (opcionales, puedes crear los tuyos desde el admin)
INSERT INTO membership_plans (name, price, points, description, is_active, is_featured, display_order) VALUES
('Proveedor de STREAMING', 50.00, 500, '40% de descuento en todas las cuentas de tienda|Acceso ilimitado al Marketplace Oficial Proveedor|Alta prioridad para soporte técnico y asistencia|Puedes vender Cuentas de Streaming|Puedes crear comunidades', 1, 1, 1),
('Pro', 4.99, 50, '20% de descuento en todas las cuentas|Soporte VIP directo a tu correo|20% de comisión mensual con tu enlace de referido|Bot de notificación automática por WhatsApp|Insignia verificada', 1, 0, 2),
('Curso de Importación', 5.00, 0, 'Acceso al documento de importación|Lista para calcular gastos y ajustar las taras presupuestos|Comunidad de varios emprendedores|Grupo de Telegram para precio importación', 1, 0, 3);

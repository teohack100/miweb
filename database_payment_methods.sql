-- =============================================
-- Payment Methods - Estructura de Base de Datos
-- =============================================

-- Tabla de métodos de pago
CREATE TABLE IF NOT EXISTS payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    method_key VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    min_amount DECIMAL(10,2) DEFAULT 1.00,
    max_amount DECIMAL(10,2) DEFAULT 1000.00,
    allow_new_users BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT FALSE,
    display_order INT DEFAULT 0,
    config_data JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar métodos de pago por defecto (desactivados para que el admin elija cuáles usar)
INSERT INTO payment_methods (method_key, name, description, min_amount, max_amount, allow_new_users, is_active, display_order) VALUES
('stripe', 'Stripe Checkout', 'Pago con tarjeta de crédito/débito via Stripe', 10.00, 1000.00, TRUE, FALSE, 1),
('yape', 'Yape', 'Solo de Yape a Yape (No Plin, BCP, etc.) | Min $10 USD', 1.00, 100.00, TRUE, FALSE, 2),
('paypal', 'PayPal Express Checkout', 'Paypal | Desde $10 USD [Automático]', 10.00, 1000.00, TRUE, FALSE, 3),
('binance_pay', 'Binance Pay', 'Binance Pay | Desde $10 USDT [Automático] USDT', 10.00, 1000.00, TRUE, FALSE, 4),
('binance_gateway', 'Binance Pay Gateway', 'Binance Pay Gateway | Pagos directos', 10.00, 1000.00, TRUE, FALSE, 5),
('binance_usdt', 'Binance Pay (USDT)', 'Binance Pay | Solo USDT | [Automático]', 10.00, 1000.00, TRUE, FALSE, 6),
('mercadopago', 'MercadoPago', '[Automático] YAPE | BCP | PAGO EFECTIVO | (Solo Perú) | Debit/Credit Card | Cash Payment', 10.00, 1000.00, TRUE, FALSE, 7),
('cryptomus', 'Cryptomus', 'Cryptomus | USDT, BTC, ETH, etc. [Automático] Min $1', 10.00, 1000.00, TRUE, FALSE, 8),
('manual', 'Manual Payment', 'Pagos Manuales | Wsp: +51 913742547 | Min $10 USD', 10.00, 100.00, TRUE, FALSE, 9),
('hotmart', 'Hotmart Checkout', '[Automático] Tarjeta, Transferencias, Pago Efectivo, etc. (Mundial) | Recarga con Visa, Mastercard, etc [Automático]', 10.00, 10.00, TRUE, FALSE, 10),
('veripagos', 'Veripagos QR', 'Veripagos QR | Pago con QR Bolivia', 10.00, 1000.00, TRUE, FALSE, 11);

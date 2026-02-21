<?php
/**
 * Agrega la columna 'oferta' a la tabla cuentas.
 * Ejecutar una vez: abrir en el navegador o desde consola: php add_oferta_column.php
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

echo "<h1>Agregar columna Oferta</h1>";

try {
    $pdo = getConnection();
    $stmt = $pdo->query("SHOW COLUMNS FROM cuentas LIKE 'oferta'");
    if ($stmt->fetch()) {
        echo "<p style='color:green;'>✅ La columna 'oferta' ya existe.</p>";
    } else {
        $pdo->exec("ALTER TABLE cuentas ADD COLUMN oferta TINYINT(1) NOT NULL DEFAULT 0 AFTER tipo_cuenta");
        echo "<p style='color:green;'>✅ Columna 'oferta' agregada correctamente.</p>";
    }
} catch (PDOException $e) {
    echo "<p style='color:red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "<p><a href='admin.php'>Volver al Admin</a></p>";

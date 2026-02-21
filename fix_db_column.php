<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

echo "<h1>Diagnóstico de Base de Datos</h1>";
echo "<p>Intentando agregar la columna 'tipo_cuenta'...</p>";

try {
    $pdo = getConnection();
    
    // Verificar si existe la columna
    $stmt = $pdo->query("SHOW COLUMNS FROM cuentas LIKE 'tipo_cuenta'");
    if ($stmt->fetch()) {
        echo "<p style='color:green;'>✅ La columna 'tipo_cuenta' ya existe. Todo correcto.</p>";
    } else {
        // Ejecutar ALTER TABLE
        $sql = "ALTER TABLE cuentas ADD COLUMN tipo_cuenta ENUM('completa', 'perfil') DEFAULT 'completa' AFTER precio";
        $pdo->exec($sql);
        echo "<p style='color:green;'>✅ Columna 'tipo_cuenta' agregada exitosamente.</p>";
    }
} catch (PDOException $e) {
    echo "<p style='color:red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><a href='admin.php'>Volver al Admin</a></p>";
?>

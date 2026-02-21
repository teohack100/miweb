-- Añadir columna perfiles_disponibles a cuentas (para cuentas tipo Perfil)
-- Ejecutar una vez si la tabla cuentas ya existe sin esta columna.
-- Si la columna ya existe, MySQL mostrará error; puedes ignorarlo.

ALTER TABLE cuentas
ADD COLUMN perfiles_disponibles INT NULL DEFAULT NULL
COMMENT 'Número de perfiles/pantallas disponibles (solo para tipo_cuenta=perfil)'
AFTER tipo_cuenta;

-- Migración: agrega columna precio_pro (opcional) a la tabla cuentas
ALTER TABLE `cuentas`
    ADD COLUMN `precio_pro` DECIMAL(10,2) NULL DEFAULT NULL
    COMMENT 'Precio especial Pro (opcional). NULL = no aplica precio pro.'
    AFTER `precio`;

-- =====================================================================
-- PAPC | Reversión de la migración 003
-- Vuelve a dejar nombre_usuario como columna normal (VARCHAR(100) NOT NULL),
-- con el nombre completo que tenía cada cuenta, y elimina las columnas
-- nombres_usuario, apellido_paterno_usuario y apellido_materno_usuario.
--
-- ADVERTENCIA: se pierde la separación en nombres y apellidos. Respaldar
-- antes (phpMyAdmin > Exportar). El código que usa la migración 003 deja de
-- funcionar: hay que regresar también a la versión anterior del código.
-- =====================================================================

-- 1. Copia temporal del nombre completo calculado.
ALTER TABLE usuario ADD COLUMN nombre_respaldo_usuario VARCHAR(100) NULL;
UPDATE usuario SET nombre_respaldo_usuario = nombre_usuario;

-- 2. Quita la columna calculada, la restricción, el índice y las partes.
ALTER TABLE usuario
  DROP CONSTRAINT chk_usuario_nombre_usuario,
  DROP INDEX idx_usuario_apellido_paterno_usuario,
  DROP COLUMN nombre_usuario;

ALTER TABLE usuario
  DROP COLUMN apellido_materno_usuario,
  DROP COLUMN apellido_paterno_usuario,
  DROP COLUMN nombres_usuario;

-- 3. Restaura nombre_usuario como en el esquema 1.2.
ALTER TABLE usuario
  CHANGE COLUMN nombre_respaldo_usuario nombre_usuario VARCHAR(100) NOT NULL AFTER id_usuario;

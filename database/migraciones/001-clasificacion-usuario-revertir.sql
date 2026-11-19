-- =====================================================================
-- PAPC | Reversión de la migración 001
-- ADVERTENCIA: elimina la clasificación ALUMNO/PROFESOR y el estado de
-- verificación de todas las cuentas. Respaldar antes:
--   C:\xampp\mysql\bin\mysqldump.exe -h 127.0.0.1 -P 3307 --protocol=TCP -u usuario_equipo_7 -p equipo_7 usuario > respaldo_usuario_AAAAMMDD.sql
-- El código del módulo de autenticación requiere estas columnas: después
-- de revertir, la aplicación dejará de funcionar hasta volver a migrar.
-- =====================================================================

ALTER TABLE usuario
  DROP INDEX idx_usuario_estado_verificacion_usuario,
  DROP COLUMN version_sesion_usuario,
  DROP COLUMN estado_verificacion_usuario,
  DROP COLUMN tipo_integrante_usuario;

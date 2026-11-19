-- =====================================================================
-- PAPC | Cuenta MariaDB de la aplicación con mínimos privilegios
-- Ejecutar como root UNA vez, después de schema.sql y las migraciones.
--
-- SITUACIÓN EN EL SERVIDOR DEL LABORATORIO: la institución asignó la base
-- equipo_7 y la cuenta usuario_equipo_7 (con todos los permisos sobre esa
-- base), y el equipo no tiene acceso a root. Por eso la aplicación usa hoy
-- usuario_equipo_7. Este script queda listo para que el profesor (o quien
-- administre MariaDB) cree una cuenta restringida si es posible.
--
-- ANTES DE EJECUTAR: reemplaza CAMBIAR_POR_CONTRASENA_LARGA_Y_ALEATORIA por
-- una contraseña generada (por ejemplo, con:
--   C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(24));"
-- ), cópiala en el .env (DB_USUARIO=papc_app y DB_CONTRASENA) y NO guardes
-- la contraseña real en este archivo ni en el repositorio.
-- Si la conexión llega como 127.0.0.1 y MariaDB no la resuelve como
-- 'localhost', repite las sentencias con 'papc_app'@'127.0.0.1'.
--
-- Principio: la aplicación no necesita DROP, ALTER, CREATE ni GRANT.
--   * auditoria: solo SELECT e INSERT  -> bitácora de solo anexado.
--   * usuario:   SELECT, INSERT, UPDATE -> no puede borrar cuentas.
--   * intento_acceso: también DELETE (purga de intentos antiguos).
--   * solicitud_acceso, token_solicitud, token_cuenta: SELECT, INSERT,
--     UPDATE (los tokens se invalidan marcando su fecha de consumo; no se
--     borran, para conservar el rastro).
--   * codigo_recuperacion_mfa: también DELETE (al regenerar los códigos).
-- Las tablas de avisos/comentarios/favoritos/propuestas se agregarán
-- cuando se implementen esos módulos.
-- =====================================================================

CREATE USER 'papc_app'@'localhost' IDENTIFIED BY 'CAMBIAR_POR_CONTRASENA_LARGA_Y_ALEATORIA';

GRANT SELECT, INSERT, UPDATE ON equipo_7.usuario TO 'papc_app'@'localhost';
GRANT SELECT, INSERT ON equipo_7.auditoria TO 'papc_app'@'localhost';
GRANT SELECT, INSERT, DELETE ON equipo_7.intento_acceso TO 'papc_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON equipo_7.solicitud_acceso TO 'papc_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON equipo_7.token_solicitud TO 'papc_app'@'localhost';
GRANT SELECT, INSERT, UPDATE ON equipo_7.token_cuenta TO 'papc_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON equipo_7.codigo_recuperacion_mfa TO 'papc_app'@'localhost';

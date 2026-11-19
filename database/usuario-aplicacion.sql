-- =====================================================================
-- PAPC | Cuenta MariaDB de la aplicación con mínimos privilegios
-- Ejecutar como root UNA vez, después de schema.sql y las migraciones.
--
-- ANTES DE EJECUTAR: reemplaza CAMBIAR_POR_CONTRASENA_LARGA_Y_ALEATORIA por
-- una contraseña generada (por ejemplo, con:
--   php -r "echo bin2hex(random_bytes(24));"
-- ) y cópiala en el .env (DB_CONTRASENA). NO guardes la contraseña real en
-- este archivo ni en el repositorio.
--
-- Principio: la aplicación no necesita DROP, ALTER, CREATE ni GRANT.
--   * auditoria: solo SELECT e INSERT  -> bitácora de solo anexado.
--   * usuario:   SELECT, INSERT, UPDATE -> no puede borrar cuentas.
--   * intento_acceso: también DELETE (purga de intentos antiguos).
-- Las tablas de avisos/comentarios/favoritos/propuestas se agregarán
-- cuando se implementen esos módulos.
-- =====================================================================

CREATE USER 'papc_app'@'localhost' IDENTIFIED BY 'CAMBIAR_POR_CONTRASENA_LARGA_Y_ALEATORIA';

GRANT SELECT, INSERT, UPDATE ON papc_db.usuario TO 'papc_app'@'localhost';
GRANT SELECT, INSERT ON papc_db.auditoria TO 'papc_app'@'localhost';
GRANT SELECT, INSERT, DELETE ON papc_db.intento_acceso TO 'papc_app'@'localhost';

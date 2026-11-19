-- =====================================================================
-- PAPC | Migración 002: tabla intento_acceso (limitación de intentos)
-- =====================================================================
--
-- QUÉ AGREGA
--   Tabla nueva intento_acceso. No modifica tablas existentes.
--
-- POR QUÉ ES NECESARIA
--   OWASP Authentication Cheat Sheet exige limitar intentos fallidos por
--   cuenta y por origen. Guardarlo en la sesión no sirve (el atacante
--   simplemente descarta la cookie), por eso se persiste en el servidor.
--
-- DISEÑO Y PRIVACIDAD
--   * No se guarda el correo ni la IP en claro: se guarda una "huella"
--     HMAC-SHA256 (64 caracteres hex) calculada con CLAVE_HUELLAS del .env.
--   * No hay FK hacia usuario a propósito: también se registran intentos
--     contra correos inexistentes, y así el comportamiento es idéntico
--     exista o no la cuenta (evita enumeración).
--   * Solo se guardan intentos FALLIDOS. El bloqueo es temporal (ventana
--     deslizante): no existen bloqueos permanentes que permitan a un tercero
--     dejar sin acceso a un usuario legítimo de forma indefinida.
--   * Los índices compuestos (huella, fecha) aceleran el conteo por ventana.
--
-- EFECTO SOBRE DATOS EXISTENTES: ninguno.
-- REVERSIÓN: database/migraciones/002-intento-acceso-revertir.sql
-- MANTENIMIENTO: la aplicación purga registros con más de 1 día.
-- =====================================================================

CREATE TABLE intento_acceso (
  id_intento_acceso BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  huella_correo_intento_acceso CHAR(64) NOT NULL,
  huella_origen_intento_acceso CHAR(64) NOT NULL,
  fecha_intento_acceso TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_intento_acceso_huella_correo_intento_acceso (huella_correo_intento_acceso, fecha_intento_acceso),
  INDEX idx_intento_acceso_huella_origen_intento_acceso (huella_origen_intento_acceso, fecha_intento_acceso),
  INDEX idx_intento_acceso_fecha_intento_acceso (fecha_intento_acceso)
) ENGINE=InnoDB;

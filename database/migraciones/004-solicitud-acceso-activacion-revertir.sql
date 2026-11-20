-- =====================================================================
-- PAPC | Reversión de la migración 004
-- Ejecutar SOLO con respaldo previo. Orden de reversión: 004 -> 003 -> 002 -> 001.
--
-- EFECTOS
--   * Se eliminan solicitudes, tokens y códigos de recuperación.
--   * Se pierden los estados de cuenta y la configuración del segundo factor.
--   * Una cuenta INVITADA no tiene contraseña; como hash_contrasena_usuario
--     vuelve a ser NOT NULL, se le asigna el marcador '!' (no es un hash
--     válido: esa cuenta NO podrá iniciar sesión).
--   * Las cuentas SUSPENDIDA o BAJA volverían a poder iniciar sesión con el
--     código anterior, que no conoce los estados. Por eso este script se
--     DETIENE (antes de borrar nada) si existe alguna cuenta INVITADA, SUSPENDIDA o
--     BAJA cuya contraseña siga siendo utilizable (hallazgo R-08).
--     Para continuar, con autorización y respaldo, ejecute antes
--       database/migraciones/004-preparar-reversion.sql
--     que deja esas cuentas sin contraseña utilizable y cierra sus sesiones.
--   * Si la migración 005 está aplicada, revierta primero la 005.
-- =====================================================================

-- 0. Precondición ejecutable: si falla el CHECK, el script se detiene aquí
--    con el error "CONSTRAINT chk_reversion_cuentas_restringidas failed".
DROP TEMPORARY TABLE IF EXISTS verificacion_reversion_004;
CREATE TEMPORARY TABLE verificacion_reversion_004 (
  cuentas_restringidas_utilizables INT NOT NULL,
  CONSTRAINT chk_reversion_cuentas_restringidas CHECK (cuentas_restringidas_utilizables = 0)
);
INSERT INTO verificacion_reversion_004 (cuentas_restringidas_utilizables)
SELECT COUNT(*) FROM usuario
 WHERE estado_cuenta_usuario IN ('INVITADA', 'SUSPENDIDA', 'BAJA')
   AND hash_contrasena_usuario IS NOT NULL AND hash_contrasena_usuario <> '!';
DROP TEMPORARY TABLE verificacion_reversion_004;

DROP TABLE codigo_recuperacion_mfa;
DROP TABLE token_cuenta;
DROP TABLE token_solicitud;
DROP TABLE solicitud_acceso;

UPDATE usuario SET hash_contrasena_usuario = '!' WHERE hash_contrasena_usuario IS NULL;

ALTER TABLE usuario
  DROP CONSTRAINT chk_usuario_hash_contrasena_usuario,
  DROP FOREIGN KEY fk_usuario_usuario_autorizador,
  DROP INDEX uq_usuario_identificador_institucional_usuario,
  DROP INDEX idx_usuario_estado_cuenta_usuario,
  DROP COLUMN estado_cuenta_usuario,
  DROP COLUMN identificador_institucional_usuario,
  DROP COLUMN fecha_validacion_institucional_usuario,
  DROP COLUMN id_usuario_autorizador,
  DROP COLUMN secreto_mfa_usuario,
  DROP COLUMN fecha_activacion_mfa_usuario,
  DROP COLUMN ultimo_paso_mfa_usuario,
  MODIFY COLUMN hash_contrasena_usuario VARCHAR(255) NOT NULL;

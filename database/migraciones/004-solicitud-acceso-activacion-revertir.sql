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
--     código anterior. Revísalas antes de revertir:
--       SELECT id_usuario, correo_electronico_usuario, estado_cuenta_usuario
--         FROM usuario WHERE estado_cuenta_usuario <> 'ACTIVA';
-- =====================================================================

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

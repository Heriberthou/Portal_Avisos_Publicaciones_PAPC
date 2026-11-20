-- =====================================================================
-- PAPC | Reversión de la migración 005
-- Ejecutar SOLO con respaldo previo. Orden: 005 -> 004 -> 003 -> 002 -> 001.
--
-- EFECTOS
--   * Se eliminan los enlaces de enrolamiento del segundo factor.
--   * El valor predeterminado de estado_cuenta_usuario vuelve a ACTIVA.
--   * Se reactivan SOLO las cuentas que la 005 suspendió (auditoría
--     SUSPENSION_TRANSICION_005) y que siguen SUSPENDIDAS sin validación
--     institucional. Una cuenta suspendida después por un administrador
--     NO se reactiva.
-- =====================================================================

DELETE FROM token_cuenta WHERE finalidad_token_cuenta = 'ENROLAMIENTO_MFA';

ALTER TABLE token_cuenta
  MODIFY COLUMN finalidad_token_cuenta ENUM('ACTIVACION','RECUPERACION') NOT NULL;

ALTER TABLE usuario
  ALTER COLUMN estado_cuenta_usuario SET DEFAULT 'ACTIVA';

UPDATE usuario u
   SET u.estado_cuenta_usuario = 'ACTIVA', u.version_sesion_usuario = u.version_sesion_usuario + 1
 WHERE u.estado_cuenta_usuario = 'SUSPENDIDA'
   AND u.fecha_validacion_institucional_usuario IS NULL
   AND EXISTS (SELECT 1 FROM auditoria a
                WHERE a.accion_auditoria = 'SUSPENSION_TRANSICION_005' AND a.id_registro_afectado = u.id_usuario)
   AND NOT EXISTS (SELECT 1 FROM auditoria b
                    WHERE b.accion_auditoria = 'CAMBIO_ESTADO_CUENTA' AND b.id_registro_afectado = u.id_usuario
                      AND b.id_auditoria > (SELECT MAX(c.id_auditoria) FROM auditoria c
                                             WHERE c.accion_auditoria = 'SUSPENSION_TRANSICION_005'
                                               AND c.id_registro_afectado = u.id_usuario));

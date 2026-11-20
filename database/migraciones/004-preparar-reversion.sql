-- =====================================================================
-- PAPC | Preparación AUTORIZADA para revertir la migración 004 (R-08)
--
-- Ejecutar solo si la persona responsable decidió revertir la 004 y con
-- respaldo previo. Conserva la imposibilidad de acceso de las cuentas
-- INVITADA, SUSPENDIDA y BAJA cuando desaparezca la columna de estado:
--   * Guarda en auditoria qué cuentas estaban restringidas (evidencia).
--   * Sustituye su hash por el marcador '!' (no es un hash válido: ninguna
--     contraseña coincide) y cierra sus sesiones.
-- Si después se vuelve a aplicar la 004, esas cuentas recuperan el acceso
-- solo con "¿Olvidó su contraseña?" una vez que un administrador las reactive.
--
-- Revise primero la lista:
--   SELECT id_usuario, correo_electronico_usuario, estado_cuenta_usuario
--     FROM usuario WHERE estado_cuenta_usuario IN ('INVITADA','SUSPENDIDA','BAJA');
-- =====================================================================

INSERT INTO auditoria (id_usuario_actor, accion_auditoria, entidad_afectada_auditoria, id_registro_afectado, detalle_auditoria)
SELECT NULL, 'BLOQUEO_PREVIO_REVERSION_004', 'usuario', id_usuario,
       CONCAT('{"estadoCuenta":"', estado_cuenta_usuario, '","canal":"MIGRACION"}')
  FROM usuario
 WHERE estado_cuenta_usuario IN ('INVITADA', 'SUSPENDIDA', 'BAJA')
   AND hash_contrasena_usuario IS NOT NULL AND hash_contrasena_usuario <> '!';

UPDATE usuario
   SET hash_contrasena_usuario = '!', version_sesion_usuario = version_sesion_usuario + 1
 WHERE estado_cuenta_usuario IN ('INVITADA', 'SUSPENDIDA', 'BAJA')
   AND hash_contrasena_usuario IS NOT NULL AND hash_contrasena_usuario <> '!';

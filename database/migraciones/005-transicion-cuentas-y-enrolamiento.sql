-- =====================================================================
-- PAPC | Migración 005: transición de cuentas heredadas y autorización
--        del enrolamiento del segundo factor
-- Aplica sobre: esquema 1.2 + migraciones 001 a 004
-- Motor: MariaDB 10.4+ (XAMPP) / InnoDB
-- Se puede pegar completa en phpMyAdmin (base equipo_7 > pestaña SQL).
-- Responde a los hallazgos R-02 y R-03 de docs/revision-codex.md.
-- =====================================================================
--
-- POR QUÉ
--   R-03: la migración 004 dejó ACTIVA toda cuenta existente, aunque se
--         hubiera creado con el registro abierto anterior y nadie hubiera
--         validado su pertenencia institucional (SEG-01).
--   R-02: un administrador sin segundo factor podía configurarlo solo con su
--         contraseña. Ahora se exige además un enlace de un solo uso
--         (finalidad ENROLAMIENTO_MFA) entregado por un canal independiente.
--
-- QUÉ HACE
--   1. Toda cuenta ACTIVA sin validación institucional registrada
--      (fecha_validacion_institucional_usuario IS NULL) pasa a SUSPENDIDA y
--      se cierran sus sesiones. NO se borra ningún dato: un administrador la
--      revisa y, si corresponde, la reactiva (la aplicación registra en ese
--      momento la validación y quién la autorizó). El primer principal se
--      habilita desde la consola (herramientas/inicializar-administrador-principal.php).
--   2. Cada cuenta suspendida por esta migración queda en auditoria como
--      SUSPENSION_TRANSICION_005 (permite revertir con precisión).
--   3. El valor predeterminado de estado_cuenta_usuario pasa a INVITADA:
--      si algún código anterior inserta una cuenta sin indicar estado, esa
--      cuenta NO puede iniciar sesión.
--   4. token_cuenta admite la finalidad ENROLAMIENTO_MFA.
--
-- ANTES DE APLICAR
--   * Respaldo obligatorio (phpMyAdmin > equipo_7 > Exportar).
--   * Lista de cuentas que se suspenderán:
--       SELECT id_usuario, nombre_usuario, correo_electronico_usuario, rol_usuario
--         FROM usuario WHERE estado_cuenta_usuario = 'ACTIVA'
--          AND fecha_validacion_institucional_usuario IS NULL;
--   * Después de aplicarla, habilite al principal desde la consola y revise
--     las demás cuentas en /admin/usuarios ("Validar y reactivar").
--
-- REVERSIÓN: database/migraciones/005-transicion-cuentas-y-enrolamiento-revertir.sql
-- =====================================================================

-- 1 y 2. Cuentas heredadas sin validación: auditoría y suspensión.
INSERT INTO auditoria (id_usuario_actor, accion_auditoria, entidad_afectada_auditoria, id_registro_afectado, detalle_auditoria)
SELECT NULL, 'SUSPENSION_TRANSICION_005', 'usuario', id_usuario,
       '{"motivo":"CUENTA_HEREDADA_SIN_VALIDACION_INSTITUCIONAL","canal":"MIGRACION_005"}'
  FROM usuario
 WHERE estado_cuenta_usuario = 'ACTIVA' AND fecha_validacion_institucional_usuario IS NULL;

UPDATE usuario
   SET estado_cuenta_usuario = 'SUSPENDIDA', version_sesion_usuario = version_sesion_usuario + 1
 WHERE estado_cuenta_usuario = 'ACTIVA' AND fecha_validacion_institucional_usuario IS NULL;

-- 3. Una inserción sin estado explícito nunca crea una cuenta utilizable.
ALTER TABLE usuario
  ALTER COLUMN estado_cuenta_usuario SET DEFAULT 'INVITADA';

-- 4. Nueva finalidad de enlace: autorizar la configuración del segundo factor.
ALTER TABLE token_cuenta
  MODIFY COLUMN finalidad_token_cuenta ENUM('ACTIVACION','RECUPERACION','ENROLAMIENTO_MFA') NOT NULL;

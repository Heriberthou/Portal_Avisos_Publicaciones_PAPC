-- =====================================================================
-- PAPC | Migración 001: clasificación institucional y versión de sesión
-- Aplica sobre: database/schema.sql (estándar de nomenclatura 1.2)
-- Motor: MariaDB 10.4+ (XAMPP) / InnoDB
-- =====================================================================
--
-- QUÉ AGREGA
--   usuario.tipo_integrante_usuario      ENUM('ALUMNO','PROFESOR') NULL
--   usuario.estado_verificacion_usuario  ENUM('NO_REQUERIDA','PENDIENTE','VERIFICADA','RECHAZADA')
--   usuario.version_sesion_usuario       INT UNSIGNED (contador de invalidación de sesiones)
--   idx_usuario_estado_verificacion_usuario
--
-- POR QUÉ ES NECESARIA
--   * El esquema 1.2 solo tiene rol_usuario (rol de SEGURIDAD). El tipo de
--     integrante (ALUMNO/PROFESOR) es una CLASIFICACIÓN institucional distinta
--     y no debe mezclarse con el rol: ser PROFESOR no concede permisos.
--   * La condición de profesor es declarada por el navegador y no es evidencia
--     de identidad; queda PENDIENTE hasta que un administrador la resuelva.
--   * version_sesion_usuario permite invalidar todas las sesiones abiertas de
--     una cuenta cuando cambian su rol o su contraseña (OWASP Session
--     Management): cada solicitud compara la versión guardada en la sesión con
--     la de la base de datos.
--
-- RESTRICCIONES
--   * Los ENUM limitan los valores a constantes en MAYÚSCULAS (NOM-09).
--   * tipo_integrante_usuario admite NULL únicamente para cuentas que ya
--     existían antes de esta migración ("sin clasificar"). La aplicación
--     siempre lo asigna en los registros nuevos.
--
-- EFECTO SOBRE DATOS EXISTENTES
--   * No elimina ni modifica columnas originales.
--   * Filas existentes: tipo = NULL, estado = 'NO_REQUERIDA', versión = 1.
--   * ALTER TABLE reconstruye la tabla: ejecutar en ventana de mantenimiento.
--
-- RESPALDO PREVIO (obligatorio si hay datos):
--   C:\xampp\mysql\bin\mysqldump.exe -h 127.0.0.1 -P 3307 --protocol=TCP -u usuario_equipo_7 -p equipo_7 usuario > respaldo_usuario_AAAAMMDD.sql
-- REVERSIÓN:
--   database/migraciones/001-clasificacion-usuario-revertir.sql
-- =====================================================================

ALTER TABLE usuario
  ADD COLUMN tipo_integrante_usuario ENUM('ALUMNO','PROFESOR') NULL DEFAULT NULL
    AFTER rol_usuario,
  ADD COLUMN estado_verificacion_usuario ENUM('NO_REQUERIDA','PENDIENTE','VERIFICADA','RECHAZADA')
    NOT NULL DEFAULT 'NO_REQUERIDA'
    AFTER tipo_integrante_usuario,
  ADD COLUMN version_sesion_usuario INT UNSIGNED NOT NULL DEFAULT 1
    AFTER estado_verificacion_usuario,
  ADD INDEX idx_usuario_estado_verificacion_usuario (estado_verificacion_usuario);

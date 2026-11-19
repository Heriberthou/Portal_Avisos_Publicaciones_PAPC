-- =====================================================================
-- PAPC | Migración 003: nombre del usuario en partes atómicas
-- Aplica sobre: esquema 1.2 + migraciones 001 y 002
-- Motor: MariaDB 10.4+ (XAMPP) / InnoDB
-- Se puede pegar completa en phpMyAdmin (base equipo_7 > pestaña SQL).
-- =====================================================================
--
-- QUÉ AGREGA
--   usuario.nombres_usuario            VARCHAR(100) NOT NULL
--   usuario.apellido_paterno_usuario   VARCHAR(50)  NULL (*)
--   usuario.apellido_materno_usuario   VARCHAR(50)  NULL (opcional)
--   usuario.nombre_usuario             pasa a ser COLUMNA CALCULADA (VIRTUAL):
--                                      nombres + apellido paterno + apellido materno
--   chk_usuario_nombre_usuario         CHECK: el nombre completo cabe en 100 caracteres
--   idx_usuario_apellido_paterno_usuario
--
-- POR QUÉ
--   * Atomicidad (1FN): el nombre completo en un solo campo impide ordenar o
--     buscar por apellido y validar cada parte. En México el formato
--     institucional es nombres + apellido paterno + apellido materno.
--   * nombre_usuario se CONSERVA con el mismo nombre y tipo VARCHAR(100) que
--     en la Fase I, pero ya no se escribe: MariaDB lo calcula. Así no hay
--     datos duplicados y los módulos que lo lean siguen funcionando.
--   * Apellido materno opcional: muchas personas tienen un solo apellido.
--   * (*) apellido_paterno_usuario admite NULL SOLO para cuentas que ya
--     existían; la aplicación lo exige en todos los registros nuevos
--     (mismo criterio que tipo_integrante_usuario en la migración 001).
--
-- EFECTO SOBRE DATOS EXISTENTES
--   * Un nombre completo no se puede dividir de forma confiable
--     ("María José de la Garza"). Por eso cada cuenta existente conserva su
--     nombre completo en nombres_usuario y queda con los apellidos en NULL.
--     El nombre visible (nombre_usuario) NO cambia.
--   * Corrige esas cuentas a mano después de migrar, por ejemplo:
--       UPDATE usuario SET nombres_usuario = 'Nombre(s)',
--              apellido_paterno_usuario = 'Paterno',
--              apellido_materno_usuario = 'Materno'
--        WHERE correo_electronico_usuario = 'correo@dominio.mx';
--     y lista las pendientes con:
--       SELECT id_usuario, nombre_usuario, correo_electronico_usuario
--         FROM usuario WHERE apellido_paterno_usuario IS NULL;
--
-- ORDEN EN EL SERVIDOR: ejecutar esta migración y, enseguida, subir el
-- código que la usa. El código anterior no puede registrar cuentas sobre
-- la base migrada, y el código nuevo no funciona sin esta migración.
--
-- RESPALDO PREVIO (obligatorio): phpMyAdmin > equipo_7 > Exportar, o
--   C:\xampp\mysql\bin\mysqldump.exe -h 127.0.0.1 -P 3307 --protocol=TCP -u usuario_equipo_7 -p equipo_7 usuario > respaldo_usuario_AAAAMMDD.sql
-- REVERSIÓN: database/migraciones/003-nombre-atomico-usuario-revertir.sql
-- =====================================================================

-- 1. Columnas nuevas (temporalmente NULL para poder copiar los datos).
ALTER TABLE usuario
  ADD COLUMN nombres_usuario VARCHAR(100) NULL AFTER id_usuario,
  ADD COLUMN apellido_paterno_usuario VARCHAR(50) NULL AFTER nombres_usuario,
  ADD COLUMN apellido_materno_usuario VARCHAR(50) NULL AFTER apellido_paterno_usuario;

-- 2. Las cuentas existentes conservan su nombre completo en nombres_usuario.
UPDATE usuario SET nombres_usuario = nombre_usuario;

-- 3. nombres_usuario pasa a ser obligatorio; nombre_usuario se vuelve calculado.
ALTER TABLE usuario
  MODIFY COLUMN nombres_usuario VARCHAR(100) NOT NULL,
  DROP COLUMN nombre_usuario;

ALTER TABLE usuario
  ADD COLUMN nombre_usuario VARCHAR(100)
    AS (CONCAT_WS(' ', nombres_usuario, apellido_paterno_usuario, apellido_materno_usuario)) VIRTUAL
    AFTER apellido_materno_usuario,
  ADD CONSTRAINT chk_usuario_nombre_usuario
    CHECK (CHAR_LENGTH(CONCAT_WS(' ', nombres_usuario, apellido_paterno_usuario, apellido_materno_usuario)) <= 100),
  ADD INDEX idx_usuario_apellido_paterno_usuario (apellido_paterno_usuario);

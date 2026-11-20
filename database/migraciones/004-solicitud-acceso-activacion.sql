-- =====================================================================
-- PAPC | Migración 004: solicitud de acceso, activación, estados de cuenta
--        y segundo factor (especificación versión 1.3)
-- Aplica sobre: esquema 1.2 + migraciones 001, 002 y 003
-- Motor: MariaDB 10.4+ (XAMPP) / InnoDB
-- Se puede pegar completa en phpMyAdmin (base equipo_7 > pestaña SQL).
-- =====================================================================
--
-- POR QUÉ
--   La versión 1.3 sustituye "Registrarse" (alta inmediata) por
--   "Solicitar acceso": solicitud -> verificación del correo -> aprobación
--   de un administrador -> cuenta INVITADA -> activación con contraseña
--   propia -> cuenta ACTIVA. Ninguna persona obtiene una cuenta activa por
--   llenar un formulario público.
--
-- QUÉ AGREGA
--   usuario (extensión)
--     estado_cuenta_usuario                  INVITADA | ACTIVA | SUSPENDIDA | BAJA
--     identificador_institucional_usuario    matrícula o número de empleado (UNIQUE, NULL en cuentas previas)
--     fecha_validacion_institucional_usuario cuándo se validó la pertenencia
--     id_usuario_autorizador                 administrador que aprobó (FK)
--     secreto_mfa_usuario                    secreto TOTP CIFRADO (AES-256-GCM), nunca en claro
--     fecha_activacion_mfa_usuario           cuándo se configuró el segundo factor
--     ultimo_paso_mfa_usuario                último código TOTP aceptado (impide reutilizarlo)
--     hash_contrasena_usuario                pasa a admitir NULL: una cuenta INVITADA aún no
--                                            tiene contraseña. El CHECK obliga a que toda cuenta
--                                            ACTIVA tenga hash.
--   solicitud_acceso         peticiones públicas (NO son cuentas)
--   token_solicitud          enlaces para verificar el correo de una solicitud
--   token_cuenta             enlaces de ACTIVACION y RECUPERACION de una cuenta
--   codigo_recuperacion_mfa  códigos de un solo uso por si se pierde el autenticador
--
-- DECISIONES DE SEGURIDAD
--   * Los tokens y códigos se guardan como hash SHA-256 / HMAC; el valor en
--     claro solo viaja en el enlace o se muestra una vez.
--   * Las fechas de expiración son DATETIME y se comparan con NOW() de
--     MariaDB (evita desfases de zona horaria entre PHP y la base).
--   * correo_pendiente_solicitud es una columna calculada con UNIQUE: impide,
--     aun con dos envíos simultáneos, que existan dos solicitudes PENDIENTES
--     con el mismo correo.
--   * Las FK usan ON DELETE RESTRICT: no se borran en cascada cuentas con
--     historial (las bajas son lógicas: estado BAJA).
--
-- EFECTO SOBRE DATOS EXISTENTES
--   * Las cuentas existentes quedan ACTIVA (ya tienen contraseña).
--   * Las cuentas ADMINISTRADOR y ADMINISTRADOR_PRINCIPAL deberán configurar
--     el segundo factor en su siguiente inicio de sesión.
--   * identificador_institucional_usuario queda NULL en las cuentas previas.
--
-- ORDEN EN EL SERVIDOR: ejecutar esta migración y enseguida actualizar el
-- código (git pull). El código anterior no conoce estado_cuenta_usuario.
--
-- RESPALDO PREVIO (obligatorio): phpMyAdmin > equipo_7 > Exportar, o
--   C:\xampp\mysql\bin\mysqldump.exe -h 127.0.0.1 -P 3307 --protocol=TCP -u usuario_equipo_7 -p equipo_7 > respaldo_equipo_7_AAAAMMDD.sql
-- REVERSIÓN: database/migraciones/004-solicitud-acceso-activacion-revertir.sql
-- =====================================================================

-- 1. Extensión de usuario.
ALTER TABLE usuario
  MODIFY COLUMN hash_contrasena_usuario VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN estado_cuenta_usuario ENUM('INVITADA','ACTIVA','SUSPENDIDA','BAJA') NOT NULL DEFAULT 'ACTIVA'
    AFTER rol_usuario,
  ADD COLUMN identificador_institucional_usuario VARCHAR(20) NULL DEFAULT NULL
    AFTER tipo_integrante_usuario,
  ADD COLUMN fecha_validacion_institucional_usuario TIMESTAMP NULL DEFAULT NULL
    AFTER identificador_institucional_usuario,
  ADD COLUMN id_usuario_autorizador INT UNSIGNED NULL DEFAULT NULL
    AFTER fecha_validacion_institucional_usuario,
  ADD COLUMN secreto_mfa_usuario VARCHAR(255) NULL DEFAULT NULL
    AFTER version_sesion_usuario,
  ADD COLUMN fecha_activacion_mfa_usuario TIMESTAMP NULL DEFAULT NULL
    AFTER secreto_mfa_usuario,
  ADD COLUMN ultimo_paso_mfa_usuario BIGINT UNSIGNED NULL DEFAULT NULL
    AFTER fecha_activacion_mfa_usuario,
  ADD CONSTRAINT uq_usuario_identificador_institucional_usuario UNIQUE (identificador_institucional_usuario),
  ADD CONSTRAINT fk_usuario_usuario_autorizador FOREIGN KEY (id_usuario_autorizador)
    REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
  ADD CONSTRAINT chk_usuario_hash_contrasena_usuario
    CHECK (estado_cuenta_usuario <> 'ACTIVA' OR hash_contrasena_usuario IS NOT NULL),
  ADD INDEX idx_usuario_estado_cuenta_usuario (estado_cuenta_usuario);

-- 2. Solicitudes de acceso (no son cuentas: no tienen contraseña ni rol).
CREATE TABLE solicitud_acceso (
  id_solicitud_acceso INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombres_solicitante VARCHAR(60) NOT NULL,
  apellido_paterno_solicitante VARCHAR(50) NOT NULL,
  apellido_materno_solicitante VARCHAR(50) NULL DEFAULT NULL,
  correo_electronico_solicitante VARCHAR(150) NOT NULL,
  tipo_integrante_solicitado ENUM('ALUMNO','PROFESOR') NOT NULL,
  identificador_institucional_solicitante VARCHAR(20) NOT NULL,
  estado_solicitud ENUM('PENDIENTE','APROBADA','RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
  fecha_creacion_solicitud TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_verificacion_correo_solicitud TIMESTAMP NULL DEFAULT NULL,
  id_usuario_revisor INT UNSIGNED NULL DEFAULT NULL,
  fecha_resolucion_solicitud TIMESTAMP NULL DEFAULT NULL,
  metodo_verificacion_solicitud VARCHAR(120) NULL DEFAULT NULL,
  motivo_resolucion_solicitud VARCHAR(500) NULL DEFAULT NULL,
  id_usuario_vinculado INT UNSIGNED NULL DEFAULT NULL,
  correo_pendiente_solicitud VARCHAR(150)
    AS (IF(estado_solicitud = 'PENDIENTE', correo_electronico_solicitante, NULL)) PERSISTENT,
  CONSTRAINT fk_solicitud_acceso_usuario_revisor FOREIGN KEY (id_usuario_revisor)
    REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
  CONSTRAINT fk_solicitud_acceso_usuario_vinculado FOREIGN KEY (id_usuario_vinculado)
    REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
  CONSTRAINT uq_solicitud_acceso_correo_pendiente_solicitud UNIQUE (correo_pendiente_solicitud),
  CONSTRAINT uq_solicitud_acceso_id_usuario_vinculado UNIQUE (id_usuario_vinculado),
  CONSTRAINT chk_solicitud_acceso_estado_solicitud
    CHECK (estado_solicitud = 'PENDIENTE' OR (id_usuario_revisor IS NOT NULL AND fecha_resolucion_solicitud IS NOT NULL)),
  CONSTRAINT chk_solicitud_acceso_id_usuario_vinculado
    CHECK (estado_solicitud <> 'APROBADA' OR (id_usuario_vinculado IS NOT NULL AND fecha_verificacion_correo_solicitud IS NOT NULL)),
  CONSTRAINT chk_solicitud_acceso_motivo_resolucion_solicitud
    CHECK (estado_solicitud <> 'RECHAZADA' OR motivo_resolucion_solicitud IS NOT NULL),
  INDEX idx_solicitud_acceso_estado_solicitud (estado_solicitud, fecha_creacion_solicitud),
  INDEX idx_solicitud_acceso_correo_electronico_solicitante (correo_electronico_solicitante)
) ENGINE=InnoDB;

-- 3. Enlaces de verificación de correo de una solicitud.
CREATE TABLE token_solicitud (
  id_token_solicitud INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_solicitud_acceso_destinataria INT UNSIGNED NOT NULL,
  hash_token_solicitud CHAR(64) NOT NULL,
  fecha_creacion_token_solicitud TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_expiracion_token_solicitud DATETIME NOT NULL,
  fecha_consumo_token_solicitud DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_token_solicitud_solicitud_acceso_destinataria FOREIGN KEY (id_solicitud_acceso_destinataria)
    REFERENCES solicitud_acceso(id_solicitud_acceso) ON DELETE RESTRICT,
  CONSTRAINT uq_token_solicitud_hash_token_solicitud UNIQUE (hash_token_solicitud)
) ENGINE=InnoDB;

-- 4. Enlaces de activación y recuperación de una cuenta.
CREATE TABLE token_cuenta (
  id_token_cuenta INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario_destinatario INT UNSIGNED NOT NULL,
  hash_token_cuenta CHAR(64) NOT NULL,
  finalidad_token_cuenta ENUM('ACTIVACION','RECUPERACION') NOT NULL,
  fecha_creacion_token_cuenta TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_expiracion_token_cuenta DATETIME NOT NULL,
  fecha_consumo_token_cuenta DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_token_cuenta_usuario_destinatario FOREIGN KEY (id_usuario_destinatario)
    REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
  CONSTRAINT uq_token_cuenta_hash_token_cuenta UNIQUE (hash_token_cuenta),
  INDEX idx_token_cuenta_finalidad_token_cuenta (id_usuario_destinatario, finalidad_token_cuenta)
) ENGINE=InnoDB;

-- 5. Códigos de recuperación del segundo factor (solo se guarda su HMAC).
CREATE TABLE codigo_recuperacion_mfa (
  id_codigo_recuperacion_mfa INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario_propietario INT UNSIGNED NOT NULL,
  hash_codigo_recuperacion_mfa CHAR(64) NOT NULL,
  fecha_creacion_codigo_recuperacion_mfa TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_uso_codigo_recuperacion_mfa DATETIME NULL DEFAULT NULL,
  CONSTRAINT fk_codigo_recuperacion_mfa_usuario_propietario FOREIGN KEY (id_usuario_propietario)
    REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
  CONSTRAINT uq_codigo_recuperacion_mfa_hash_codigo_recuperacion_mfa UNIQUE (hash_codigo_recuperacion_mfa)
) ENGINE=InnoDB;

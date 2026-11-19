-- PAPC | Esquema propuesto conforme al estándar de nomenclatura 1.2
-- MariaDB / MySQL (InnoDB). Ejecutar únicamente en entorno autorizado y vacío.
-- No incluye cuentas, contraseñas ni registros de prueba.
-- CREATE TABLE IF NOT EXISTS se evita para NO ocultar diferencias de estructura.
USE equipo_7;

CREATE TABLE usuario (
  id_usuario INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre_usuario VARCHAR(100) NOT NULL,
  correo_electronico_usuario VARCHAR(150) NOT NULL,
  hash_contrasena_usuario VARCHAR(255) NOT NULL,
  rol_usuario ENUM('USUARIO','ADMINISTRADOR','ADMINISTRADOR_PRINCIPAL') NOT NULL DEFAULT 'USUARIO',
  fecha_registro_usuario TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uq_usuario_correo_electronico_usuario UNIQUE (correo_electronico_usuario)
) ENGINE=InnoDB;

CREATE TABLE propuesta_aviso (
  id_propuesta INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario_solicitante INT UNSIGNED NOT NULL,
  titulo_propuesta VARCHAR(200) NOT NULL,
  contenido_propuesta TEXT NOT NULL,
  categoria_propuesta VARCHAR(60) NOT NULL,
  estado_propuesta ENUM('PENDIENTE','APROBADA','RECHAZADA') NOT NULL DEFAULT 'PENDIENTE',
  id_usuario_revisor INT UNSIGNED NULL,
  motivo_revision_propuesta TEXT NULL,
  fecha_envio_propuesta TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_revision_propuesta TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT fk_propuesta_aviso_usuario_solicitante FOREIGN KEY (id_usuario_solicitante) REFERENCES usuario(id_usuario) ON DELETE RESTRICT,
  CONSTRAINT fk_propuesta_aviso_usuario_revisor FOREIGN KEY (id_usuario_revisor) REFERENCES usuario(id_usuario) ON DELETE SET NULL,
  INDEX idx_propuesta_aviso_estado_propuesta (estado_propuesta)
) ENGINE=InnoDB;

CREATE TABLE aviso (
  id_aviso INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo_aviso VARCHAR(200) NOT NULL,
  contenido_aviso TEXT NOT NULL,
  categoria_aviso VARCHAR(60) NOT NULL,
  id_usuario_publicador INT UNSIGNED NULL,
  id_propuesta_origen INT UNSIGNED NULL,
  estado_aviso ENUM('BORRADOR','PUBLICADO') NOT NULL DEFAULT 'BORRADOR',
  fecha_publicacion_aviso TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT fk_aviso_usuario_publicador FOREIGN KEY (id_usuario_publicador) REFERENCES usuario(id_usuario) ON DELETE SET NULL,
  CONSTRAINT fk_aviso_propuesta_origen FOREIGN KEY (id_propuesta_origen) REFERENCES propuesta_aviso(id_propuesta) ON DELETE RESTRICT,
  CONSTRAINT uq_aviso_id_propuesta_origen UNIQUE (id_propuesta_origen),
  INDEX idx_aviso_estado_aviso (estado_aviso),
  INDEX idx_aviso_categoria_aviso (categoria_aviso)
) ENGINE=InnoDB;

CREATE TABLE comentario (
  id_comentario INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_aviso_comentado INT UNSIGNED NOT NULL,
  id_usuario_comentarista INT UNSIGNED NOT NULL,
  contenido_comentario TEXT NOT NULL,
  estado_comentario ENUM('VISIBLE','OCULTO') NOT NULL DEFAULT 'VISIBLE',
  fecha_creacion_comentario TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_edicion_comentario TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT fk_comentario_aviso_comentado FOREIGN KEY (id_aviso_comentado) REFERENCES aviso(id_aviso) ON DELETE CASCADE,
  CONSTRAINT fk_comentario_usuario_comentarista FOREIGN KEY (id_usuario_comentarista) REFERENCES usuario(id_usuario) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE favorito (
  id_favorito INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario_propietario INT UNSIGNED NOT NULL,
  id_aviso_favorito INT UNSIGNED NOT NULL,
  fecha_guardado_favorito TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_favorito_usuario_propietario FOREIGN KEY (id_usuario_propietario) REFERENCES usuario(id_usuario) ON DELETE CASCADE,
  CONSTRAINT fk_favorito_aviso_favorito FOREIGN KEY (id_aviso_favorito) REFERENCES aviso(id_aviso) ON DELETE CASCADE,
  CONSTRAINT uq_favorito_usuario_propietario_aviso_favorito UNIQUE (id_usuario_propietario,id_aviso_favorito)
) ENGINE=InnoDB;

CREATE TABLE auditoria (
  id_auditoria BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_usuario_actor INT UNSIGNED NULL,
  accion_auditoria VARCHAR(80) NOT NULL,
  entidad_afectada_auditoria VARCHAR(60) NOT NULL,
  id_registro_afectado INT UNSIGNED NULL,
  fecha_evento_auditoria TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  detalle_auditoria TEXT NULL,
  CONSTRAINT fk_auditoria_usuario_actor FOREIGN KEY (id_usuario_actor) REFERENCES usuario(id_usuario) ON DELETE SET NULL,
  INDEX idx_auditoria_fecha_evento_auditoria (fecha_evento_auditoria)
) ENGINE=InnoDB;

-- SEGURIDAD / ALCANCE DEL ESQUEMA:
-- 1. Solo la aplicación puede comprobar roles y pertenencia; no confiar en la interfaz.
-- 2. Aprobación de propuesta y creación de aviso: transacción PHP (commit / rollback).
-- 3. Estado APROBADA, revisión por administrador, visibilidad y cambios posteriores
--    requieren validaciones de negocio en PHP. UNIQUE y FK no cubren estas reglas.
-- 4. Aplicar consultas preparadas, tokens CSRF, sesiones seguras y hashing de contraseñas.
-- 5. Registrar actividades administrativas relevantes sin secretos en auditoria.
-- 6. Si ya existe un esquema 1.1, NO ejecute este CREATE TABLE sobre esas tablas:
--    prepare una migración ALTER TABLE con respaldo, verificación y aprobación.

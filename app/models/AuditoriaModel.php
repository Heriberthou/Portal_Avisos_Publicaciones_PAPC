<?php

declare(strict_types=1);

/**
 * Registro de eventos de seguridad en la tabla auditoria (esquema 1.2).
 *
 * Columnas usadas: id_usuario_actor, accion_auditoria,
 * entidad_afectada_auditoria, id_registro_afectado, detalle_auditoria.
 * fecha_evento_auditoria la asigna MariaDB.
 *
 * Nunca se guardan contraseñas, hashes, tokens ni identificadores de sesión
 * (OWASP Logging Cheat Sheet). La cuenta MariaDB de la aplicación solo tiene
 * SELECT e INSERT sobre esta tabla: la bitácora es de solo anexado.
 */
final class AuditoriaModel
{
    public const ACCION_REGISTRO_USUARIO = 'REGISTRO_USUARIO';
    public const ACCION_REGISTRO_RECHAZADO = 'REGISTRO_RECHAZADO';
    public const ACCION_INTENTO_ESCALADA_REGISTRO = 'INTENTO_ESCALADA_REGISTRO';
    public const ACCION_LOGIN_EXITOSO = 'LOGIN_EXITOSO';
    public const ACCION_LOGIN_FALLIDO = 'LOGIN_FALLIDO';
    public const ACCION_LOGIN_BLOQUEADO = 'LOGIN_BLOQUEADO';
    public const ACCION_CIERRE_SESION = 'CIERRE_SESION';
    public const ACCION_SESION_EXPIRADA = 'SESION_EXPIRADA';
    public const ACCION_ACCESO_DENEGADO = 'ACCESO_DENEGADO';
    public const ACCION_INTENTO_CAMBIO_PRIVILEGIO_DENEGADO = 'INTENTO_CAMBIO_PRIVILEGIO_DENEGADO';
    public const ACCION_CAMBIO_ROL = 'CAMBIO_ROL';
    public const ACCION_CAMBIO_VERIFICACION = 'CAMBIO_VERIFICACION';
    public const ACCION_INICIALIZACION_ADMINISTRADOR_PRINCIPAL = 'INICIALIZACION_ADMINISTRADOR_PRINCIPAL';
    public const ACCION_CSRF_INVALIDO = 'CSRF_INVALIDO';
    public const ACCION_REHASH_CONTRASENA = 'REHASH_CONTRASENA';

    /**
     * @param PDO $conexion Conexión de config/database.php.
     */
    public function __construct(private readonly PDO $conexion)
    {
    }

    /**
     * Inserta un evento de auditoría.
     *
     * Añade automáticamente el origen (IP) y el agente de usuario. El
     * detalle pasa por un filtro que omite claves sensibles.
     *
     * @param int|null $idUsuarioActor Quién realizó la acción (null = invitado/sistema).
     * @param string $accionAuditoria Constante ACCION_*.
     * @param string $entidadAfectada Tabla o concepto afectado.
     * @param int|null $idRegistroAfectado Id del registro afectado.
     * @param array<string, mixed> $detalle Datos adicionales NO sensibles.
     * @return void
     * @throws PDOException Si no puede escribirse (la operación debe fallar
     *                      antes que quedar sin rastro).
     */
    public function registrarEventoAuditoria(
        ?int $idUsuarioActor,
        string $accionAuditoria,
        string $entidadAfectada,
        ?int $idRegistroAfectado,
        array $detalle = []
    ): void {
        $detalleCompleto = RegistroAplicacion::filtrarContexto($detalle) + [
            'origen'        => Solicitud::obtenerOrigen(),
            'agenteUsuario' => Solicitud::obtenerAgenteUsuario(),
        ];

        $sentencia = $this->conexion->prepare(
            'INSERT INTO auditoria (id_usuario_actor, accion_auditoria, entidad_afectada_auditoria,
                 id_registro_afectado, detalle_auditoria)
             VALUES (:idUsuarioActor, :accionAuditoria, :entidadAfectada, :idRegistroAfectado, :detalleAuditoria)'
        );
        $sentencia->execute([
            'idUsuarioActor'     => $idUsuarioActor,
            'accionAuditoria'    => $accionAuditoria,
            'entidadAfectada'    => $entidadAfectada,
            'idRegistroAfectado' => $idRegistroAfectado,
            'detalleAuditoria'   => json_encode($detalleCompleto, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }

    /**
     * Cuenta eventos de un tipo (usado por las pruebas y la futura bitácora).
     *
     * @param string $accionAuditoria Constante ACCION_*.
     * @param int|null $idRegistroAfectado Filtra por registro si se indica.
     * @return int Número de eventos.
     */
    public function contarEventos(string $accionAuditoria, ?int $idRegistroAfectado = null): int
    {
        $sentencia = $this->conexion->prepare(
            'SELECT COUNT(*) FROM auditoria WHERE accion_auditoria = :accionAuditoria
             AND (:idRegistroFiltro IS NULL OR id_registro_afectado = :idRegistroAfectado)'
        );
        $sentencia->execute([
            'accionAuditoria'    => $accionAuditoria,
            'idRegistroFiltro'   => $idRegistroAfectado,
            'idRegistroAfectado' => $idRegistroAfectado,
        ]);
        return (int) $sentencia->fetchColumn();
    }
}

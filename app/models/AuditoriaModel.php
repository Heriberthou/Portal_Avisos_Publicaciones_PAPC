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
    /* Solicitudes de acceso (especificación 1.3). */
    public const ACCION_SOLICITUD_CREADA = 'SOLICITUD_CREADA';
    public const ACCION_SOLICITUD_REPETIDA = 'SOLICITUD_REPETIDA';
    public const ACCION_SOLICITUD_LIMITADA = 'SOLICITUD_LIMITADA';
    public const ACCION_CORREO_SOLICITUD_VERIFICADO = 'CORREO_SOLICITUD_VERIFICADO';
    public const ACCION_SOLICITUD_APROBADA = 'SOLICITUD_APROBADA';
    public const ACCION_SOLICITUD_RECHAZADA = 'SOLICITUD_RECHAZADA';
    public const ACCION_DECISION_SOLICITUD_DENEGADA = 'DECISION_SOLICITUD_DENEGADA';
    public const ACCION_INVITACION_GENERADA = 'INVITACION_GENERADA';
    public const ACCION_ENVIO_CORREO_FALLIDO = 'ENVIO_CORREO_FALLIDO';
    public const ACCION_INTENTO_ESCALADA_FORMULARIO = 'INTENTO_ESCALADA_FORMULARIO';

    /* Cuentas y credenciales. */
    public const ACCION_CUENTA_ACTIVADA = 'CUENTA_ACTIVADA';
    public const ACCION_RECUPERACION_SOLICITADA = 'RECUPERACION_SOLICITADA';
    public const ACCION_CONTRASENA_RESTABLECIDA = 'CONTRASENA_RESTABLECIDA';
    public const ACCION_ENLACE_INVALIDO = 'ENLACE_INVALIDO';
    public const ACCION_CAMBIO_ESTADO_CUENTA = 'CAMBIO_ESTADO_CUENTA';

    /* Inicio de sesión y segundo factor. */
    public const ACCION_LOGIN_EXITOSO = 'LOGIN_EXITOSO';
    public const ACCION_LOGIN_FALLIDO = 'LOGIN_FALLIDO';
    public const ACCION_LOGIN_BLOQUEADO = 'LOGIN_BLOQUEADO';
    public const ACCION_SEGUNDO_FACTOR_REQUERIDO = 'SEGUNDO_FACTOR_REQUERIDO';
    public const ACCION_SEGUNDO_FACTOR_FALLIDO = 'SEGUNDO_FACTOR_FALLIDO';
    public const ACCION_SEGUNDO_FACTOR_CONFIGURADO = 'SEGUNDO_FACTOR_CONFIGURADO';
    public const ACCION_SEGUNDO_FACTOR_RESTABLECIDO = 'SEGUNDO_FACTOR_RESTABLECIDO';
    public const ACCION_CODIGO_RECUPERACION_USADO = 'CODIGO_RECUPERACION_USADO';
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
     * Eventos recientes para la bitácora administrativa.
     *
     * Del detalle se excluyen origen (IP) y agente de usuario: la pantalla
     * muestra solo lo necesario para reconstruir decisiones (SEG-16).
     *
     * @param int $limite Máximo de eventos (1 a 500).
     * @return list<array<string, mixed>> Eventos del más reciente al más antiguo.
     */
    public function listarEventosRecientes(int $limite = 100): array
    {
        $limite = max(1, min(500, $limite));
        $eventos = $this->conexion->query(
            'SELECT a.id_auditoria, a.fecha_evento_auditoria, a.accion_auditoria, a.entidad_afectada_auditoria,
                    a.id_registro_afectado, a.id_usuario_actor, u.nombre_usuario AS nombre_actor, a.detalle_auditoria
             FROM auditoria a LEFT JOIN usuario u ON u.id_usuario = a.id_usuario_actor
             ORDER BY a.id_auditoria DESC LIMIT ' . $limite
        )->fetchAll();

        foreach ($eventos as &$evento) {
            $detalle = json_decode((string) $evento['detalle_auditoria'], true);
            unset($detalle['origen'], $detalle['agenteUsuario']);
            $evento['detalle_auditoria'] = is_array($detalle) ? $detalle : [];
        }
        unset($evento);
        return $eventos;
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

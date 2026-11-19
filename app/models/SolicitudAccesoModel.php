<?php

declare(strict_types=1);

/**
 * Acceso a la tabla solicitud_acceso (migración 004).
 *
 * Una solicitud NO es una cuenta: no tiene contraseña ni rol. Las
 * transiciones (PENDIENTE -> APROBADA | RECHAZADA) se hacen con UPDATE
 * condicional: solo cambian si la solicitud sigue PENDIENTE y con el correo
 * verificado, así dos administradores no pueden resolverla dos veces.
 */
final class SolicitudAccesoModel
{
    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_APROBADA = 'APROBADA';
    public const ESTADO_RECHAZADA = 'RECHAZADA';
    public const ESTADOS = [self::ESTADO_PENDIENTE, self::ESTADO_APROBADA, self::ESTADO_RECHAZADA];

    /** Máximo de filas por consulta de la bandeja. */
    private const LIMITE_LISTADO = 200;

    /**
     * @param PDO $conexion Conexión de config/database.php.
     */
    public function __construct(private readonly PDO $conexion)
    {
    }

    /**
     * Registra una solicitud nueva en estado PENDIENTE y sin verificar.
     *
     * @param array{nombres: string, apellidoPaterno: string, apellidoMaterno: string|null,
     *              correo: string, tipoIntegrante: string, identificador: string} $datosSolicitud
     * @return int Id de la solicitud.
     * @throws PDOException 1062 si ya hay una PENDIENTE con ese correo.
     */
    public function crearSolicitud(array $datosSolicitud): int
    {
        $sentencia = $this->conexion->prepare(
            'INSERT INTO solicitud_acceso (nombres_solicitante, apellido_paterno_solicitante, apellido_materno_solicitante,
                 correo_electronico_solicitante, tipo_integrante_solicitado, identificador_institucional_solicitante)
             VALUES (:nombres, :apellidoPaterno, :apellidoMaterno, :correo, :tipoIntegrante, :identificador)'
        );
        $sentencia->execute([
            'nombres'         => $datosSolicitud['nombres'],
            'apellidoPaterno' => $datosSolicitud['apellidoPaterno'],
            'apellidoMaterno' => $datosSolicitud['apellidoMaterno'],
            'correo'          => $datosSolicitud['correo'],
            'tipoIntegrante'  => $datosSolicitud['tipoIntegrante'],
            'identificador'   => $datosSolicitud['identificador'],
        ]);
        return (int) $this->conexion->lastInsertId();
    }

    /**
     * @param int $idSolicitud Identificador.
     * @param bool $bloquear true para SELECT ... FOR UPDATE (dentro de una transacción).
     * @return array<string, mixed>|null Fila o null.
     */
    public function obtenerSolicitudPorId(int $idSolicitud, bool $bloquear = false): ?array
    {
        $sentencia = $this->conexion->prepare(
            'SELECT s.*, r.nombre_usuario AS nombre_revisor
             FROM solicitud_acceso s LEFT JOIN usuario r ON r.id_usuario = s.id_usuario_revisor
             WHERE s.id_solicitud_acceso = :idSolicitud LIMIT 1' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $sentencia->execute(['idSolicitud' => $idSolicitud]);
        $fila = $sentencia->fetch();
        return $fila === false ? null : $fila;
    }

    /**
     * Solicitud más reciente asociada a un correo (para deduplicar).
     *
     * @param string $correo Correo normalizado.
     * @return array<string, mixed>|null Fila o null.
     */
    public function obtenerUltimaSolicitudPorCorreo(string $correo): ?array
    {
        $sentencia = $this->conexion->prepare(
            'SELECT * FROM solicitud_acceso WHERE correo_electronico_solicitante = :correo
             ORDER BY id_solicitud_acceso DESC LIMIT 1'
        );
        $sentencia->execute(['correo' => $correo]);
        $fila = $sentencia->fetch();
        return $fila === false ? null : $fila;
    }

    /**
     * Marca el correo como verificado (solo una vez y solo si sigue PENDIENTE).
     *
     * @param int $idSolicitud Solicitud.
     * @return bool true si se marcó.
     */
    public function marcarCorreoVerificado(int $idSolicitud): bool
    {
        $sentencia = $this->conexion->prepare(
            "UPDATE solicitud_acceso SET fecha_verificacion_correo_solicitud = NOW()
             WHERE id_solicitud_acceso = :idSolicitud AND estado_solicitud = 'PENDIENTE'
               AND fecha_verificacion_correo_solicitud IS NULL"
        );
        $sentencia->execute(['idSolicitud' => $idSolicitud]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * PENDIENTE (con correo verificado) -> APROBADA.
     *
     * @param int $idSolicitud Solicitud.
     * @param int $idUsuarioRevisor Administrador que decide.
     * @param string $metodoVerificacion Cómo se cotejó la pertenencia.
     * @param int $idUsuarioVinculado Cuenta INVITADA creada.
     * @return bool true si se aplicó.
     */
    public function aprobarSolicitud(int $idSolicitud, int $idUsuarioRevisor, string $metodoVerificacion, int $idUsuarioVinculado): bool
    {
        $sentencia = $this->conexion->prepare(
            "UPDATE solicitud_acceso SET estado_solicitud = 'APROBADA', id_usuario_revisor = :idRevisor,
                 fecha_resolucion_solicitud = NOW(), metodo_verificacion_solicitud = :metodo,
                 id_usuario_vinculado = :idVinculado
             WHERE id_solicitud_acceso = :idSolicitud AND estado_solicitud = 'PENDIENTE'
               AND fecha_verificacion_correo_solicitud IS NOT NULL"
        );
        $sentencia->execute([
            'idRevisor'   => $idUsuarioRevisor,
            'metodo'      => $metodoVerificacion,
            'idVinculado' => $idUsuarioVinculado,
            'idSolicitud' => $idSolicitud,
        ]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * PENDIENTE (con correo verificado) -> RECHAZADA. No crea cuenta.
     *
     * @param int $idSolicitud Solicitud.
     * @param int $idUsuarioRevisor Administrador que decide.
     * @param string $motivo Motivo interno (solo personal autorizado).
     * @return bool true si se aplicó.
     */
    public function rechazarSolicitud(int $idSolicitud, int $idUsuarioRevisor, string $motivo): bool
    {
        $sentencia = $this->conexion->prepare(
            "UPDATE solicitud_acceso SET estado_solicitud = 'RECHAZADA', id_usuario_revisor = :idRevisor,
                 fecha_resolucion_solicitud = NOW(), motivo_resolucion_solicitud = :motivo
             WHERE id_solicitud_acceso = :idSolicitud AND estado_solicitud = 'PENDIENTE'
               AND fecha_verificacion_correo_solicitud IS NOT NULL"
        );
        $sentencia->execute(['idRevisor' => $idUsuarioRevisor, 'motivo' => $motivo, 'idSolicitud' => $idSolicitud]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * Bandeja administrativa.
     *
     * Con estado PENDIENTE solo devuelve solicitudes con correo verificado
     * (especificación 4.5): sin verificación no entran a la decisión.
     *
     * @param string $estado Uno de self::ESTADOS.
     * @param string|null $tipoIntegrante ALUMNO, PROFESOR o null (todos).
     * @param string|null $busqueda Texto a buscar en nombre, correo o identificador.
     * @return list<array<string, mixed>> Filas (sin motivos internos).
     * @throws InvalidArgumentException Si el estado o el tipo no existen.
     */
    public function listarSolicitudes(string $estado, ?string $tipoIntegrante, ?string $busqueda): array
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            throw new InvalidArgumentException('Estado de solicitud no válido.');
        }
        if ($tipoIntegrante !== null && !in_array($tipoIntegrante, UsuarioModel::TIPOS_INTEGRANTE, true)) {
            throw new InvalidArgumentException('Tipo de integrante no válido.');
        }

        $condiciones = ['estado_solicitud = :estado'];
        $parametros = ['estado' => $estado];
        if ($estado === self::ESTADO_PENDIENTE) {
            $condiciones[] = 'fecha_verificacion_correo_solicitud IS NOT NULL';
        }
        if ($tipoIntegrante !== null) {
            $condiciones[] = 'tipo_integrante_solicitado = :tipoIntegrante';
            $parametros['tipoIntegrante'] = $tipoIntegrante;
        }
        if ($busqueda !== null && $busqueda !== '') {
            // Los comodines de LIKE escritos por el administrador se tratan como texto.
            $patron = '%' . addcslashes($busqueda, '%_\\') . '%';
            $condiciones[] = "(CONCAT_WS(' ', nombres_solicitante, apellido_paterno_solicitante, apellido_materno_solicitante) LIKE :busquedaNombre
                OR correo_electronico_solicitante LIKE :busquedaCorreo
                OR identificador_institucional_solicitante LIKE :busquedaIdentificador)";
            $parametros['busquedaNombre'] = $patron;
            $parametros['busquedaCorreo'] = $patron;
            $parametros['busquedaIdentificador'] = $patron;
        }

        $sentencia = $this->conexion->prepare(
            'SELECT id_solicitud_acceso, nombres_solicitante, apellido_paterno_solicitante, apellido_materno_solicitante,
                    correo_electronico_solicitante, tipo_integrante_solicitado, identificador_institucional_solicitante,
                    estado_solicitud, fecha_creacion_solicitud, fecha_verificacion_correo_solicitud, fecha_resolucion_solicitud
             FROM solicitud_acceso WHERE ' . implode(' AND ', $condiciones) . '
             ORDER BY fecha_creacion_solicitud ASC, id_solicitud_acceso ASC LIMIT ' . self::LIMITE_LISTADO
        );
        $sentencia->execute($parametros);
        return $sentencia->fetchAll();
    }
}

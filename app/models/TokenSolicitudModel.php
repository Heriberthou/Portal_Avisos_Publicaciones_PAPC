<?php

declare(strict_types=1);

/**
 * Enlaces de verificación del correo de una solicitud (tabla token_solicitud).
 *
 * Solo se guarda el SHA-256 del token. Cada enlace nuevo invalida los
 * anteriores de la misma solicitud (solo uno vigente) y el consumo es una
 * actualización condicional: dos usos simultáneos no pueden tener éxito.
 */
final class TokenSolicitudModel
{
    /**
     * @param PDO $conexion Conexión de config/database.php.
     */
    public function __construct(private readonly PDO $conexion)
    {
    }

    /**
     * Invalida los enlaces vigentes de la solicitud y registra uno nuevo.
     *
     * @param int $idSolicitud Solicitud destinataria.
     * @param string $hashToken SHA-256 del token.
     * @param int $vigenciaSegundos Duración del enlace.
     * @return void
     */
    public function crearToken(int $idSolicitud, string $hashToken, int $vigenciaSegundos): void
    {
        // R-04: bloquear la solicitud y emitir en una sola transacción.
        $transaccionPropia = !$this->conexion->inTransaction();
        if ($transaccionPropia) {
            $this->conexion->beginTransaction();
        }
        try {
            $this->bloquearSolicitud($idSolicitud);

            $sentenciaInvalidar = $this->conexion->prepare(
                'UPDATE token_solicitud SET fecha_consumo_token_solicitud = NOW()
                 WHERE id_solicitud_acceso_destinataria = :idSolicitud AND fecha_consumo_token_solicitud IS NULL'
            );
            $sentenciaInvalidar->execute(['idSolicitud' => $idSolicitud]);

            $sentencia = $this->conexion->prepare(
                'INSERT INTO token_solicitud (id_solicitud_acceso_destinataria, hash_token_solicitud, fecha_expiracion_token_solicitud)
                 VALUES (:idSolicitud, :hashToken, NOW() + INTERVAL :vigencia SECOND)'
            );
            $sentencia->execute(['idSolicitud' => $idSolicitud, 'hashToken' => $hashToken, 'vigencia' => $vigenciaSegundos]);
            if ($transaccionPropia) {
                $this->conexion->commit();
            }
        } catch (Throwable $excepcion) {
            if ($transaccionPropia && $this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $excepcion;
        }
    }

    /**
     * @param string $hashToken SHA-256 del token recibido.
     * @return array<string, mixed>|null Token vigente (no usado, no vencido) o null.
     */
    public function obtenerTokenVigente(string $hashToken): ?array
    {
        $sentencia = $this->conexion->prepare(
            'SELECT id_token_solicitud, id_solicitud_acceso_destinataria FROM token_solicitud
             WHERE hash_token_solicitud = :hashToken AND fecha_consumo_token_solicitud IS NULL
               AND fecha_expiracion_token_solicitud > NOW() LIMIT 1'
        );
        $sentencia->execute(['hashToken' => $hashToken]);
        $fila = $sentencia->fetch();
        return $fila === false ? null : $fila;
    }

    /**
     * Consume el token una sola vez (UPDATE condicional).
     *
     * R-09: primero bloquea la solicitud y después el token, el mismo orden
     * que crearToken(). Debe llamarse dentro de la transacción que después
     * actualiza la solicitud.
     *
     * @param int $idToken Token.
     * @param int $idSolicitud Solicitud destinataria esperada.
     * @return bool true solo para la primera petición que lo consume.
     * @throws LogicException Si no hay una transacción abierta.
     */
    public function consumirToken(int $idToken, int $idSolicitud): bool
    {
        if (!$this->conexion->inTransaction()) {
            throw new LogicException('consumirToken() requiere una transacción abierta.');
        }
        $this->bloquearSolicitud($idSolicitud);
        $sentencia = $this->conexion->prepare(
            'UPDATE token_solicitud SET fecha_consumo_token_solicitud = NOW()
             WHERE id_token_solicitud = :idToken AND id_solicitud_acceso_destinataria = :idSolicitud
               AND fecha_consumo_token_solicitud IS NULL AND fecha_expiracion_token_solicitud > NOW()'
        );
        $sentencia->execute(['idToken' => $idToken, 'idSolicitud' => $idSolicitud]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * Bloquea la fila de la solicitud hasta el fin de la transacción (R-04, R-09).
     *
     * @param int $idSolicitud Solicitud.
     * @return void
     */
    private function bloquearSolicitud(int $idSolicitud): void
    {
        $bloqueo = $this->conexion->prepare('SELECT id_solicitud_acceso FROM solicitud_acceso WHERE id_solicitud_acceso = :idSolicitud FOR UPDATE');
        $bloqueo->execute(['idSolicitud' => $idSolicitud]);
        $bloqueo->fetchAll();
    }
}

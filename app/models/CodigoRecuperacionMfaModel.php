<?php

declare(strict_types=1);

/**
 * Códigos de recuperación del segundo factor (tabla codigo_recuperacion_mfa).
 *
 * Solo se guardan sus HMAC (CifradorSecretos). Cada código sirve una vez:
 * el consumo es una actualización condicional.
 */
final class CodigoRecuperacionMfaModel
{
    /**
     * @param PDO $conexion Conexión de config/database.php.
     */
    public function __construct(private readonly PDO $conexion)
    {
    }

    /**
     * Sustituye todos los códigos de la cuenta por los nuevos.
     *
     * @param int $idUsuario Cuenta.
     * @param list<string> $huellasCodigos HMAC de cada código.
     * @return void
     */
    public function reemplazarCodigos(int $idUsuario, array $huellasCodigos): void
    {
        $this->eliminarCodigos($idUsuario);
        $sentencia = $this->conexion->prepare(
            'INSERT INTO codigo_recuperacion_mfa (id_usuario_propietario, hash_codigo_recuperacion_mfa) VALUES (:idUsuario, :huella)'
        );
        foreach ($huellasCodigos as $huellaCodigo) {
            $sentencia->execute(['idUsuario' => $idUsuario, 'huella' => $huellaCodigo]);
        }
    }

    /**
     * @param int $idUsuario Cuenta.
     * @param string $huellaCodigo HMAC del código escrito.
     * @return bool true si el código existía, era de esa cuenta y no se había usado.
     */
    public function consumirCodigo(int $idUsuario, string $huellaCodigo): bool
    {
        $sentencia = $this->conexion->prepare(
            'UPDATE codigo_recuperacion_mfa SET fecha_uso_codigo_recuperacion_mfa = NOW()
             WHERE id_usuario_propietario = :idUsuario AND hash_codigo_recuperacion_mfa = :huella
               AND fecha_uso_codigo_recuperacion_mfa IS NULL'
        );
        $sentencia->execute(['idUsuario' => $idUsuario, 'huella' => $huellaCodigo]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * @param int $idUsuario Cuenta.
     * @return int Códigos sin usar.
     */
    public function contarCodigosDisponibles(int $idUsuario): int
    {
        $sentencia = $this->conexion->prepare(
            'SELECT COUNT(*) FROM codigo_recuperacion_mfa
             WHERE id_usuario_propietario = :idUsuario AND fecha_uso_codigo_recuperacion_mfa IS NULL'
        );
        $sentencia->execute(['idUsuario' => $idUsuario]);
        return (int) $sentencia->fetchColumn();
    }

    /**
     * @param int $idUsuario Cuenta.
     * @return void
     */
    public function eliminarCodigos(int $idUsuario): void
    {
        $sentencia = $this->conexion->prepare('DELETE FROM codigo_recuperacion_mfa WHERE id_usuario_propietario = :idUsuario');
        $sentencia->execute(['idUsuario' => $idUsuario]);
    }
}

<?php

declare(strict_types=1);

/**
 * Acceso a la tabla intento_acceso (migración 002).
 *
 * Solo almacena huellas HMAC, nunca correos ni IP en claro. Los cálculos de
 * tiempo se hacen con NOW() de MariaDB para no depender del reloj de PHP.
 */
final class IntentoAccesoModel
{
    /**
     * @param PDO $conexion Conexión de config/database.php.
     */
    public function __construct(private readonly PDO $conexion)
    {
    }

    /**
     * Registra un intento fallido de inicio de sesión.
     *
     * @param string $huellaCorreo HMAC del correo normalizado.
     * @param string $huellaOrigen HMAC de la IP de origen.
     * @return void
     */
    public function registrarIntentoFallido(string $huellaCorreo, string $huellaOrigen): void
    {
        $sentencia = $this->conexion->prepare(
            'INSERT INTO intento_acceso (huella_correo_intento_acceso, huella_origen_intento_acceso)
             VALUES (:huellaCorreo, :huellaOrigen)'
        );
        $sentencia->execute(['huellaCorreo' => $huellaCorreo, 'huellaOrigen' => $huellaOrigen]);
    }

    /**
     * @param string $huellaCorreo HMAC del correo.
     * @param int $ventanaSegundos Antigüedad máxima considerada.
     * @return int Intentos fallidos recientes contra esa cuenta.
     */
    public function contarIntentosPorCorreo(string $huellaCorreo, int $ventanaSegundos): int
    {
        $sentencia = $this->conexion->prepare(
            'SELECT COUNT(*) FROM intento_acceso
             WHERE huella_correo_intento_acceso = :huellaCorreo
               AND fecha_intento_acceso > NOW() - INTERVAL :ventanaSegundos SECOND'
        );
        $sentencia->execute(['huellaCorreo' => $huellaCorreo, 'ventanaSegundos' => $ventanaSegundos]);
        return (int) $sentencia->fetchColumn();
    }

    /**
     * @param string $huellaOrigen HMAC de la IP.
     * @param int $ventanaSegundos Antigüedad máxima considerada.
     * @return int Intentos fallidos recientes desde ese origen.
     */
    public function contarIntentosPorOrigen(string $huellaOrigen, int $ventanaSegundos): int
    {
        $sentencia = $this->conexion->prepare(
            'SELECT COUNT(*) FROM intento_acceso
             WHERE huella_origen_intento_acceso = :huellaOrigen
               AND fecha_intento_acceso > NOW() - INTERVAL :ventanaSegundos SECOND'
        );
        $sentencia->execute(['huellaOrigen' => $huellaOrigen, 'ventanaSegundos' => $ventanaSegundos]);
        return (int) $sentencia->fetchColumn();
    }

    /**
     * Borra los intentos de una cuenta tras un inicio de sesión exitoso.
     * Los intentos por origen se conservan (siguen contando para la IP).
     *
     * @param string $huellaCorreo HMAC del correo.
     * @return void
     */
    public function eliminarIntentosPorCorreo(string $huellaCorreo): void
    {
        $sentencia = $this->conexion->prepare(
            'DELETE FROM intento_acceso WHERE huella_correo_intento_acceso = :huellaCorreo'
        );
        $sentencia->execute(['huellaCorreo' => $huellaCorreo]);
    }

    /**
     * Elimina intentos más antiguos que la retención configurada.
     *
     * @param int $retencionSegundos Antigüedad máxima a conservar.
     * @return void
     */
    public function purgarIntentosAntiguos(int $retencionSegundos): void
    {
        $sentencia = $this->conexion->prepare(
            'DELETE FROM intento_acceso WHERE fecha_intento_acceso < NOW() - INTERVAL :retencionSegundos SECOND'
        );
        $sentencia->execute(['retencionSegundos' => $retencionSegundos]);
    }
}

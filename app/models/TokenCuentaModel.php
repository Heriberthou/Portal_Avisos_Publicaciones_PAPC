<?php

declare(strict_types=1);

/**
 * Enlaces de ACTIVACION y RECUPERACION de una cuenta (tabla token_cuenta).
 *
 * Finalidades separadas: un token de recuperación no sirve para activar y
 * viceversa. Solo se guarda el SHA-256; solo uno vigente por cuenta y
 * finalidad; consumo por actualización condicional.
 */
final class TokenCuentaModel
{
    public const FINALIDAD_ACTIVACION = 'ACTIVACION';
    public const FINALIDAD_RECUPERACION = 'RECUPERACION';
    /** Autoriza configurar el segundo factor (migración 005, hallazgo R-02). */
    public const FINALIDAD_ENROLAMIENTO_MFA = 'ENROLAMIENTO_MFA';
    public const FINALIDADES = [self::FINALIDAD_ACTIVACION, self::FINALIDAD_RECUPERACION, self::FINALIDAD_ENROLAMIENTO_MFA];

    /**
     * @param PDO $conexion Conexión de config/database.php.
     */
    public function __construct(private readonly PDO $conexion)
    {
    }

    /**
     * Invalida los enlaces vigentes de esa finalidad y registra uno nuevo.
     *
     * @param int $idUsuario Cuenta destinataria.
     * @param string $finalidad ACTIVACION o RECUPERACION.
     * @param string $hashToken SHA-256 del token.
     * @param int $vigenciaSegundos Duración del enlace.
     * @return void
     * @throws InvalidArgumentException Si la finalidad no existe.
     */
    public function crearToken(int $idUsuario, string $finalidad, string $hashToken, int $vigenciaSegundos): void
    {
        $this->exigirFinalidad($finalidad);
        // R-04: invalidar e insertar en una transacción que primero bloquea la
        // cuenta. Dos emisiones simultáneas para la misma cuenta se ejecutan
        // una tras otra, y solo el último enlace queda vigente.
        $transaccionPropia = !$this->conexion->inTransaction();
        if ($transaccionPropia) {
            $this->conexion->beginTransaction();
        }
        try {
            $bloqueo = $this->conexion->prepare('SELECT id_usuario FROM usuario WHERE id_usuario = :idUsuario FOR UPDATE');
            $bloqueo->execute(['idUsuario' => $idUsuario]);
            $bloqueo->fetchAll();

            $this->invalidarTokens($idUsuario, $finalidad);
            $sentencia = $this->conexion->prepare(
                'INSERT INTO token_cuenta (id_usuario_destinatario, hash_token_cuenta, finalidad_token_cuenta, fecha_expiracion_token_cuenta)
                 VALUES (:idUsuario, :hashToken, :finalidad, NOW() + INTERVAL :vigencia SECOND)'
            );
            $sentencia->execute([
                'idUsuario' => $idUsuario,
                'hashToken' => $hashToken,
                'finalidad' => $finalidad,
                'vigencia'  => $vigenciaSegundos,
            ]);
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
     * Cuenta los enlaces vigentes de una cuenta y finalidad (para pruebas y diagnóstico).
     *
     * @param int $idUsuario Cuenta.
     * @param string $finalidad Finalidad.
     * @return int Enlaces sin usar y sin vencer.
     */
    public function contarTokensVigentes(int $idUsuario, string $finalidad): int
    {
        $this->exigirFinalidad($finalidad);
        $sentencia = $this->conexion->prepare(
            'SELECT COUNT(*) FROM token_cuenta WHERE id_usuario_destinatario = :idUsuario AND finalidad_token_cuenta = :finalidad
               AND fecha_consumo_token_cuenta IS NULL AND fecha_expiracion_token_cuenta > NOW()'
        );
        $sentencia->execute(['idUsuario' => $idUsuario, 'finalidad' => $finalidad]);
        return (int) $sentencia->fetchColumn();
    }

    /**
     * @param string $hashToken SHA-256 del token recibido.
     * @param string $finalidad Finalidad esperada.
     * @return array<string, mixed>|null Token vigente o null.
     */
    public function obtenerTokenVigente(string $hashToken, string $finalidad): ?array
    {
        $this->exigirFinalidad($finalidad);
        $sentencia = $this->conexion->prepare(
            'SELECT id_token_cuenta, id_usuario_destinatario FROM token_cuenta
             WHERE hash_token_cuenta = :hashToken AND finalidad_token_cuenta = :finalidad
               AND fecha_consumo_token_cuenta IS NULL AND fecha_expiracion_token_cuenta > NOW() LIMIT 1'
        );
        $sentencia->execute(['hashToken' => $hashToken, 'finalidad' => $finalidad]);
        $fila = $sentencia->fetch();
        return $fila === false ? null : $fila;
    }

    /**
     * @param int $idToken Token.
     * @return bool true solo para la primera petición que lo consume.
     */
    public function consumirToken(int $idToken): bool
    {
        $sentencia = $this->conexion->prepare(
            'UPDATE token_cuenta SET fecha_consumo_token_cuenta = NOW()
             WHERE id_token_cuenta = :idToken AND fecha_consumo_token_cuenta IS NULL
               AND fecha_expiracion_token_cuenta > NOW()'
        );
        $sentencia->execute(['idToken' => $idToken]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * Invalida los enlaces pendientes de una cuenta.
     *
     * @param int $idUsuario Cuenta.
     * @param string|null $finalidad Solo esa finalidad, o todas si es null.
     * @return void
     */
    public function invalidarTokens(int $idUsuario, ?string $finalidad = null): void
    {
        $filtroFinalidad = '';
        $parametros = ['idUsuario' => $idUsuario];
        if ($finalidad !== null) {
            $this->exigirFinalidad($finalidad);
            $filtroFinalidad = ' AND finalidad_token_cuenta = :finalidad';
            $parametros['finalidad'] = $finalidad;
        }
        $sentencia = $this->conexion->prepare(
            'UPDATE token_cuenta SET fecha_consumo_token_cuenta = NOW()
             WHERE id_usuario_destinatario = :idUsuario AND fecha_consumo_token_cuenta IS NULL' . $filtroFinalidad
        );
        $sentencia->execute($parametros);
    }

    /**
     * @param string $finalidad Valor a comprobar.
     * @return void
     * @throws InvalidArgumentException Si no es una finalidad conocida.
     */
    private function exigirFinalidad(string $finalidad): void
    {
        if (!in_array($finalidad, self::FINALIDADES, true)) {
            throw new InvalidArgumentException('Finalidad de token no válida.');
        }
    }
}

<?php

declare(strict_types=1);

/**
 * Activación de cuentas INVITADA y recuperación de contraseña.
 *
 * Reglas (especificación 1.3, 4.6, 5.6, 5.7 y SEG-03/SEG-14):
 *  - El administrador nunca establece ni conoce la contraseña: la crea el
 *    titular con un enlace de un solo uso.
 *  - Tokens con finalidad separada (ACTIVACION / RECUPERACION), guardados
 *    como hash, con caducidad, uno vigente por cuenta y consumo atómico.
 *  - Consumir el enlace NO inicia sesión: se exige un login separado.
 *  - Recuperar no reactiva cuentas SUSPENDIDA o BAJA.
 *  - Al restablecer se cierran todas las sesiones (version_sesion_usuario)
 *    y se invalidan los demás enlaces pendientes.
 *  - La solicitud de recuperación responde igual exista o no la cuenta.
 */
final class ServicioCredenciales
{
    public const RESULTADO_EXITO = 'EXITO';
    public const RESULTADO_ENLACE_INVALIDO = 'ENLACE_INVALIDO';
    public const RESULTADO_CONTRASENA_RECHAZADA = 'CONTRASENA_RECHAZADA';
    public const RESULTADO_RECIBIDA = 'RECIBIDA';
    public const RESULTADO_LIMITE_ALCANZADO = 'LIMITE_ALCANZADO';

    private const AMBITO_LIMITE = 'recuperacion';

    /**
     * @param PDO $conexion Conexión para transacciones.
     * @param UsuarioModel $usuarioModel Cuentas.
     * @param TokenCuentaModel $tokenCuentaModel Enlaces de cuenta.
     * @param AuditoriaModel $auditoriaModel Bitácora.
     * @param LimitadorIntentos $limitadorIntentos Antiautomatización.
     * @param ServicioCorreo $servicioCorreo Notificaciones.
     */
    public function __construct(
        private readonly PDO $conexion,
        private readonly UsuarioModel $usuarioModel,
        private readonly TokenCuentaModel $tokenCuentaModel,
        private readonly AuditoriaModel $auditoriaModel,
        private readonly LimitadorIntentos $limitadorIntentos,
        private readonly ServicioCorreo $servicioCorreo
    ) {
    }

    /**
     * Devuelve la cuenta a la que pertenece un enlace vigente (para mostrar
     * el formulario), sin consumirlo.
     *
     * @param string $token Token en claro.
     * @param string $finalidad ACTIVACION o RECUPERACION.
     * @return array<string, mixed>|null Cuenta en el estado esperado, o null.
     */
    public function obtenerCuentaDeEnlace(string $token, string $finalidad): ?array
    {
        $tokenVigente = $this->tokenCuentaModel->obtenerTokenVigente(GeneradorTokens::calcularHashToken($token), $finalidad);
        if ($tokenVigente === null) {
            return null;
        }
        $usuario = $this->usuarioModel->obtenerUsuarioPorId((int) $tokenVigente['id_usuario_destinatario']);
        $estadoEsperado = $finalidad === TokenCuentaModel::FINALIDAD_ACTIVACION ? UsuarioModel::ESTADO_INVITADA : UsuarioModel::ESTADO_ACTIVA;
        return $usuario !== null && $usuario['estado_cuenta_usuario'] === $estadoEsperado ? $usuario : null;
    }

    /**
     * INVITADA -> ACTIVA con la contraseña elegida.
     *
     * @param string $token Token de activación.
     * @param string|null $contrasena Contraseña nueva.
     * @return array{resultado: string, error: string|null}
     */
    public function activarCuenta(string $token, ?string $contrasena): array
    {
        return $this->aplicarContrasenaConEnlace($token, $contrasena, TokenCuentaModel::FINALIDAD_ACTIVACION);
    }

    /**
     * Cambia la contraseña de una cuenta ACTIVA con un enlace de recuperación.
     *
     * @param string $token Token de recuperación.
     * @param string|null $contrasena Contraseña nueva.
     * @return array{resultado: string, error: string|null}
     */
    public function restablecerContrasena(string $token, ?string $contrasena): array
    {
        return $this->aplicarContrasenaConEnlace($token, $contrasena, TokenCuentaModel::FINALIDAD_RECUPERACION);
    }

    /**
     * "¿Olvidaste tu contraseña?". Siempre responde RECIBIDA (salvo límite):
     *  - Cuenta ACTIVA: enlace de recuperación (30 min).
     *  - Cuenta INVITADA: enlace de activación nuevo (el titular controla el
     *    buzón verificado; así se resuelve un enlace de activación vencido).
     *  - SUSPENDIDA, BAJA o inexistente: no se envía nada.
     *
     * @param string $correo Correo normalizado.
     * @param string $origen IP del cliente.
     * @return string RESULTADO_RECIBIDA o RESULTADO_LIMITE_ALCANZADO.
     */
    public function solicitarRecuperacion(string $correo, string $origen): string
    {
        if (!$this->limitadorIntentos->verificarLimiteAmbito(self::AMBITO_LIMITE, $correo, $origen, MAX_ENVIOS_FORMULARIO_CORREO, MAX_ENVIOS_FORMULARIO_ORIGEN)) {
            return self::RESULTADO_LIMITE_ALCANZADO;
        }
        $this->limitadorIntentos->registrarIntentoAmbito(self::AMBITO_LIMITE, $correo, $origen);

        $usuario = $this->usuarioModel->obtenerUsuarioPorCorreo($correo);
        $estado = $usuario['estado_cuenta_usuario'] ?? null;
        $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_RECUPERACION_SOLICITADA, 'usuario', $usuario !== null ? (int) $usuario['id_usuario'] : null, [
            'huellaCorreo' => $this->limitadorIntentos->calcularHuella($correo),
        ]);
        if ($usuario === null || !in_array($estado, [UsuarioModel::ESTADO_ACTIVA, UsuarioModel::ESTADO_INVITADA], true)) {
            return self::RESULTADO_RECIBIDA;
        }

        $idUsuario = (int) $usuario['id_usuario'];
        $token = GeneradorTokens::generarToken();
        if ($estado === UsuarioModel::ESTADO_ACTIVA) {
            $this->tokenCuentaModel->crearToken($idUsuario, TokenCuentaModel::FINALIDAD_RECUPERACION, GeneradorTokens::calcularHashToken($token), VIGENCIA_TOKEN_RECUPERACION_SEGUNDOS);
            $enviado = $this->servicioCorreo->enviarRecuperacion($correo, $token);
        } else {
            $this->tokenCuentaModel->crearToken($idUsuario, TokenCuentaModel::FINALIDAD_ACTIVACION, GeneradorTokens::calcularHashToken($token), VIGENCIA_TOKEN_ACTIVACION_SEGUNDOS);
            $enviado = $this->servicioCorreo->enviarInvitacionActivacion($correo, (string) $usuario['nombres_usuario'], $token);
        }
        if (!$enviado) {
            $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_ENVIO_CORREO_FALLIDO, 'usuario', $idUsuario, ['tipoCorreo' => 'RECUPERACION']);
        }
        return self::RESULTADO_RECIBIDA;
    }

    /**
     * Valida la contraseña, consume el enlace y aplica el cambio en una
     * transacción. Si dos peticiones usan el mismo enlace a la vez, el
     * UPDATE condicional deja pasar solo a una.
     *
     * @param string $token Token en claro.
     * @param string|null $contrasena Contraseña nueva.
     * @param string $finalidad ACTIVACION o RECUPERACION.
     * @return array{resultado: string, error: string|null}
     */
    private function aplicarContrasenaConEnlace(string $token, ?string $contrasena, string $finalidad): array
    {
        $usuario = $this->obtenerCuentaDeEnlace($token, $finalidad);
        if ($usuario === null) {
            $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_ENLACE_INVALIDO, 'token_cuenta', null, ['finalidad' => $finalidad]);
            return ['resultado' => self::RESULTADO_ENLACE_INVALIDO, 'error' => null];
        }

        $errorContrasena = PoliticaContrasenas::validarNuevaContrasena($contrasena, [
            $usuario['correo_electronico_usuario'],
            $usuario['nombres_usuario'],
            $usuario['apellido_paterno_usuario'],
            $usuario['apellido_materno_usuario'],
            $usuario['identificador_institucional_usuario'],
        ]);
        if ($errorContrasena !== null) {
            return ['resultado' => self::RESULTADO_CONTRASENA_RECHAZADA, 'error' => $errorContrasena];
        }

        // El hash (lento a propósito) se calcula fuera de la transacción.
        $hashContrasena = GestorContrasenas::generarHash((string) $contrasena);
        $idUsuario = (int) $usuario['id_usuario'];

        $this->conexion->beginTransaction();
        try {
            $tokenVigente = $this->tokenCuentaModel->obtenerTokenVigente(GeneradorTokens::calcularHashToken($token), $finalidad);
            $aplicado = $tokenVigente !== null
                && (int) $tokenVigente['id_usuario_destinatario'] === $idUsuario
                && $this->tokenCuentaModel->consumirToken((int) $tokenVigente['id_token_cuenta'])
                && ($finalidad === TokenCuentaModel::FINALIDAD_ACTIVACION
                    ? $this->usuarioModel->activarCuenta($idUsuario, $hashContrasena)
                    : $this->usuarioModel->restablecerContrasena($idUsuario, $hashContrasena));
            if (!$aplicado) {
                $this->conexion->rollBack();
                $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_ENLACE_INVALIDO, 'token_cuenta', null, ['finalidad' => $finalidad]);
                return ['resultado' => self::RESULTADO_ENLACE_INVALIDO, 'error' => null];
            }
            // Cualquier otro enlace pendiente de la cuenta deja de servir.
            $this->tokenCuentaModel->invalidarTokens($idUsuario);
            $this->auditoriaModel->registrarEventoAuditoria(
                $idUsuario,
                $finalidad === TokenCuentaModel::FINALIDAD_ACTIVACION ? AuditoriaModel::ACCION_CUENTA_ACTIVADA : AuditoriaModel::ACCION_CONTRASENA_RESTABLECIDA,
                'usuario',
                $idUsuario
            );
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $excepcion;
        }
        return ['resultado' => self::RESULTADO_EXITO, 'error' => null];
    }
}

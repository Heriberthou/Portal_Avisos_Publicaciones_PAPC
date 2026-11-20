<?php

declare(strict_types=1);

/**
 * Segundo factor (TOTP) para ADMINISTRADOR y ADMINISTRADOR_PRINCIPAL
 * (especificación 1.3, 5.8 y SEG-08).
 *
 *  - Hasta superar el segundo factor NO existe sesión autenticada: la
 *    contraseña correcta sola no abre ninguna ruta.
 *  - Configuración (enrolamiento), hallazgo R-02: además de la contraseña se
 *    exige un enlace de un solo uso con finalidad ENROLAMIENTO_MFA, vigente
 *    1 h y entregado por un canal independiente de la contraseña: el correo
 *    verificado de la cuenta (al recibir el rol o al restablecer el factor)
 *    o la consola del servidor (primer principal y emergencias). Quien solo
 *    conoce la contraseña no puede configurar su propio autenticador.
 *  - El secreto se guarda en la sesión hasta que la persona demuestra que lo
 *    cargó en su aplicación (un código válido); entonces, en UNA transacción,
 *    se consume el enlace, se guarda el secreto CIFRADO y se generan códigos
 *    de recuperación de un solo uso.
 *  - Cada intento se reserva antes de comprobar el código (R-06); se limitan
 *    por cuenta y por origen.
 *  - Un código TOTP aceptado no puede volver a usarse.
 */
final class ServicioSegundoFactor
{
    public const RESULTADO_EXITO = 'EXITO';
    public const RESULTADO_CODIGO_INVALIDO = 'CODIGO_INVALIDO';
    public const RESULTADO_LIMITE_ALCANZADO = 'LIMITE_ALCANZADO';
    public const RESULTADO_SIN_PENDIENTE = 'SIN_PENDIENTE';
    public const RESULTADO_SIN_AUTORIZACION = 'SIN_AUTORIZACION';
    public const RESULTADO_ENLACE_ENVIADO = 'ENLACE_ENVIADO';
    public const RESULTADO_ENVIO_FALLIDO = 'ENVIO_FALLIDO';

    private const AMBITO_LIMITE = 'segundo-factor';
    private const AMBITO_ENLACE = 'enlace-segundo-factor';

    /** Alfabeto sin caracteres ambiguos (0/O, 1/I/L) para códigos de recuperación. */
    private const ALFABETO_CODIGOS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const LONGITUD_CODIGO_RECUPERACION = 10;

    /**
     * @param PDO $conexion Conexión para transacciones.
     * @param UsuarioModel $usuarioModel Cuentas.
     * @param CodigoRecuperacionMfaModel $codigoRecuperacionModel Códigos de recuperación.
     * @param TokenCuentaModel $tokenCuentaModel Enlaces de enrolamiento.
     * @param AuditoriaModel $auditoriaModel Bitácora.
     * @param LimitadorIntentos $limitadorIntentos Fuerza bruta.
     * @param GestorSesion $gestorSesion Estado pendiente y sesión final.
     * @param CifradorSecretos $cifradorSecretos Cifrado del secreto.
     * @param ServicioCorreo $servicioCorreo Envío del enlace de enrolamiento.
     */
    public function __construct(
        private readonly PDO $conexion,
        private readonly UsuarioModel $usuarioModel,
        private readonly CodigoRecuperacionMfaModel $codigoRecuperacionModel,
        private readonly TokenCuentaModel $tokenCuentaModel,
        private readonly AuditoriaModel $auditoriaModel,
        private readonly LimitadorIntentos $limitadorIntentos,
        private readonly GestorSesion $gestorSesion,
        private readonly CifradorSecretos $cifradorSecretos,
        private readonly ServicioCorreo $servicioCorreo
    ) {
    }

    /**
     * Cuenta con segundo factor pendiente, revalidada contra la BD.
     *
     * @return array<string, mixed>|null Usuario ACTIVO con rol administrativo, o null.
     */
    public function obtenerCuentaPendiente(): ?array
    {
        $pendiente = $this->gestorSesion->obtenerSegundoFactorPendiente();
        if ($pendiente === null) {
            return null;
        }
        $usuario = $this->usuarioModel->obtenerUsuarioPorId($pendiente['idUsuario']);
        if ($usuario === null
            || (int) $usuario['version_sesion_usuario'] !== $pendiente['versionSesion']
            || !UsuarioModel::puedeIniciarSesion($usuario)
            || !in_array($usuario['rol_usuario'], UsuarioModel::ROLES_CON_SEGUNDO_FACTOR, true)) {
            $this->gestorSesion->limpiarSegundoFactorPendiente();
            return null;
        }
        return $usuario;
    }

    /**
     * Genera un enlace de enrolamiento (invalida los anteriores). Lo usan la
     * administración (al asignar un rol o restablecer el factor) y la consola.
     *
     * @param int $idUsuario Cuenta administrativa.
     * @return string Token en claro (para el correo o la consola).
     */
    public function emitirEnlaceEnrolamiento(int $idUsuario): string
    {
        $token = GeneradorTokens::generarToken();
        $this->tokenCuentaModel->crearToken($idUsuario, TokenCuentaModel::FINALIDAD_ENROLAMIENTO_MFA, GeneradorTokens::calcularHashToken($token), VIGENCIA_TOKEN_ENROLAMIENTO_MFA_SEGUNDOS);
        return $token;
    }

    /**
     * ¿La persona con contraseña ya validada abrió además un enlace de
     * enrolamiento vigente de SU cuenta?
     *
     * @return bool true si puede configurar el segundo factor.
     */
    public function tieneAutorizacionEnrolamiento(): bool
    {
        $usuario = $this->obtenerCuentaPendiente();
        return $usuario !== null
            && (int) $usuario['tiene_segundo_factor_usuario'] !== 1
            && $this->obtenerTokenEnrolamientoVigente((int) $usuario['id_usuario']) !== null;
    }

    /**
     * "Enviarme el enlace de configuración": lo manda al correo de la cuenta
     * con contraseña ya validada (nunca a otro destino).
     *
     * @param string $origen IP del cliente.
     * @return string RESULTADO_ENLACE_ENVIADO, RESULTADO_ENVIO_FALLIDO,
     *                RESULTADO_LIMITE_ALCANZADO o RESULTADO_SIN_PENDIENTE.
     */
    public function solicitarEnlaceEnrolamiento(string $origen): string
    {
        $usuario = $this->obtenerCuentaPendiente();
        if ($usuario === null || (int) $usuario['tiene_segundo_factor_usuario'] === 1) {
            return self::RESULTADO_SIN_PENDIENTE;
        }
        $idUsuario = (int) $usuario['id_usuario'];
        if ($this->limitadorIntentos->reservarIntento(self::AMBITO_ENLACE, (string) $idUsuario, $origen, MAX_ENVIOS_FORMULARIO_CORREO, MAX_ENVIOS_FORMULARIO_ORIGEN) === null) {
            return self::RESULTADO_LIMITE_ALCANZADO;
        }
        $token = $this->emitirEnlaceEnrolamiento($idUsuario);
        $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_ENLACE_ENROLAMIENTO_EMITIDO, 'usuario', $idUsuario, ['canal' => 'CORREO']);
        if (!$this->servicioCorreo->enviarEnlaceEnrolamiento((string) $usuario['correo_electronico_usuario'], (string) $usuario['nombres_usuario'], $token)) {
            $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_ENVIO_CORREO_FALLIDO, 'usuario', $idUsuario, ['tipoCorreo' => 'ENROLAMIENTO_MFA']);
            return self::RESULTADO_ENVIO_FALLIDO;
        }
        return self::RESULTADO_ENLACE_ENVIADO;
    }

    /**
     * Datos para la pantalla de configuración inicial.
     *
     * @return array{secreto: string, uri: string, correo: string}|null null si
     *         no hay contraseña validada, ya tiene factor o falta el enlace.
     */
    public function prepararConfiguracion(): ?array
    {
        if (!$this->tieneAutorizacionEnrolamiento()) {
            return null;
        }
        $usuario = (array) $this->obtenerCuentaPendiente();
        $pendiente = (array) $this->gestorSesion->obtenerSegundoFactorPendiente();
        $secreto = $pendiente['secretoPropuesto'] ?? null;
        if (!is_string($secreto) || SegundoFactorTotp::decodificarBase32($secreto) === '') {
            $secreto = SegundoFactorTotp::generarSecreto();
            $this->gestorSesion->guardarSecretoPropuesto($secreto);
        }
        $correo = (string) $usuario['correo_electronico_usuario'];
        return ['secreto' => $secreto, 'uri' => SegundoFactorTotp::construirUriAprovisionamiento($secreto, $correo), 'correo' => $correo];
    }

    /**
     * Confirma la configuración con un primer código válido.
     *
     * @param string $codigo Código de 6 dígitos.
     * @param string $origen IP del cliente.
     * @return array{resultado: string, codigosRecuperacion: list<string>}
     */
    public function confirmarConfiguracion(string $codigo, string $origen): array
    {
        $usuario = $this->obtenerCuentaPendiente();
        $pendiente = $this->gestorSesion->obtenerSegundoFactorPendiente();
        $secreto = $pendiente['secretoPropuesto'] ?? null;
        if ($usuario === null || (int) $usuario['tiene_segundo_factor_usuario'] === 1) {
            return ['resultado' => self::RESULTADO_SIN_PENDIENTE, 'codigosRecuperacion' => []];
        }
        $idUsuario = (int) $usuario['id_usuario'];
        $tokenEnrolamiento = $this->obtenerTokenEnrolamientoVigente($idUsuario);
        if ($tokenEnrolamiento === null || !is_string($secreto)) {
            $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_SEGUNDO_FACTOR_FALLIDO, 'usuario', $idUsuario, ['etapa' => 'SIN_AUTORIZACION_ENROLAMIENTO']);
            return ['resultado' => self::RESULTADO_SIN_AUTORIZACION, 'codigosRecuperacion' => []];
        }
        $reserva = $this->reservarIntento($idUsuario, $origen);
        if ($reserva === null) {
            return ['resultado' => self::RESULTADO_LIMITE_ALCANZADO, 'codigosRecuperacion' => []];
        }

        $pasoAceptado = SegundoFactorTotp::verificarCodigo($secreto, $codigo, null);
        if ($pasoAceptado === null) {
            $this->auditarFallo($idUsuario, 'CONFIGURACION');
            return ['resultado' => self::RESULTADO_CODIGO_INVALIDO, 'codigosRecuperacion' => []];
        }

        $codigosRecuperacion = $this->generarCodigosRecuperacion();
        $this->conexion->beginTransaction();
        try {
            $guardado = $this->tokenCuentaModel->consumirToken((int) $tokenEnrolamiento['id_token_cuenta'], $idUsuario)
                && $this->usuarioModel->guardarSegundoFactor($idUsuario, $this->cifradorSecretos->cifrar($secreto, 'usuario:' . $idUsuario), $pasoAceptado);
            if (!$guardado) {
                $this->conexion->rollBack();
                return ['resultado' => self::RESULTADO_SIN_AUTORIZACION, 'codigosRecuperacion' => []];
            }
            $this->codigoRecuperacionModel->reemplazarCodigos($idUsuario, array_map(
                fn (string $codigoRecuperacion): string => $this->cifradorSecretos->calcularHuellaCodigoRecuperacion($this->normalizarCodigoRecuperacion($codigoRecuperacion)),
                $codigosRecuperacion
            ));
            $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_SEGUNDO_FACTOR_CONFIGURADO, 'usuario', $idUsuario);
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $excepcion;
        }

        $this->gestorSesion->eliminarTokenEnlace(GestorSesion::ENLACE_ENROLAMIENTO);
        $this->abrirSesionAdministrativa($usuario, 'TOTP_CONFIGURACION', $reserva);
        return ['resultado' => self::RESULTADO_EXITO, 'codigosRecuperacion' => $codigosRecuperacion];
    }

    /**
     * Verifica el código TOTP o un código de recuperación y abre la sesión.
     *
     * @param string $codigo Código escrito.
     * @param string $origen IP del cliente.
     * @return string Una de las constantes RESULTADO_*.
     */
    public function verificarCodigo(string $codigo, string $origen): string
    {
        $usuario = $this->obtenerCuentaPendiente();
        if ($usuario === null || (int) $usuario['tiene_segundo_factor_usuario'] !== 1) {
            return self::RESULTADO_SIN_PENDIENTE;
        }
        $idUsuario = (int) $usuario['id_usuario'];
        $reserva = $this->reservarIntento($idUsuario, $origen);
        if ($reserva === null) {
            return self::RESULTADO_LIMITE_ALCANZADO;
        }

        $codigoLimpio = (string) preg_replace('/\s+/', '', $codigo);
        if (preg_match('/^\d{' . SegundoFactorTotp::DIGITOS . '}$/', $codigoLimpio) === 1) {
            $segundoFactor = $this->usuarioModel->obtenerSegundoFactor($idUsuario);
            $secreto = $segundoFactor['secreto'] !== null
                ? $this->cifradorSecretos->descifrar($segundoFactor['secreto'], 'usuario:' . $idUsuario)
                : null;
            $pasoAceptado = $secreto !== null ? SegundoFactorTotp::verificarCodigo($secreto, $codigoLimpio, $segundoFactor['ultimoPaso']) : null;
            if ($pasoAceptado !== null && $this->usuarioModel->registrarPasoSegundoFactor($idUsuario, $pasoAceptado)) {
                $this->abrirSesionAdministrativa($usuario, 'TOTP', $reserva);
                return self::RESULTADO_EXITO;
            }
        } else {
            $huellaCodigo = $this->cifradorSecretos->calcularHuellaCodigoRecuperacion($this->normalizarCodigoRecuperacion($codigoLimpio));
            if ($this->codigoRecuperacionModel->consumirCodigo($idUsuario, $huellaCodigo)) {
                $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_CODIGO_RECUPERACION_USADO, 'usuario', $idUsuario, [
                    'codigosRestantes' => $this->codigoRecuperacionModel->contarCodigosDisponibles($idUsuario),
                ]);
                $this->abrirSesionAdministrativa($usuario, 'CODIGO_RECUPERACION', $reserva);
                return self::RESULTADO_EXITO;
            }
        }

        $this->auditarFallo($idUsuario, 'VERIFICACION');
        return self::RESULTADO_CODIGO_INVALIDO;
    }

    /**
     * @param int $idUsuario Cuenta.
     * @return int Códigos de recuperación sin usar.
     */
    public function contarCodigosDisponibles(int $idUsuario): int
    {
        return $this->codigoRecuperacionModel->contarCodigosDisponibles($idUsuario);
    }

    /**
     * Mayúsculas y sin guiones ni espacios ("abcde-fghjk" == "ABCDEFGHJK").
     *
     * @param string $codigo Código escrito.
     * @return string Código normalizado.
     */
    public function normalizarCodigoRecuperacion(string $codigo): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/', '', $codigo));
    }

    /**
     * Enlace de enrolamiento que la persona abrió en ESTE navegador, si sigue
     * vigente y pertenece a la cuenta indicada.
     *
     * @param int $idUsuario Cuenta con contraseña ya validada.
     * @return array<string, mixed>|null Fila del token o null.
     */
    private function obtenerTokenEnrolamientoVigente(int $idUsuario): ?array
    {
        $token = $this->gestorSesion->obtenerTokenEnlace(GestorSesion::ENLACE_ENROLAMIENTO);
        if ($token === null) {
            return null;
        }
        $fila = $this->tokenCuentaModel->obtenerTokenVigente(GeneradorTokens::calcularHashToken($token), TokenCuentaModel::FINALIDAD_ENROLAMIENTO_MFA);
        return $fila !== null && (int) $fila['id_usuario_destinatario'] === $idUsuario ? $fila : null;
    }

    /**
     * @param array<string, mixed> $usuario Cuenta verificada.
     * @param string $metodo TOTP, TOTP_CONFIGURACION o CODIGO_RECUPERACION.
     * @param int $reserva Reserva de esta petición; es la única que se retira (R-06).
     * @return void
     */
    private function abrirSesionAdministrativa(array $usuario, string $metodo, int $reserva): void
    {
        $idUsuario = (int) $usuario['id_usuario'];
        $this->limitadorIntentos->liberarReserva($reserva);
        $this->gestorSesion->crearSesion($usuario, true);
        $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_LOGIN_EXITOSO, 'usuario', $idUsuario, ['segundoFactor' => $metodo]);
    }

    /**
     * Reserva el intento antes de comprobar el código (R-06). Si se alcanzó
     * el límite, descarta el estado pendiente: hay que volver a escribir la
     * contraseña.
     *
     * @param int $idUsuario Cuenta.
     * @param string $origen IP.
     * @return int|null Id de la reserva o null si se alcanzó el límite.
     */
    private function reservarIntento(int $idUsuario, string $origen): ?int
    {
        $reserva = $this->limitadorIntentos->reservarIntento(self::AMBITO_LIMITE, (string) $idUsuario, $origen, MAX_INTENTOS_SEGUNDO_FACTOR, MAX_INTENTOS_LOGIN_ORIGEN);
        if ($reserva === null) {
            $this->gestorSesion->limpiarSegundoFactorPendiente();
            $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_LOGIN_BLOQUEADO, 'usuario', $idUsuario, ['etapa' => 'SEGUNDO_FACTOR']);
        }
        return $reserva;
    }

    /**
     * La reserva ya cuenta como intento fallido; aquí solo se audita.
     *
     * @param int $idUsuario Cuenta.
     * @param string $etapa CONFIGURACION o VERIFICACION.
     * @return void
     */
    private function auditarFallo(int $idUsuario, string $etapa): void
    {
        $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_SEGUNDO_FACTOR_FALLIDO, 'usuario', $idUsuario, ['etapa' => $etapa]);
    }

    /**
     * @return list<string> Códigos legibles con formato XXXXX-XXXXX.
     */
    private function generarCodigosRecuperacion(): array
    {
        $codigos = [];
        $maximoIndice = strlen(self::ALFABETO_CODIGOS) - 1;
        while (count($codigos) < CANTIDAD_CODIGOS_RECUPERACION_MFA) {
            $codigo = '';
            for ($posicion = 0; $posicion < self::LONGITUD_CODIGO_RECUPERACION; $posicion++) {
                $codigo .= self::ALFABETO_CODIGOS[random_int(0, $maximoIndice)];
            }
            $codigos[$codigo] = substr($codigo, 0, 5) . '-' . substr($codigo, 5);
        }
        return array_values($codigos);
    }
}

<?php

declare(strict_types=1);

/**
 * Segundo factor (TOTP) para ADMINISTRADOR y ADMINISTRADOR_PRINCIPAL
 * (especificación 1.3, 5.8 y SEG-08).
 *
 *  - Hasta superar el segundo factor NO existe sesión autenticada: la
 *    contraseña correcta sola no abre ninguna ruta.
 *  - Configuración inicial: el secreto se genera y se guarda en la sesión
 *    hasta que la persona demuestra que lo cargó en su aplicación (un código
 *    válido); solo entonces se guarda CIFRADO en la base de datos y se
 *    generan códigos de recuperación de un solo uso.
 *  - Los intentos fallidos se limitan por cuenta y por origen.
 *  - Un código TOTP aceptado no puede volver a usarse.
 *
 * Limitación conocida: si alguien obtiene la contraseña de un administrador
 * que TODAVÍA no configuró su segundo factor, podría configurarlo él. Por
 * eso el principal debe configurarlo en cuanto recibe el rol.
 */
final class ServicioSegundoFactor
{
    public const RESULTADO_EXITO = 'EXITO';
    public const RESULTADO_CODIGO_INVALIDO = 'CODIGO_INVALIDO';
    public const RESULTADO_LIMITE_ALCANZADO = 'LIMITE_ALCANZADO';
    public const RESULTADO_SIN_PENDIENTE = 'SIN_PENDIENTE';

    private const AMBITO_LIMITE = 'segundo-factor';

    /** Alfabeto sin caracteres ambiguos (0/O, 1/I/L) para códigos de recuperación. */
    private const ALFABETO_CODIGOS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const LONGITUD_CODIGO_RECUPERACION = 10;

    /**
     * @param PDO $conexion Conexión para transacciones.
     * @param UsuarioModel $usuarioModel Cuentas.
     * @param CodigoRecuperacionMfaModel $codigoRecuperacionModel Códigos de recuperación.
     * @param AuditoriaModel $auditoriaModel Bitácora.
     * @param LimitadorIntentos $limitadorIntentos Fuerza bruta.
     * @param GestorSesion $gestorSesion Estado pendiente y sesión final.
     * @param CifradorSecretos $cifradorSecretos Cifrado del secreto.
     */
    public function __construct(
        private readonly PDO $conexion,
        private readonly UsuarioModel $usuarioModel,
        private readonly CodigoRecuperacionMfaModel $codigoRecuperacionModel,
        private readonly AuditoriaModel $auditoriaModel,
        private readonly LimitadorIntentos $limitadorIntentos,
        private readonly GestorSesion $gestorSesion,
        private readonly CifradorSecretos $cifradorSecretos
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
            || $usuario['estado_cuenta_usuario'] !== UsuarioModel::ESTADO_ACTIVA
            || !in_array($usuario['rol_usuario'], UsuarioModel::ROLES_CON_SEGUNDO_FACTOR, true)) {
            $this->gestorSesion->limpiarSegundoFactorPendiente();
            return null;
        }
        return $usuario;
    }

    /**
     * Datos para la pantalla de configuración inicial.
     *
     * @return array{secreto: string, uri: string, correo: string}|null null si no corresponde configurar.
     */
    public function prepararConfiguracion(): ?array
    {
        $usuario = $this->obtenerCuentaPendiente();
        if ($usuario === null || (int) $usuario['tiene_segundo_factor_usuario'] === 1) {
            return null;
        }
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
        if ($usuario === null || (int) $usuario['tiene_segundo_factor_usuario'] === 1 || !is_string($secreto)) {
            return ['resultado' => self::RESULTADO_SIN_PENDIENTE, 'codigosRecuperacion' => []];
        }
        $idUsuario = (int) $usuario['id_usuario'];
        if (!$this->verificarLimite($idUsuario, $origen)) {
            return ['resultado' => self::RESULTADO_LIMITE_ALCANZADO, 'codigosRecuperacion' => []];
        }

        $pasoAceptado = SegundoFactorTotp::verificarCodigo($secreto, $codigo, null);
        if ($pasoAceptado === null) {
            $this->registrarFallo($idUsuario, $origen, 'CONFIGURACION');
            return ['resultado' => self::RESULTADO_CODIGO_INVALIDO, 'codigosRecuperacion' => []];
        }

        $codigosRecuperacion = $this->generarCodigosRecuperacion();
        $this->conexion->beginTransaction();
        try {
            if (!$this->usuarioModel->guardarSegundoFactor($idUsuario, $this->cifradorSecretos->cifrar($secreto, 'usuario:' . $idUsuario), $pasoAceptado)) {
                $this->conexion->rollBack();
                return ['resultado' => self::RESULTADO_SIN_PENDIENTE, 'codigosRecuperacion' => []];
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

        $this->abrirSesionAdministrativa($usuario, 'TOTP_CONFIGURACION');
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
        if (!$this->verificarLimite($idUsuario, $origen)) {
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
                $this->abrirSesionAdministrativa($usuario, 'TOTP');
                return self::RESULTADO_EXITO;
            }
        } else {
            $huellaCodigo = $this->cifradorSecretos->calcularHuellaCodigoRecuperacion($this->normalizarCodigoRecuperacion($codigoLimpio));
            if ($this->codigoRecuperacionModel->consumirCodigo($idUsuario, $huellaCodigo)) {
                $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_CODIGO_RECUPERACION_USADO, 'usuario', $idUsuario, [
                    'codigosRestantes' => $this->codigoRecuperacionModel->contarCodigosDisponibles($idUsuario),
                ]);
                $this->abrirSesionAdministrativa($usuario, 'CODIGO_RECUPERACION');
                return self::RESULTADO_EXITO;
            }
        }

        $this->registrarFallo($idUsuario, $origen, 'VERIFICACION');
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
     * @param array<string, mixed> $usuario Cuenta verificada.
     * @param string $metodo TOTP, TOTP_CONFIGURACION o CODIGO_RECUPERACION.
     * @return void
     */
    private function abrirSesionAdministrativa(array $usuario, string $metodo): void
    {
        $idUsuario = (int) $usuario['id_usuario'];
        $this->limitadorIntentos->reiniciarIntentosAmbito(self::AMBITO_LIMITE, (string) $idUsuario);
        $this->gestorSesion->crearSesion($usuario, true);
        $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_LOGIN_EXITOSO, 'usuario', $idUsuario, ['segundoFactor' => $metodo]);
    }

    /**
     * @param int $idUsuario Cuenta.
     * @param string $origen IP.
     * @return bool true si se permite otro intento.
     */
    private function verificarLimite(int $idUsuario, string $origen): bool
    {
        if ($this->limitadorIntentos->verificarLimiteAmbito(self::AMBITO_LIMITE, (string) $idUsuario, $origen, MAX_INTENTOS_SEGUNDO_FACTOR, MAX_INTENTOS_LOGIN_ORIGEN)) {
            return true;
        }
        // Se descarta el estado pendiente: hay que volver a escribir la contraseña.
        $this->gestorSesion->limpiarSegundoFactorPendiente();
        $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_LOGIN_BLOQUEADO, 'usuario', $idUsuario, ['etapa' => 'SEGUNDO_FACTOR']);
        return false;
    }

    /**
     * @param int $idUsuario Cuenta.
     * @param string $origen IP.
     * @param string $etapa CONFIGURACION o VERIFICACION.
     * @return void
     */
    private function registrarFallo(int $idUsuario, string $origen, string $etapa): void
    {
        $this->limitadorIntentos->registrarIntentoAmbito(self::AMBITO_LIMITE, (string) $idUsuario, $origen);
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

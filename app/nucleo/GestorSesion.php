<?php

declare(strict_types=1);

/**
 * Administra el ciclo de vida de la sesión del servidor.
 *
 * Responsabilidades (OWASP Session Management Cheat Sheet):
 *  - Configuración segura de la cookie (HttpOnly, SameSite, Secure con HTTPS).
 *  - Modo estricto: PHP rechaza identificadores que él no generó (fijación).
 *  - Regeneración del ID al autenticar y periódicamente.
 *  - Expiración por inactividad y por duración máxima absoluta.
 *  - Revalidación en CADA solicitud contra la base de datos: el rol se lee
 *    siempre de MariaDB, nunca de la sesión, y si version_sesion_usuario
 *    cambió (cambio de rol o contraseña) la sesión se invalida.
 *
 * La sesión solo guarda el id del usuario y marcas de tiempo; nunca el rol,
 * la contraseña ni el hash.
 */
final class GestorSesion
{
    public const MOTIVO_INACTIVIDAD = 'INACTIVIDAD';
    public const MOTIVO_DURACION_MAXIMA = 'DURACION_MAXIMA';
    public const MOTIVO_VERSION_CAMBIADA = 'PRIVILEGIOS_O_CREDENCIALES_CAMBIADOS';
    public const MOTIVO_CUENTA_INEXISTENTE = 'CUENTA_INEXISTENTE';
    public const MOTIVO_CUENTA_NO_ACTIVA = 'CUENTA_NO_ACTIVA';
    public const MOTIVO_SEGUNDO_FACTOR_REQUERIDO = 'SEGUNDO_FACTOR_REQUERIDO';

    /** Enlaces cuyo token se guarda en la sesión para limpiar la URL. */
    public const ENLACE_VERIFICACION = 'verificacion';
    public const ENLACE_ACTIVACION = 'activacion';
    public const ENLACE_RECUPERACION = 'recuperacion';
    /** Enlace que autoriza configurar el segundo factor (R-02). */
    public const ENLACE_ENROLAMIENTO = 'enrolamiento';
    private const ENLACES_VALIDOS = [self::ENLACE_VERIFICACION, self::ENLACE_ACTIVACION, self::ENLACE_RECUPERACION, self::ENLACE_ENROLAMIENTO];

    /** Motivo de la última expiración detectada por validarSesion(). */
    private ?string $motivoUltimaExpiracion = null;

    /** Id del usuario de la sesión que acaba de expirar (para auditoría). */
    private ?int $idUsuarioExpirado = null;

    /**
     * @param UsuarioModel $usuarioModel Fuente de verdad del rol y la versión.
     */
    public function __construct(private readonly UsuarioModel $usuarioModel)
    {
    }

    /** Directorio privado de archivos de sesión (fuera de public/). */
    private const DIRECTORIO_SESIONES = '/storage/sesiones';

    /**
     * Configura e inicia la sesión PHP con parámetros seguros.
     *
     * Cookie:
     *  - Path = APP_RUTA_BASE (p. ej. "/papc/"): en un XAMPP compartido por
     *    varios proyectos, el navegador NO envía la cookie de PAPC a los
     *    demás proyectos del mismo servidor.
     *  - Producción en la raíz del sitio: "__Host-papc_sesion" (exige Secure,
     *    Path=/ y sin Domain). Producción en subcarpeta: "__Secure-papc_sesion"
     *    (exige Secure). Desarrollo por HTTP: "papc_sesion" sin Secure; esto
     *    NO equivale a HTTPS.
     *
     * Archivos de sesión en storage/sesiones: no se mezclan con los de otros
     * proyectos ni los borra el recolector de basura de otra aplicación.
     *
     * @return void
     */
    public static function iniciarSesionPhp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $cookieSegura = Configuracion::esProduccion() || Solicitud::esHttps();
        $rutaCookie = Solicitud::obtenerRutaBase() . '/';

        $directorioSesiones = RUTA_RAIZ . self::DIRECTORIO_SESIONES;
        if (is_dir($directorioSesiones) && is_writable($directorioSesiones)) {
            session_save_path($directorioSesiones);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) DURACION_MAXIMA_SESION_SEGUNDOS);
        session_cache_limiter('nocache');

        session_name(self::obtenerNombreCookie($cookieSegura, $rutaCookie));
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $rutaCookie,
            'secure'   => $cookieSegura,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /**
     * Elige el nombre de la cookie según HTTPS y la ruta publicada.
     *
     * @param bool $cookieSegura Si la cookie lleva el atributo Secure.
     * @param string $rutaCookie Ruta de la cookie ("/" o "/papc/").
     * @return string Nombre de la cookie de sesión.
     */
    private static function obtenerNombreCookie(bool $cookieSegura, string $rutaCookie): string
    {
        if (!$cookieSegura) {
            return 'papc_sesion';
        }
        return $rutaCookie === '/' ? '__Host-papc_sesion' : '__Secure-papc_sesion';
    }

    /**
     * Convierte la sesión anónima actual en una sesión autenticada.
     *
     * Regenera el identificador (evita fijación de sesión), descarta todo
     * dato previo y renueva el token CSRF.
     *
     * @param array<string, mixed> $usuario Fila de usuario ya verificada.
     * @param bool $segundoFactorCompletado true si además superó el segundo
     *        factor (obligatorio para roles administrativos).
     * @return void
     */
    public function crearSesion(array $usuario, bool $segundoFactorCompletado = false): void
    {
        self::iniciarSesionPhp();
        session_regenerate_id(true);
        $_SESSION = [];

        $momentoActual = time();
        $_SESSION['idUsuario'] = (int) $usuario['id_usuario'];
        $_SESSION['versionSesion'] = (int) $usuario['version_sesion_usuario'];
        $_SESSION['segundoFactorCompletado'] = $segundoFactorCompletado;
        $_SESSION['fechaCreacion'] = $momentoActual;
        $_SESSION['ultimaActividad'] = $momentoActual;
        $_SESSION['ultimaRegeneracion'] = $momentoActual;

        ProtectorCsrf::renovarTokenCsrf();
    }

    /**
     * Comprueba que la sesión autenticada siga siendo válida.
     *
     * @return array<string, mixed>|null Usuario actualizado desde la BD, o
     *         null si no hay sesión autenticada o si expiró (en ese caso la
     *         sesión ya fue destruida y el motivo queda disponible).
     */
    public function validarSesion(): ?array
    {
        self::iniciarSesionPhp();
        $this->motivoUltimaExpiracion = null;
        $this->idUsuarioExpirado = null;

        if (!isset($_SESSION['idUsuario']) || !is_int($_SESSION['idUsuario'])) {
            return null;
        }

        $momentoActual = time();
        $motivoExpiracion = $this->detectarExpiracionTemporal($momentoActual);

        $usuario = null;
        if ($motivoExpiracion === null) {
            $usuario = $this->usuarioModel->obtenerUsuarioPorId($_SESSION['idUsuario']);
            if ($usuario === null) {
                $motivoExpiracion = self::MOTIVO_CUENTA_INEXISTENTE;
            } elseif ((int) $usuario['version_sesion_usuario'] !== (int) ($_SESSION['versionSesion'] ?? -1)) {
                $motivoExpiracion = self::MOTIVO_VERSION_CAMBIADA;
            } elseif (!UsuarioModel::puedeIniciarSesion($usuario)) {
                // INVITADA, SUSPENDIDA o BAJA: no mantienen sesión (RF-08).
                $motivoExpiracion = self::MOTIVO_CUENTA_NO_ACTIVA;
            } elseif (in_array($usuario['rol_usuario'], UsuarioModel::ROLES_CON_SEGUNDO_FACTOR, true)
                && ($_SESSION['segundoFactorCompletado'] ?? false) !== true) {
                // Defensa en profundidad: una sesión administrativa sin segundo factor no se acepta.
                $motivoExpiracion = self::MOTIVO_SEGUNDO_FACTOR_REQUERIDO;
            }
        }

        if ($motivoExpiracion !== null) {
            $this->expirarSesion($motivoExpiracion);
            return null;
        }

        $_SESSION['ultimaActividad'] = $momentoActual;
        if ($momentoActual - (int) $_SESSION['ultimaRegeneracion'] >= INTERVALO_REGENERACION_SESION_SEGUNDOS) {
            session_regenerate_id(true);
            $_SESSION['ultimaRegeneracion'] = $momentoActual;
        }

        return $usuario;
    }

    /**
     * Termina la sesión por un motivo de seguridad y conserva el motivo para
     * que el middleware lo audite e informe al usuario.
     *
     * @param string $motivo Una de las constantes MOTIVO_*.
     * @return void
     */
    public function expirarSesion(string $motivo): void
    {
        $this->motivoUltimaExpiracion = $motivo;
        $this->idUsuarioExpirado = isset($_SESSION['idUsuario']) ? (int) $_SESSION['idUsuario'] : null;
        $this->destruirSesion();
        // Se abre una sesión anónima nueva para poder mostrar el aviso.
        self::iniciarSesionPhp();
    }

    /**
     * Elimina los datos, el archivo de sesión del servidor y la cookie.
     *
     * Tras esto, el identificador anterior queda inservible: aunque alguien
     * lo reenvíe, el modo estricto obliga a PHP a emitir uno nuevo vacío.
     *
     * @return void
     */
    public function destruirSesion(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        if (!headers_sent()) {
            $parametrosCookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $parametrosCookie['path'],
                'secure'   => $parametrosCookie['secure'],
                'httponly' => true,
                'samesite' => $parametrosCookie['samesite'],
            ]);
        }
        session_destroy();
    }

    /**
     * @return string|null Motivo de la última expiración, si ocurrió.
     */
    public function obtenerMotivoUltimaExpiracion(): ?string
    {
        return $this->motivoUltimaExpiracion;
    }

    /**
     * @return int|null Id del usuario cuya sesión acaba de expirar.
     */
    public function obtenerIdUsuarioExpirado(): ?int
    {
        return $this->idUsuarioExpirado;
    }

    /**
     * Guarda un mensaje de un solo uso para la siguiente página.
     *
     * @param string $tipoMensaje 'exito', 'error' o 'aviso'.
     * @param string $mensaje Texto (se escapará al mostrarse).
     * @return void
     */
    public function guardarMensajeFlash(string $tipoMensaje, string $mensaje): void
    {
        self::iniciarSesionPhp();
        $_SESSION['mensajesFlash'][] = ['tipo' => $tipoMensaje, 'mensaje' => $mensaje];
    }

    /**
     * Devuelve y elimina los mensajes pendientes.
     *
     * @return list<array{tipo: string, mensaje: string}>
     */
    public function extraerMensajesFlash(): array
    {
        self::iniciarSesionPhp();
        $mensajes = $_SESSION['mensajesFlash'] ?? [];
        unset($_SESSION['mensajesFlash']);
        return is_array($mensajes) ? $mensajes : [];
    }

    /**
     * Recuerda a qué ruta interna quería ir el usuario antes del login.
     *
     * @param string $rutaInterna Ruta GET validada como interna.
     * @return void
     */
    public function guardarRutaPendiente(string $rutaInterna): void
    {
        if (Respuesta::esRutaInternaSegura($rutaInterna)) {
            $_SESSION['rutaPendiente'] = $rutaInterna;
        }
    }

    /**
     * Devuelve y elimina la ruta pendiente (o null).
     *
     * @return string|null Ruta interna segura.
     */
    public function extraerRutaPendiente(): ?string
    {
        $ruta = $_SESSION['rutaPendiente'] ?? null;
        unset($_SESSION['rutaPendiente']);
        return is_string($ruta) && Respuesta::esRutaInternaSegura($ruta) ? $ruta : null;
    }

    /* ----------------- Segundo factor pendiente (antes de la sesión) ----------------- */

    /**
     * Marca que la contraseña de una cuenta administrativa fue correcta pero
     * falta el segundo factor. NO crea una sesión autenticada: hasta superar
     * el segundo factor, la persona sigue siendo un visitante.
     *
     * @param array<string, mixed> $usuario Cuenta verificada por contraseña.
     * @return void
     */
    public function iniciarSegundoFactorPendiente(array $usuario): void
    {
        self::iniciarSesionPhp();
        session_regenerate_id(true);
        // Se conserva solo el enlace de enrolamiento, por si la persona lo abrió
        // antes de escribir su contraseña en este mismo navegador.
        $tokenEnrolamiento = $_SESSION['tokensEnlace'][self::ENLACE_ENROLAMIENTO] ?? null;
        $_SESSION = [];
        if (GeneradorTokens::esFormatoValido($tokenEnrolamiento)) {
            $_SESSION['tokensEnlace'][self::ENLACE_ENROLAMIENTO] = $tokenEnrolamiento;
        }
        $_SESSION['segundoFactorPendiente'] = [
            'idUsuario'     => (int) $usuario['id_usuario'],
            'versionSesion' => (int) $usuario['version_sesion_usuario'],
            'momentoInicio' => time(),
        ];
        ProtectorCsrf::renovarTokenCsrf();
    }

    /**
     * @return array{idUsuario: int, versionSesion: int, momentoInicio: int, secretoPropuesto?: string}|null
     *         Datos pendientes vigentes, o null si no hay o vencieron.
     */
    public function obtenerSegundoFactorPendiente(): ?array
    {
        self::iniciarSesionPhp();
        $pendiente = $_SESSION['segundoFactorPendiente'] ?? null;
        if (!is_array($pendiente) || !is_int($pendiente['idUsuario'] ?? null)) {
            return null;
        }
        if (time() - (int) $pendiente['momentoInicio'] > VIGENCIA_SEGUNDO_FACTOR_PENDIENTE_SEGUNDOS) {
            $this->limpiarSegundoFactorPendiente();
            return null;
        }
        return $pendiente;
    }

    /**
     * Guarda el secreto propuesto durante la configuración inicial (aún no
     * confirmado, por eso no va a la base de datos).
     *
     * @param string $secretoBase32 Secreto generado.
     * @return void
     */
    public function guardarSecretoPropuesto(string $secretoBase32): void
    {
        if (isset($_SESSION['segundoFactorPendiente']) && is_array($_SESSION['segundoFactorPendiente'])) {
            $_SESSION['segundoFactorPendiente']['secretoPropuesto'] = $secretoBase32;
        }
    }

    /**
     * @return void
     */
    public function limpiarSegundoFactorPendiente(): void
    {
        unset($_SESSION['segundoFactorPendiente']);
    }

    /* ---------------------- Tokens de enlaces y datos de un solo uso ---------------------- */

    /**
     * Guarda el token de un enlace para redirigir a una URL sin token
     * (así no queda en el historial ni en el encabezado Referer).
     *
     * @param string $tipoEnlace Constante ENLACE_*.
     * @param string $token Token con formato ya validado.
     * @return void
     */
    public function guardarTokenEnlace(string $tipoEnlace, string $token): void
    {
        self::iniciarSesionPhp();
        if (in_array($tipoEnlace, self::ENLACES_VALIDOS, true)) {
            $_SESSION['tokensEnlace'][$tipoEnlace] = $token;
        }
    }

    /**
     * @param string $tipoEnlace Constante ENLACE_*.
     * @return string|null Token guardado o null.
     */
    public function obtenerTokenEnlace(string $tipoEnlace): ?string
    {
        self::iniciarSesionPhp();
        $token = $_SESSION['tokensEnlace'][$tipoEnlace] ?? null;
        return GeneradorTokens::esFormatoValido($token) ? $token : null;
    }

    /**
     * @param string $tipoEnlace Constante ENLACE_*.
     * @return void
     */
    public function eliminarTokenEnlace(string $tipoEnlace): void
    {
        unset($_SESSION['tokensEnlace'][$tipoEnlace]);
    }

    /**
     * Guarda un dato que se mostrará UNA sola vez (p. ej. códigos de
     * recuperación recién generados).
     *
     * @param string $clave Nombre del dato.
     * @param mixed $valor Valor.
     * @return void
     */
    public function guardarDatoUnaVez(string $clave, mixed $valor): void
    {
        self::iniciarSesionPhp();
        $_SESSION['datosUnaVez'][$clave] = $valor;
    }

    /**
     * @param string $clave Nombre del dato.
     * @return mixed Valor o null; se elimina al leerlo.
     */
    public function extraerDatoUnaVez(string $clave): mixed
    {
        self::iniciarSesionPhp();
        $valor = $_SESSION['datosUnaVez'][$clave] ?? null;
        unset($_SESSION['datosUnaVez'][$clave]);
        return $valor;
    }

    /**
     * Tiempo de inactividad tras el cual la sesión expira.
     *
     * Por omisión TIEMPO_INACTIVIDAD_SESION_SEGUNDOS (30 min). Para demostrar
     * la expiración (E10-P07) puede reducirse en el .env con
     * SESION_INACTIVIDAD_SEGUNDOS, entre 60 y el valor por omisión; nunca se
     * permite alargarlo ni desactivarlo desde el .env.
     *
     * @return int Segundos de inactividad permitidos.
     */
    public static function obtenerTiempoInactividadSegundos(): int
    {
        $valorConfigurado = Configuracion::obtener('SESION_INACTIVIDAD_SEGUNDOS');
        if ($valorConfigurado === null || $valorConfigurado === '') {
            return TIEMPO_INACTIVIDAD_SESION_SEGUNDOS;
        }
        $segundos = filter_var($valorConfigurado, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => TIEMPO_INACTIVIDAD_MINIMO_SEGUNDOS, 'max_range' => TIEMPO_INACTIVIDAD_SESION_SEGUNDOS],
        ]);
        return $segundos === false ? TIEMPO_INACTIVIDAD_SESION_SEGUNDOS : $segundos;
    }

    /**
     * Evalúa los límites de tiempo de la sesión.
     *
     * @param int $momentoActual Marca de tiempo UNIX actual.
     * @return string|null Motivo de expiración o null si sigue vigente.
     */
    private function detectarExpiracionTemporal(int $momentoActual): ?string
    {
        $ultimaActividad = (int) ($_SESSION['ultimaActividad'] ?? 0);
        $fechaCreacion = (int) ($_SESSION['fechaCreacion'] ?? 0);

        if ($momentoActual - $ultimaActividad > self::obtenerTiempoInactividadSegundos()) {
            return self::MOTIVO_INACTIVIDAD;
        }
        if ($momentoActual - $fechaCreacion > DURACION_MAXIMA_SESION_SEGUNDOS) {
            return self::MOTIVO_DURACION_MAXIMA;
        }
        return null;
    }
}

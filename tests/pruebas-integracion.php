<?php

/**
 * Pruebas de integración del circuito de la especificación 1.3:
 * solicitud -> verificación del correo -> aprobación -> cuenta INVITADA ->
 * activación -> inicio de sesión (con segundo factor para administradores)
 * -> recuperación, estados de cuenta y roles.
 *
 * Se ejecutan contra una base MariaDB de pruebas, conectando con la cuenta
 * de MÍNIMOS privilegios (igual que la aplicación real). Los correos van a
 * un buzón temporal (BuzonPruebas) del que se leen los enlaces.
 * Los identificadores AC-xx remiten a la sección 11.1 de la especificación.
 */

declare(strict_types=1);

return static function (MarcoPruebas $marco, BaseDatosPruebas $baseDatos): void {
    $buzon = new BuzonPruebas((string) getenv('CORREO_DIRECTORIO_BUZON'));
    $buzon->vaciar();

    $dependencias = Aplicacion::construirDependencias(obtenerConexionBaseDatos());
    /** @var ServicioAutenticacion $servicioAutenticacion */
    $servicioAutenticacion = $dependencias['servicioAutenticacion'];
    /** @var ServicioSolicitudAcceso $servicioSolicitud */
    $servicioSolicitud = $dependencias['servicioSolicitudAcceso'];
    /** @var ServicioCredenciales $servicioCredenciales */
    $servicioCredenciales = $dependencias['servicioCredenciales'];
    /** @var ServicioSegundoFactor $servicioSegundoFactor */
    $servicioSegundoFactor = $dependencias['servicioSegundoFactor'];
    /** @var ServicioAdministracionUsuarios $servicioAdministracion */
    $servicioAdministracion = $dependencias['servicioAdministracion'];
    /** @var GestorSesion $gestorSesion */
    $gestorSesion = $dependencias['gestorSesion'];
    /** @var RoleMiddleware $roleMiddleware */
    $roleMiddleware = $dependencias['roleMiddleware'];
    /** @var UsuarioModel $usuarioModel */
    $usuarioModel = $dependencias['usuarioModel'];

    $contrasenaComun = 'frase de paso de pruebas numero uno';
    $contrasenaPrincipal = 'el faro del puerto ilumina la bahia';

    $reiniciarSesion = static function (): void {
        GestorSesion::iniciarSesionPhp();
        $_SESSION = [];
    };

    // Cada solicitud llega desde una IP distinta para no mezclar los límites
    // por origen con lo que se prueba (el límite se prueba aparte en P15).
    $contadorOrigen = 0;
    $origenNuevo = static function () use (&$contadorOrigen): string {
        $contadorOrigen++;
        return '198.18.' . intdiv($contadorOrigen, 250) . '.' . ($contadorOrigen % 250 + 1);
    };

    $datosSolicitud = static fn (string $nombres, string $paterno, string $correo, string $tipo, string $identificador): array => [
        'nombres' => $nombres, 'apellidoPaterno' => $paterno, 'apellidoMaterno' => null,
        'tipoIntegrante' => $tipo, 'identificador' => $identificador, 'correo' => $correo,
    ];

    $solicitar = static function (string $nombres, string $paterno, string $correo, string $tipo, string $identificador) use ($servicioSolicitud, $datosSolicitud, $origenNuevo): string {
        return $servicioSolicitud->registrarSolicitud($datosSolicitud($nombres, $paterno, $correo, $tipo, $identificador), $origenNuevo());
    };

    $verificar = static function (string $correo) use ($servicioSolicitud, $buzon): string {
        $token = $buzon->ultimoToken($correo, '/verificar-solicitud');
        MarcoPruebas::afirmar($token !== null, "Hay enlace de verificación para {$correo}");
        return $servicioSolicitud->verificarCorreo((string) $token);
    };

    $actor = static fn (string $correo): array => (array) $usuarioModel->obtenerUsuarioPorId((int) $usuarioModel->obtenerUsuarioPorCorreo($correo)['id_usuario']);

    $aprobar = static function (array $usuarioActor, string $correo) use ($servicioSolicitud, $baseDatos): array {
        return $servicioSolicitud->aprobarSolicitud($usuarioActor, (int) $baseDatos->obtenerFilaSolicitud($correo)['id_solicitud_acceso'], 'Cotejo con el padrón ficticio de pruebas', true);
    };

    $activar = static function (string $correo, string $contrasena) use ($servicioCredenciales, $buzon): array {
        return $servicioCredenciales->activarCuenta((string) $buzon->ultimoToken($correo, '/activar-cuenta'), $contrasena);
    };

    $altaCompleta = static function (string $nombres, string $paterno, string $correo, string $tipo, string $identificador, array $usuarioActor)
        use ($solicitar, $verificar, $aprobar, $activar, $contrasenaComun): void {
        $solicitar($nombres, $paterno, $correo, $tipo, $identificador);
        $verificar($correo);
        $resultado = $aprobar($usuarioActor, $correo);
        MarcoPruebas::afirmar($resultado['exito'], 'Aprobación de ' . $correo . ': ' . $resultado['mensaje']);
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_EXITO, $activar($correo, $contrasenaComun)['resultado'], "Activación de {$correo}");
    };

    $iniciarComo = static function (string $correo, ?string $contrasena = null) use ($servicioAutenticacion, $reiniciarSesion, $contrasenaComun): string {
        $reiniciarSesion();
        return $servicioAutenticacion->iniciarSesion($correo, $contrasena ?? $contrasenaComun, '192.0.2.250');
    };

    // Secretos TOTP que las "aplicaciones de autenticación" de prueba conocen.
    $secretosTotp = [];
    $codigoActual = static fn (string $secreto, int $desplazamiento = 0): string
        => SegundoFactorTotp::calcularCodigo(SegundoFactorTotp::decodificarBase32($secreto), intdiv(time(), 30) + $desplazamiento);

    /**
     * Inicia sesión de un administrador completando (o configurando) el
     * segundo factor. Para que las pruebas no dependan del reloj, antes de
     * usar el código vigente se olvida el último paso usado (preparación de
     * escenario; la protección contra reutilización se prueba en P26b).
     */
    $entrarConSegundoFactor = static function (string $correo, string $contrasena)
        use (&$secretosTotp, $iniciarComo, $servicioSegundoFactor, $codigoActual, $baseDatos): string {
        $resultado = $iniciarComo($correo, $contrasena);
        if ($resultado === ServicioAutenticacion::RESULTADO_REQUIERE_CONFIGURAR_SEGUNDO_FACTOR) {
            $configuracion = $servicioSegundoFactor->prepararConfiguracion();
            $secretosTotp[$correo] = $configuracion['secreto'];
            return $servicioSegundoFactor->confirmarConfiguracion($codigoActual($configuracion['secreto']), '192.0.2.250')['resultado'];
        }
        if ($resultado === ServicioAutenticacion::RESULTADO_REQUIERE_SEGUNDO_FACTOR) {
            $baseDatos->obtenerConexionAdministrativa()->prepare('UPDATE usuario SET ultimo_paso_mfa_usuario = NULL WHERE correo_electronico_usuario = ?')->execute([$correo]);
            return $servicioSegundoFactor->verificarCodigo($codigoActual($secretosTotp[$correo]), '192.0.2.250');
        }
        return $resultado;
    };

    $construirEnrutador = static function () use ($dependencias): Enrutador {
        $enrutador = new Enrutador($dependencias['authMiddleware'], $dependencias['roleMiddleware'], $dependencias['auditoriaModel']);
        (require RUTA_RAIZ . '/config/rutas.php')($enrutador, $dependencias);
        return $enrutador;
    };

    /* ======================= Primer administrador principal ======================= */

    $marco->probar('P19', 'AC-27: el primer principal se crea solo por consola, sin contraseña predeterminada y una sola vez', static function () use ($servicioAdministracion, $baseDatos): void {
        $resultado = $servicioAdministracion->inicializarAdministradorPrincipal('paula@ejemplo.mx', ['nombres' => 'Paula', 'apellidoPaterno' => 'Principal', 'apellidoMaterno' => null]);
        MarcoPruebas::afirmar($resultado['exito'], $resultado['mensaje']);
        MarcoPruebas::afirmar(GeneradorTokens::esFormatoValido($resultado['tokenActivacion']), 'Se entrega un enlace de activación');
        $fila = $baseDatos->obtenerFilaUsuario('paula@ejemplo.mx');
        MarcoPruebas::afirmarIgual('ADMINISTRADOR_PRINCIPAL', $fila['rol_usuario'], 'Rol principal');
        MarcoPruebas::afirmarIgual('INVITADA', $fila['estado_cuenta_usuario'], 'Cuenta INVITADA');
        MarcoPruebas::afirmarIgual(null, $fila['hash_contrasena_usuario'], 'Sin contraseña hasta activar');

        $segundo = $servicioAdministracion->inicializarAdministradorPrincipal('otro@ejemplo.mx', ['nombres' => 'Otro', 'apellidoPaterno' => 'Principal', 'apellidoMaterno' => null]);
        MarcoPruebas::afirmar(!$segundo['exito'], 'La inicialización no se repite');
        MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaUsuario('otro@ejemplo.mx'), 'No se creó otra cuenta');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contarEventos('INICIALIZACION_ADMINISTRADOR_PRINCIPAL'), 'Inicialización auditada una vez');
        $GLOBALS['tokenActivacionPaula'] = $resultado['tokenActivacion'];
    });

    $marco->probar('P30a', 'AC-09/AC-10: activación rechaza contraseñas comunes, usa el enlace una sola vez y no abre sesión', static function () use ($servicioCredenciales, $baseDatos, $contrasenaPrincipal, $reiniciarSesion): void {
        $reiniciarSesion();
        $token = (string) $GLOBALS['tokenActivacionPaula'];
        $rechazada = $servicioCredenciales->activarCuenta($token, 'Universidad del Mar 2026');
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_CONTRASENA_RECHAZADA, $rechazada['resultado'], 'Contraseña común de 15+ rechazada');
        MarcoPruebas::afirmarIgual('INVITADA', $baseDatos->obtenerFilaUsuario('paula@ejemplo.mx')['estado_cuenta_usuario'], 'Sigue INVITADA y el enlace no se consumió');

        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_EXITO, $servicioCredenciales->activarCuenta($token, $contrasenaPrincipal)['resultado'], 'Activación');
        $fila = $baseDatos->obtenerFilaUsuario('paula@ejemplo.mx');
        MarcoPruebas::afirmarIgual('ACTIVA', $fila['estado_cuenta_usuario'], 'Cuenta ACTIVA');
        MarcoPruebas::afirmar(str_starts_with((string) $fila['hash_contrasena_usuario'], '$argon2id$'), 'Hash Argon2id (P05b)');
        MarcoPruebas::afirmar(!str_contains((string) $fila['hash_contrasena_usuario'], $contrasenaPrincipal), 'Sin contraseña en claro');
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_ENLACE_INVALIDO, $servicioCredenciales->activarCuenta($token, 'otra frase distinta y larga de paso')['resultado'], 'El enlace ya no sirve');
        MarcoPruebas::afirmar(!isset($_SESSION['idUsuario']), 'Activar no inicia sesión');
    });

    $marco->probar('P26a', 'AC-14: la contraseña correcta de un administrador sin segundo factor no abre sesión', static function () use ($iniciarComo, $gestorSesion, $construirEnrutador, $contrasenaPrincipal, $servicioSegundoFactor, $codigoActual, $baseDatos, &$secretosTotp): void {
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_REQUIERE_CONFIGURAR_SEGUNDO_FACTOR, $iniciarComo('paula@ejemplo.mx', $contrasenaPrincipal), 'Pide configurar el segundo factor');
        MarcoPruebas::afirmar(!isset($_SESSION['idUsuario']), 'No hay sesión autenticada');
        MarcoPruebas::afirmarIgual(null, $gestorSesion->validarSesion(), 'Para el servidor sigue siendo visitante');
        $enrutador = $construirEnrutador();
        MarcoPruebas::afirmarExcepcionHttp(401, static fn () => $enrutador->despachar('GET', '/admin/solicitudes', []), 'Administración cerrada');

        $configuracion = $servicioSegundoFactor->prepararConfiguracion();
        MarcoPruebas::afirmar($configuracion !== null && str_starts_with($configuracion['uri'], 'otpauth://totp/PAPC:'), 'Se propone un secreto');
        $fallido = $servicioSegundoFactor->confirmarConfiguracion('000000' === $codigoActual($configuracion['secreto']) ? '111111' : '000000', '192.0.2.250');
        MarcoPruebas::afirmarIgual(ServicioSegundoFactor::RESULTADO_CODIGO_INVALIDO, $fallido['resultado'], 'Código incorrecto rechazado');
        MarcoPruebas::afirmar(!isset($_SESSION['idUsuario']), 'Sigue sin sesión');

        $resultado = $servicioSegundoFactor->confirmarConfiguracion($codigoActual($configuracion['secreto']), '192.0.2.250');
        MarcoPruebas::afirmarIgual(ServicioSegundoFactor::RESULTADO_EXITO, $resultado['resultado'], 'Configuración confirmada');
        MarcoPruebas::afirmarIgual(CANTIDAD_CODIGOS_RECUPERACION_MFA, count($resultado['codigosRecuperacion']), 'Códigos de recuperación generados');
        MarcoPruebas::afirmar($gestorSesion->validarSesion() !== null && $_SESSION['segundoFactorCompletado'] === true, 'Sesión administrativa tras el segundo factor');
        $secretoGuardado = (string) $baseDatos->obtenerFilaUsuario('paula@ejemplo.mx')['secreto_mfa_usuario'];
        MarcoPruebas::afirmar(str_starts_with($secretoGuardado, 'v1:') && !str_contains($secretoGuardado, $configuracion['secreto']), 'Secreto cifrado en la BD');
        MarcoPruebas::afirmarIgual(CANTIDAD_CODIGOS_RECUPERACION_MFA, $baseDatos->contar('SELECT COUNT(*) FROM codigo_recuperacion_mfa WHERE fecha_uso_codigo_recuperacion_mfa IS NULL'), 'Solo se guardan sus huellas');
        $secretosTotp['paula@ejemplo.mx'] = $configuracion['secreto'];
        $GLOBALS['codigosRecuperacionPaula'] = $resultado['codigosRecuperacion'];
    });

    /* ============================ Solicitud de acceso ============================ */

    $marco->probar('P01', 'AC-01/RF-02: la solicitud queda PENDIENTE sin crear cuenta ni contraseña y envía un enlace de verificación', static function () use ($solicitar, $baseDatos, $buzon): void {
        MarcoPruebas::afirmarIgual(ServicioSolicitudAcceso::RESULTADO_RECIBIDA, $solicitar('Ana', 'Alumna', 'ana@ejemplo.mx', 'ALUMNO', 'A2026001'), 'Respuesta neutral');
        $solicitud = $baseDatos->obtenerFilaSolicitud('ana@ejemplo.mx');
        MarcoPruebas::afirmarIgual('PENDIENTE', $solicitud['estado_solicitud'], 'Estado PENDIENTE');
        MarcoPruebas::afirmarIgual(null, $solicitud['fecha_verificacion_correo_solicitud'], 'Correo aún no verificado');
        MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaUsuario('ana@ejemplo.mx'), 'No existe cuenta');
        MarcoPruebas::afirmar($buzon->ultimoToken('ana@ejemplo.mx', '/verificar-solicitud') !== null, 'Enlace de verificación en el buzón');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contarEventos('SOLICITUD_CREADA'), 'Auditado');
        MarcoPruebas::afirmarIgual(0, $baseDatos->contar("SELECT COUNT(*) FROM token_solicitud WHERE hash_token_solicitud = '" . $buzon->ultimoToken('ana@ejemplo.mx', '/verificar-solicitud') . "'"), 'El token NO se guarda en claro');
    });

    $marco->probar('P02', 'AC-03: sin verificar el correo la solicitud no aparece en la bandeja ni puede aprobarse', static function () use ($actor, $aprobar, $baseDatos): void {
        $bandeja = (new SolicitudAccesoModel(obtenerConexionBaseDatos()))->listarSolicitudes('PENDIENTE', null, null);
        MarcoPruebas::afirmarIgual([], $bandeja, 'Bandeja vacía');
        $resultado = $aprobar($actor('paula@ejemplo.mx'), 'ana@ejemplo.mx');
        MarcoPruebas::afirmar(!$resultado['exito'], 'No se puede aprobar');
        MarcoPruebas::afirmarIgual('PENDIENTE', $baseDatos->obtenerFilaSolicitud('ana@ejemplo.mx')['estado_solicitud'], 'Sin cambios');
        MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaUsuario('ana@ejemplo.mx'), 'Sin cuenta');
    });

    $marco->probar('P03', 'AC-04: una solicitud repetida no crea otra solicitud ni deja dos enlaces vigentes', static function () use ($solicitar, $buzon, $baseDatos, $servicioSolicitud, $verificar): void {
        $tokenAnterior = (string) $buzon->ultimoToken('ana@ejemplo.mx', '/verificar-solicitud');
        MarcoPruebas::afirmarIgual(ServicioSolicitudAcceso::RESULTADO_RECIBIDA, $solicitar('Ana María', 'Otra', 'ana@ejemplo.mx', 'PROFESOR', 'P999'), 'Misma respuesta neutral');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contar("SELECT COUNT(*) FROM solicitud_acceso WHERE correo_electronico_solicitante = 'ana@ejemplo.mx'"), 'Una sola solicitud');
        MarcoPruebas::afirmarIgual('ALUMNO', $baseDatos->obtenerFilaSolicitud('ana@ejemplo.mx')['tipo_integrante_solicitado'], 'Los datos originales no se reemplazan en silencio');
        MarcoPruebas::afirmar($buzon->ultimoToken('ana@ejemplo.mx', '/verificar-solicitud') !== $tokenAnterior, 'Se reenvió un enlace nuevo');
        MarcoPruebas::afirmarIgual(ServicioSolicitudAcceso::RESULTADO_ENLACE_INVALIDO, $servicioSolicitud->verificarCorreo($tokenAnterior), 'El enlace anterior dejó de servir');
        MarcoPruebas::afirmarIgual(ServicioSolicitudAcceso::RESULTADO_VERIFICADA, $verificar('ana@ejemplo.mx'), 'El nuevo sí verifica');

        $mensajesAntes = count($buzon->mensajesPara('ana@ejemplo.mx'));
        $solicitar('Ana', 'Alumna', 'ana@ejemplo.mx', 'ALUMNO', 'A2026001');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contar("SELECT COUNT(*) FROM solicitud_acceso WHERE correo_electronico_solicitante = 'ana@ejemplo.mx'"), 'Sigue una sola');
        $mensajes = $buzon->mensajesPara('ana@ejemplo.mx');
        MarcoPruebas::afirmar(count($mensajes) === $mensajesAntes + 1 && str_contains(end($mensajes), 'ya está en revisión'), 'Se avisa por correo que está en revisión');
    });

    $marco->probar('P25', 'RF-03: el enlace de verificación sirve una vez, vence y puede reenviarse', static function () use ($solicitar, $buzon, $servicioSolicitud, $baseDatos, $origenNuevo): void {
        $solicitar('Beto', 'Bravo', 'beto@ejemplo.mx', 'ALUMNO', 'A2026002');
        $token = (string) $buzon->ultimoToken('beto@ejemplo.mx', '/verificar-solicitud');
        $baseDatos->vencerTokens('token_solicitud');
        MarcoPruebas::afirmarIgual(ServicioSolicitudAcceso::RESULTADO_ENLACE_INVALIDO, $servicioSolicitud->verificarCorreo($token), 'Enlace vencido rechazado');
        MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaSolicitud('beto@ejemplo.mx')['fecha_verificacion_correo_solicitud'], 'Sigue sin verificar');

        MarcoPruebas::afirmarIgual(ServicioSolicitudAcceso::RESULTADO_RECIBIDA, $servicioSolicitud->reenviarVerificacion('beto@ejemplo.mx', $origenNuevo()), 'Reenvío');
        MarcoPruebas::afirmarIgual(ServicioSolicitudAcceso::RESULTADO_RECIBIDA, $servicioSolicitud->reenviarVerificacion('nadie@ejemplo.mx', $origenNuevo()), 'Misma respuesta para un correo sin solicitud');
        MarcoPruebas::afirmarIgual([], $buzon->mensajesPara('nadie@ejemplo.mx'), 'Sin correo para quien no solicitó');
        $tokenNuevo = (string) $buzon->ultimoToken('beto@ejemplo.mx', '/verificar-solicitud');
        MarcoPruebas::afirmarIgual(ServicioSolicitudAcceso::RESULTADO_VERIFICADA, $servicioSolicitud->verificarCorreo($tokenNuevo), 'Verificado');
        MarcoPruebas::afirmarIgual(ServicioSolicitudAcceso::RESULTADO_ENLACE_INVALIDO, $servicioSolicitud->verificarCorreo($tokenNuevo), 'Segundo uso rechazado');
    });

    /* ======================== Revisión administrativa ======================== */

    $marco->probar('P27', 'AC-06/RF-04/RF-05: aprobar crea cuenta INVITADA (rol USUARIO), registra revisor, fecha y método, y envía invitación', static function () use ($actor, $aprobar, $baseDatos, $buzon, $servicioSolicitud): void {
        $paula = $actor('paula@ejemplo.mx');
        $idSolicitud = (int) $baseDatos->obtenerFilaSolicitud('ana@ejemplo.mx')['id_solicitud_acceso'];
        $sinConfirmar = $servicioSolicitud->aprobarSolicitud($paula, $idSolicitud, 'Cotejo con el padrón', false);
        MarcoPruebas::afirmar(!$sinConfirmar['exito'], 'Exige confirmar la verificación de pertenencia');
        $sinMetodo = $servicioSolicitud->aprobarSolicitud($paula, $idSolicitud, null, true);
        MarcoPruebas::afirmar(!$sinMetodo['exito'], 'Exige registrar el método de verificación');

        $resultado = $aprobar($paula, 'ana@ejemplo.mx');
        MarcoPruebas::afirmar($resultado['exito'], $resultado['mensaje']);
        MarcoPruebas::afirmar(str_contains($resultado['mensaje'], 'Se generó una invitación'), 'Mensaje de éxito real (el buzón recibió el correo)');
        $cuenta = $baseDatos->obtenerFilaUsuario('ana@ejemplo.mx');
        MarcoPruebas::afirmarIgual('INVITADA', $cuenta['estado_cuenta_usuario'], 'Cuenta INVITADA');
        MarcoPruebas::afirmarIgual('USUARIO', $cuenta['rol_usuario'], 'Rol USUARIO');
        MarcoPruebas::afirmarIgual(null, $cuenta['hash_contrasena_usuario'], 'El administrador no fija contraseña');
        MarcoPruebas::afirmarIgual((int) $paula['id_usuario'], (int) $cuenta['id_usuario_autorizador'], 'Autorizador registrado');
        MarcoPruebas::afirmarIgual('A2026001', $cuenta['identificador_institucional_usuario'], 'Identificador validado');
        MarcoPruebas::afirmarIgual('Ana Alumna', $cuenta['nombre_usuario'], 'Nombre calculado por MariaDB (migración 003)');
        $solicitud = $baseDatos->obtenerFilaSolicitud('ana@ejemplo.mx');
        MarcoPruebas::afirmarIgual('APROBADA', $solicitud['estado_solicitud'], 'Solicitud APROBADA');
        MarcoPruebas::afirmarIgual((int) $paula['id_usuario'], (int) $solicitud['id_usuario_revisor'], 'Revisor');
        MarcoPruebas::afirmar($solicitud['fecha_resolucion_solicitud'] !== null && $solicitud['metodo_verificacion_solicitud'] !== null, 'Fecha y método');
        MarcoPruebas::afirmarIgual((int) $cuenta['id_usuario'], (int) $solicitud['id_usuario_vinculado'], 'Solicitud vinculada a la cuenta');
        MarcoPruebas::afirmar($buzon->ultimoToken('ana@ejemplo.mx', '/activar-cuenta') !== null, 'Invitación con enlace de activación');
        MarcoPruebas::afirmar(!str_contains(implode('', $buzon->mensajesPara('ana@ejemplo.mx')), 'Contraseña:'), 'La invitación no contiene contraseña');

        $repetida = $aprobar($paula, 'ana@ejemplo.mx');
        MarcoPruebas::afirmar(!$repetida['exito'] && str_contains($repetida['mensaje'], 'ya fue resuelta'), 'No se admiten decisiones duplicadas');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contar("SELECT COUNT(*) FROM usuario WHERE correo_electronico_usuario = 'ana@ejemplo.mx'"), 'Una sola cuenta');
    });

    $marco->probar('P29', 'AC-12: una cuenta INVITADA no inicia sesión y recibe la respuesta neutral', static function () use ($iniciarComo, $contrasenaComun): void {
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_CREDENCIALES_INVALIDAS, $iniciarComo('ana@ejemplo.mx', $contrasenaComun), 'INVITADA rechazada');
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_CREDENCIALES_INVALIDAS, $iniciarComo('nadie@ejemplo.mx', $contrasenaComun), 'Igual que un correo inexistente');
        MarcoPruebas::afirmar(!isset($_SESSION['idUsuario']), 'Sin sesión');
    });

    $marco->probar('P30b', 'AC-08: un enlace de activación vencido no activa; reenviar la invitación invalida el anterior', static function () use ($baseDatos, $buzon, $servicioCredenciales, $servicioSolicitud, $actor, $contrasenaComun): void {
        $tokenVencido = (string) $buzon->ultimoToken('ana@ejemplo.mx', '/activar-cuenta');
        $baseDatos->vencerTokens('token_cuenta');
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_ENLACE_INVALIDO, $servicioCredenciales->activarCuenta($tokenVencido, $contrasenaComun)['resultado'], 'Vencido rechazado');
        MarcoPruebas::afirmarIgual('INVITADA', $baseDatos->obtenerFilaUsuario('ana@ejemplo.mx')['estado_cuenta_usuario'], 'El vencimiento no cambia el estado de la cuenta');

        $reenvio = $servicioSolicitud->reenviarInvitacion($actor('paula@ejemplo.mx'), (int) $baseDatos->obtenerFilaSolicitud('ana@ejemplo.mx')['id_solicitud_acceso']);
        MarcoPruebas::afirmar($reenvio['exito'], $reenvio['mensaje']);
        $tokenNuevo = (string) $buzon->ultimoToken('ana@ejemplo.mx', '/activar-cuenta');
        MarcoPruebas::afirmar($tokenNuevo !== $tokenVencido, 'Enlace nuevo');
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_EXITO, $servicioCredenciales->activarCuenta($tokenNuevo, $contrasenaComun)['resultado'], 'Activación con el nuevo');
        MarcoPruebas::afirmarIgual('ACTIVA', $baseDatos->obtenerFilaUsuario('ana@ejemplo.mx')['estado_cuenta_usuario'], 'ACTIVA');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contarEventos('CUENTA_ACTIVADA') - 1, 'Activación auditada (además de la de Paula)');
    });

    $marco->probar('P31', 'AC-13: una cuenta ACTIVA inicia sesión con ID nuevo y solo permisos de USUARIO', static function () use ($reiniciarSesion, $servicioAutenticacion, $contrasenaComun, $gestorSesion, $roleMiddleware): void {
        $reiniciarSesion();
        $idSesionAnonima = session_id();
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_EXITO, $servicioAutenticacion->iniciarSesion('ana@ejemplo.mx', $contrasenaComun, '192.0.2.1'), 'Inicio de sesión');
        MarcoPruebas::afirmar(session_id() !== $idSesionAnonima, 'El ID de sesión cambió (anti-fijación)');
        MarcoPruebas::afirmar(!isset($_SESSION['rol']) && !isset($_SESSION['rolUsuario']), 'La sesión no almacena el rol');
        $usuario = $gestorSesion->validarSesion();
        MarcoPruebas::afirmarIgual('USUARIO', $usuario['rol_usuario'], 'Rol USUARIO');
        foreach (['revisar_solicitudes', 'resolver_solicitud', 'administrar_cuentas', 'asignar_rol_administrativo'] as $permiso) {
            MarcoPruebas::afirmar(!$roleMiddleware->tienePermiso($usuario['rol_usuario'], $permiso), "Sin {$permiso}");
        }
    });

    $marco->probar('P07', 'SEG-06: credenciales incorrectas responden igual exista o no el correo', static function () use ($iniciarComo): void {
        $resultadoContrasenaMala = $iniciarComo('ana@ejemplo.mx', 'contraseña equivocada larga');
        $resultadoCorreoInexistente = $iniciarComo('nadie@ejemplo.mx', 'contraseña equivocada larga');
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_CREDENCIALES_INVALIDAS, $resultadoContrasenaMala, 'Contraseña incorrecta');
        MarcoPruebas::afirmarIgual($resultadoContrasenaMala, $resultadoCorreoInexistente, 'Mismo resultado para correo inexistente');
        MarcoPruebas::afirmar(!isset($_SESSION['idUsuario']), 'No se creó sesión');
    });

    $marco->probar('P28', 'AC-07: rechazar exige motivo, no crea cuenta y una nueva solicitud no lo revierte en silencio', static function () use ($solicitar, $verificar, $actor, $servicioSolicitud, $baseDatos, $buzon): void {
        $solicitar('Carlos', 'Cardenas', 'carlos@ejemplo.mx', 'PROFESOR', 'P2026003');
        $verificar('carlos@ejemplo.mx');
        $idSolicitud = (int) $baseDatos->obtenerFilaSolicitud('carlos@ejemplo.mx')['id_solicitud_acceso'];
        $paula = $actor('paula@ejemplo.mx');
        MarcoPruebas::afirmar(!$servicioSolicitud->rechazarSolicitud($paula, $idSolicitud, null)['exito'], 'Sin motivo no se rechaza');
        $resultado = $servicioSolicitud->rechazarSolicitud($paula, $idSolicitud, 'No aparece en el padrón de personal vigente');
        MarcoPruebas::afirmar($resultado['exito'], $resultado['mensaje']);
        $solicitud = $baseDatos->obtenerFilaSolicitud('carlos@ejemplo.mx');
        MarcoPruebas::afirmarIgual('RECHAZADA', $solicitud['estado_solicitud'], 'RECHAZADA');
        MarcoPruebas::afirmarIgual('No aparece en el padrón de personal vigente', $solicitud['motivo_resolucion_solicitud'], 'Motivo guardado');
        MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaUsuario('carlos@ejemplo.mx'), 'No se creó cuenta');
        $mensajes = $buzon->mensajesPara('carlos@ejemplo.mx');
        MarcoPruebas::afirmar(str_contains(end($mensajes), 'no fue autorizada') && !str_contains(end($mensajes), 'padrón'), 'Aviso al solicitante sin revelar el motivo interno');

        $solicitar('Carlos', 'Cardenas', 'carlos@ejemplo.mx', 'PROFESOR', 'P2026003');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contar("SELECT COUNT(*) FROM solicitud_acceso WHERE correo_electronico_solicitante = 'carlos@ejemplo.mx'"), 'No se crea otra solicitud');
        MarcoPruebas::afirmarIgual('RECHAZADA', $baseDatos->obtenerFilaSolicitud('carlos@ejemplo.mx')['estado_solicitud'], 'El rechazo no cambia en silencio');
    });

    /* ============================ Roles y administración ============================ */

    $marco->probar('P18', 'El principal asigna roles con re-autenticación, nunca el propio y solo a cuentas ACTIVAS', static function () use ($altaCompleta, $actor, $servicioAdministracion, $contrasenaPrincipal, $baseDatos, $solicitar, $verificar, $aprobar): void {
        $paula = $actor('paula@ejemplo.mx');
        $altaCompleta('Arturo', 'Admin', 'arturo@ejemplo.mx', 'PROFESOR', 'P2026004', $paula);
        $idArturo = (int) $baseDatos->obtenerFilaUsuario('arturo@ejemplo.mx')['id_usuario'];

        MarcoPruebas::afirmar(!$servicioAdministracion->cambiarRolUsuario($paula, $idArturo, 'ADMINISTRADOR', 'contraseña equivocada')['exito'], 'Sin re-autenticación no hay cambio');
        MarcoPruebas::afirmar(!$servicioAdministracion->cambiarRolUsuario($paula, (int) $paula['id_usuario'], 'USUARIO', $contrasenaPrincipal)['exito'], 'No cambia su propio rol');
        $resultado = $servicioAdministracion->cambiarRolUsuario($paula, $idArturo, 'ADMINISTRADOR', $contrasenaPrincipal);
        MarcoPruebas::afirmar($resultado['exito'], $resultado['mensaje']);
        MarcoPruebas::afirmarIgual('ADMINISTRADOR', $baseDatos->obtenerFilaUsuario('arturo@ejemplo.mx')['rol_usuario'], 'Arturo es ADMINISTRADOR');

        $solicitar('Diana', 'Duarte', 'diana@ejemplo.mx', 'ALUMNO', 'A2026005');
        $verificar('diana@ejemplo.mx');
        $aprobar($paula, 'diana@ejemplo.mx');
        $idDiana = (int) $baseDatos->obtenerFilaUsuario('diana@ejemplo.mx')['id_usuario'];
        $invitada = $servicioAdministracion->cambiarRolUsuario($paula, $idDiana, 'ADMINISTRADOR', $contrasenaPrincipal);
        MarcoPruebas::afirmar(!$invitada['exito'], 'No se da rol administrativo a una cuenta INVITADA');
        MarcoPruebas::afirmarIgual('USUARIO', $baseDatos->obtenerFilaUsuario('diana@ejemplo.mx')['rol_usuario'], 'Diana sigue USUARIO');
    });

    $marco->probar('P12a', 'Un ADMINISTRADOR no puede cambiar roles ni restablecer segundos factores (servicio)', static function () use ($actor, $servicioAdministracion, $baseDatos, $contrasenaComun): void {
        $arturo = $actor('arturo@ejemplo.mx');
        $idAna = (int) $baseDatos->obtenerFilaUsuario('ana@ejemplo.mx')['id_usuario'];
        MarcoPruebas::afirmar(!$servicioAdministracion->cambiarRolUsuario($arturo, $idAna, 'ADMINISTRADOR_PRINCIPAL', $contrasenaComun)['exito'], 'Cambio de rol denegado');
        MarcoPruebas::afirmarIgual('USUARIO', $baseDatos->obtenerFilaUsuario('ana@ejemplo.mx')['rol_usuario'], 'Sin cambios');
        $idPaula = (int) $baseDatos->obtenerFilaUsuario('paula@ejemplo.mx')['id_usuario'];
        MarcoPruebas::afirmar(!$servicioAdministracion->restablecerSegundoFactor($arturo, $idPaula, $contrasenaComun)['exito'], 'Restablecer segundo factor denegado');
        MarcoPruebas::afirmar($baseDatos->contarEventos('INTENTO_CAMBIO_PRIVILEGIO_DENEGADO') >= 2, 'Intentos auditados');
    });

    $marco->probar('P34', 'Un administrador no resuelve una solicitud asociada a su propia identidad', static function () use ($actor, $servicioSolicitud, $baseDatos): void {
        $conexion = $baseDatos->obtenerConexionAdministrativa();
        $conexion->exec("INSERT INTO solicitud_acceso (nombres_solicitante, apellido_paterno_solicitante, correo_electronico_solicitante,
            tipo_integrante_solicitado, identificador_institucional_solicitante, fecha_verificacion_correo_solicitud)
            VALUES ('Arturo', 'Alterno', 'arturo.alterno@ejemplo.mx', 'PROFESOR', 'P2026004', NOW())");
        $idSolicitud = (int) $conexion->lastInsertId();
        $resultado = $servicioSolicitud->aprobarSolicitud($actor('arturo@ejemplo.mx'), $idSolicitud, 'Me conozco a mí mismo', true);
        MarcoPruebas::afirmar(!$resultado['exito'] && str_contains($resultado['mensaje'], 'propia identidad'), 'Solicitud propia rechazada');
        MarcoPruebas::afirmarIgual('PENDIENTE', $baseDatos->obtenerFilaSolicitud('arturo.alterno@ejemplo.mx')['estado_solicitud'], 'Sin cambios');
        $conexion->exec("UPDATE solicitud_acceso SET estado_solicitud = 'RECHAZADA', id_usuario_revisor = 1, fecha_resolucion_solicitud = NOW(),
            motivo_resolucion_solicitud = 'Limpieza de escenario de prueba' WHERE id_solicitud_acceso = {$idSolicitud}");
    });

    $marco->probar('P26b', 'SEG-08: el segundo factor no acepta códigos reutilizados y los códigos de recuperación sirven una vez', static function () use ($iniciarComo, $servicioSegundoFactor, $contrasenaPrincipal, &$secretosTotp, $codigoActual, $baseDatos, $gestorSesion): void {
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_REQUIERE_SEGUNDO_FACTOR, $iniciarComo('paula@ejemplo.mx', $contrasenaPrincipal), 'Pide el código');
        $ultimoPaso = (int) $baseDatos->obtenerFilaUsuario('paula@ejemplo.mx')['ultimo_paso_mfa_usuario'];
        $codigoUsado = SegundoFactorTotp::calcularCodigo(SegundoFactorTotp::decodificarBase32($secretosTotp['paula@ejemplo.mx']), $ultimoPaso);
        MarcoPruebas::afirmarIgual(ServicioSegundoFactor::RESULTADO_CODIGO_INVALIDO, $servicioSegundoFactor->verificarCodigo($codigoUsado, '192.0.2.250'), 'Código ya usado rechazado');
        MarcoPruebas::afirmar(!isset($_SESSION['idUsuario']), 'Sin sesión');
        MarcoPruebas::afirmarIgual(ServicioSegundoFactor::RESULTADO_EXITO, $servicioSegundoFactor->verificarCodigo($codigoActual($secretosTotp['paula@ejemplo.mx'], 1), '192.0.2.250'), 'Código siguiente aceptado');
        MarcoPruebas::afirmar($gestorSesion->validarSesion() !== null, 'Sesión administrativa');

        $codigoRecuperacion = strtolower((string) $GLOBALS['codigosRecuperacionPaula'][0]);
        $iniciarComo('paula@ejemplo.mx', $contrasenaPrincipal);
        MarcoPruebas::afirmarIgual(ServicioSegundoFactor::RESULTADO_EXITO, $servicioSegundoFactor->verificarCodigo($codigoRecuperacion, '192.0.2.250'), 'Código de recuperación (sin distinguir mayúsculas)');
        $iniciarComo('paula@ejemplo.mx', $contrasenaPrincipal);
        MarcoPruebas::afirmarIgual(ServicioSegundoFactor::RESULTADO_CODIGO_INVALIDO, $servicioSegundoFactor->verificarCodigo($codigoRecuperacion, '192.0.2.250'), 'No se reutiliza');
        MarcoPruebas::afirmarIgual(CANTIDAD_CODIGOS_RECUPERACION_MFA - 1, $servicioSegundoFactor->contarCodigosDisponibles((int) $baseDatos->obtenerFilaUsuario('paula@ejemplo.mx')['id_usuario']), 'Quedan 9');
        $baseDatos->limpiarIntentos();
    });

    $marco->probar('P35', 'Sin segundo factor no hay sesión administrativa aunque se fuerce la sesión (defensa en profundidad)', static function () use ($reiniciarSesion, $gestorSesion, $usuarioModel): void {
        $reiniciarSesion();
        $gestorSesion->crearSesion((array) $usuarioModel->obtenerUsuarioPorId((int) $usuarioModel->obtenerUsuarioPorCorreo('arturo@ejemplo.mx')['id_usuario']), false);
        MarcoPruebas::afirmarIgual(null, $gestorSesion->validarSesion(), 'Sesión administrativa sin segundo factor rechazada');
        MarcoPruebas::afirmarIgual(GestorSesion::MOTIVO_SEGUNDO_FACTOR_REQUERIDO, $gestorSesion->obtenerMotivoUltimaExpiracion(), 'Motivo');
    });

    $marco->probar('P11a', 'AC-05/AC-15: un USUARIO recibe 403 en la bandeja y al aprobar; la solicitud no cambia', static function () use ($iniciarComo, $construirEnrutador, $solicitar, $verificar, $baseDatos): void {
        $solicitar('Elena', 'Esquivel', 'elena@ejemplo.mx', 'ALUMNO', 'A2026006');
        $verificar('elena@ejemplo.mx');
        $idSolicitud = (string) $baseDatos->obtenerFilaSolicitud('elena@ejemplo.mx')['id_solicitud_acceso'];

        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_EXITO, $iniciarComo('ana@ejemplo.mx'), 'Ana entra');
        $enrutador = $construirEnrutador();
        $token = ProtectorCsrf::generarTokenCsrf();
        MarcoPruebas::afirmarExcepcionHttp(403, static fn () => $enrutador->despachar('GET', '/admin/solicitudes', []), 'Bandeja');
        MarcoPruebas::afirmarExcepcionHttp(403, static fn () => $enrutador->despachar('GET', '/admin/solicitudes/detalle', ['id' => $idSolicitud]), 'Detalle');
        MarcoPruebas::afirmarExcepcionHttp(403, static fn () => $enrutador->despachar('POST', '/admin/solicitudes/aprobar', [
            'token_csrf' => $token, 'id_solicitud' => $idSolicitud, 'metodo_verificacion' => 'Yo misma', 'confirmacion_pertenencia' => 'si',
        ]), 'Aprobar');
        MarcoPruebas::afirmarExcepcionHttp(403, static fn () => $enrutador->despachar('GET', '/admin/roles', []), 'Sección del principal');
        MarcoPruebas::afirmarExcepcionHttp(403, static fn () => $enrutador->despachar('GET', '/admin/bitacora', []), 'Bitácora');
        MarcoPruebas::afirmarIgual('PENDIENTE', $baseDatos->obtenerFilaSolicitud('elena@ejemplo.mx')['estado_solicitud'], 'Solicitud sin cambios');
        MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaUsuario('elena@ejemplo.mx'), 'Sin cuenta');
    });

    $marco->probar('P37', 'Un ADMINISTRADOR (con segundo factor) revisa solicitudes pero no abre la sección del principal', static function () use ($entrarConSegundoFactor, $construirEnrutador, $baseDatos, $contrasenaComun, $buzon): void {
        MarcoPruebas::afirmarIgual(ServicioSegundoFactor::RESULTADO_EXITO, $entrarConSegundoFactor('arturo@ejemplo.mx', $contrasenaComun), 'Arturo configura su segundo factor y entra');
        $enrutador = $construirEnrutador();
        $bandeja = $enrutador->despachar('GET', '/admin/solicitudes', []);
        MarcoPruebas::afirmarIgual(200, $bandeja->codigoEstado, 'Bandeja visible');
        $correosBandeja = array_column($bandeja->datosVista['solicitudes'], 'correo_electronico_solicitante');
        sort($correosBandeja);
        MarcoPruebas::afirmarIgual(['beto@ejemplo.mx', 'elena@ejemplo.mx'], $correosBandeja, 'Solo las pendientes con correo verificado');
        MarcoPruebas::afirmarExcepcionHttp(403, static fn () => $enrutador->despachar('GET', '/admin/roles', []), 'Roles: solo principal');

        $respuesta = $enrutador->despachar('POST', '/admin/solicitudes/aprobar', [
            'token_csrf' => ProtectorCsrf::generarTokenCsrf(),
            'id_solicitud' => (string) $baseDatos->obtenerFilaSolicitud('elena@ejemplo.mx')['id_solicitud_acceso'],
            'metodo_verificacion' => 'Cotejo con la lista de inscritos 2026-B',
            'confirmacion_pertenencia' => 'si',
        ]);
        MarcoPruebas::afirmarIgual('/admin/solicitudes', $respuesta->rutaRedireccion, 'Vuelve a la bandeja');
        MarcoPruebas::afirmarIgual('INVITADA', $baseDatos->obtenerFilaUsuario('elena@ejemplo.mx')['estado_cuenta_usuario'], 'Cuenta INVITADA creada');
        MarcoPruebas::afirmar($buzon->ultimoToken('elena@ejemplo.mx', '/activar-cuenta') !== null, 'Invitación enviada');
    });

    $marco->probar('P33', 'AC-16/RF-08: suspender revoca la sesión abierta; la cuenta no entra ni se recupera hasta reactivarse', static function () use ($iniciarComo, $gestorSesion, $actor, $servicioAdministracion, $servicioCredenciales, $baseDatos, $buzon, $contrasenaComun): void {
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_EXITO, $iniciarComo('ana@ejemplo.mx'), 'Ana con sesión abierta');
        $arturo = $actor('arturo@ejemplo.mx');
        $idAna = (int) $baseDatos->obtenerFilaUsuario('ana@ejemplo.mx')['id_usuario'];
        MarcoPruebas::afirmar(!$servicioAdministracion->cambiarEstadoCuenta($arturo, $idAna, 'SUSPENDIDA', 'contraseña equivocada')['exito'], 'Exige re-autenticación');
        $resultado = $servicioAdministracion->cambiarEstadoCuenta($arturo, $idAna, 'SUSPENDIDA', $contrasenaComun);
        MarcoPruebas::afirmar($resultado['exito'], $resultado['mensaje']);
        MarcoPruebas::afirmarIgual(null, $gestorSesion->validarSesion(), 'La cookie anterior ya no sirve');

        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_CREDENCIALES_INVALIDAS, $iniciarComo('ana@ejemplo.mx'), 'Suspendida no entra (respuesta neutral)');
        $mensajesAntes = count($buzon->mensajesPara('ana@ejemplo.mx'));
        $servicioCredenciales->solicitarRecuperacion('ana@ejemplo.mx', '192.0.2.40');
        MarcoPruebas::afirmarIgual($mensajesAntes, count($buzon->mensajesPara('ana@ejemplo.mx')), 'SEG-14: recuperar no reactiva una cuenta suspendida');

        $idPaula = (int) $baseDatos->obtenerFilaUsuario('paula@ejemplo.mx')['id_usuario'];
        MarcoPruebas::afirmar(!$servicioAdministracion->cambiarEstadoCuenta($arturo, $idPaula, 'SUSPENDIDA', $contrasenaComun)['exito'], 'Un ADMINISTRADOR no suspende al principal');
        MarcoPruebas::afirmar(!$servicioAdministracion->cambiarEstadoCuenta($arturo, (int) $arturo['id_usuario'], 'BAJA', $contrasenaComun)['exito'], 'Nadie cambia su propio estado');

        MarcoPruebas::afirmar($servicioAdministracion->cambiarEstadoCuenta($arturo, $idAna, 'ACTIVA', $contrasenaComun)['exito'], 'Reactivación autorizada');
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_EXITO, $iniciarComo('ana@ejemplo.mx'), 'Vuelve a entrar');
        MarcoPruebas::afirmarIgual(2, $baseDatos->contarEventos('CAMBIO_ESTADO_CUENTA'), 'Suspensión y reactivación auditadas');
    });

    $marco->probar('P36', 'La BAJA es definitiva y una invitación pendiente puede cancelarse', static function () use ($actor, $servicioAdministracion, $servicioCredenciales, $baseDatos, $buzon, $contrasenaPrincipal, $contrasenaComun): void {
        $paula = $actor('paula@ejemplo.mx');
        $idDiana = (int) $baseDatos->obtenerFilaUsuario('diana@ejemplo.mx')['id_usuario'];
        $tokenDiana = (string) $buzon->ultimoToken('diana@ejemplo.mx', '/activar-cuenta');
        MarcoPruebas::afirmar($servicioAdministracion->cambiarEstadoCuenta($paula, $idDiana, 'BAJA', $contrasenaPrincipal)['exito'], 'INVITADA -> BAJA');
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_ENLACE_INVALIDO, $servicioCredenciales->activarCuenta($tokenDiana, $contrasenaComun)['resultado'], 'Su invitación dejó de servir');
        MarcoPruebas::afirmar(!$servicioAdministracion->cambiarEstadoCuenta($paula, $idDiana, 'ACTIVA', $contrasenaPrincipal)['exito'], 'BAJA -> ACTIVA no permitido');
        MarcoPruebas::afirmarIgual('BAJA', $baseDatos->obtenerFilaUsuario('diana@ejemplo.mx')['estado_cuenta_usuario'], 'Sigue en BAJA (se conserva para auditoría)');
    });

    /* ================================ Recuperación ================================ */

    $marco->probar('P32', 'AC-17/AC-18/RF-07: recuperación neutral, enlace de un uso que vence y revoca las sesiones', static function () use ($servicioCredenciales, $buzon, $baseDatos, $iniciarComo, $gestorSesion, $contrasenaComun): void {
        $baseDatos->limpiarIntentos();
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_EXITO, $iniciarComo('ana@ejemplo.mx'), 'Sesión abierta antes de recuperar');
        $resultadoExistente = $servicioCredenciales->solicitarRecuperacion('ana@ejemplo.mx', '192.0.2.41');
        $resultadoInexistente = $servicioCredenciales->solicitarRecuperacion('fantasma@ejemplo.mx', '192.0.2.41');
        MarcoPruebas::afirmarIgual($resultadoExistente, $resultadoInexistente, 'Mismo resultado exista o no');
        MarcoPruebas::afirmarIgual([], $buzon->mensajesPara('fantasma@ejemplo.mx'), 'Nada se envía a un correo sin cuenta');
        $token = (string) $buzon->ultimoToken('ana@ejemplo.mx', '/restablecer-contrasena');
        MarcoPruebas::afirmar(GeneradorTokens::esFormatoValido($token), 'Enlace de recuperación para la cuenta real');

        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_ENLACE_INVALIDO, $servicioCredenciales->activarCuenta($token, 'otra frase segura para activar')['resultado'], 'Un enlace de recuperación no sirve para activar (finalidad separada)');
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_CONTRASENA_RECHAZADA, $servicioCredenciales->restablecerContrasena($token, 'passwordpassword')['resultado'], 'Contraseña bloqueada');
        $contrasenaNueva = 'las olas llegan suaves al amanecer';
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_EXITO, $servicioCredenciales->restablecerContrasena($token, $contrasenaNueva)['resultado'], 'Restablecida');
        MarcoPruebas::afirmarIgual(null, $gestorSesion->validarSesion(), 'La sesión abierta se revocó');
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_ENLACE_INVALIDO, $servicioCredenciales->restablecerContrasena($token, 'otra frase nueva y distinta')['resultado'], 'AC-18: enlace usado no se reutiliza');
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_CREDENCIALES_INVALIDAS, $iniciarComo('ana@ejemplo.mx', $contrasenaComun), 'La contraseña anterior ya no sirve');
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_EXITO, $iniciarComo('ana@ejemplo.mx', $contrasenaNueva), 'La nueva sí');

        $servicioCredenciales->solicitarRecuperacion('ana@ejemplo.mx', '192.0.2.42');
        $tokenVencido = (string) $buzon->ultimoToken('ana@ejemplo.mx', '/restablecer-contrasena');
        $baseDatos->vencerTokens('token_cuenta');
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_ENLACE_INVALIDO, $servicioCredenciales->restablecerContrasena($tokenVencido, 'frase que no llega a guardarse')['resultado'], 'AC-18: enlace vencido rechazado');
        $baseDatos->obtenerConexionAdministrativa()->prepare('UPDATE usuario SET hash_contrasena_usuario = ? WHERE correo_electronico_usuario = ?')
            ->execute([GestorContrasenas::generarHash($contrasenaComun), 'ana@ejemplo.mx']);
    });

    $marco->probar('P38', 'INVITADA que perdió su enlace: "¿Olvidó su contraseña?" le envía una activación nueva', static function () use ($servicioCredenciales, $buzon, $baseDatos, $contrasenaComun): void {
        $tokenAnterior = (string) $buzon->ultimoToken('elena@ejemplo.mx', '/activar-cuenta');
        $servicioCredenciales->solicitarRecuperacion('elena@ejemplo.mx', '192.0.2.43');
        $tokenNuevo = (string) $buzon->ultimoToken('elena@ejemplo.mx', '/activar-cuenta');
        MarcoPruebas::afirmar($tokenNuevo !== $tokenAnterior, 'Enlace de activación nuevo');
        MarcoPruebas::afirmarIgual(null, $buzon->ultimoToken('elena@ejemplo.mx', '/restablecer-contrasena'), 'No se envía recuperación a una cuenta sin contraseña');
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_ENLACE_INVALIDO, $servicioCredenciales->activarCuenta($tokenAnterior, $contrasenaComun)['resultado'], 'El anterior quedó invalidado');
        MarcoPruebas::afirmarIgual(ServicioCredenciales::RESULTADO_EXITO, $servicioCredenciales->activarCuenta($tokenNuevo, $contrasenaComun)['resultado'], 'Activación con el nuevo');
        MarcoPruebas::afirmarIgual('ACTIVA', $baseDatos->obtenerFilaUsuario('elena@ejemplo.mx')['estado_cuenta_usuario'], 'ACTIVA');
    });

    /* ============================ Sesiones y límites ============================ */

    $marco->probar('P08a', 'Cerrar sesión elimina la sesión del servidor', static function () use ($servicioAutenticacion, $iniciarComo, $usuarioModel): void {
        $iniciarComo('ana@ejemplo.mx');
        $idSesionAutenticada = session_id();
        $archivoSesion = session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . $idSesionAutenticada;
        session_write_close();
        MarcoPruebas::afirmar(is_file($archivoSesion), 'Existía el archivo de sesión');
        GestorSesion::iniciarSesionPhp();
        $servicioAutenticacion->cerrarSesion($usuarioModel->obtenerUsuarioPorCorreo('ana@ejemplo.mx'));
        clearstatcache();
        MarcoPruebas::afirmar(!is_file($archivoSesion), 'El archivo de la sesión anterior fue destruido');
        MarcoPruebas::afirmar(session_id() !== $idSesionAutenticada, 'Nuevo ID anónimo');
        MarcoPruebas::afirmar(!isset($_SESSION['idUsuario']), 'Sin usuario en la sesión nueva');
    });

    $marco->probar('P20', 'SEG-10: expiración por inactividad y por duración máxima', static function () use ($iniciarComo, $gestorSesion): void {
        $iniciarComo('ana@ejemplo.mx');
        $_SESSION['ultimaActividad'] = time() - TIEMPO_INACTIVIDAD_SESION_SEGUNDOS - 1;
        MarcoPruebas::afirmarIgual(null, $gestorSesion->validarSesion(), 'Sesión inactiva rechazada');
        MarcoPruebas::afirmarIgual(GestorSesion::MOTIVO_INACTIVIDAD, $gestorSesion->obtenerMotivoUltimaExpiracion(), 'Motivo');
        $iniciarComo('ana@ejemplo.mx');
        $_SESSION['fechaCreacion'] = time() - DURACION_MAXIMA_SESION_SEGUNDOS - 1;
        MarcoPruebas::afirmarIgual(null, $gestorSesion->validarSesion(), 'Sesión demasiado antigua rechazada');
        MarcoPruebas::afirmarIgual(GestorSesion::MOTIVO_DURACION_MAXIMA, $gestorSesion->obtenerMotivoUltimaExpiracion(), 'Motivo');
        $iniciarComo('ana@ejemplo.mx');
        MarcoPruebas::afirmar($gestorSesion->validarSesion() !== null, 'Sesión vigente aceptada');
    });

    $marco->probar('P17a', 'Revocar un rol administrativo invalida la sesión abierta del afectado', static function () use ($entrarConSegundoFactor, $gestorSesion, $servicioAdministracion, $actor, $contrasenaComun, $contrasenaPrincipal, $baseDatos): void {
        MarcoPruebas::afirmarIgual(ServicioSegundoFactor::RESULTADO_EXITO, $entrarConSegundoFactor('arturo@ejemplo.mx', $contrasenaComun), 'Arturo con sesión administrativa');
        MarcoPruebas::afirmar($gestorSesion->validarSesion() !== null, 'Sesión válida');
        $resultado = $servicioAdministracion->cambiarRolUsuario($actor('paula@ejemplo.mx'), (int) $baseDatos->obtenerFilaUsuario('arturo@ejemplo.mx')['id_usuario'], 'USUARIO', $contrasenaPrincipal);
        MarcoPruebas::afirmar($resultado['exito'], $resultado['mensaje']);
        MarcoPruebas::afirmarIgual(null, $gestorSesion->validarSesion(), 'La sesión de Arturo se invalidó');
        MarcoPruebas::afirmarIgual(GestorSesion::MOTIVO_VERSION_CAMBIADA, $gestorSesion->obtenerMotivoUltimaExpiracion(), 'Por cambio de privilegios');
    });

    $marco->probar('P15', 'AC-21/SEG-07: límites por cuenta y por origen sin bloquear a todo el salón', static function () use ($iniciarComo, $reiniciarSesion, $servicioAutenticacion, $servicioSolicitud, $datosSolicitud, $contrasenaComun, $baseDatos): void {
        $baseDatos->limpiarIntentos();
        $reiniciarSesion();
        for ($intento = 1; $intento <= MAX_INTENTOS_LOGIN; $intento++) {
            $servicioAutenticacion->iniciarSesion('ana@ejemplo.mx', 'contraseña equivocada larga', '198.51.100.' . $intento);
        }
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_LIMITE_ALCANZADO, $servicioAutenticacion->iniciarSesion('ana@ejemplo.mx', $contrasenaComun, '198.51.100.99'), 'Cuenta limitada aunque cambie la IP');
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_EXITO, $servicioAutenticacion->iniciarSesion('elena@ejemplo.mx', $contrasenaComun, '198.51.100.1'), 'Otra persona del mismo salón sí entra');
        $baseDatos->obtenerConexionAdministrativa()->exec('UPDATE intento_acceso SET fecha_intento_acceso = NOW() - INTERVAL ' . (VENTANA_INTENTOS_SEGUNDOS + 60) . ' SECOND');
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_EXITO, $iniciarComo('ana@ejemplo.mx'), 'Al salir de la ventana vuelve a entrar (sin bloqueo permanente)');

        $baseDatos->limpiarIntentos();
        $resultados = [];
        for ($envio = 1; $envio <= MAX_ENVIOS_FORMULARIO_CORREO + 1; $envio++) {
            $resultados[] = $servicioSolicitud->registrarSolicitud($datosSolicitud('Fabio', 'Flores', 'fabio@ejemplo.mx', 'ALUMNO', 'A2026007'), '203.0.113.' . $envio);
        }
        MarcoPruebas::afirmarIgual(ServicioSolicitudAcceso::RESULTADO_LIMITE_ALCANZADO, end($resultados), 'Solicitudes repetidas del mismo correo se limitan');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contar("SELECT COUNT(*) FROM solicitud_acceso WHERE correo_electronico_solicitante = 'fabio@ejemplo.mx'"), 'Y no se duplican');
        for ($companero = 1; $companero <= 5; $companero++) {
            MarcoPruebas::afirmarIgual(ServicioSolicitudAcceso::RESULTADO_RECIBIDA,
                $servicioSolicitud->registrarSolicitud($datosSolicitud('Alumno', 'Salon', "salon{$companero}@ejemplo.mx", 'ALUMNO', "S{$companero}000"), '203.0.113.200'),
                "Compañero {$companero} desde la misma IP del salón");
        }
        MarcoPruebas::afirmar($baseDatos->contarEventos('LOGIN_BLOQUEADO') >= 1 && $baseDatos->contarEventos('SOLICITUD_LIMITADA') >= 1, 'Límites auditados');
        $baseDatos->limpiarIntentos();
    });

    $marco->probar('P14b', 'AC-20: entradas con comillas o SQL se guardan como datos o se rechazan', static function () use ($servicioAutenticacion, $reiniciarSesion, $usuarioModel, $solicitar, $baseDatos): void {
        $totalAntes = $baseDatos->contar('SELECT COUNT(*) FROM usuario');
        $reiniciarSesion();
        foreach (["' OR '1'='1' -- ", "ana@ejemplo.mx' -- ", "x'; DROP TABLE usuario; -- "] as $correoMalicioso) {
            MarcoPruebas::afirmarIgual(null, $usuarioModel->obtenerUsuarioPorCorreo($correoMalicioso), 'Sin coincidencias para ' . $correoMalicioso);
            MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_CREDENCIALES_INVALIDAS, $servicioAutenticacion->iniciarSesion($correoMalicioso, "' OR '1'='1", '192.0.2.3'), 'Login rechazado');
        }
        $solicitar("Seán O'Brien", "D'Arcy", 'obrien@ejemplo.mx', 'ALUMNO', 'A2026008');
        $solicitud = $baseDatos->obtenerFilaSolicitud('obrien@ejemplo.mx');
        MarcoPruebas::afirmarIgual("Seán O'Brien", $solicitud['nombres_solicitante'], 'Apóstrofos guardados literalmente');
        $resultados = (new SolicitudAccesoModel(obtenerConexionBaseDatos()))->listarSolicitudes('RECHAZADA', null, "%' OR '1'='1");
        MarcoPruebas::afirmarIgual([], $resultados, 'La búsqueda trata comodines y comillas como texto');
        MarcoPruebas::afirmarIgual($totalAntes, $baseDatos->contar('SELECT COUNT(*) FROM usuario'), 'La tabla usuario sigue intacta');
    });

    /* ================================ Enrutador ================================ */

    $marco->probar('P09a', 'Enrutador: rutas protegidas sin sesión responden 401; inexistentes 404; método 405', static function () use ($construirEnrutador, $reiniciarSesion): void {
        $reiniciarSesion();
        $enrutador = $construirEnrutador();
        foreach (['/inicio', '/admin/solicitudes', '/admin/usuarios', '/admin/roles', '/admin/bitacora', '/codigos-recuperacion'] as $ruta) {
            MarcoPruebas::afirmarExcepcionHttp(401, static fn () => $enrutador->despachar('GET', $ruta, []), "GET {$ruta}");
        }
        MarcoPruebas::afirmarExcepcionHttp(401, static fn () => $enrutador->despachar('POST', '/admin/roles/cambiar', ['id_usuario' => '1', 'rol_nuevo' => 'ADMINISTRADOR']), 'POST rol');
        MarcoPruebas::afirmarExcepcionHttp(404, static fn () => $enrutador->despachar('GET', '/no-existe', []), 'Ruta inexistente');
        MarcoPruebas::afirmarExcepcionHttp(405, static fn () => $enrutador->despachar('POST', '/registro', []), 'El registro abierto ya no acepta envíos');
        MarcoPruebas::afirmarExcepcionHttp(405, static fn () => $enrutador->despachar('GET', '/cerrar-sesion', []), 'Método no permitido');
        MarcoPruebas::afirmarIgual('/solicitar-acceso', $enrutador->despachar('GET', '/registro', [])->rutaRedireccion, 'La ruta anterior lleva al nuevo formulario');
    });

    $marco->probar('P10a', 'AC-02/SEG-01: una solicitud con rol o estado adulterados se rechaza (400), se audita y no se guarda', static function () use ($construirEnrutador, $reiniciarSesion, $baseDatos): void {
        $reiniciarSesion();
        $enrutador = $construirEnrutador();
        $datosManipulados = [
            'token_csrf' => ProtectorCsrf::generarTokenCsrf(), 'nombres' => 'Mallory', 'apellido_paterno' => 'Atacante',
            'tipo_integrante' => 'PROFESOR', 'identificador_institucional' => 'P6666', 'correo_electronico' => 'mallory@ejemplo.mx',
            'rol_usuario' => 'ADMINISTRADOR_PRINCIPAL', 'estado_cuenta_usuario' => 'ACTIVA', 'solicitud_aprobada' => '1',
        ];
        MarcoPruebas::afirmarExcepcionHttp(400, static fn () => $enrutador->despachar('POST', '/solicitar-acceso', $datosManipulados), 'Rechazo 400');
        MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaSolicitud('mallory@ejemplo.mx'), 'No se guardó la solicitud');
        MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaUsuario('mallory@ejemplo.mx'), 'No se creó cuenta');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contarEventos('INTENTO_ESCALADA_FORMULARIO'), 'Intento auditado');

        $sinToken = $datosManipulados;
        unset($sinToken['rol_usuario'], $sinToken['estado_cuenta_usuario'], $sinToken['solicitud_aprobada']);
        $sinToken['token_csrf'] = 'token-falso';
        MarcoPruebas::afirmarExcepcionHttp(400, static fn () => $enrutador->despachar('POST', '/solicitar-acceso', $sinToken), 'AC-19: sin CSRF válido');
        MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaSolicitud('mallory@ejemplo.mx'), 'Sin cambios');
    });

    $marco->probar('P23', 'Verificación de profesor (cuentas anteriores a la 004): solo administradores, sin cambiar el rol', static function () use ($servicioAdministracion, $actor, $baseDatos): void {
        $baseDatos->obtenerConexionAdministrativa()->exec("INSERT INTO usuario (nombres_usuario, apellido_paterno_usuario, correo_electronico_usuario,
            hash_contrasena_usuario, tipo_integrante_usuario, estado_verificacion_usuario) VALUES ('Gilda', 'Previa', 'gilda@ejemplo.mx', '!', 'PROFESOR', 'PENDIENTE')");
        $idGilda = (int) $baseDatos->obtenerFilaUsuario('gilda@ejemplo.mx')['id_usuario'];
        MarcoPruebas::afirmar(!$servicioAdministracion->resolverVerificacion($actor('ana@ejemplo.mx'), $idGilda, 'VERIFICADA')['exito'], 'Un USUARIO no verifica');
        MarcoPruebas::afirmar($servicioAdministracion->resolverVerificacion($actor('paula@ejemplo.mx'), $idGilda, 'VERIFICADA')['exito'], 'El principal sí');
        $fila = $baseDatos->obtenerFilaUsuario('gilda@ejemplo.mx');
        MarcoPruebas::afirmarIgual('VERIFICADA', $fila['estado_verificacion_usuario'], 'Verificada');
        MarcoPruebas::afirmarIgual('USUARIO', $fila['rol_usuario'], 'Sin permisos adicionales');
    });

    $marco->probar('P39', 'SEG-16: la bitácora reconstruye aprobaciones y cambios de rol sin contraseñas ni tokens', static function () use ($baseDatos, $buzon, $contrasenaComun, $contrasenaPrincipal): void {
        $eventos = (new AuditoriaModel(obtenerConexionBaseDatos()))->listarEventosRecientes(500);
        $aprobaciones = array_values(array_filter($eventos, static fn (array $evento): bool => $evento['accion_auditoria'] === 'SOLICITUD_APROBADA'));
        MarcoPruebas::afirmar(count($aprobaciones) >= 3, 'Aprobaciones registradas');
        MarcoPruebas::afirmar($aprobaciones[0]['id_usuario_actor'] !== null && isset($aprobaciones[0]['detalle_auditoria']['idUsuarioCreado']), 'Quién aprobó y qué cuenta se creó');
        MarcoPruebas::afirmar(!isset($aprobaciones[0]['detalle_auditoria']['origen']), 'La pantalla no muestra la IP');
        MarcoPruebas::afirmar($baseDatos->contarEventos('CAMBIO_ROL') >= 2, 'Cambios de rol registrados');

        $todoElDetalle = implode("\n", $baseDatos->obtenerConexionAdministrativa()->query('SELECT detalle_auditoria FROM auditoria')->fetchAll(PDO::FETCH_COLUMN));
        foreach ([$contrasenaComun, $contrasenaPrincipal, 'argon2id'] as $secreto) {
            MarcoPruebas::afirmar(!str_contains($todoElDetalle, $secreto), "La bitácora no contiene: {$secreto}");
        }
        foreach (glob($buzon->directorio . '/*.txt') ?: [] as $archivo) {
            if (preg_match('/token=([A-Za-z0-9_-]{43})/', (string) file_get_contents($archivo), $coincidencia) === 1) {
                MarcoPruebas::afirmar(!str_contains($todoElDetalle, $coincidencia[1]), 'La bitácora no contiene tokens');
            }
        }
    });

    $marco->probar('P22', 'Mínimo privilegio: la cuenta de la aplicación no altera la auditoría, no borra ni cambia la estructura', static function (): void {
        $conexionAplicacion = obtenerConexionBaseDatos();
        foreach ([
            'DELETE FROM auditoria',
            'UPDATE auditoria SET accion_auditoria = \'X\'',
            'DELETE FROM usuario',
            'DELETE FROM solicitud_acceso',
            'DELETE FROM token_cuenta',
            'DROP TABLE intento_acceso',
            'ALTER TABLE usuario ADD COLUMN x INT',
            'SELECT * FROM aviso',
        ] as $sentenciaProhibida) {
            $fueRechazada = false;
            try {
                $conexionAplicacion->exec($sentenciaProhibida);
            } catch (PDOException) {
                $fueRechazada = true;
            }
            MarcoPruebas::afirmar($fueRechazada, "MariaDB debió rechazar: {$sentenciaProhibida}");
        }
    });

    $reiniciarSesion();
};

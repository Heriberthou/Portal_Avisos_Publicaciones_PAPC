<?php

/**
 * Pruebas de extremo a extremo por HTTP (especificación 1.3).
 *
 * Levanta el servidor embebido de PHP con DocumentRoot = public/ (la misma
 * regla que debe aplicarse en Apache) y actúa como un navegador: cookies,
 * formularios, tokens CSRF, redirecciones y enlaces leídos del buzón de
 * pruebas.
 *
 * Limitación honesta: el servidor embebido NO interpreta .htaccess. Estas
 * pruebas demuestran la protección por DocumentRoot; la configuración de
 * Apache/XAMPP debe verificarse con los comandos curl del README.
 */

declare(strict_types=1);

return static function (MarcoPruebas $marco, BaseDatosPruebas $baseDatos, array $variablesEntorno, int $puertoHttp, string $directorioSesiones): void {
    $buzon = new BuzonPruebas($variablesEntorno['CORREO_DIRECTORIO_BUZON']);
    $buzon->vaciar();

    $archivoBitacoraServidor = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'papc-servidor-pruebas.log';
    $proceso = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $directorioSesiones, '-S', "127.0.0.1:{$puertoHttp}", '-t', RUTA_RAIZ . '/public', RUTA_RAIZ . '/public/index.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $archivoBitacoraServidor, 'w'], 2 => ['file', $archivoBitacoraServidor, 'a']],
        $tuberias,
        RUTA_RAIZ,
        array_merge(getenv(), $variablesEntorno)
    );
    if (!is_resource($proceso)) {
        $marco->probar('HTTP', 'Arranque del servidor de pruebas', static fn () => MarcoPruebas::afirmar(false, 'No se pudo iniciar php -S'));
        return;
    }

    $servidorListo = false;
    for ($espera = 0; $espera < 50 && !$servidorListo; $espera++) {
        $conexionPrueba = @fsockopen('127.0.0.1', $puertoHttp, $codigoError, $mensajeError, 0.2);
        if ($conexionPrueba !== false) {
            fclose($conexionPrueba);
            $servidorListo = true;
        } else {
            usleep(200000);
        }
    }

    $urlBase = "http://127.0.0.1:{$puertoHttp}";
    $contrasenaComun = 'frase de paso http de pruebas';
    $contrasenaPrincipal = 'la marea sube despacio por la tarde';
    $nuevoCliente = static fn (): ClienteHttp => new ClienteHttp($urlBase);

    $obtenerUbicacion = static function (array $respuesta): string {
        return preg_match('/^Location:\s*(\S+)/mi', $respuesta['encabezados'], $coincidencias) === 1 ? $coincidencias[1] : '';
    };

    $iniciarSesionPorHttp = static function (string $correo, ?string $contrasena = null) use ($nuevoCliente, $contrasenaComun): array {
        $cliente = $nuevoCliente();
        $respuesta = $cliente->enviar('/iniciar-sesion', [
            'token_csrf'         => $cliente->obtenerTokenDe('/iniciar-sesion'),
            'correo_electronico' => $correo,
            'contrasena'         => $contrasena ?? $contrasenaComun,
        ]);
        return [$cliente, $respuesta];
    };

    $solicitarPorHttp = static function (string $nombres, string $paterno, string $correo, string $tipo, string $identificador, array $extra = []) use ($nuevoCliente): array {
        $cliente = $nuevoCliente();
        return $cliente->enviar('/solicitar-acceso', [
            'token_csrf'                  => $cliente->obtenerTokenDe('/solicitar-acceso'),
            'nombres'                     => $nombres,
            'apellido_paterno'            => $paterno,
            'apellido_materno'            => '',
            'tipo_integrante'             => $tipo,
            'identificador_institucional' => $identificador,
            'correo_electronico'          => $correo,
        ] + $extra);
    };

    /**
     * Abre un enlace del correo como lo haría el navegador: el GET con token
     * debe redirigir a la URL limpia, que muestra el formulario.
     */
    $abrirEnlace = static function (ClienteHttp $cliente, string $ruta, string $token) use ($obtenerUbicacion): array {
        $respuesta = $cliente->obtener($ruta . '?token=' . rawurlencode($token));
        MarcoPruebas::afirmarIgual(303, $respuesta['codigo'], "El enlace {$ruta} redirige");
        MarcoPruebas::afirmarIgual($ruta, $obtenerUbicacion($respuesta), 'A la URL limpia, sin token');
        return $cliente->obtener($ruta);
    };

    $verificarPorHttp = static function (string $correo) use ($nuevoCliente, $buzon, $abrirEnlace): array {
        $cliente = $nuevoCliente();
        $pagina = $abrirEnlace($cliente, '/verificar-solicitud', (string) $buzon->ultimoToken($correo, '/verificar-solicitud'));
        return $cliente->enviar('/verificar-solicitud', ['token_csrf' => ClienteHttp::extraerTokenCsrf($pagina['cuerpo'])]);
    };

    $activarPorHttp = static function (string $correo, string $contrasena) use ($nuevoCliente, $buzon, $abrirEnlace): array {
        $cliente = $nuevoCliente();
        $pagina = $abrirEnlace($cliente, '/activar-cuenta', (string) $buzon->ultimoToken($correo, '/activar-cuenta'));
        return $cliente->enviar('/activar-cuenta', ['token_csrf' => ClienteHttp::extraerTokenCsrf($pagina['cuerpo']), 'contrasena' => $contrasena]);
    };

    $codigoTotp = static fn (string $secreto, int $desplazamiento = 0): string
        => SegundoFactorTotp::calcularCodigo(SegundoFactorTotp::decodificarBase32($secreto), intdiv(time(), 30) + $desplazamiento);

    $ejecutarHerramienta = static function (array $argumentos) use ($variablesEntorno): array {
        $procesoHerramienta = proc_open(
            array_merge([PHP_BINARY], $argumentos),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tuberiasHerramienta,
            RUTA_RAIZ,
            array_merge(getenv(), $variablesEntorno)
        );
        $salida = stream_get_contents($tuberiasHerramienta[1]) . stream_get_contents($tuberiasHerramienta[2]);
        return ['codigo' => proc_close($procesoHerramienta), 'salida' => (string) $salida];
    };

    // Cliente del principal con sesión administrativa (se llena en H05).
    $clientePrincipal = null;

    try {
        $marco->probar('H00', 'El servidor de pruebas responde', static fn () => MarcoPruebas::afirmar($servidorListo, 'php -S no respondió en el puerto ' . $puertoHttp));
        if (!$servidorListo) {
            return;
        }

        $marco->probar('H01', 'AC-01: portada y "Solicitar acceso" públicas, sin contraseña, rol ni opciones administrativas', static function () use ($nuevoCliente, $obtenerUbicacion): void {
            $cliente = $nuevoCliente();
            $portada = $cliente->obtener('/');
            MarcoPruebas::afirmarIgual(200, $portada['codigo'], 'Portada');
            MarcoPruebas::afirmar(str_contains($portada['cuerpo'], 'Iniciar sesión') && str_contains($portada['cuerpo'], 'Solicitar acceso'), 'Dos caminos claros');
            MarcoPruebas::afirmar(str_contains($portada['cuerpo'], 'Portal en desarrollo'), 'No simula módulos inexistentes');
            MarcoPruebas::afirmar(preg_match('/^Referrer-Policy: no-referrer/mi', $portada['encabezados']) === 1, 'Referrer-Policy: no-referrer');

            $formulario = $cliente->obtener('/solicitar-acceso');
            MarcoPruebas::afirmarIgual(200, $formulario['codigo'], 'Formulario');
            MarcoPruebas::afirmar(!str_contains($formulario['cuerpo'], 'type="password"'), 'No pide contraseña');
            MarcoPruebas::afirmar(!preg_match('/name="rol|ADMINISTRADOR|admin\//', $formulario['cuerpo']), 'No ofrece roles ni enlaces administrativos');
            MarcoPruebas::afirmar(str_contains($formulario['cuerpo'], 'name="identificador_institucional"') && str_contains($formulario['cuerpo'], '/aviso-privacidad'), 'Identificador y aviso de privacidad');
            MarcoPruebas::afirmarIgual('/solicitar-acceso', $obtenerUbicacion($cliente->obtener('/registro')), 'La ruta anterior /registro lleva al nuevo formulario');
            foreach (['/ayuda', '/aviso-privacidad', '/recuperar-contrasena', '/reenviar-verificacion'] as $ruta) {
                MarcoPruebas::afirmarIgual(200, $cliente->obtener($ruta)['codigo'], "GET {$ruta}");
            }
        });

        $marco->probar('H02', 'AC-27: la consola crea al principal con enlace de activación, una sola vez, y no es accesible por web', static function () use ($ejecutarHerramienta, $baseDatos, $nuevoCliente, &$tokenPrincipal, &$tokenEnrolamientoPrincipal): void {
            $herramienta = RUTA_RAIZ . '/herramientas/inicializar-administrador-principal.php';
            $primera = $ejecutarHerramienta([$herramienta, 'paula@http.mx', 'Paula', 'Principal']);
            MarcoPruebas::afirmarIgual(0, $primera['codigo'], 'Primera ejecución: ' . $primera['salida']);
            MarcoPruebas::afirmar(preg_match('#/activar-cuenta\?token=([A-Za-z0-9_-]{43})#', $primera['salida'], $coincidencia) === 1, 'Imprime el enlace de activación');
            $tokenPrincipal = $coincidencia[1];
            MarcoPruebas::afirmar(preg_match('#/configurar-segundo-factor\?token=([A-Za-z0-9_-]{43})#', $primera['salida'], $coincidenciaMfa) === 1, 'R-02: y el enlace para configurar el segundo factor');
            $tokenEnrolamientoPrincipal = $coincidenciaMfa[1];
            MarcoPruebas::afirmar(!preg_match('/contrase(ñ|n)a:\s*\S/i', $primera['salida']), 'No imprime ninguna contraseña');
            MarcoPruebas::afirmarIgual('INVITADA', $baseDatos->obtenerFilaUsuario('paula@http.mx')['estado_cuenta_usuario'], 'Cuenta INVITADA');
            MarcoPruebas::afirmarIgual(1, $ejecutarHerramienta([$herramienta, 'otro@http.mx', 'Otro', 'Principal'])['codigo'], 'Segunda ejecución rechazada');
            MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaUsuario('otro@http.mx'), 'Sin segunda cuenta');
            MarcoPruebas::afirmarIgual(404, $nuevoCliente()->obtener('/herramientas/inicializar-administrador-principal.php')['codigo'], 'No expuesta por HTTP');
        });

        $marco->probar('H04', 'RF-05: activar por el enlace (URL limpia), rechazo de contraseña común y login separado', static function () use ($nuevoCliente, $abrirEnlace, $baseDatos, $obtenerUbicacion, $contrasenaPrincipal, &$tokenPrincipal): void {
            $cliente = $nuevoCliente();
            $formulario = $abrirEnlace($cliente, '/activar-cuenta', (string) $tokenPrincipal);
            MarcoPruebas::afirmarIgual(200, $formulario['codigo'], 'Formulario de activación');
            MarcoPruebas::afirmar(str_contains($formulario['cuerpo'], 'Activar mi cuenta') && str_contains($formulario['cuerpo'], 'autocomplete="new-password"'), 'Contraseña nueva compatible con gestores');
            MarcoPruebas::afirmar(!str_contains($formulario['cuerpo'], (string) $tokenPrincipal), 'El token no aparece en la página');
            $token = ClienteHttp::extraerTokenCsrf($formulario['cuerpo']);

            $rechazo = $cliente->enviar('/activar-cuenta', ['token_csrf' => $token, 'contrasena' => '123456789012345']);
            MarcoPruebas::afirmarIgual(422, $rechazo['codigo'], 'Contraseña predecible rechazada');
            MarcoPruebas::afirmar(str_contains($rechazo['cuerpo'], 'muy común o fácil de adivinar'), 'Mensaje claro sin revelar la lista');

            $activacion = $cliente->enviar('/activar-cuenta', ['token_csrf' => $token, 'contrasena' => $contrasenaPrincipal]);
            MarcoPruebas::afirmarIgual(303, $activacion['codigo'], 'Activada');
            MarcoPruebas::afirmarIgual('/iniciar-sesion', $obtenerUbicacion($activacion), 'Exige iniciar sesión por separado');
            MarcoPruebas::afirmarIgual('ACTIVA', $baseDatos->obtenerFilaUsuario('paula@http.mx')['estado_cuenta_usuario'], 'ACTIVA');
            $reuso = $nuevoCliente()->obtener('/activar-cuenta?token=' . $tokenPrincipal);
            MarcoPruebas::afirmarIgual(303, $reuso['codigo'], 'El enlace redirige');
        });

        $marco->probar('H05', 'AC-14/R-02: el principal sin segundo factor no entra; lo configura solo con el enlace y recibe sus códigos una vez', static function () use ($iniciarSesionPorHttp, $obtenerUbicacion, $abrirEnlace, $contrasenaPrincipal, $codigoTotp, &$clientePrincipal, &$secretoPrincipal, &$tokenEnrolamientoPrincipal): void {
            [$cliente, $respuesta] = $iniciarSesionPorHttp('paula@http.mx', $contrasenaPrincipal);
            MarcoPruebas::afirmarIgual('/configurar-segundo-factor', $obtenerUbicacion($respuesta), 'Va a configurar el segundo factor');
            MarcoPruebas::afirmarIgual('/iniciar-sesion', $obtenerUbicacion($cliente->obtener('/admin/solicitudes')), 'Sin segundo factor no hay administración');
            MarcoPruebas::afirmarIgual('/iniciar-sesion', $obtenerUbicacion($cliente->obtener('/inicio')), 'Ni siquiera hay sesión');

            $sinEnlace = $cliente->obtener('/configurar-segundo-factor');
            MarcoPruebas::afirmar(str_contains($sinEnlace['cuerpo'], 'Enviarme un enlace nuevo') && !str_contains($sinEnlace['cuerpo'], 'clave-secreta'), 'R-02: con la contraseña sola no se muestra ninguna clave');

            $pagina = $abrirEnlace($cliente, '/configurar-segundo-factor', (string) $tokenEnrolamientoPrincipal);
            MarcoPruebas::afirmarIgual(200, $pagina['codigo'], 'Instrucciones');
            MarcoPruebas::afirmar(preg_match('#<code class="clave-secreta">([A-Z2-7 ]+)</code>#', $pagina['cuerpo'], $coincidencia) === 1, 'Muestra la clave para la aplicación');
            $secretoPrincipal = str_replace(' ', '', $coincidencia[1]);
            $token = ClienteHttp::extraerTokenCsrf($pagina['cuerpo']);

            $error = $cliente->enviar('/configurar-segundo-factor', ['token_csrf' => $token, 'codigo' => '000000' === $codigoTotp($secretoPrincipal) ? '111111' : '000000']);
            MarcoPruebas::afirmarIgual(401, $error['codigo'], 'Código incorrecto');
            $confirmacion = $cliente->enviar('/configurar-segundo-factor', ['token_csrf' => $token, 'codigo' => $codigoTotp($secretoPrincipal)]);
            MarcoPruebas::afirmarIgual('/codigos-recuperacion', $obtenerUbicacion($confirmacion), 'Configurado');
            $codigos = $cliente->obtener('/codigos-recuperacion');
            MarcoPruebas::afirmarIgual(CANTIDAD_CODIGOS_RECUPERACION_MFA, preg_match_all('#<li><code>[A-Z2-9]{5}-[A-Z2-9]{5}</code></li>#', $codigos['cuerpo']), 'Diez códigos de recuperación');
            MarcoPruebas::afirmarIgual('/inicio', $obtenerUbicacion($cliente->obtener('/codigos-recuperacion')), 'Solo se muestran una vez');
            MarcoPruebas::afirmarIgual(200, $cliente->obtener('/admin/solicitudes')['codigo'], 'Ahora sí administra');
            $clientePrincipal = $cliente;
        });

        $marco->probar('H03', 'RF-02/RF-03: solicitud con respuesta neutral, verificación con botón y enlace de un solo uso', static function () use ($solicitarPorHttp, $obtenerUbicacion, $nuevoCliente, $verificarPorHttp, $baseDatos, $buzon): void {
            $respuesta = $solicitarPorHttp('Ana', 'Alumna', 'ana@http.mx', 'ALUMNO', 'A3001');
            MarcoPruebas::afirmarIgual('/solicitud-recibida', $obtenerUbicacion($respuesta), 'Redirige a la confirmación');
            $confirmacion = $nuevoCliente()->obtener('/solicitud-recibida');
            MarcoPruebas::afirmar(str_contains($confirmacion['cuerpo'], 'Si los datos permiten continuar el trámite'), 'Mensaje neutral');
            MarcoPruebas::afirmar(str_contains($confirmacion['cuerpo'], 'Si recibe un mensaje con un enlace') && !str_contains($confirmacion['cuerpo'], 'que le enviamos'), 'R-07: instrucciones condicionales, sin afirmar que se envió');
            MarcoPruebas::afirmar(str_contains($confirmacion['cuerpo'], 'modo de demostración'), 'R-07: avisa que los mensajes quedan en el buzón de pruebas');
            $repetida = $solicitarPorHttp('Ana', 'Alumna', 'paula@http.mx', 'ALUMNO', 'A3009');
            MarcoPruebas::afirmarIgual('/solicitud-recibida', $obtenerUbicacion($repetida), 'Misma respuesta con un correo que ya tiene cuenta (sin enumeración)');
            MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaSolicitud('paula@http.mx'), 'Y no se crea solicitud');

            $tokenVerificacion = (string) $buzon->ultimoToken('ana@http.mx', '/verificar-solicitud');
            $resultado = $verificarPorHttp('ana@http.mx');
            MarcoPruebas::afirmarIgual(200, $resultado['codigo'], 'Verificación');
            MarcoPruebas::afirmar(str_contains($resultado['cuerpo'], 'Su correo se verificó correctamente'), 'Confirmación');
            MarcoPruebas::afirmar($baseDatos->obtenerFilaSolicitud('ana@http.mx')['fecha_verificacion_correo_solicitud'] !== null, 'Registrada en la BD');

            $cliente = $nuevoCliente();
            $cliente->obtener('/verificar-solicitud?token=' . $tokenVerificacion);
            $segundaVez = $cliente->enviar('/verificar-solicitud', ['token_csrf' => ClienteHttp::extraerTokenCsrf($cliente->obtener('/verificar-solicitud')['cuerpo'])]);
            MarcoPruebas::afirmarIgual(400, $segundaVez['codigo'], 'El enlace no sirve dos veces');
            MarcoPruebas::afirmar(str_contains($segundaVez['cuerpo'], 'no es válido o ha vencido') && str_contains($segundaVez['cuerpo'], 'Reenviar enlace'), 'Mensaje y acción segura');
        });

        $marco->probar('H06', 'AC-06: el principal aprueba en la bandeja con confirmación y método registrado', static function () use (&$clientePrincipal, $baseDatos, $obtenerUbicacion, $buzon): void {
            MarcoPruebas::afirmar($clientePrincipal instanceof ClienteHttp, 'Hay sesión del principal');
            $bandeja = $clientePrincipal->obtener('/admin/solicitudes');
            MarcoPruebas::afirmar(str_contains($bandeja['cuerpo'], 'ana@http.mx') && str_contains($bandeja['cuerpo'], 'Correo verificado'), 'La solicitud verificada aparece');
            $idSolicitud = (string) $baseDatos->obtenerFilaSolicitud('ana@http.mx')['id_solicitud_acceso'];
            $detalle = $clientePrincipal->obtener('/admin/solicitudes/detalle?id=' . $idSolicitud);
            MarcoPruebas::afirmar(str_contains($detalle['cuerpo'], 'Aprobar solicitud') && str_contains($detalle['cuerpo'], 'Rechazar solicitud'), 'Acciones visibles');
            $token = ClienteHttp::extraerTokenCsrf($detalle['cuerpo']);

            $sinConfirmar = $clientePrincipal->enviar('/admin/solicitudes/aprobar', ['token_csrf' => $token, 'id_solicitud' => $idSolicitud, 'metodo_verificacion' => 'Padrón 2026']);
            MarcoPruebas::afirmarIgual('/admin/solicitudes/detalle?id=' . $idSolicitud, $obtenerUbicacion($sinConfirmar), 'Sin confirmación vuelve al detalle');
            MarcoPruebas::afirmarIgual('PENDIENTE', $baseDatos->obtenerFilaSolicitud('ana@http.mx')['estado_solicitud'], 'Sin cambios');

            $aprobada = $clientePrincipal->enviar('/admin/solicitudes/aprobar', [
                'token_csrf' => $token, 'id_solicitud' => $idSolicitud, 'metodo_verificacion' => 'Cotejo con el padrón 2026-B', 'confirmacion_pertenencia' => 'si',
            ]);
            MarcoPruebas::afirmarIgual('/admin/solicitudes', $obtenerUbicacion($aprobada), 'Vuelve a la bandeja');
            MarcoPruebas::afirmar(str_contains($clientePrincipal->obtener('/admin/solicitudes')['cuerpo'], 'Se generó una invitación de activación'), 'Mensaje de éxito');
            MarcoPruebas::afirmarIgual('INVITADA', $baseDatos->obtenerFilaUsuario('ana@http.mx')['estado_cuenta_usuario'], 'Cuenta INVITADA');
            MarcoPruebas::afirmar($buzon->ultimoToken('ana@http.mx', '/activar-cuenta') !== null, 'Invitación en el buzón');
        });

        $marco->probar('P06b', 'AC-13: la persona activa su cuenta y entra; cookie segura y datos no sensibles en /inicio', static function () use ($activarPorHttp, $iniciarSesionPorHttp, $obtenerUbicacion, $contrasenaComun): void {
            MarcoPruebas::afirmarIgual(303, $activarPorHttp('ana@http.mx', $contrasenaComun)['codigo'], 'Activación');
            [$cliente, $respuesta] = $iniciarSesionPorHttp('ana@http.mx');
            MarcoPruebas::afirmarIgual(303, $respuesta['codigo'], 'Login correcto');
            MarcoPruebas::afirmarIgual('/inicio', $obtenerUbicacion($respuesta), 'Destino');
            MarcoPruebas::afirmar(preg_match('/^Set-Cookie: papc_sesion=[^;]+;.*HttpOnly/mi', $respuesta['encabezados']) === 1, 'Cookie HttpOnly');
            MarcoPruebas::afirmar(preg_match('/^Set-Cookie: papc_sesion=.*SameSite=Lax/mi', $respuesta['encabezados']) === 1, 'Cookie SameSite=Lax');
            $inicio = $cliente->obtener('/inicio');
            MarcoPruebas::afirmarIgual(200, $inicio['codigo'], '/inicio accesible');
            MarcoPruebas::afirmar(str_contains($inicio['cuerpo'], 'Ana Alumna') && str_contains($inicio['cuerpo'], 'USUARIO') && str_contains($inicio['cuerpo'], 'ACTIVA'), 'Nombre, rol y estado');
            MarcoPruebas::afirmar(!str_contains($inicio['cuerpo'], 'A3001') && str_contains($inicio['cuerpo'], '••001'), 'Matrícula enmascarada');
            MarcoPruebas::afirmar(!str_contains($inicio['cuerpo'], '/admin/'), 'Sin enlaces administrativos');
            MarcoPruebas::afirmar(preg_match('/^Cache-Control: no-store/mi', $inicio['encabezados']) === 1, 'AC-22: no se guarda en caché');
            $idSesion = $cliente->exportarCookies()['papc_sesion'] ?? 'sin-cookie';
            MarcoPruebas::afirmar(!str_contains($inicio['cuerpo'], $idSesion), 'El ID de sesión no aparece en el HTML ni en URLs');
        });

        $marco->probar('P07b', 'SEG-06: credenciales incorrectas, cuenta inexistente o INVITADA: mismo código y mensaje', static function () use ($iniciarSesionPorHttp, $solicitarPorHttp, $verificarPorHttp, &$clientePrincipal, $baseDatos, $contrasenaComun): void {
            $solicitarPorHttp('Ivan', 'Invitado', 'ivan@http.mx', 'ALUMNO', 'A3002');
            $verificarPorHttp('ivan@http.mx');
            $idSolicitud = (string) $baseDatos->obtenerFilaSolicitud('ivan@http.mx')['id_solicitud_acceso'];
            $token = ClienteHttp::extraerTokenCsrf($clientePrincipal->obtener('/admin/solicitudes/detalle?id=' . $idSolicitud)['cuerpo']);
            $clientePrincipal->enviar('/admin/solicitudes/aprobar', ['token_csrf' => $token, 'id_solicitud' => $idSolicitud, 'metodo_verificacion' => 'Padrón de prueba', 'confirmacion_pertenencia' => 'si']);
            MarcoPruebas::afirmarIgual('INVITADA', $baseDatos->obtenerFilaUsuario('ivan@http.mx')['estado_cuenta_usuario'], 'Iván está INVITADO');

            $respuestas = [
                $iniciarSesionPorHttp('ana@http.mx', 'contraseña totalmente equivocada')[1],
                $iniciarSesionPorHttp('fantasma@http.mx', 'contraseña totalmente equivocada')[1],
                $iniciarSesionPorHttp('ivan@http.mx', $contrasenaComun)[1],
            ];
            foreach ($respuestas as $respuesta) {
                MarcoPruebas::afirmarIgual(401, $respuesta['codigo'], 'Código 401');
                MarcoPruebas::afirmar(str_contains($respuesta['cuerpo'], 'No fue posible iniciar sesión. Verifique los datos e intente de nuevo.'), 'Mensaje neutral idéntico');
            }
        });

        $marco->probar('P09b', 'Rutas protegidas sin sesión: redirección al login o 401', static function () use ($nuevoCliente, $obtenerUbicacion): void {
            $cliente = $nuevoCliente();
            foreach (['/inicio', '/admin/usuarios', '/admin/solicitudes', '/admin/roles', '/admin/bitacora'] as $rutaProtegida) {
                $respuesta = $cliente->obtener($rutaProtegida);
                MarcoPruebas::afirmarIgual(303, $respuesta['codigo'], "GET {$rutaProtegida}");
                MarcoPruebas::afirmarIgual('/iniciar-sesion', $obtenerUbicacion($respuesta), 'Destino');
            }
            MarcoPruebas::afirmarIgual(401, $cliente->enviar('/cerrar-sesion', ['token_csrf' => 'x'])['codigo'], 'POST /cerrar-sesion');
            MarcoPruebas::afirmarIgual(401, $cliente->enviar('/admin/roles/cambiar', ['id_usuario' => '1', 'rol_nuevo' => 'ADMINISTRADOR'])['codigo'], 'POST rol');
            MarcoPruebas::afirmarIgual(405, $cliente->obtener('/cerrar-sesion')['codigo'], 'GET /cerrar-sesion => 405');
        });

        $marco->probar('P13b', 'AC-19: POST sin token CSRF válido se rechaza y no cambia datos', static function () use ($nuevoCliente, $iniciarSesionPorHttp, $contrasenaComun, $baseDatos, &$clientePrincipal, $solicitarPorHttp, $verificarPorHttp): void {
            $cliente = $nuevoCliente();
            $cliente->obtener('/iniciar-sesion');
            $eventosAntes = $baseDatos->contarEventos('CSRF_INVALIDO');
            MarcoPruebas::afirmarIgual(400, $cliente->enviar('/iniciar-sesion', ['correo_electronico' => 'ana@http.mx', 'contrasena' => $contrasenaComun])['codigo'], 'Login sin token');
            $tokenDeOtroCliente = $nuevoCliente()->obtenerTokenDe('/iniciar-sesion');
            MarcoPruebas::afirmarIgual(400, $cliente->enviar('/iniciar-sesion', ['token_csrf' => $tokenDeOtroCliente, 'correo_electronico' => 'ana@http.mx', 'contrasena' => $contrasenaComun])['codigo'], 'Token de otra sesión');

            [$clienteAutenticado] = $iniciarSesionPorHttp('ana@http.mx');
            MarcoPruebas::afirmarIgual(400, $clienteAutenticado->enviar('/cerrar-sesion', [])['codigo'], 'Logout sin token (CSRF de cierre forzado)');
            MarcoPruebas::afirmarIgual(200, $clienteAutenticado->obtener('/inicio')['codigo'], 'La sesión sigue activa');

            $solicitarPorHttp('Jaime', 'Jimenez', 'jaime@http.mx', 'ALUMNO', 'A3003');
            $verificarPorHttp('jaime@http.mx');
            $idSolicitud = (string) $baseDatos->obtenerFilaSolicitud('jaime@http.mx')['id_solicitud_acceso'];
            MarcoPruebas::afirmarIgual(400, $clientePrincipal->enviar('/admin/solicitudes/aprobar', [
                'id_solicitud' => $idSolicitud, 'metodo_verificacion' => 'Sin token', 'confirmacion_pertenencia' => 'si',
            ])['codigo'], 'Aprobación sin token');
            MarcoPruebas::afirmarIgual('PENDIENTE', $baseDatos->obtenerFilaSolicitud('jaime@http.mx')['estado_solicitud'], 'La solicitud no cambia');
            MarcoPruebas::afirmarIgual($eventosAntes + 4, $baseDatos->contarEventos('CSRF_INVALIDO'), 'Rechazos auditados');
        });

        $marco->probar('P10b', 'AC-02: solicitud con rol y estado adulterados: 400, sin solicitud ni cuenta y auditada', static function () use ($solicitarPorHttp, $baseDatos): void {
            $respuesta = $solicitarPorHttp('Mallory', 'Atacante', 'mallory@http.mx', 'PROFESOR', 'P6666', [
                'rol_usuario' => 'ADMINISTRADOR_PRINCIPAL', 'estado_cuenta_usuario' => 'ACTIVA', 'contrasena' => 'una contraseña que nadie pidió',
            ]);
            MarcoPruebas::afirmarIgual(400, $respuesta['codigo'], 'Rechazo');
            MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaSolicitud('mallory@http.mx'), 'Sin solicitud');
            MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaUsuario('mallory@http.mx'), 'Sin cuenta');
            MarcoPruebas::afirmarIgual(1, $baseDatos->contarEventos('INTENTO_ESCALADA_FORMULARIO'), 'Auditado');
            MarcoPruebas::afirmarIgual(1, $baseDatos->contar("SELECT COUNT(*) FROM usuario WHERE rol_usuario <> 'USUARIO'"), 'La única cuenta administrativa es la del principal');
        });

        $marco->probar('P11b', 'AC-05/AC-15: un USUARIO recibe 403 en la bandeja, al aprobar y en la sección del principal', static function () use ($iniciarSesionPorHttp, $baseDatos, $contrasenaComun): void {
            [$cliente] = $iniciarSesionPorHttp('ana@http.mx');
            $token = ClienteHttp::extraerTokenCsrf($cliente->obtener('/inicio')['cuerpo']);
            $idSolicitud = (string) $baseDatos->obtenerFilaSolicitud('jaime@http.mx')['id_solicitud_acceso'];
            $idAna = (string) $baseDatos->obtenerFilaUsuario('ana@http.mx')['id_usuario'];
            foreach (['/admin/solicitudes', '/admin/usuarios', '/admin/roles', '/admin/bitacora'] as $ruta) {
                $respuesta = $cliente->obtener($ruta);
                MarcoPruebas::afirmarIgual(403, $respuesta['codigo'], "GET {$ruta}");
                MarcoPruebas::afirmar(!str_contains($respuesta['cuerpo'], 'jaime@http.mx'), 'Sin información');
            }
            MarcoPruebas::afirmarIgual(403, $cliente->enviar('/admin/solicitudes/aprobar', ['token_csrf' => $token, 'id_solicitud' => $idSolicitud, 'metodo_verificacion' => 'Yo', 'confirmacion_pertenencia' => 'si'])['codigo'], 'Aprobar');
            MarcoPruebas::afirmarIgual(403, $cliente->enviar('/admin/roles/cambiar', ['token_csrf' => $token, 'id_usuario' => $idAna, 'rol_nuevo' => 'ADMINISTRADOR_PRINCIPAL', 'contrasena_confirmacion' => $contrasenaComun])['codigo'], 'Autoelevación');
            MarcoPruebas::afirmarIgual('PENDIENTE', $baseDatos->obtenerFilaSolicitud('jaime@http.mx')['estado_solicitud'], 'Solicitud sin cambios');
            MarcoPruebas::afirmarIgual('USUARIO', $baseDatos->obtenerFilaUsuario('ana@http.mx')['rol_usuario'], 'Rol sin cambios');
        });

        $marco->probar('P08b', 'AC-22: cerrar sesión invalida la cookie anterior aunque se reutilice', static function () use ($iniciarSesionPorHttp, $obtenerUbicacion): void {
            [$cliente] = $iniciarSesionPorHttp('ana@http.mx');
            $cookiesAntiguas = $cliente->exportarCookies();
            $token = ClienteHttp::extraerTokenCsrf($cliente->obtener('/inicio')['cuerpo']);
            MarcoPruebas::afirmarIgual(303, $cliente->enviar('/cerrar-sesion', ['token_csrf' => $token])['codigo'], 'Cierre de sesión');
            MarcoPruebas::afirmarIgual(303, $cliente->obtener('/inicio')['codigo'], 'Con la cookie nueva ya no hay acceso');
            $cliente->importarCookies($cookiesAntiguas);
            $respuestaReutilizada = $cliente->obtener('/inicio');
            MarcoPruebas::afirmarIgual(303, $respuestaReutilizada['codigo'], 'La cookie anterior no da acceso');
            MarcoPruebas::afirmarIgual('/iniciar-sesion', $obtenerUbicacion($respuestaReutilizada), 'Redirige al login');
        });

        $marco->probar('H08', 'AC-17/RF-07: recuperar por HTTP responde igual; restablecer revoca las sesiones abiertas', static function () use ($nuevoCliente, $iniciarSesionPorHttp, $obtenerUbicacion, $abrirEnlace, $buzon, $contrasenaComun): void {
            [$clienteAbierto] = $iniciarSesionPorHttp('ana@http.mx');
            $mensajes = [];
            foreach (['ana@http.mx', 'fantasma@http.mx'] as $correo) {
                $cliente = $nuevoCliente();
                $respuesta = $cliente->enviar('/recuperar-contrasena', ['token_csrf' => $cliente->obtenerTokenDe('/recuperar-contrasena'), 'correo_electronico' => $correo]);
                MarcoPruebas::afirmarIgual('/iniciar-sesion', $obtenerUbicacion($respuesta), "Misma redirección para {$correo}");
                $mensajes[] = preg_match('#<p class="mensaje mensaje-aviso"[^>]*>\s*(.*?)\s*</p>#s', $cliente->obtener('/iniciar-sesion')['cuerpo'], $coincidencia) === 1 ? $coincidencia[1] : '';
            }
            MarcoPruebas::afirmar($mensajes[0] !== '' && $mensajes[0] === $mensajes[1], 'Mismo mensaje exista o no la cuenta');

            $cliente = $nuevoCliente();
            $formulario = $abrirEnlace($cliente, '/restablecer-contrasena', (string) $buzon->ultimoToken('ana@http.mx', '/restablecer-contrasena'));
            $contrasenaNueva = 'el viento del sur trae lluvia fresca';
            $resultado = $cliente->enviar('/restablecer-contrasena', ['token_csrf' => ClienteHttp::extraerTokenCsrf($formulario['cuerpo']), 'contrasena' => $contrasenaNueva]);
            MarcoPruebas::afirmarIgual('/iniciar-sesion', $obtenerUbicacion($resultado), 'Restablecida; exige login');
            MarcoPruebas::afirmarIgual(303, $clienteAbierto->obtener('/inicio')['codigo'], 'La sesión que estaba abierta se cerró');
            MarcoPruebas::afirmarIgual(401, $iniciarSesionPorHttp('ana@http.mx', $contrasenaComun)[1]['codigo'], 'La contraseña anterior ya no sirve');
            MarcoPruebas::afirmarIgual(303, $iniciarSesionPorHttp('ana@http.mx', $contrasenaNueva)[1]['codigo'], 'La nueva sí');
        });

        $marco->probar('P14c', 'AC-20: XSS reflejado e inyección SQL por formulario', static function () use ($nuevoCliente, $solicitarPorHttp): void {
            $cliente = $nuevoCliente();
            $cargaXss = '"><script>alert(1)</script>';
            $respuestaXss = $cliente->enviar('/iniciar-sesion', ['token_csrf' => $cliente->obtenerTokenDe('/iniciar-sesion'), 'correo_electronico' => $cargaXss, 'contrasena' => 'x']);
            MarcoPruebas::afirmar(!str_contains($respuestaXss['cuerpo'], '<script>alert(1)</script>'), 'La carga no se refleja sin escapar');
            MarcoPruebas::afirmar(str_contains($respuestaXss['cuerpo'], '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;'), 'Se refleja escapada');
            MarcoPruebas::afirmar(preg_match("/^Content-Security-Policy: default-src 'self'/mi", $respuestaXss['encabezados']) === 1, 'CSP presente');

            $solicitudXss = $solicitarPorHttp($cargaXss, 'Prueba', 'xss@http.mx', 'ALUMNO', 'A3004');
            MarcoPruebas::afirmarIgual(422, $solicitudXss['codigo'], 'Nombre con etiquetas rechazado');
            MarcoPruebas::afirmar(!str_contains($solicitudXss['cuerpo'], '<script>alert(1)</script>'), 'Ni se refleja sin escapar');

            $respuestaSql = $cliente->enviar('/iniciar-sesion', ['token_csrf' => $cliente->obtenerTokenDe('/iniciar-sesion'), 'correo_electronico' => "' OR '1'='1' -- ", 'contrasena' => "' OR '1'='1"]);
            MarcoPruebas::afirmarIgual(422, $respuestaSql['codigo'], 'Inyección SQL no pasa ni la validación');
            MarcoPruebas::afirmar(!preg_match('/SQLSTATE|PDOException|syntax|Stack trace|\.php/i', $respuestaSql['cuerpo']), 'No se filtran errores internos');
        });

        $marco->probar('P16', 'AC-23: archivos privados, correos de prueba y herramientas no accesibles por HTTP', static function () use ($nuevoCliente): void {
            $cliente = $nuevoCliente();
            foreach ([
                '/config/database.php', '/config/permisos.php', '/.env', '/.env.example', '/.git/config',
                '/storage/logs/app.log', '/storage/logs/php-errores.log', '/storage/correos/', '/database/schema.sql',
                '/database/migraciones/004-solicitud-acceso-activacion.sql', '/database/usuario-aplicacion.sql',
                '/app/controllers/AuthController.php', '/app/views/login.php', '/herramientas/ver-buzon-pruebas.php',
                '/tests/ejecutar-pruebas.php', '/README.md', '/config/contrasenas-comunes.txt',
                '/laboratorio/inyeccion-sql/demostracion.php', '/../config/database.php', '/%2e%2e/config/database.php',
            ] as $rutaPrivada) {
                $respuesta = $cliente->obtener($rutaPrivada);
                MarcoPruebas::afirmar(in_array($respuesta['codigo'], [403, 404], true), "{$rutaPrivada} devolvió {$respuesta['codigo']}");
                MarcoPruebas::afirmar(!preg_match('/obtenerConexionBaseDatos|DB_CONTRASENA|CREATE TABLE|GRANT|<\?php|\[core\]|token=/', $respuesta['cuerpo']), "{$rutaPrivada} filtró contenido");
            }
            MarcoPruebas::afirmarIgual(404, $cliente->obtener('/css/')['codigo'], 'Sin listado de directorios');
            MarcoPruebas::afirmarIgual(200, $cliente->obtener('/css/portal.css')['codigo'], 'Los activos públicos sí se sirven');
            MarcoPruebas::afirmarIgual(200, $cliente->obtener('/js/validacion-formularios.js')['codigo'], 'El script de ayuda se sirve');
        });

        $marco->probar('P15b', 'AC-21: intentos repetidos por HTTP: 429 aun con la contraseña correcta', static function () use ($iniciarSesionPorHttp, $baseDatos): void {
            $baseDatos->limpiarIntentos();
            for ($intento = 1; $intento <= MAX_INTENTOS_LOGIN; $intento++) {
                [, $respuesta] = $iniciarSesionPorHttp('ana@http.mx', 'contraseña equivocada numero ' . $intento);
                MarcoPruebas::afirmarIgual(401, $respuesta['codigo'], "Fallo {$intento}");
            }
            [, $respuestaLimitada] = $iniciarSesionPorHttp('ana@http.mx', 'el viento del sur trae lluvia fresca');
            MarcoPruebas::afirmarIgual(429, $respuestaLimitada['codigo'], 'Limitado temporalmente');
            MarcoPruebas::afirmar(str_contains($respuestaLimitada['cuerpo'], '¿Olvidó su contraseña?'), 'Ofrece la recuperación');
            $baseDatos->limpiarIntentos();
        });
    } finally {
        foreach ($tuberias as $tuberia) {
            if (is_resource($tuberia)) {
                fclose($tuberia);
            }
        }
        proc_terminate($proceso);
        proc_close($proceso);
    }
};

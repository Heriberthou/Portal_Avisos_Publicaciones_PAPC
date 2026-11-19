<?php

/**
 * Pruebas de extremo a extremo por HTTP.
 *
 * Levanta el servidor embebido de PHP con DocumentRoot = public/ (la misma
 * regla que debe aplicarse en Apache) y actúa como un navegador: cookies,
 * formularios, tokens CSRF y redirecciones.
 *
 * Limitación honesta: el servidor embebido NO interpreta .htaccess. Estas
 * pruebas demuestran la protección por DocumentRoot; la configuración de
 * Apache/XAMPP debe verificarse con los comandos curl del README.
 */

declare(strict_types=1);

return static function (MarcoPruebas $marco, BaseDatosPruebas $baseDatos, array $variablesEntorno, int $puertoHttp, string $directorioSesiones): void {
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
    $nuevoCliente = static fn (): ClienteHttp => new ClienteHttp($urlBase);

    // "Ana Alumna" => nombres "Ana", apellido paterno "Alumna"; materno vacío (opcional).
    $registrarPorHttp = static function (string $nombre, string $correo, string $tipo) use ($nuevoCliente, $contrasenaComun): array {
        $cliente = $nuevoCliente();
        [$nombres, $apellidoPaterno] = explode(' ', $nombre, 2);
        return $cliente->enviar('/registro', [
            'token_csrf'               => $cliente->obtenerTokenDe('/registro'),
            'nombres_usuario'          => $nombres,
            'apellido_paterno_usuario' => $apellidoPaterno,
            'apellido_materno_usuario' => '',
            'correo_electronico'      => $correo,
            'contrasena'              => $contrasenaComun,
            'confirmacion_contrasena' => $contrasenaComun,
            'tipo_integrante'         => $tipo,
        ]);
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

    $obtenerUbicacion = static function (array $respuesta): string {
        return preg_match('/^Location:\s*(\S+)/mi', $respuesta['encabezados'], $coincidencias) === 1 ? $coincidencias[1] : '';
    };

    try {
        $marco->probar('H00', 'El servidor de pruebas responde', static fn () => MarcoPruebas::afirmar($servidorListo, 'php -S no respondió en el puerto ' . $puertoHttp));
        if (!$servidorListo) {
            return;
        }

        $marco->probar('H01', 'Registro por formulario: alumno y profesor reciben rol USUARIO', static function () use ($registrarPorHttp, $baseDatos, $obtenerUbicacion): void {
            foreach ([
                ['Paula Principal', 'paula@http.mx', 'PROFESOR'],
                ['Arturo Admin', 'arturo@http.mx', 'ALUMNO'],
                ['Ana Alumna', 'ana@http.mx', 'ALUMNO'],
                ['Pedro Profesor', 'pedro@http.mx', 'PROFESOR'],
            ] as [$nombre, $correo, $tipo]) {
                $respuesta = $registrarPorHttp($nombre, $correo, $tipo);
                MarcoPruebas::afirmarIgual(303, $respuesta['codigo'], "Registro de {$correo}");
                MarcoPruebas::afirmarIgual('/iniciar-sesion', $obtenerUbicacion($respuesta), 'Redirige al login');
                MarcoPruebas::afirmarIgual('USUARIO', $baseDatos->obtenerFilaUsuario($correo)['rol_usuario'], "Rol de {$correo}");
            }
            MarcoPruebas::afirmarIgual(409, $registrarPorHttp('Ana Duplicada', 'ANA@http.mx', 'ALUMNO')['codigo'], 'Duplicado rechazado (409)');
        });

        $marco->probar('H02', 'Herramienta de consola inicializa al principal una sola vez y no es accesible por web', static function () use ($variablesEntorno, $baseDatos, $nuevoCliente): void {
            $ejecutarHerramienta = static function (string $correo) use ($variablesEntorno): int {
                $procesoHerramienta = proc_open(
                    [PHP_BINARY, RUTA_RAIZ . '/herramientas/inicializar-administrador-principal.php', $correo],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $tuberiasHerramienta,
                    RUTA_RAIZ,
                    array_merge(getenv(), $variablesEntorno)
                );
                stream_get_contents($tuberiasHerramienta[1]);
                stream_get_contents($tuberiasHerramienta[2]);
                return proc_close($procesoHerramienta);
            };
            MarcoPruebas::afirmarIgual(0, $ejecutarHerramienta('paula@http.mx'), 'Primera ejecución');
            MarcoPruebas::afirmarIgual('ADMINISTRADOR_PRINCIPAL', $baseDatos->obtenerFilaUsuario('paula@http.mx')['rol_usuario'], 'Paula es principal');
            MarcoPruebas::afirmarIgual(1, $ejecutarHerramienta('ana@http.mx'), 'Segunda ejecución rechazada');
            MarcoPruebas::afirmarIgual('USUARIO', $baseDatos->obtenerFilaUsuario('ana@http.mx')['rol_usuario'], 'Ana sin cambios');
            MarcoPruebas::afirmarIgual(404, $nuevoCliente()->obtener('/herramientas/inicializar-administrador-principal.php')['codigo'], 'No expuesta por HTTP');
            // Preparación de escenario: Arturo será ADMINISTRADOR común.
            $baseDatos->fijarRol('arturo@http.mx', 'ADMINISTRADOR');
        });

        $marco->probar('P06b', 'Inicio de sesión válido por HTTP y cookie de sesión segura', static function () use ($iniciarSesionPorHttp, $obtenerUbicacion): void {
            [$cliente, $respuesta] = $iniciarSesionPorHttp('ana@http.mx');
            MarcoPruebas::afirmarIgual(303, $respuesta['codigo'], 'Login correcto');
            MarcoPruebas::afirmarIgual('/inicio', $obtenerUbicacion($respuesta), 'Destino');
            MarcoPruebas::afirmar(preg_match('/^Set-Cookie: papc_sesion=[^;]+;.*HttpOnly/mi', $respuesta['encabezados']) === 1, 'Cookie HttpOnly');
            MarcoPruebas::afirmar(preg_match('/^Set-Cookie: papc_sesion=.*SameSite=Lax/mi', $respuesta['encabezados']) === 1, 'Cookie SameSite=Lax');
            $inicio = $cliente->obtener('/inicio');
            MarcoPruebas::afirmarIgual(200, $inicio['codigo'], '/inicio accesible');
            MarcoPruebas::afirmar(str_contains($inicio['cuerpo'], 'Ana Alumna') && str_contains($inicio['cuerpo'], 'USUARIO'), 'Datos de la cuenta');
            $idSesion = $cliente->exportarCookies()['papc_sesion'] ?? 'sin-cookie';
            MarcoPruebas::afirmar(!str_contains($inicio['cuerpo'], $idSesion), 'El ID de sesión no aparece en el HTML ni en URLs');
        });

        $marco->probar('P07b', 'Credenciales incorrectas por HTTP: mismo código y mensaje', static function () use ($iniciarSesionPorHttp): void {
            [, $respuestaContrasenaMala] = $iniciarSesionPorHttp('ana@http.mx', 'contraseña totalmente equivocada');
            [, $respuestaCorreoInexistente] = $iniciarSesionPorHttp('fantasma@http.mx', 'contraseña totalmente equivocada');
            MarcoPruebas::afirmarIgual(401, $respuestaContrasenaMala['codigo'], 'Código contraseña mala');
            MarcoPruebas::afirmarIgual(401, $respuestaCorreoInexistente['codigo'], 'Código correo inexistente');
            $mensajeGenerico = 'Correo electrónico o contraseña incorrectos.';
            MarcoPruebas::afirmar(str_contains($respuestaContrasenaMala['cuerpo'], $mensajeGenerico) && str_contains($respuestaCorreoInexistente['cuerpo'], $mensajeGenerico), 'Mensaje genérico idéntico');
        });

        $marco->probar('P09b', 'Rutas protegidas sin sesión: redirección al login o 401', static function () use ($nuevoCliente, $obtenerUbicacion): void {
            $cliente = $nuevoCliente();
            foreach (['/inicio', '/admin/usuarios'] as $rutaProtegida) {
                $respuesta = $cliente->obtener($rutaProtegida);
                MarcoPruebas::afirmarIgual(303, $respuesta['codigo'], "GET {$rutaProtegida}");
                MarcoPruebas::afirmarIgual('/iniciar-sesion', $obtenerUbicacion($respuesta), 'Destino');
            }
            MarcoPruebas::afirmarIgual(401, $cliente->enviar('/cerrar-sesion', ['token_csrf' => 'x'])['codigo'], 'POST /cerrar-sesion');
            MarcoPruebas::afirmarIgual(401, $cliente->enviar('/admin/usuarios/rol', ['id_usuario' => '1', 'rol_nuevo' => 'ADMINISTRADOR'])['codigo'], 'POST rol');
            MarcoPruebas::afirmarIgual(405, $cliente->obtener('/cerrar-sesion')['codigo'], 'GET /cerrar-sesion => 405');
        });

        $marco->probar('P13b', 'CSRF: POST sin token o con token ajeno se rechaza', static function () use ($nuevoCliente, $iniciarSesionPorHttp, $contrasenaComun, $baseDatos): void {
            $cliente = $nuevoCliente();
            $cliente->obtener('/iniciar-sesion');
            $eventosAntes = $baseDatos->contarEventos('CSRF_INVALIDO');
            MarcoPruebas::afirmarIgual(400, $cliente->enviar('/iniciar-sesion', ['correo_electronico' => 'ana@http.mx', 'contrasena' => $contrasenaComun])['codigo'], 'Login sin token');
            $tokenDeOtroCliente = $nuevoCliente()->obtenerTokenDe('/iniciar-sesion');
            MarcoPruebas::afirmarIgual(400, $cliente->enviar('/iniciar-sesion', ['token_csrf' => $tokenDeOtroCliente, 'correo_electronico' => 'ana@http.mx', 'contrasena' => $contrasenaComun])['codigo'], 'Token de otra sesión');

            [$clienteAutenticado] = $iniciarSesionPorHttp('ana@http.mx');
            MarcoPruebas::afirmarIgual(400, $clienteAutenticado->enviar('/cerrar-sesion', [])['codigo'], 'Logout sin token (CSRF de cierre forzado)');
            MarcoPruebas::afirmarIgual(200, $clienteAutenticado->obtener('/inicio')['codigo'], 'La sesión sigue activa');
            MarcoPruebas::afirmarIgual($eventosAntes + 3, $baseDatos->contarEventos('CSRF_INVALIDO'), 'Rechazos auditados');
        });

        $marco->probar('P08b', 'Cerrar sesión invalida la cookie anterior aunque se reutilice', static function () use ($iniciarSesionPorHttp, $obtenerUbicacion): void {
            [$cliente] = $iniciarSesionPorHttp('ana@http.mx');
            $cookiesAntiguas = $cliente->exportarCookies();
            $token = ClienteHttp::extraerTokenCsrf($cliente->obtener('/inicio')['cuerpo']);
            $respuestaCierre = $cliente->enviar('/cerrar-sesion', ['token_csrf' => $token]);
            MarcoPruebas::afirmarIgual(303, $respuestaCierre['codigo'], 'Cierre de sesión');
            MarcoPruebas::afirmarIgual(303, $cliente->obtener('/inicio')['codigo'], 'Con la cookie nueva ya no hay acceso');
            $cliente->importarCookies($cookiesAntiguas);
            $respuestaReutilizada = $cliente->obtener('/inicio');
            MarcoPruebas::afirmarIgual(303, $respuestaReutilizada['codigo'], 'La cookie anterior no da acceso');
            MarcoPruebas::afirmarIgual('/iniciar-sesion', $obtenerUbicacion($respuestaReutilizada), 'Redirige al login');
        });

        $marco->probar('P10b', 'Registro con campos internos manipulados: 400, sin cuenta y auditado', static function () use ($nuevoCliente, $baseDatos, $contrasenaComun): void {
            $cliente = $nuevoCliente();
            $respuesta = $cliente->enviar('/registro', [
                'token_csrf'                  => $cliente->obtenerTokenDe('/registro'),
                'nombres_usuario'             => 'Mallory',
                'apellido_paterno_usuario'    => 'Atacante',
                'correo_electronico'          => 'mallory@http.mx',
                'contrasena'                  => $contrasenaComun,
                'confirmacion_contrasena'     => $contrasenaComun,
                'tipo_integrante'             => 'PROFESOR',
                'rol_usuario'                 => 'ADMINISTRADOR_PRINCIPAL',
                'estado_verificacion_usuario' => 'VERIFICADA',
            ]);
            MarcoPruebas::afirmarIgual(400, $respuesta['codigo'], 'Rechazo');
            MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaUsuario('mallory@http.mx'), 'Sin cuenta creada');
            MarcoPruebas::afirmarIgual(1, $baseDatos->contarEventos('INTENTO_ESCALADA_REGISTRO'), 'Auditado');
            MarcoPruebas::afirmarIgual(0, (int) $baseDatos->obtenerConexionAdministrativa()->query("SELECT COUNT(*) FROM usuario WHERE rol_usuario <> 'USUARIO' AND correo_electronico_usuario NOT IN ('paula@http.mx','arturo@http.mx')")->fetchColumn(), 'Ninguna otra cuenta administrativa');
        });

        $marco->probar('P11b', 'Un USUARIO no accede a operaciones administrativas por HTTP', static function () use ($iniciarSesionPorHttp, $baseDatos, $contrasenaComun): void {
            [$cliente] = $iniciarSesionPorHttp('ana@http.mx');
            $token = ClienteHttp::extraerTokenCsrf($cliente->obtener('/inicio')['cuerpo']);
            $idAna = (string) $baseDatos->obtenerFilaUsuario('ana@http.mx')['id_usuario'];
            $idPedro = (string) $baseDatos->obtenerFilaUsuario('pedro@http.mx')['id_usuario'];
            MarcoPruebas::afirmarIgual(403, $cliente->obtener('/admin/usuarios')['codigo'], 'Listado administrativo');
            MarcoPruebas::afirmarIgual(403, $cliente->enviar('/admin/usuarios/verificacion', ['token_csrf' => $token, 'id_usuario' => $idPedro, 'decision' => 'VERIFICADA'])['codigo'], 'Verificar profesor');
            MarcoPruebas::afirmarIgual(403, $cliente->enviar('/admin/usuarios/rol', ['token_csrf' => $token, 'id_usuario' => $idAna, 'rol_nuevo' => 'ADMINISTRADOR_PRINCIPAL', 'contrasena_confirmacion' => $contrasenaComun])['codigo'], 'Autoelevación');
            MarcoPruebas::afirmarIgual('USUARIO', $baseDatos->obtenerFilaUsuario('ana@http.mx')['rol_usuario'], 'Rol sin cambios');
            MarcoPruebas::afirmarIgual('PENDIENTE', $baseDatos->obtenerFilaUsuario('pedro@http.mx')['estado_verificacion_usuario'], 'Verificación sin cambios');
        });

        $marco->probar('P12b', 'Un ADMINISTRADOR común no puede cambiar roles por HTTP (403 + auditoría)', static function () use ($iniciarSesionPorHttp, $baseDatos, $contrasenaComun): void {
            [$cliente] = $iniciarSesionPorHttp('arturo@http.mx');
            $listado = $cliente->obtener('/admin/usuarios');
            MarcoPruebas::afirmarIgual(200, $listado['codigo'], 'El administrador sí ve el listado');
            MarcoPruebas::afirmar(!str_contains($listado['cuerpo'], 'name="rol_nuevo"'), 'No se le muestra el formulario de roles');
            $token = ClienteHttp::extraerTokenCsrf($listado['cuerpo']);
            $eventosAntes = $baseDatos->contarEventos('INTENTO_CAMBIO_PRIVILEGIO_DENEGADO');
            foreach (['ana@http.mx' => 'ADMINISTRADOR', 'arturo@http.mx' => 'ADMINISTRADOR_PRINCIPAL'] as $correoObjetivo => $rolSolicitado) {
                $respuesta = $cliente->enviar('/admin/usuarios/rol', [
                    'token_csrf'              => $token,
                    'id_usuario'              => (string) $baseDatos->obtenerFilaUsuario($correoObjetivo)['id_usuario'],
                    'rol_nuevo'               => $rolSolicitado,
                    'contrasena_confirmacion' => $contrasenaComun,
                ]);
                MarcoPruebas::afirmarIgual(403, $respuesta['codigo'], "Elevar {$correoObjetivo} a {$rolSolicitado}");
            }
            MarcoPruebas::afirmarIgual('USUARIO', $baseDatos->obtenerFilaUsuario('ana@http.mx')['rol_usuario'], 'Ana sin cambios');
            MarcoPruebas::afirmarIgual('ADMINISTRADOR', $baseDatos->obtenerFilaUsuario('arturo@http.mx')['rol_usuario'], 'Arturo sin cambios');
            MarcoPruebas::afirmarIgual($eventosAntes + 2, $baseDatos->contarEventos('INTENTO_CAMBIO_PRIVILEGIO_DENEGADO'), 'Intentos auditados');
        });

        $marco->probar('P17b', 'Revocar ADMINISTRADOR cierra la sesión abierta y retira el acceso', static function () use ($iniciarSesionPorHttp, $baseDatos, $contrasenaComun, $obtenerUbicacion): void {
            [$clientePrincipal] = $iniciarSesionPorHttp('paula@http.mx');
            $tokenPrincipal = ClienteHttp::extraerTokenCsrf($clientePrincipal->obtener('/admin/usuarios')['cuerpo']);
            $idPedro = (string) $baseDatos->obtenerFilaUsuario('pedro@http.mx')['id_usuario'];
            $cambiarRolPedro = static fn (string $rolNuevo): array => $clientePrincipal->enviar('/admin/usuarios/rol', [
                'token_csrf' => $tokenPrincipal, 'id_usuario' => $idPedro, 'rol_nuevo' => $rolNuevo, 'contrasena_confirmacion' => $contrasenaComun,
            ]);

            MarcoPruebas::afirmarIgual(303, $cambiarRolPedro('ADMINISTRADOR')['codigo'], 'Promoción por el principal');
            MarcoPruebas::afirmarIgual('ADMINISTRADOR', $baseDatos->obtenerFilaUsuario('pedro@http.mx')['rol_usuario'], 'Pedro es ADMINISTRADOR');

            [$clientePedro] = $iniciarSesionPorHttp('pedro@http.mx');
            MarcoPruebas::afirmarIgual(200, $clientePedro->obtener('/admin/usuarios')['codigo'], 'Pedro administra');

            MarcoPruebas::afirmarIgual(303, $cambiarRolPedro('USUARIO')['codigo'], 'Revocación por el principal');
            $respuestaTrasRevocar = $clientePedro->obtener('/admin/usuarios');
            MarcoPruebas::afirmarIgual(303, $respuestaTrasRevocar['codigo'], 'La sesión de Pedro se invalidó');
            MarcoPruebas::afirmarIgual('/iniciar-sesion', $obtenerUbicacion($respuestaTrasRevocar), 'Redirige al login');

            [$clientePedroNuevo] = $iniciarSesionPorHttp('pedro@http.mx');
            MarcoPruebas::afirmarIgual(403, $clientePedroNuevo->obtener('/admin/usuarios')['codigo'], 'Con sesión nueva ya no tiene acceso');
            MarcoPruebas::afirmar($baseDatos->contarEventos('SESION_EXPIRADA') >= 1, 'Invalidación auditada');
        });

        $marco->probar('P14c', 'XSS reflejado e inyección SQL por formulario', static function () use ($nuevoCliente): void {
            $cliente = $nuevoCliente();
            $cargaXss = '"><script>alert(1)</script>';
            $respuestaXss = $cliente->enviar('/iniciar-sesion', ['token_csrf' => $cliente->obtenerTokenDe('/iniciar-sesion'), 'correo_electronico' => $cargaXss, 'contrasena' => 'x']);
            MarcoPruebas::afirmar(!str_contains($respuestaXss['cuerpo'], '<script>alert(1)</script>'), 'La carga no se refleja sin escapar');
            MarcoPruebas::afirmar(str_contains($respuestaXss['cuerpo'], '&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;'), 'Se refleja escapada');
            MarcoPruebas::afirmar(preg_match("/^Content-Security-Policy: default-src 'self'/mi", $respuestaXss['encabezados']) === 1, 'CSP presente');

            $respuestaSql = $cliente->enviar('/iniciar-sesion', ['token_csrf' => $cliente->obtenerTokenDe('/iniciar-sesion'), 'correo_electronico' => "' OR '1'='1' -- ", 'contrasena' => "' OR '1'='1"]);
            MarcoPruebas::afirmarIgual(401, $respuestaSql['codigo'], 'Inyección SQL no autentica');
            MarcoPruebas::afirmar(!preg_match('/SQLSTATE|PDOException|syntax|Stack trace|\.php/i', $respuestaSql['cuerpo']), 'No se filtran errores internos');
        });

        $marco->probar('P16', 'Archivos privados no accesibles por HTTP directo', static function () use ($nuevoCliente): void {
            $cliente = $nuevoCliente();
            foreach ([
                '/config/database.php', '/config/permisos.php', '/.env', '/.env.example', '/.git/config',
                '/storage/logs/app.log', '/storage/logs/php-errores.log', '/database/schema.sql',
                '/database/usuario-aplicacion.sql', '/app/controllers/AuthController.php', '/app/views/login.php',
                '/tests/ejecutar-pruebas.php', '/README.md', '/config/contrasenas-comunes.txt',
                '/laboratorio/inyeccion-sql/demostracion.php', '/laboratorio/inyeccion-sql/LaboratorioInyeccionSql.php', '/../config/database.php', '/%2e%2e/config/database.php',
            ] as $rutaPrivada) {
                $respuesta = $cliente->obtener($rutaPrivada);
                MarcoPruebas::afirmar(in_array($respuesta['codigo'], [403, 404], true), "{$rutaPrivada} devolvió {$respuesta['codigo']}");
                MarcoPruebas::afirmar(!preg_match('/obtenerConexionBaseDatos|DB_CONTRASENA|CREATE TABLE|GRANT|<\?php|\[core\]/', $respuesta['cuerpo']), "{$rutaPrivada} filtró contenido");
            }
            MarcoPruebas::afirmarIgual(404, $cliente->obtener('/css/')['codigo'], 'Sin listado de directorios');
            MarcoPruebas::afirmarIgual(200, $cliente->obtener('/css/portal.css')['codigo'], 'Los activos públicos sí se sirven');
        });

        $marco->probar('P15b', 'Intentos repetidos por HTTP: 429 aun con la contraseña correcta', static function () use ($iniciarSesionPorHttp, $baseDatos): void {
            $baseDatos->limpiarIntentos();
            for ($intento = 1; $intento <= MAX_INTENTOS_LOGIN; $intento++) {
                [, $respuesta] = $iniciarSesionPorHttp('ana@http.mx', 'contraseña equivocada numero ' . $intento);
                MarcoPruebas::afirmarIgual(401, $respuesta['codigo'], "Fallo {$intento}");
            }
            [, $respuestaLimitada] = $iniciarSesionPorHttp('ana@http.mx');
            MarcoPruebas::afirmarIgual(429, $respuestaLimitada['codigo'], 'Limitado temporalmente');
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

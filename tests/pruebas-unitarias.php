<?php

/**
 * Pruebas unitarias: no requieren base de datos ni servidor web.
 */

declare(strict_types=1);

return static function (MarcoPruebas $marco): void {
    $datosRegistroValidos = [
        'token_csrf'               => 'x',
        'nombres_usuario'          => '  María   José ',
        'apellido_paterno_usuario' => 'Pérez',
        'apellido_materno_usuario' => 'de la Garza',
        'correo_electronico'       => '  Maria.Perez@Ejemplo.MX ',
        'contrasena'              => 'una frase de paso bastante larga',
        'confirmacion_contrasena' => 'una frase de paso bastante larga',
        'tipo_integrante'         => 'ALUMNO',
    ];

    $marco->probar('U01', 'Validación acepta un registro correcto y normaliza el correo', static function () use ($datosRegistroValidos): void {
        $validacion = ValidadorEntrada::validarDatosRegistro($datosRegistroValidos);
        MarcoPruebas::afirmar($validacion['valido'], 'Debería ser válido: ' . json_encode($validacion['errores']));
        MarcoPruebas::afirmarIgual('maria.perez@ejemplo.mx', $validacion['datos']['correo_electronico'], 'Correo normalizado');
        MarcoPruebas::afirmarIgual('María José', $validacion['datos']['nombres_usuario'], 'Espacios de nombres normalizados');
        MarcoPruebas::afirmarIgual('de la Garza', $validacion['datos']['apellido_materno_usuario'], 'Apellido materno compuesto');
    });

    $marco->probar('U06', 'Nombre atómico: materno opcional y nombre completo de máximo 100', static function () use ($datosRegistroValidos): void {
        foreach (['', '   '] as $maternoVacio) {
            $validacion = ValidadorEntrada::validarDatosRegistro(array_merge($datosRegistroValidos, ['apellido_materno_usuario' => $maternoVacio]));
            MarcoPruebas::afirmar($validacion['valido'], 'Materno vacío permitido: ' . json_encode($validacion['errores']));
            MarcoPruebas::afirmarIgual(null, $validacion['datos']['apellido_materno_usuario'], 'Materno vacío se guarda como NULL');
        }
        $sinMaterno = $datosRegistroValidos;
        unset($sinMaterno['apellido_materno_usuario']);
        MarcoPruebas::afirmar(ValidadorEntrada::validarDatosRegistro($sinMaterno)['valido'], 'Materno omitido permitido');

        $nombreLargo = ['nombres_usuario' => str_repeat('a', 60), 'apellido_paterno_usuario' => str_repeat('b', 20), 'apellido_materno_usuario' => str_repeat('c', 20)];
        $validacion = ValidadorEntrada::validarDatosRegistro(array_merge($datosRegistroValidos, $nombreLargo));
        MarcoPruebas::afirmar(!$validacion['valido'] && isset($validacion['errores']['nombres_usuario']), '60+1+20+1+20 = 102 caracteres se rechaza');
        $nombreLimite = ['nombres_usuario' => str_repeat('a', 58), 'apellido_paterno_usuario' => str_repeat('b', 20), 'apellido_materno_usuario' => str_repeat('c', 20)];
        MarcoPruebas::afirmar(ValidadorEntrada::validarDatosRegistro(array_merge($datosRegistroValidos, $nombreLimite))['valido'], '58+1+20+1+20 = 100 caracteres se acepta');
        MarcoPruebas::afirmarIgual(['nombre_usuario'], ValidadorEntrada::obtenerCamposProtegidos(['nombre_usuario' => 'X']), 'nombre_usuario (calculado) es campo protegido');
    });

    $marco->probar('P04', 'Rechazo de campos inválidos en el registro', static function () use ($datosRegistroValidos): void {
        $casosInvalidos = [
            'nombres vacíos'           => ['nombres_usuario' => '', 'campo' => 'nombres_usuario'],
            'nombres con etiquetas'    => ['nombres_usuario' => '<script>alert(1)</script>', 'campo' => 'nombres_usuario'],
            'nombres de 61 caracteres' => ['nombres_usuario' => str_repeat('a', 61), 'campo' => 'nombres_usuario'],
            'paterno vacío'            => ['apellido_paterno_usuario' => '   ', 'campo' => 'apellido_paterno_usuario'],
            'paterno de 1 carácter'    => ['apellido_paterno_usuario' => 'P', 'campo' => 'apellido_paterno_usuario'],
            'paterno de 51 caracteres' => ['apellido_paterno_usuario' => str_repeat('p', 51), 'campo' => 'apellido_paterno_usuario'],
            'paterno con números'      => ['apellido_paterno_usuario' => 'Pérez2', 'campo' => 'apellido_paterno_usuario'],
            'materno con etiquetas'    => ['apellido_materno_usuario' => '<b>x</b>', 'campo' => 'apellido_materno_usuario'],
            'materno como arreglo'     => ['apellido_materno_usuario' => ['a'], 'campo' => 'apellido_materno_usuario'],
            'correo sin arroba'        => ['correo_electronico' => 'correo-invalido', 'campo' => 'correo_electronico'],
            'correo de 151 caracteres' => ['correo_electronico' => str_repeat('a', 140) . '@ejemplo.mx', 'campo' => 'correo_electronico'],
            'contraseña de 14'         => ['contrasena' => str_repeat('a', 14), 'confirmacion_contrasena' => str_repeat('a', 14), 'campo' => 'contrasena'],
            'contraseña de 129'        => ['contrasena' => str_repeat('a', 129), 'confirmacion_contrasena' => str_repeat('a', 129), 'campo' => 'contrasena'],
            'confirmación distinta'    => ['confirmacion_contrasena' => 'otra frase de paso diferente', 'campo' => 'confirmacion_contrasena'],
            'tipo no permitido'        => ['tipo_integrante' => 'ADMINISTRADOR', 'campo' => 'tipo_integrante'],
            'tipo en minúsculas'       => ['tipo_integrante' => 'profesor', 'campo' => 'tipo_integrante'],
            'nombres como arreglo'     => ['nombres_usuario' => ['a', 'b'], 'campo' => 'nombres_usuario'],
            'UTF-8 inválido'           => ['nombres_usuario' => "Ana\xC3\x28", 'campo' => 'nombres_usuario'],
        ];
        foreach ($casosInvalidos as $nombreCaso => $caso) {
            $campoEsperado = $caso['campo'];
            unset($caso['campo']);
            $validacion = ValidadorEntrada::validarDatosRegistro(array_merge($datosRegistroValidos, $caso));
            MarcoPruebas::afirmar(!$validacion['valido'], "Caso '{$nombreCaso}' debió rechazarse");
            MarcoPruebas::afirmar(isset($validacion['errores'][$campoEsperado]), "Caso '{$nombreCaso}' debió marcar {$campoEsperado}");
        }
        $sinTipo = $datosRegistroValidos;
        unset($sinTipo['tipo_integrante']);
        MarcoPruebas::afirmar(!ValidadorEntrada::validarDatosRegistro($sinTipo)['valido'], 'Falta tipo_integrante');
    });

    $marco->probar('U02', 'Detección de parámetros inesperados y campos protegidos', static function () use ($datosRegistroValidos): void {
        $datosManipulados = $datosRegistroValidos + ['rol_usuario' => 'ADMINISTRADOR_PRINCIPAL', 'campo_extra' => '1'];
        $inesperados = ValidadorEntrada::obtenerParametrosInesperados($datosManipulados, ValidadorEntrada::CAMPOS_REGISTRO);
        MarcoPruebas::afirmarIgual(['rol_usuario', 'campo_extra'], $inesperados, 'Parámetros inesperados');
        MarcoPruebas::afirmarIgual(['rol_usuario'], ValidadorEntrada::obtenerCamposProtegidos($datosManipulados), 'Campos protegidos');
    });

    $marco->probar('P05a', 'Hash con Argon2id, salado y verificable (sin texto claro)', static function (): void {
        $contrasena = 'una frase de paso bastante larga';
        $hashUno = GestorContrasenas::generarHash($contrasena);
        $hashDos = GestorContrasenas::generarHash($contrasena);
        MarcoPruebas::afirmar(GestorContrasenas::argon2idDisponible(), 'Argon2id debería estar disponible en este PHP');
        MarcoPruebas::afirmar(str_starts_with($hashUno, '$argon2id$v=19$m=65536,t=4,p=1$'), 'Algoritmo/parámetros: ' . substr($hashUno, 0, 32));
        MarcoPruebas::afirmar($hashUno !== $hashDos, 'La sal debe producir hashes distintos');
        MarcoPruebas::afirmar(!str_contains($hashUno, $contrasena), 'El hash no contiene la contraseña');
        MarcoPruebas::afirmar(GestorContrasenas::verificar($contrasena, $hashUno), 'Debe verificar');
        MarcoPruebas::afirmar(!GestorContrasenas::verificar($contrasena . 'x', $hashUno), 'No debe verificar otra contraseña');
        MarcoPruebas::afirmar(!GestorContrasenas::necesitaRehash($hashUno), 'Hash vigente no requiere rehash');
        $hashBcrypt = password_hash($contrasena, PASSWORD_BCRYPT, ['cost' => 10]);
        MarcoPruebas::afirmar(GestorContrasenas::necesitaRehash($hashBcrypt), 'bcrypt antiguo sí requiere rehash');
    });

    $marco->probar('P13a', 'Token CSRF: aleatorio, por sesión y comparado de forma estricta', static function (): void {
        GestorSesion::iniciarSesionPhp();
        $token = ProtectorCsrf::generarTokenCsrf();
        MarcoPruebas::afirmar(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 'Token de 256 bits en hex');
        MarcoPruebas::afirmarIgual($token, ProtectorCsrf::generarTokenCsrf(), 'Estable dentro de la sesión');
        MarcoPruebas::afirmar(ProtectorCsrf::validarTokenCsrf($token), 'Token correcto se acepta');
        foreach ([null, '', 'abc', strtoupper($token), [$token], 0, true] as $tokenFalso) {
            MarcoPruebas::afirmar(!ProtectorCsrf::validarTokenCsrf($tokenFalso), 'Debe rechazar ' . var_export($tokenFalso, true));
        }
        $tokenNuevo = ProtectorCsrf::renovarTokenCsrf();
        MarcoPruebas::afirmar($tokenNuevo !== $token && !ProtectorCsrf::validarTokenCsrf($token), 'El token anterior deja de valer al renovarse');
        $_SESSION = [];
    });

    $marco->probar('P14a', 'Escape de salida HTML contra XSS', static function (): void {
        MarcoPruebas::afirmarIgual(
            '&quot;&gt;&lt;script&gt;alert(&apos;x&apos;)&lt;/script&gt;',
            Vista::escapar('"><script>alert(\'x\')</script>'),
            'Escape de etiquetas y comillas'
        );
    });

    $marco->probar('U03', 'Prevención de redirección abierta', static function (): void {
        foreach (['/', '/inicio', '/admin/usuarios'] as $rutaSegura) {
            MarcoPruebas::afirmar(Respuesta::esRutaInternaSegura($rutaSegura), "Debe aceptar {$rutaSegura}");
        }
        foreach (['//evil.example', '/\\evil.example', 'https://evil.example', 'javascript:alert(1)', "/inicio\r\nSet-Cookie: x=1", 'inicio', ''] as $rutaPeligrosa) {
            MarcoPruebas::afirmar(!Respuesta::esRutaInternaSegura($rutaPeligrosa), 'Debe rechazar ' . json_encode($rutaPeligrosa));
        }
        MarcoPruebas::afirmarIgual('/', Respuesta::redireccion('//evil.example')->rutaRedireccion, 'Redirección externa se sustituye por /');
    });

    $marco->probar('U05', 'Publicación en subcarpeta /papc: rutas, enlaces y cookie limitada', static function (): void {
        $valorOriginal = getenv('APP_RUTA_BASE');
        $uriOriginal = $_SERVER['REQUEST_URI'] ?? null;
        try {
            foreach (['/papc', 'papc/', '/papc/'] as $variante) {
                putenv('APP_RUTA_BASE=' . $variante);
                MarcoPruebas::afirmarIgual('/papc', Solicitud::obtenerRutaBase(), "Normaliza '{$variante}'");
            }
            $casos = ['/papc' => '/', '/papc/' => '/', '/papc/iniciar-sesion?x=1' => '/iniciar-sesion', '/papc/admin/usuarios/' => '/admin/usuarios', '/papcotro/inicio' => '/papcotro/inicio'];
            foreach ($casos as $uri => $rutaEsperada) {
                $_SERVER['REQUEST_URI'] = $uri;
                MarcoPruebas::afirmarIgual($rutaEsperada, Solicitud::obtenerRuta(), "Ruta de {$uri}");
            }
            MarcoPruebas::afirmarIgual('/papc/inicio', Vista::url('/inicio'), 'Enlaces con prefijo');
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            GestorSesion::iniciarSesionPhp();
            MarcoPruebas::afirmarIgual('/papc/', session_get_cookie_params()['path'], 'La cookie no se envía a otros proyectos del servidor');
            MarcoPruebas::afirmar(str_ends_with(str_replace('\\', '/', session_save_path()), '/storage/sesiones'), 'Sesiones en storage/sesiones');
            session_write_close();
        } finally {
            putenv($valorOriginal === false ? 'APP_RUTA_BASE' : 'APP_RUTA_BASE=' . $valorOriginal);
            $_SERVER['REQUEST_URI'] = $uriOriginal;
        }
    });

    $marco->probar('U04','Matriz RBAC: denegación por defecto y separación de roles', static function (): void {
        $auditoriaFalsa = (new ReflectionClass(AuditoriaModel::class))->newInstanceWithoutConstructor();
        $roleMiddleware = new RoleMiddleware(require RUTA_RAIZ . '/config/permisos.php', $auditoriaFalsa);
        $casos = [
            [null, 'registrarse', true], [null, 'administrar_perfil_propio', false],
            ['USUARIO', 'enviar_propuesta', true], ['USUARIO', 'administrar_cuentas', false],
            ['USUARIO', 'asignar_rol_administrativo', false], ['USUARIO', 'registrarse', false],
            ['ADMINISTRADOR', 'administrar_cuentas', true], ['ADMINISTRADOR', 'asignar_rol_administrativo', false],
            ['ADMINISTRADOR_PRINCIPAL', 'asignar_rol_administrativo', true],
            ['ADMINISTRADOR_PRINCIPAL', 'permiso_inexistente', false],
            ['PROFESOR', 'enviar_propuesta', false], ['INVITADO', 'administrar_cuentas', false],
            ['SUPERUSUARIO', 'leer_publicaciones', false],
        ];
        foreach ($casos as [$rol, $permiso, $esperado]) {
            MarcoPruebas::afirmarIgual($esperado, $roleMiddleware->tienePermiso($rol, $permiso), "tienePermiso({$rol}, {$permiso})");
        }
    });
    $marco->probar('U07', 'Lista de bloqueo: rechaza contraseñas comunes y predecibles, acepta frases', static function () use ($datosRegistroValidos): void {
        $contrasenasDebiles = [
            '123456789012345', '987654321098765', 'abcdefghijklmnopq', 'aaaaaaaaaaaaaaa',
            '121212121212121', 'passwordpassword', 'Contraseña Segura 123', 'contrasenasegura123',
            'Universidad del Mar 2026', 'UMAR Puerto Escondido', 'holaholaholahola', 'qwertyuiopasdfghjkl',
            '!!!!!!!!!!!!!!!!', str_repeat('xk7 ', 32),
        ];
        foreach ($contrasenasDebiles as $contrasenaDebil) {
            MarcoPruebas::afirmar(ListaBloqueoContrasenas::esContrasenaDebil($contrasenaDebil), "Debe rechazarse: {$contrasenaDebil}");
            $validacion = ValidadorEntrada::validarDatosRegistro(array_merge($datosRegistroValidos, [
                'contrasena' => $contrasenaDebil, 'confirmacion_contrasena' => $contrasenaDebil,
            ]));
            MarcoPruebas::afirmar(!$validacion['valido'] && isset($validacion['errores']['contrasena']), "Registro rechazado: {$contrasenaDebil}");
        }
        $contrasenasValidas = [
            'una frase de paso bastante larga', 'mi password favorito es el mar azul',
            'El café de Puerto Escondido sabe mejor',
        ];
        foreach ($contrasenasValidas as $contrasenaValida) {
            MarcoPruebas::afirmar(!ListaBloqueoContrasenas::esContrasenaDebil($contrasenaValida), "Debe aceptarse: {$contrasenaValida}");
        }
        MarcoPruebas::afirmarIgual('contrasenasegura123', ListaBloqueoContrasenas::normalizar('Contraseña Segura-123'), 'Normalización solo para comparar');
    });

    $marco->probar('U08', 'Login: indica qué campo falta y conserva el correo escrito', static function (): void {
        $soloContrasena = ValidadorEntrada::validarDatosLogin(['correo_electronico' => '  ', 'contrasena' => 'algo']);
        MarcoPruebas::afirmar(!$soloContrasena['valido'], 'Correo vacío es inválido');
        MarcoPruebas::afirmarIgual(['correo_electronico'], array_keys($soloContrasena['errores']), 'Solo se pide el correo');

        $soloCorreo = ValidadorEntrada::validarDatosLogin(['correo_electronico' => 'ana@ejemplo.mx', 'contrasena' => '']);
        MarcoPruebas::afirmarIgual(['contrasena'], array_keys($soloCorreo['errores']), 'Solo se pide la contraseña');
        MarcoPruebas::afirmarIgual('ana@ejemplo.mx', $soloCorreo['correoPrevio'], 'El correo escrito se conserva');

        $vacio = ValidadorEntrada::validarDatosLogin([]);
        MarcoPruebas::afirmarIgual(['correo_electronico', 'contrasena'], array_keys($vacio['errores']), 'Ambos campos vacíos');

        $completo = ValidadorEntrada::validarDatosLogin(['correo_electronico' => ' Ana@Ejemplo.MX ', 'contrasena' => ' clave ']);
        MarcoPruebas::afirmar($completo['valido'], 'Datos completos');
        MarcoPruebas::afirmarIgual(' clave ', $completo['datos']['contrasena'], 'La contraseña no se recorta');
    });

    $marco->probar('U09', 'Inactividad configurable en .env solo entre 60 s y 30 min', static function (): void {
        $casos = ['' => 1800, '120' => 120, '60' => 60, '59' => 1800, '3600' => 1800, '0' => 1800, 'abc' => 1800, '1800' => 1800];
        foreach ($casos as $valor => $esperado) {
            Configuracion::definirValores(['SESION_INACTIVIDAD_SEGUNDOS' => (string) $valor]);
            MarcoPruebas::afirmarIgual($esperado, GestorSesion::obtenerTiempoInactividadSegundos(), "SESION_INACTIVIDAD_SEGUNDOS={$valor}");
        }
        Configuracion::definirValores(['SESION_INACTIVIDAD_SEGUNDOS' => '']);
    });
    $marco->probar('U10', 'Laboratorio E8: la consulta concatenada se altera y la preparada no', static function (): void {
        require_once RUTA_RAIZ . '/laboratorio/inyeccion-sql/LaboratorioInyeccionSql.php';
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            MarcoPruebas::afirmar(true, 'Sin pdo_sqlite: se demuestra con --mariadb');
            return;
        }
        $laboratorio = new LaboratorioInyeccionSql(new PDO('sqlite::memory:'));
        $laboratorio->prepararDatosFicticios();
        $resultados = [];
        foreach ($laboratorio->ejecutarComparacion() as $resultado) {
            $resultados[$resultado['caso']['clave']] = $resultado;
        }
        MarcoPruebas::afirmar($resultados['E8-P01']['vulnerable']['acceso'] && $resultados['E8-P01']['segura']['acceso'], 'Credenciales normales funcionan en ambas');
        MarcoPruebas::afirmar($resultados['E8-P02']['vulnerable']['error'] !== null, 'La comilla rompe la consulta vulnerable');
        MarcoPruebas::afirmar($resultados['E8-P02']['segura']['acceso'], 'La preparada trata la comilla como dato');
        MarcoPruebas::afirmarIgual(3, $resultados['E8-P03']['vulnerable']['filas'], "' OR '1'='1 devuelve todas las filas en la vulnerable");
        MarcoPruebas::afirmarIgual(0, $resultados['E8-P03']['segura']['filas'], 'La preparada no devuelve filas');
        MarcoPruebas::afirmar($resultados['E8-P03b']['vulnerable']['acceso'], 'UNION concede acceso en la vulnerable');
        MarcoPruebas::afirmar(!$resultados['E8-P03b']['segura']['acceso'], 'UNION no concede acceso en la preparada');
    });
};

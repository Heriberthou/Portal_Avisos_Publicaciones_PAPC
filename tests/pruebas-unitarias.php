<?php

/**
 * Pruebas unitarias: no requieren base de datos ni servidor web.
 */

declare(strict_types=1);

return static function (MarcoPruebas $marco): void {
    $datosRegistroValidos = [
        'token_csrf'              => 'x',
        'nombre_usuario'          => 'María José Pérez',
        'correo_electronico'      => '  Maria.Perez@Ejemplo.MX ',
        'contrasena'              => 'una frase de paso bastante larga',
        'confirmacion_contrasena' => 'una frase de paso bastante larga',
        'tipo_integrante'         => 'ALUMNO',
    ];

    $marco->probar('U01', 'Validación acepta un registro correcto y normaliza el correo', static function () use ($datosRegistroValidos): void {
        $validacion = ValidadorEntrada::validarDatosRegistro($datosRegistroValidos);
        MarcoPruebas::afirmar($validacion['valido'], 'Debería ser válido: ' . json_encode($validacion['errores']));
        MarcoPruebas::afirmarIgual('maria.perez@ejemplo.mx', $validacion['datos']['correo_electronico'], 'Correo normalizado');
    });

    $marco->probar('P04', 'Rechazo de campos inválidos en el registro', static function () use ($datosRegistroValidos): void {
        $casosInvalidos = [
            'nombre vacío'             => ['nombre_usuario' => '', 'campo' => 'nombre_usuario'],
            'nombre con etiquetas'     => ['nombre_usuario' => '<script>alert(1)</script>', 'campo' => 'nombre_usuario'],
            'nombre de 101 caracteres' => ['nombre_usuario' => str_repeat('a', 101), 'campo' => 'nombre_usuario'],
            'correo sin arroba'        => ['correo_electronico' => 'correo-invalido', 'campo' => 'correo_electronico'],
            'correo de 151 caracteres' => ['correo_electronico' => str_repeat('a', 140) . '@ejemplo.mx', 'campo' => 'correo_electronico'],
            'contraseña de 14'         => ['contrasena' => str_repeat('a', 14), 'confirmacion_contrasena' => str_repeat('a', 14), 'campo' => 'contrasena'],
            'contraseña de 129'        => ['contrasena' => str_repeat('a', 129), 'confirmacion_contrasena' => str_repeat('a', 129), 'campo' => 'contrasena'],
            'confirmación distinta'    => ['confirmacion_contrasena' => 'otra frase de paso diferente', 'campo' => 'confirmacion_contrasena'],
            'tipo no permitido'        => ['tipo_integrante' => 'ADMINISTRADOR', 'campo' => 'tipo_integrante'],
            'tipo en minúsculas'       => ['tipo_integrante' => 'profesor', 'campo' => 'tipo_integrante'],
            'nombre como arreglo'      => ['nombre_usuario' => ['a', 'b'], 'campo' => 'nombre_usuario'],
            'UTF-8 inválido'           => ['nombre_usuario' => "Ana\xC3\x28", 'campo' => 'nombre_usuario'],
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
};

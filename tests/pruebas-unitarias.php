<?php

/**
 * Pruebas unitarias: no requieren base de datos ni servidor web.
 * Los identificadores AC-xx remiten a los casos de aceptación de la
 * especificación 1.3 (sección 11.1).
 */

declare(strict_types=1);

return static function (MarcoPruebas $marco): void {
    $datosSolicitudValidos = [
        'token_csrf'                  => 'x',
        'nombres'                     => '  María   José ',
        'apellido_paterno'            => 'Pérez',
        'apellido_materno'            => 'de la Garza',
        'tipo_integrante'             => 'ALUMNO',
        'identificador_institucional' => ' 2026-0123 ',
        'correo_electronico'          => '  Maria.Perez@Ejemplo.MX ',
    ];

    $marco->probar('U01', 'Solicitud de acceso: datos válidos se normalizan y no llevan contraseña ni rol', static function () use ($datosSolicitudValidos): void {
        $validacion = ValidadorEntrada::validarDatosSolicitud($datosSolicitudValidos);
        MarcoPruebas::afirmar($validacion['valido'], 'Debería ser válida: ' . json_encode($validacion['errores']));
        MarcoPruebas::afirmarIgual('maria.perez@ejemplo.mx', $validacion['datos']['correo'], 'Correo normalizado');
        MarcoPruebas::afirmarIgual('María José', $validacion['datos']['nombres'], 'Espacios de nombres normalizados');
        MarcoPruebas::afirmarIgual('de la Garza', $validacion['datos']['apellidoMaterno'], 'Apellido materno compuesto');
        MarcoPruebas::afirmarIgual('2026-0123', $validacion['datos']['identificador'], 'Identificador sin espacios y en mayúsculas');
        MarcoPruebas::afirmar(!in_array('contrasena', ValidadorEntrada::CAMPOS_SOLICITUD, true), 'El formulario no tiene contraseña');
        MarcoPruebas::afirmar(!in_array('rol_usuario', ValidadorEntrada::CAMPOS_SOLICITUD, true), 'El formulario no tiene rol');
    });

    $marco->probar('U06', 'Nombre atómico: materno opcional y nombre completo de máximo 100', static function () use ($datosSolicitudValidos): void {
        foreach (['', '   '] as $maternoVacio) {
            $validacion = ValidadorEntrada::validarDatosSolicitud(array_merge($datosSolicitudValidos, ['apellido_materno' => $maternoVacio]));
            MarcoPruebas::afirmar($validacion['valido'], 'Materno vacío permitido: ' . json_encode($validacion['errores']));
            MarcoPruebas::afirmarIgual(null, $validacion['datos']['apellidoMaterno'], 'Materno vacío se guarda como NULL');
        }
        $nombreLargo = ['nombres' => str_repeat('a', 60), 'apellido_paterno' => str_repeat('b', 20), 'apellido_materno' => str_repeat('c', 20)];
        $validacion = ValidadorEntrada::validarDatosSolicitud(array_merge($datosSolicitudValidos, $nombreLargo));
        MarcoPruebas::afirmar(!$validacion['valido'] && isset($validacion['errores']['nombres']), '60+1+20+1+20 = 102 caracteres se rechaza');
        $nombreLimite = ['nombres' => str_repeat('a', 58), 'apellido_paterno' => str_repeat('b', 20), 'apellido_materno' => str_repeat('c', 20)];
        MarcoPruebas::afirmar(ValidadorEntrada::validarDatosSolicitud(array_merge($datosSolicitudValidos, $nombreLimite))['valido'], '58+1+20+1+20 = 100 caracteres se acepta');
    });

    $marco->probar('P04', 'Solicitud: rechazo de campos inválidos (incluido tipo ADMINISTRADOR)', static function () use ($datosSolicitudValidos): void {
        $casosInvalidos = [
            'nombres vacíos'              => ['nombres' => '', 'campo' => 'nombres'],
            'nombres con etiquetas'       => ['nombres' => '<script>alert(1)</script>', 'campo' => 'nombres'],
            'nombres de 61 caracteres'    => ['nombres' => str_repeat('a', 61), 'campo' => 'nombres'],
            'paterno de 1 carácter'       => ['apellido_paterno' => 'P', 'campo' => 'apellido_paterno'],
            'paterno con números'         => ['apellido_paterno' => 'Pérez2', 'campo' => 'apellido_paterno'],
            'materno como arreglo'        => ['apellido_materno' => ['a'], 'campo' => 'apellido_materno'],
            'correo sin arroba'           => ['correo_electronico' => 'correo-invalido', 'campo' => 'correo_electronico'],
            'correo de 151 caracteres'    => ['correo_electronico' => str_repeat('a', 140) . '@ejemplo.mx', 'campo' => 'correo_electronico'],
            'tipo ADMINISTRADOR'          => ['tipo_integrante' => 'ADMINISTRADOR', 'campo' => 'tipo_integrante'],
            'tipo en minúsculas'          => ['tipo_integrante' => 'profesor', 'campo' => 'tipo_integrante'],
            'identificador vacío'         => ['identificador_institucional' => '  ', 'campo' => 'identificador_institucional'],
            'identificador de 3'          => ['identificador_institucional' => 'A12', 'campo' => 'identificador_institucional'],
            'identificador de 21'         => ['identificador_institucional' => str_repeat('9', 21), 'campo' => 'identificador_institucional'],
            'identificador con comillas'  => ['identificador_institucional' => "12'34", 'campo' => 'identificador_institucional'],
            'UTF-8 inválido'              => ['nombres' => "Ana\xC3\x28", 'campo' => 'nombres'],
        ];
        foreach ($casosInvalidos as $nombreCaso => $caso) {
            $campoEsperado = $caso['campo'];
            unset($caso['campo']);
            $validacion = ValidadorEntrada::validarDatosSolicitud(array_merge($datosSolicitudValidos, $caso));
            MarcoPruebas::afirmar(!$validacion['valido'], "Caso '{$nombreCaso}' debió rechazarse");
            MarcoPruebas::afirmar(isset($validacion['errores'][$campoEsperado]), "Caso '{$nombreCaso}' debió marcar {$campoEsperado}");
        }
    });

    $marco->probar('U02', 'AC-02: campos internos enviados desde el navegador se detectan como manipulación', static function () use ($datosSolicitudValidos): void {
        $datosManipulados = $datosSolicitudValidos + ['rol_usuario' => 'ADMINISTRADOR_PRINCIPAL', 'estado_cuenta_usuario' => 'ACTIVA', 'id_usuario_revisor' => '1', 'campo_extra' => '1'];
        $inesperados = ValidadorEntrada::obtenerParametrosInesperados($datosManipulados, ValidadorEntrada::CAMPOS_SOLICITUD);
        MarcoPruebas::afirmarIgual(['rol_usuario', 'estado_cuenta_usuario', 'id_usuario_revisor', 'campo_extra'], $inesperados, 'Parámetros inesperados');
        MarcoPruebas::afirmarIgual(['rol_usuario', 'estado_cuenta_usuario', 'id_usuario_revisor'], ValidadorEntrada::obtenerCamposProtegidos($datosManipulados, ValidadorEntrada::CAMPOS_SOLICITUD), 'Campos protegidos');
        MarcoPruebas::afirmarIgual([], ValidadorEntrada::obtenerCamposProtegidos(['contrasena' => 'x'], ValidadorEntrada::CAMPOS_LOGIN), 'La contraseña es legítima en el login');
        MarcoPruebas::afirmarIgual(['contrasena'], ValidadorEntrada::obtenerCamposProtegidos(['contrasena' => 'x'], ValidadorEntrada::CAMPOS_SOLICITUD), 'Pero no en la solicitud');
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

    $marco->probar('U03', 'Prevención de redirección abierta (con consulta interna permitida)', static function (): void {
        foreach (['/', '/inicio', '/admin/usuarios', '/admin/solicitudes/detalle?id=5'] as $rutaSegura) {
            MarcoPruebas::afirmar(Respuesta::esRutaInternaSegura($rutaSegura), "Debe aceptar {$rutaSegura}");
        }
        foreach (['//evil.example', '/\\evil.example', 'https://evil.example', 'javascript:alert(1)', "/inicio\r\nSet-Cookie: x=1",
                  'inicio', '', '/a?x=<script>', '/a?url=https://evil.example', '/a#frag'] as $rutaPeligrosa) {
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

    $marco->probar('U04', 'Matriz RBAC 1.3: denegación por defecto y separación de roles', static function (): void {
        $auditoriaFalsa = (new ReflectionClass(AuditoriaModel::class))->newInstanceWithoutConstructor();
        $roleMiddleware = new RoleMiddleware(require RUTA_RAIZ . '/config/permisos.php', $auditoriaFalsa);
        $casos = [
            [null, 'solicitar_acceso', true], [null, 'administrar_perfil_propio', false], [null, 'revisar_solicitudes', false],
            ['USUARIO', 'solicitar_acceso', false], ['USUARIO', 'revisar_solicitudes', false], ['USUARIO', 'resolver_solicitud', false],
            ['USUARIO', 'gestionar_estado_cuenta', false], ['USUARIO', 'consultar_bitacora', false], ['USUARIO', 'asignar_rol_administrativo', false],
            ['ADMINISTRADOR', 'revisar_solicitudes', true], ['ADMINISTRADOR', 'resolver_solicitud', true],
            ['ADMINISTRADOR', 'gestionar_estado_cuenta', true], ['ADMINISTRADOR', 'consultar_bitacora', true],
            ['ADMINISTRADOR', 'asignar_rol_administrativo', false],
            ['ADMINISTRADOR_PRINCIPAL', 'asignar_rol_administrativo', true], ['ADMINISTRADOR_PRINCIPAL', 'permiso_inexistente', false],
            ['PROFESOR', 'enviar_propuesta', false], ['INVITADO', 'administrar_cuentas', false], ['SUPERUSUARIO', 'leer_publicaciones', false],
            [null, 'registrarse', false],
        ];
        foreach ($casos as [$rol, $permiso, $esperado]) {
            MarcoPruebas::afirmarIgual($esperado, $roleMiddleware->tienePermiso($rol, $permiso), "tienePermiso({$rol}, {$permiso})");
        }
    });

    $marco->probar('U07', 'AC-10/AC-11: lista de bloqueo rechaza contraseñas comunes de 15+ y acepta frases', static function (): void {
        $contrasenasDebiles = [
            '123456789012345', '987654321098765', 'abcdefghijklmnopq', 'aaaaaaaaaaaaaaa',
            '121212121212121', 'passwordpassword', 'Contraseña Segura 123', 'contrasenasegura123',
            'Universidad del Mar 2026', 'UMAR Puerto Escondido', 'holaholaholahola', 'qwertyuiopasdfghjkl',
            str_repeat('xk7 ', 32), 'qwertyuiopasdfg', 'inteligencia artificial',
        ];
        foreach ($contrasenasDebiles as $contrasenaDebil) {
            MarcoPruebas::afirmar(ListaBloqueoContrasenas::esContrasenaDebil($contrasenaDebil), "Debe rechazarse: {$contrasenaDebil}");
            // Se rechaza con el mensaje de lista o, si casi todo es repetición, con el de poco contenido (U07b).
            MarcoPruebas::afirmar(PoliticaContrasenas::validarNuevaContrasena($contrasenaDebil) !== null, "Política: {$contrasenaDebil}");
        }
        $contrasenasValidas = [
            'una frase de paso bastante larga', 'mi password favorito es el mar azul',
            'El café de Puerto Escondido sabe mejor', '482915736048271',
        ];
        foreach ($contrasenasValidas as $contrasenaValida) {
            MarcoPruebas::afirmarIgual(null, PoliticaContrasenas::validarNuevaContrasena($contrasenaValida), "Debe aceptarse sin exigir símbolos: {$contrasenaValida}");
        }
        MarcoPruebas::afirmarIgual('contrasenasegura123', ListaBloqueoContrasenas::normalizar('Contraseña Segura-123'), 'Normalización solo para comparar');
        MarcoPruebas::afirmar(PoliticaContrasenas::validarNuevaContrasena(str_repeat('a', 14)) !== null, '14 caracteres se rechaza');
        MarcoPruebas::afirmar(PoliticaContrasenas::validarNuevaContrasena(str_repeat('ab3 ', 33)) !== null, '132 caracteres se rechaza');
    });

    $marco->probar('U07b', 'Política: relleno, variantes y patrones predecibles que antes se aceptaban (reproducción del 20/11/2026) se rechazan; las frases siguen aceptándose', static function (): void {
        // Contenido insuficiente: el relleno completaba la longitud mínima.
        $mensajePocoContenido = sprintf(PoliticaContrasenas::MENSAJE_POCO_CONTENIDO, ListaBloqueoContrasenas::MINIMO_CONTENIDO);
        foreach (['hola!!!!!!!!!!!', 'hola           ', '!!!!!!!!!!!!!!!!', 'Password!!!!!!!', 'aaaa....bbbb....cc', 'aaaabbbbccccdddd', '111122223333444'] as $contrasena) {
            MarcoPruebas::afirmarIgual($mensajePocoContenido, PoliticaContrasenas::validarNuevaContrasena($contrasena), 'Poco contenido: ' . json_encode($contrasena, JSON_UNESCAPED_UNICODE));
        }
        // Caracteres invisibles: no cuentan para la longitud.
        $conInvisibles = 'hola' . str_repeat("\u{200B}", 11);
        MarcoPruebas::afirmarIgual(4, ListaBloqueoContrasenas::contarCaracteres($conInvisibles), 'Los espacios de ancho cero no cuentan');
        MarcoPruebas::afirmar(PoliticaContrasenas::validarNuevaContrasena($conInvisibles) !== null, 'Palabra + invisibles se rechaza');

        // Variantes y patrones (casos E4..E19 de tests/reproducir-politica-contrasenas.php).
        $predecibles = [
            'Password12345678', 'P@ssw0rd2024!!!!', '2024P@ssw0rd!!!!!', 'Contraseña12345!', 'qwerty2026!!!!!!',
            '123456789123456', '1234567891011121314', '246810121416182', '100200300400500', '2024202520262027',
            '1qaz2wsx3edc4rf', 'abcdefghijklmn1', 'umar2026umar202',
            '１２３４５６７８９０１２３４５', '98765432109876543', 'zyxwvutsrqponmlk', '1234554321123455',
            // Casos de Codex (R-10 en docs/revision-codex.md).
            '1234567891234567', '1234567890123456A', '0123456789012345', '2468246824682468',
        ];
        foreach ($predecibles as $contrasena) {
            MarcoPruebas::afirmarIgual(PoliticaContrasenas::MENSAJE_BLOQUEADA, PoliticaContrasenas::validarNuevaContrasena($contrasena), 'Predecible: ' . $contrasena);
        }

        // Controles: frases y cadenas no predecibles (incluidos otros alfabetos y números al final).
        $aceptables = [
            'las olas de puerto escondido', 'mi gato come atún los martes', 'casa grande casa azul',
            'Tengo 3 perros y 2 gatos', 'mi password favorita es larguísima', '2019 fue el año que conocí a mi esposa',
            'correr 5 km cada mañana me relaja', 'ventana azul junto al mar 1987', 'x7Y9!kq2#Lm4@Pz', '583920471628394',
            'tres tristes tigres trigo', 'Ωμέγα αλφα βήτα γάμμα', '海龟在月光下慢慢游回温暖的大海',
        ];
        foreach ($aceptables as $contrasena) {
            MarcoPruebas::afirmarIgual(null, PoliticaContrasenas::validarNuevaContrasena($contrasena), 'Debe aceptarse: ' . $contrasena);
        }

        // Patrones por separado.
        MarcoPruebas::afirmar(PatronesContrasena::esProgresionNumerica('1234567891011121314'), 'Progresión 1..14');
        MarcoPruebas::afirmar(!PatronesContrasena::esProgresionNumerica('583920471628394'), 'Números sin progresión');
        MarcoPruebas::afirmar(PatronesContrasena::esPeriodico('umar2026umar202'), 'Periódico truncado');
        MarcoPruebas::afirmar(!PatronesContrasena::esPeriodico('casagrandecasa'), 'Repetir una palabra corta no es periódico');
        MarcoPruebas::afirmar(PatronesContrasena::estaCubiertoPorTramos('1qaz2wsx3edc4rf'), 'Columnas del teclado');
        MarcoPruebas::afirmar(!PatronesContrasena::estaCubiertoPorTramos('lasolasdepuertoescondido'), 'Una frase no queda cubierta por tramos');
    });

    $marco->probar('U15', 'La contraseña no puede contener el nombre, el correo ni la matrícula de la cuenta', static function (): void {
        $datosCuenta = ['ana.lopez@ejemplo.mx', 'Ana', 'López', null, 'A2026001'];
        foreach (['soy ana.lopez y me gusta el mar', 'mi matricula es A2026001 ok', 'la familia lopez camina al mar'] as $contrasena) {
            MarcoPruebas::afirmarIgual(PoliticaContrasenas::MENSAJE_DATOS_PERSONALES, PoliticaContrasenas::validarNuevaContrasena($contrasena, $datosCuenta), "Rechaza datos personales: {$contrasena}");
        }
        MarcoPruebas::afirmarIgual(null, PoliticaContrasenas::validarNuevaContrasena('caminata por la playa ejemplo', $datosCuenta), 'El dominio del correo no cuenta como dato personal');
        MarcoPruebas::afirmarIgual(null, PoliticaContrasenas::validarNuevaContrasena('una banana madura y dulce', $datosCuenta), 'Un nombre de 3 letras ("Ana") no bloquea frases');
    });

    $marco->probar('U08', 'Login: indica qué campo falta y conserva el correo escrito', static function (): void {
        $soloContrasena = ValidadorEntrada::validarDatosLogin(['correo_electronico' => '  ', 'contrasena' => 'algo']);
        MarcoPruebas::afirmar(!$soloContrasena['valido'], 'Correo vacío es inválido');
        MarcoPruebas::afirmarIgual(['correo_electronico'], array_keys($soloContrasena['errores']), 'Solo se pide el correo');

        $soloCorreo = ValidadorEntrada::validarDatosLogin(['correo_electronico' => 'ana@ejemplo.mx', 'contrasena' => '']);
        MarcoPruebas::afirmarIgual(['contrasena'], array_keys($soloCorreo['errores']), 'Solo se pide la contraseña');
        MarcoPruebas::afirmarIgual('ana@ejemplo.mx', $soloCorreo['correoPrevio'], 'El correo escrito se conserva');

        $formatoMalo = ValidadorEntrada::validarDatosLogin(['correo_electronico' => 'ana-sin-arroba', 'contrasena' => 'x']);
        MarcoPruebas::afirmarIgual(['correo_electronico'], array_keys($formatoMalo['errores']), 'Formato de correo revisado');

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

    $marco->probar('U11', 'Tokens de enlace: 256 bits, base64url, solo se guarda su SHA-256', static function (): void {
        $tokens = [];
        for ($indice = 0; $indice < 50; $indice++) {
            $token = GeneradorTokens::generarToken();
            MarcoPruebas::afirmar(GeneradorTokens::esFormatoValido($token), "Formato válido: {$token}");
            $tokens[$token] = true;
        }
        MarcoPruebas::afirmarIgual(50, count($tokens), 'Sin repeticiones');
        $token = array_key_first($tokens);
        $hash = GeneradorTokens::calcularHashToken($token);
        MarcoPruebas::afirmar(preg_match('/^[a-f0-9]{64}$/', $hash) === 1 && !str_contains($hash, $token), 'Hash SHA-256 distinto del token');
        foreach ([null, '', 'corto', str_repeat('a', 44), str_repeat('a', 42) . '=', ['x'], "../../etc/passwd" . str_repeat('a', 27)] as $tokenFalso) {
            MarcoPruebas::afirmar(!GeneradorTokens::esFormatoValido($tokenFalso), 'Rechaza ' . json_encode($tokenFalso));
        }
    });

    $marco->probar('U12', 'TOTP: vectores oficiales RFC 6238, tolerancia de ±30 s y sin reutilizar códigos', static function (): void {
        $secretoRfc = '12345678901234567890';
        foreach ([59 => '94287082', 1111111109 => '07081804', 1234567890 => '89005924', 2000000000 => '69279037'] as $momento => $codigoEsperado) {
            MarcoPruebas::afirmarIgual($codigoEsperado, SegundoFactorTotp::calcularCodigo($secretoRfc, intdiv($momento, 30), 8), "RFC 6238 t={$momento}");
        }
        $secretoBase32 = SegundoFactorTotp::codificarBase32($secretoRfc);
        MarcoPruebas::afirmarIgual('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secretoBase32, 'Base32 del secreto RFC');
        MarcoPruebas::afirmarIgual($secretoRfc, SegundoFactorTotp::decodificarBase32(strtolower(SegundoFactorTotp::formatearSecreto($secretoBase32))), 'Ida y vuelta Base32');

        $momento = 1_800_000_000;
        $paso = intdiv($momento, 30);
        $codigoActual = SegundoFactorTotp::calcularCodigo($secretoRfc, $paso);
        MarcoPruebas::afirmarIgual($paso, SegundoFactorTotp::verificarCodigo($secretoBase32, $codigoActual, null, $momento), 'Código actual aceptado');
        MarcoPruebas::afirmarIgual($paso, SegundoFactorTotp::verificarCodigo($secretoBase32, $codigoActual, null, $momento + 30), 'Aceptado con 30 s de desfase');
        MarcoPruebas::afirmarIgual(null, SegundoFactorTotp::verificarCodigo($secretoBase32, $codigoActual, null, $momento + 90), 'Rechazado con 90 s de desfase');
        MarcoPruebas::afirmarIgual(null, SegundoFactorTotp::verificarCodigo($secretoBase32, $codigoActual, $paso, $momento), 'Un código ya usado no se acepta otra vez');
        foreach (['', '12345', '1234567', 'abcdef', '12 34 5x'] as $codigoInvalido) {
            MarcoPruebas::afirmarIgual(null, SegundoFactorTotp::verificarCodigo($secretoBase32, $codigoInvalido, null, $momento), "Rechaza '{$codigoInvalido}'");
        }
        $secretoNuevo = SegundoFactorTotp::generarSecreto();
        MarcoPruebas::afirmar(strlen($secretoNuevo) === 32 && strlen(SegundoFactorTotp::decodificarBase32($secretoNuevo)) === 20, 'Secreto nuevo de 160 bits');
    });

    $marco->probar('U13', 'Secreto del segundo factor cifrado (AES-256-GCM) y ligado a la cuenta', static function (): void {
        $cifrador = new CifradorSecretos(bin2hex(random_bytes(32)));
        MarcoPruebas::afirmar(CifradorSecretos::cifradoDisponible(), 'OpenSSL con AES-256-GCM disponible');
        $cifrado = $cifrador->cifrar('JBSWY3DPEHPK3PXP', 'usuario:7');
        MarcoPruebas::afirmar(str_starts_with($cifrado, 'v1:') && !str_contains($cifrado, 'JBSWY3DPEHPK3PXP'), 'No se guarda en claro');
        MarcoPruebas::afirmarIgual('JBSWY3DPEHPK3PXP', $cifrador->descifrar($cifrado, 'usuario:7'), 'Se descifra con su contexto');
        MarcoPruebas::afirmarIgual(null, $cifrador->descifrar($cifrado, 'usuario:8'), 'Copiado a otra cuenta no se descifra');
        $alterado = substr($cifrado, 0, -2) . (str_ends_with($cifrado, 'A') ? 'BB' : 'AA');
        MarcoPruebas::afirmarIgual(null, $cifrador->descifrar($alterado, 'usuario:7'), 'Alterado no se descifra (autenticado)');
        $otraClave = new CifradorSecretos(bin2hex(random_bytes(32)));
        MarcoPruebas::afirmarIgual(null, $otraClave->descifrar($cifrado, 'usuario:7'), 'Con otra CLAVE_HUELLAS no se descifra');
        MarcoPruebas::afirmar($cifrador->calcularHuellaCodigoRecuperacion('ABCDE') !== $otraClave->calcularHuellaCodigoRecuperacion('ABCDE'), 'Huellas de códigos dependen de la clave');
    });

    $marco->probar('U14', 'Correo: enlaces solo con APP_URL_PUBLICA válida (nunca con el encabezado Host)', static function (): void {
        $_SERVER['HTTP_HOST'] = 'atacante.example';
        $token = GeneradorTokens::generarToken();
        $servicio = new ServicioCorreo('buzon_pruebas', 'http://192.168.20.132/papc/', 'x@papc.invalid', sys_get_temp_dir());
        MarcoPruebas::afirmarIgual('http://192.168.20.132/papc/activar-cuenta?token=' . $token, $servicio->construirEnlace('/activar-cuenta', $token), 'Enlace con la URL configurada');
        foreach ([null, '', 'javascript:alert(1)', 'ftp://x.mx', 'http://x.mx/?a=1', 'no es url'] as $urlInvalida) {
            $servicioInvalido = new ServicioCorreo('buzon_pruebas', $urlInvalida, 'x@papc.invalid', sys_get_temp_dir());
            MarcoPruebas::afirmarIgual(null, $servicioInvalido->construirEnlace('/activar-cuenta', $token), 'Rechaza APP_URL_PUBLICA=' . json_encode($urlInvalida));
        }
        unset($_SERVER['HTTP_HOST']);
        $servicioDesactivado = new ServicioCorreo('desactivado', 'http://x.mx', 'x@papc.invalid', sys_get_temp_dir());
        MarcoPruebas::afirmar(!$servicioDesactivado->enviarRecuperacion('ana@x.mx', $token), 'Modo desconocido no finge un envío');
        MarcoPruebas::afirmar(!$servicio->enviarRecuperacion("ana@x.mx\r\nBcc: otro@x.mx", $token), 'Inyección de encabezados rechazada');
    });
    $marco->probar('U16', 'Correo por SMTP: autentica, entrega el mensaje con el enlace y, si el servidor rechaza, no finge el envío ni registra la contraseña', static function (): void {
        /**
         * Arranca el servidor SMTP falso y espera a que escuche.
         *
         * @return array{0: resource, 1: array<int, resource>, 2: int, 3: string}
         */
        $iniciarServidor = static function (string $contrasenaEsperada): array {
            $puerto = random_int(20000, 29999);
            $salida = tempnam(sys_get_temp_dir(), 'smtp');
            $proceso = proc_open(
                [PHP_BINARY, RUTA_RAIZ . '/tests/auxiliar-servidor-smtp.php', (string) $puerto, $contrasenaEsperada, $salida],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $tuberias
            );
            fgets($tuberias[1]); // "listo"
            return [$proceso, $tuberias, $puerto, $salida];
        };
        $terminar = static function (array $servidor): array {
            [$proceso, $tuberias, , $salida] = $servidor;
            stream_get_contents($tuberias[1]);
            stream_get_contents($tuberias[2]);
            proc_close($proceso);
            $recibido = json_decode((string) file_get_contents($salida), true);
            @unlink($salida);
            return is_array($recibido) ? $recibido : [];
        };
        $token = GeneradorTokens::generarToken();
        $cuenta = 'papc.notificaciones@ejemplo.mx';

        // 1. Envío aceptado.
        $servidor = $iniciarServidor('clave de prueba');
        $cliente = new ClienteSmtp('127.0.0.1', $servidor[2], ClienteSmtp::SEGURIDAD_NINGUNA, $cuenta, 'clave de prueba');
        $servicio = new ServicioCorreo('smtp', 'http://192.168.20.132/papc', $cuenta, sys_get_temp_dir(), $cliente);
        MarcoPruebas::afirmar($servicio->enviarRecuperacion('ana@x.mx', $token), 'El servidor aceptó el mensaje');
        $recibido = $terminar($servidor);
        MarcoPruebas::afirmarIgual($cuenta, $recibido['usuario'] ?? null, 'Se autenticó con la cuenta configurada');
        MarcoPruebas::afirmarIgual('MAIL FROM:<' . $cuenta . '>', $recibido['remitente'] ?? null, 'Remitente del sobre');
        MarcoPruebas::afirmarIgual('RCPT TO:<ana@x.mx>', $recibido['destinatario'] ?? null, 'Destinatario del sobre');
        [$encabezados, $cuerpoCodificado] = explode("\r\n\r\n", (string) ($recibido['mensaje'] ?? ''), 2) + ['', ''];
        preg_match('/^Subject: (.+)$/mi', $encabezados, $asunto);
        MarcoPruebas::afirmarIgual('Instrucciones para restablecer su contraseña del PAPC', mb_decode_mimeheader(trim($asunto[1] ?? '')), 'Asunto con acentos (UTF-8)');
        $cuerpo = (string) base64_decode((string) preg_replace('/\s+/', '', $cuerpoCodificado));
        MarcoPruebas::afirmar(str_contains($cuerpo, 'http://192.168.20.132/papc/restablecer-contrasena?token=' . $token), 'El cuerpo trae el enlace de un solo uso');
        MarcoPruebas::afirmar(str_contains($encabezados, 'Content-Type: text/plain; charset=UTF-8'), 'Texto plano UTF-8');

        // 2. Contraseña rechazada (por ejemplo, contraseña de aplicación mal copiada).
        $bitacora = RUTA_RAIZ . '/storage/logs/app.log';
        $tamanoAntes = is_file($bitacora) ? filesize($bitacora) : 0;
        $servidor = $iniciarServidor('la correcta');
        $cliente = new ClienteSmtp('127.0.0.1', $servidor[2], ClienteSmtp::SEGURIDAD_NINGUNA, $cuenta, 'clave equivocada xyz');
        $servicio = new ServicioCorreo('smtp', 'http://192.168.20.132/papc', $cuenta, sys_get_temp_dir(), $cliente);
        MarcoPruebas::afirmar(!$servicio->enviarRecuperacion('ana@x.mx', $token), 'Si el servidor rechaza, no finge el envío');
        $terminar($servidor);
        clearstatcache();
        $nuevo = (string) file_get_contents($bitacora, false, null, $tamanoAntes);
        MarcoPruebas::afirmar(str_contains($nuevo, '535'), 'La bitácora dice por qué falló');
        MarcoPruebas::afirmar(!str_contains($nuevo, 'clave equivocada xyz') && !str_contains($nuevo, base64_encode('clave equivocada xyz')), 'La contraseña no aparece en la bitácora');

        // 3. Sin cifrado solo hacia la propia máquina.
        $rechazado = false;
        try {
            new ClienteSmtp('smtp.gmail.com', 587, ClienteSmtp::SEGURIDAD_NINGUNA, $cuenta, 'x');
        } catch (InvalidArgumentException) {
            $rechazado = true;
        }
        MarcoPruebas::afirmar($rechazado, 'Sin cifrado hacia un servidor externo se rechaza (la contraseña viajaría en claro)');
        $sinCliente = new ServicioCorreo('smtp', 'http://192.168.20.132/papc', $cuenta, sys_get_temp_dir());
        MarcoPruebas::afirmar(!$sinCliente->enviarRecuperacion('ana@x.mx', $token), 'Modo smtp sin configuración no finge un envío');
    });
};

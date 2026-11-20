<?php

/**
 * Reproducción por HTTP (sin JavaScript) de la política de contraseñas en
 * los dos únicos flujos que crean o cambian una contraseña: activación de
 * cuenta y restablecimiento. Solo consola; base local desechable y cuentas
 * ficticias; los correos van al buzón de pruebas (nunca se envían).
 *
 * Uso:
 *   PAPC_PRUEBAS_DB_PUERTO=3398 php tests/reproducir-politica-contrasenas.php [puertoHttp]
 *
 * Imprime una tabla: caso, flujo, resultado esperado, resultado obtenido y
 * mensaje mostrado. Si una contraseña predecible se acepta, además intenta
 * iniciar sesión con ella para demostrar que la cuenta queda utilizable.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/nucleo/arranque.php';
require __DIR__ . '/BaseDatosPruebas.php';
require __DIR__ . '/ClienteHttp.php';
require __DIR__ . '/BuzonPruebas.php';

$puertoHttp = (int) ($argv[1] ?? 8095);
$baseDatos = new BaseDatosPruebas(
    getenv('PAPC_PRUEBAS_DB_SERVIDOR') ?: '127.0.0.1',
    (int) (getenv('PAPC_PRUEBAS_DB_PUERTO') ?: 3306),
    getenv('PAPC_PRUEBAS_DB_USUARIO') ?: 'root',
    getenv('PAPC_PRUEBAS_DB_CONTRASENA') ?: ''
);
$baseDatos->recrear();
$variablesEntorno = $baseDatos->obtenerVariablesEntorno();
$buzon = new BuzonPruebas($variablesEntorno['CORREO_DIRECTORIO_BUZON']);
$buzon->vaciar();
$directorioSesiones = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'papc-sesiones-reproduccion';
@mkdir($directorioSesiones, 0700, true);

$servidor = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $directorioSesiones, '-S', "127.0.0.1:{$puertoHttp}", '-t', RUTA_RAIZ . '/public', RUTA_RAIZ . '/public/index.php'],
    [1 => ['file', sys_get_temp_dir() . '/papc-reproduccion.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/papc-reproduccion.log', 'a']],
    $tuberiasServidor,
    RUTA_RAIZ,
    array_merge(getenv(), $variablesEntorno)
);
for ($espera = 0; $espera < 50 && @fsockopen('127.0.0.1', $puertoHttp) === false; $espera++) {
    usleep(200000);
}
$urlBase = "http://127.0.0.1:{$puertoHttp}";

$ubicacion = static fn (array $respuesta): string
    => preg_match('/^Location:\s*(\S+)/mi', $respuesta['encabezados'], $coincidencia) === 1 ? $coincidencia[1] : '';
$mensajeMostrado = static function (array $respuesta): string {
    if (preg_match('/id="error-contrasena"[^>]*>([^<]+)</', $respuesta['cuerpo'], $coincidencia) === 1) {
        return html_entity_decode(trim($coincidencia[1]), ENT_QUOTES);
    }
    return '';
};

/**
 * Envía una contraseña al formulario de un enlace (activar o restablecer).
 *
 * @return array{aceptada: bool, codigo: int, mensaje: string}
 */
$enviarContrasena = static function (string $ruta, string $token, string $contrasena) use ($urlBase, $ubicacion, $mensajeMostrado): array {
    $cliente = new ClienteHttp($urlBase);
    $cliente->obtener($ruta . '?token=' . rawurlencode($token));
    $pagina = $cliente->obtener($ruta);
    $respuesta = $cliente->enviar($ruta, ['token_csrf' => ClienteHttp::extraerTokenCsrf($pagina['cuerpo']), 'contrasena' => $contrasena]);
    $aceptada = $respuesta['codigo'] === 303 && $ubicacion($respuesta) === '/iniciar-sesion';
    return ['aceptada' => $aceptada, 'codigo' => $respuesta['codigo'], 'mensaje' => $aceptada ? '(aceptada)' : $mensajeMostrado($respuesta)];
};

/** @return bool true si el inicio de sesión con esa contraseña pasa la etapa de contraseña. */
$puedeEntrar = static function (string $correo, string $contrasena) use ($urlBase, $ubicacion): bool {
    $cliente = new ClienteHttp($urlBase);
    $respuesta = $cliente->enviar('/iniciar-sesion', [
        'token_csrf' => $cliente->obtenerTokenDe('/iniciar-sesion'),
        'correo_electronico' => $correo,
        'contrasena' => $contrasena,
    ]);
    return $respuesta['codigo'] === 303 && in_array($ubicacion($respuesta), ['/inicio', '/configurar-segundo-factor', '/verificar-segundo-factor'], true);
};

// Cuenta ficticia creada por la herramienta de consola (imprime el enlace de activación).
$correo = 'ana.ficticia@ejemplo.invalid';
$herramienta = proc_open(
    [PHP_BINARY, 'herramientas/inicializar-administrador-principal.php', $correo, 'Ana', 'Ficticia'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $tuberias,
    RUTA_RAIZ,
    array_merge(getenv(), $variablesEntorno)
);
$salidaHerramienta = stream_get_contents($tuberias[1]) . stream_get_contents($tuberias[2]);
proc_close($herramienta);
preg_match('#/activar-cuenta\?token=([A-Za-z0-9_-]{43})#', (string) $salidaHerramienta, $coincidencia);
$tokenActivacion = $coincidencia[1] ?? '';

$casos = [
    // [identificador, contraseña, esperado, descripción]
    ['B1', '123456789012345', 'rechazo', 'secuencia numérica'],
    ['B2', 'aaaaaaaaaaaaaaa', 'rechazo', 'un solo carácter'],
    ['B3', 'abcabcabcabcabc', 'rechazo', 'bloque repetido'],
    ['B4', 'passwordpassword', 'rechazo', 'en la lista'],
    ['B5', 'corta', 'rechazo', 'menos de 15 (sin minlength del navegador)'],
    ['E1', 'hola!!!!!!!!!!!', 'rechazo', 'palabra corta + relleno de signos'],
    ['E2', 'hola           ', 'rechazo', 'palabra corta + relleno de espacios'],
    ['E3', 'Password!!!!!!!', 'rechazo', 'palabra común + signos'],
    ['E4', 'Password12345678', 'rechazo', 'palabra común + secuencia'],
    ['E5', 'P@ssw0rd2024!!!!', 'rechazo', 'palabra común con sustituciones + año'],
    ['E6', 'Contraseña12345!', 'rechazo', 'palabra común en español + secuencia'],
    ['E7', '123456789123456', 'rechazo', 'secuencia que reinicia'],
    ['E8', '1234567891011121314', 'rechazo', 'conteo 1..14'],
    ['E9', '246810121416182', 'rechazo', 'números pares'],
    ['E10', '100200300400500', 'rechazo', 'progresión de 100 en 100'],
    ['E11', '2024202520262027', 'rechazo', 'años consecutivos'],
    ['E12', 'qwertyuiopasdfg', 'rechazo', 'filas del teclado'],
    ['E13', '1qaz2wsx3edc4rf', 'rechazo', 'columnas del teclado'],
    ['E14', 'aaaabbbbccccdddd', 'rechazo', 'repeticiones de una secuencia'],
    ['E15', '111122223333444', 'rechazo', 'repeticiones de una secuencia numérica'],
    ['E16', 'abcdefghijklmn1', 'rechazo', 'secuencia + 1 carácter'],
    ['E17', 'umar2026umar202', 'rechazo', 'bloque repetido truncado'],
    ['E18', 'hola' . str_repeat("\u{200B}", 11), 'rechazo', 'palabra + 11 espacios de ancho cero'],
    ['E19', '１２３４５６７８９０１２３４５', 'rechazo', 'secuencia con dígitos de ancho completo'],
    ['C1', 'las olas de puerto escondido', 'aceptación', 'frase de control'],
    ['C2', 'mi gato come atún los martes', 'aceptación', 'frase de control'],
];

$resultados = [];
$restablecer = static function () use ($baseDatos, $buzon, $urlBase, $correo): string {
    $baseDatos->limpiarIntentos();
    $cliente = new ClienteHttp($urlBase);
    $cliente->enviar('/recuperar-contrasena', ['token_csrf' => $cliente->obtenerTokenDe('/recuperar-contrasena'), 'correo_electronico' => $correo]);
    return (string) $buzon->ultimoToken($correo, '/restablecer-contrasena');
};

// 1) Activación: se prueba cada caso hasta la primera aceptación (que consume el enlace).
$activada = false;
foreach ($casos as [$id, $contrasena, $esperado, $descripcion]) {
    if ($activada || $tokenActivacion === '') {
        break;
    }
    $resultado = $enviarContrasena('/activar-cuenta', $tokenActivacion, $contrasena);
    $resultados[] = [$id, 'activación', $descripcion, $esperado, $resultado];
    $activada = $resultado['aceptada'];
    if ($activada) {
        $resultados[count($resultados) - 1][4]['entra'] = $puedeEntrar($correo, $contrasena);
    }
}

// 2) Restablecimiento: todos los casos; tras cada aceptación se pide un enlace nuevo.
$tokenRestablecer = $restablecer();
foreach ($casos as [$id, $contrasena, $esperado, $descripcion]) {
    $resultado = $enviarContrasena('/restablecer-contrasena', $tokenRestablecer, $contrasena);
    if ($resultado['aceptada']) {
        $resultado['entra'] = $puedeEntrar($correo, $contrasena);
        $tokenRestablecer = $restablecer();
    }
    $resultados[] = [$id, 'restablecimiento', $descripcion, $esperado, $resultado];
}

proc_terminate($servidor);
$baseDatos->eliminarCuentaAplicacion();

printf("PAPC | Reproducción de la política de contraseñas por HTTP, sin JavaScript (%s)\n", date('Y-m-d H:i'));
printf("PHP %s · base desechable %s · servidor php -S 127.0.0.1:%d · cuenta ficticia %s\n\n", PHP_VERSION, BaseDatosPruebas::NOMBRE_BASE, $puertoHttp, $correo);
printf("%-4s %-16s %-44s %-10s %-10s %s\n", 'Caso', 'Flujo', 'Descripción', 'Esperado', 'Obtenido', 'Detalle');
$discrepancias = 0;
foreach ($resultados as [$id, $flujo, $descripcion, $esperado, $resultado]) {
    $obtenido = $resultado['aceptada'] ? 'aceptación' : 'rechazo';
    $discrepancias += $obtenido !== $esperado ? 1 : 0;
    $detalle = $resultado['aceptada']
        ? 'HTTP 303 → /iniciar-sesion; inicio de sesión con esa contraseña: ' . (($resultado['entra'] ?? false) ? 'SÍ' : 'no')
        : 'HTTP ' . $resultado['codigo'] . ' · "' . $resultado['mensaje'] . '"';
    printf("%-4s %-16s %-44s %-10s %-10s %s%s\n", $id, $flujo, $descripcion, $esperado, $obtenido, $obtenido !== $esperado ? '<<< ' : '', $detalle);
}
printf("\nDiscrepancias con lo esperado: %d de %d comprobaciones.\n", $discrepancias, count($resultados));

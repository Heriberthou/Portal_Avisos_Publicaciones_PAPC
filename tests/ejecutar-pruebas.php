<?php

/**
 * Ejecutor de pruebas de PAPC (solo consola).
 *
 * Uso:
 *   php tests/ejecutar-pruebas.php [unitarias|integracion|http|todas]
 *
 * Variables de entorno del servidor MariaDB DE PRUEBAS (nunca producción):
 *   PAPC_PRUEBAS_DB_SERVIDOR   (predeterminado 127.0.0.1)
 *   PAPC_PRUEBAS_DB_PUERTO     (predeterminado 3306)
 *   PAPC_PRUEBAS_DB_USUARIO    (predeterminado root)
 *   PAPC_PRUEBAS_DB_CONTRASENA (predeterminado vacío)
 *   PAPC_PRUEBAS_PUERTO_HTTP   (predeterminado 8097)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/nucleo/arranque.php';
require __DIR__ . '/MarcoPruebas.php';
require __DIR__ . '/BaseDatosPruebas.php';
require __DIR__ . '/ClienteHttp.php';
require __DIR__ . '/BuzonPruebas.php';

$suiteSolicitada = $argv[1] ?? 'todas';
$suitesValidas = ['unitarias', 'integracion', 'http', 'todas'];
if (!in_array($suiteSolicitada, $suitesValidas, true)) {
    fwrite(STDERR, 'Suite no válida. Opciones: ' . implode(', ', $suitesValidas) . "\n");
    exit(2);
}

// Sesiones de las pruebas de integración en un directorio temporal propio.
$directorioSesiones = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'papc-sesiones-pruebas';
if (!is_dir($directorioSesiones)) {
    mkdir($directorioSesiones, 0700, true);
}
ini_set('session.save_path', $directorioSesiones);

// Toda la salida se acumula para que session_start() no falle por "headers sent".
ob_start();

$marco = new MarcoPruebas();
$contexto = [];

if ($suiteSolicitada === 'unitarias' || $suiteSolicitada === 'todas') {
    (require __DIR__ . '/pruebas-unitarias.php')($marco);
}

if ($suiteSolicitada !== 'unitarias') {
    $baseDatos = new BaseDatosPruebas(
        getenv('PAPC_PRUEBAS_DB_SERVIDOR') ?: '127.0.0.1',
        (int) (getenv('PAPC_PRUEBAS_DB_PUERTO') ?: 3306),
        getenv('PAPC_PRUEBAS_DB_USUARIO') ?: 'root',
        getenv('PAPC_PRUEBAS_DB_CONTRASENA') ?: ''
    );
    $baseDatos->recrear();
    $variablesEntorno = $baseDatos->obtenerVariablesEntorno();

    // putenv() tiene prioridad sobre un posible .env local: las pruebas
    // jamás apuntan a la base real.
    foreach ($variablesEntorno as $clave => $valor) {
        putenv($clave . '=' . $valor);
    }

    if ($suiteSolicitada === 'integracion' || $suiteSolicitada === 'todas') {
        (require __DIR__ . '/pruebas-integracion.php')($marco, $baseDatos);
    }
    if ($suiteSolicitada === 'http' || $suiteSolicitada === 'todas') {
        // Cada suite parte de una base limpia para ser independiente.
        $baseDatos->recrear();
        (require __DIR__ . '/pruebas-http.php')(
            $marco,
            $baseDatos,
            $variablesEntorno,
            (int) (getenv('PAPC_PRUEBAS_PUERTO_HTTP') ?: 8097),
            $directorioSesiones
        );
    }
    $baseDatos->eliminarCuentaAplicacion();
}

$codigoSalida = $marco->imprimirResumen();
ob_end_flush();
exit($codigoSalida);

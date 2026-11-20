<?php

/**
 * Muestra los mensajes del BUZÓN DE PRUEBAS (CORREO_MODO=buzon_pruebas).
 *
 * En este modo el PAPC no envía correos reales: guarda cada mensaje en
 * storage/correos/ (carpeta privada). Esta herramienta permite leerlos
 * durante la demostración sin fingir que se enviaron.
 *
 * Uso (consola del servidor):
 *   php herramientas/ver-buzon-pruebas.php              -> últimos 5 mensajes
 *   php herramientas/ver-buzon-pruebas.php correo@x.mx  -> últimos 5 de ese correo
 *   php herramientas/ver-buzon-pruebas.php correo@x.mx 1 -> solo el más reciente
 *
 * Los mensajes contienen enlaces de un solo uso: no los compartas ni los
 * subas al repositorio (storage/correos/ está en .gitignore).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/nucleo/arranque.php';

$filtroCorreo = isset($argv[1]) ? ValidadorEntrada::normalizarCorreo($argv[1]) : null;
$cantidad = max(1, min(50, (int) ($argv[2] ?? 5)));

$archivos = glob(RUTA_RAIZ . '/storage/correos/*.txt') ?: [];
rsort($archivos);

$mostrados = 0;
foreach ($archivos as $archivo) {
    $contenido = (string) file_get_contents($archivo);
    if ($filtroCorreo !== null && !str_contains(strtolower(strtok($contenido, "\n") ?: ''), $filtroCorreo)) {
        continue;
    }
    fwrite(STDOUT, str_repeat('=', 72) . "\n" . basename($archivo) . "\n" . $contenido . "\n");
    if (++$mostrados >= $cantidad) {
        break;
    }
}
if ($mostrados === 0) {
    fwrite(STDOUT, "No hay mensajes" . ($filtroCorreo !== null ? " para {$filtroCorreo}" : '') . " en storage/correos/.\n");
}

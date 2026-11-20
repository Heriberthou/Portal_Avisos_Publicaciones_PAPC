<?php

/**
 * Envía un correo de prueba con la configuración del .env (CORREO_MODO y
 * CORREO_SMTP_*), para comprobar el envío real sin pasar por todo el
 * circuito de solicitud de acceso.
 *
 * Uso (consola del servidor, desde la carpeta del proyecto):
 *   php herramientas/probar-correo.php correo@destino.mx
 *
 * Si falla, muestra el motivo que dio el servidor de correo (nunca la
 * contraseña). El mensaje de prueba no contiene enlaces ni datos de cuentas.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/nucleo/arranque.php';

$destinatario = ValidadorEntrada::normalizarCorreo($argv[1] ?? '');
if (filter_var($destinatario, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Uso: php herramientas/probar-correo.php correo@destino.mx\n");
    exit(1);
}

$modo = (string) Configuracion::obtener('CORREO_MODO', ServicioCorreo::MODO_BUZON_PRUEBAS);
fwrite(STDOUT, "Modo de correo: {$modo}\n");
if ($modo !== ServicioCorreo::MODO_SMTP) {
    fwrite(STDOUT, "Aviso: con este modo el mensaje NO sale a internet. Para enviar de verdad use CORREO_MODO=smtp en el .env.\n");
}

$bitacora = RUTA_RAIZ . '/storage/logs/app.log';
$tamanoAntes = is_file($bitacora) ? (int) filesize($bitacora) : 0;

if (ServicioCorreo::desdeConfiguracion()->enviarPrueba($destinatario)) {
    fwrite(STDOUT, "Listo: el servidor de correo aceptó el mensaje para {$destinatario}.\n"
        . "Revise la bandeja de entrada (y la carpeta de correo no deseado).\n");
    exit(0);
}

clearstatcache();
$motivo = is_file($bitacora) ? trim((string) file_get_contents($bitacora, false, null, $tamanoAntes)) : '';
fwrite(STDERR, "No se pudo enviar el correo.\n" . ($motivo !== '' ? "Motivo registrado:\n{$motivo}\n" : ''));
exit(1);

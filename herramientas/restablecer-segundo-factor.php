<?php

/**
 * Procedimiento de EMERGENCIA: elimina el segundo factor de una cuenta
 * para que lo configure de nuevo en su siguiente inicio de sesión.
 *
 * Para cuando un administrador perdió su teléfono y sus códigos de
 * recuperación y no hay otro principal que lo restablezca desde
 * /admin/roles. Solo desde la consola del servidor; se audita como
 * SEGUNDO_FACTOR_RESTABLECIDO (canal CONSOLA) y cierra las sesiones de la
 * cuenta. Antes de usarlo, compruebe la identidad de la persona por un
 * medio institucional.
 *
 * Uso:
 *   php herramientas/restablecer-segundo-factor.php correo@dominio
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/nucleo/arranque.php';

$correoNormalizado = ValidadorEntrada::normalizarCorreo($argv[1] ?? '');
if (filter_var($correoNormalizado, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Uso: php herramientas/restablecer-segundo-factor.php correo@dominio\n");
    exit(2);
}

try {
    $dependencias = Aplicacion::construirDependencias(obtenerConexionBaseDatos());
    $resultado = $dependencias['servicioAdministracion']->restablecerSegundoFactorDesdeConsola($correoNormalizado);
} catch (Throwable $excepcion) {
    RegistroAplicacion::registrarError('Fallo al restablecer segundo factor', ['mensaje' => $excepcion->getMessage()]);
    fwrite(STDERR, "Error interno. Revise storage/logs/app.log.\n");
    exit(1);
}

fwrite($resultado['exito'] ? STDOUT : STDERR, $resultado['mensaje'] . "\n");
exit($resultado['exito'] ? 0 : 1);

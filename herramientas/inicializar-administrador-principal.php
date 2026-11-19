<?php

/**
 * Inicialización controlada del primer ADMINISTRADOR_PRINCIPAL.
 *
 * Procedimiento seguro (no hay cuentas ni contraseñas predeterminadas):
 *   1. La persona responsable se registra en /registro con su propia
 *      contraseña (queda como USUARIO).
 *   2. Un operador con acceso a la CONSOLA del servidor ejecuta:
 *        php herramientas/inicializar-administrador-principal.php correo@dominio
 *   3. El script solo funciona si NO existe ningún administrador principal;
 *      después, cualquier cambio de rol se hace con la operación web
 *      auditada (/admin/usuarios/rol) y re-autenticación.
 *   4. El evento queda en auditoria como INICIALIZACION_ADMINISTRADOR_PRINCIPAL.
 *
 * Este archivo está fuera de public/ y además se niega a ejecutarse vía web.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/nucleo/arranque.php';

$correoRecibido = $argv[1] ?? '';
$correoNormalizado = ValidadorEntrada::normalizarCorreo($correoRecibido);
if (filter_var($correoNormalizado, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Uso: php herramientas/inicializar-administrador-principal.php correo@dominio\n");
    exit(2);
}

try {
    $dependencias = Aplicacion::construirDependencias(obtenerConexionBaseDatos());
    $resultado = $dependencias['servicioAdministracion']->inicializarAdministradorPrincipal($correoNormalizado);
} catch (Throwable $excepcion) {
    RegistroAplicacion::registrarError('Fallo al inicializar administrador principal', ['mensaje' => $excepcion->getMessage()]);
    fwrite(STDERR, "Error interno. Revise storage/logs/app.log.\n");
    exit(1);
}

fwrite($resultado['exito'] ? STDOUT : STDERR, $resultado['mensaje'] . "\n");
exit($resultado['exito'] ? 0 : 1);

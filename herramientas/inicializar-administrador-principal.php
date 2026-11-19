<?php

/**
 * Inicialización controlada del primer ADMINISTRADOR_PRINCIPAL
 * (especificación 1.3, sección 7.6). No hay cuentas ni contraseñas
 * predeterminadas.
 *
 * Uso (consola del servidor, nunca por web):
 *   A) La cuenta ya existe y está ACTIVA (p. ej. creada antes de la 004):
 *        php herramientas/inicializar-administrador-principal.php correo@dominio
 *   B) La cuenta no existe: se crea INVITADA y se imprime su enlace de
 *      activación (la persona crea su propia contraseña):
 *        php herramientas/inicializar-administrador-principal.php correo@dominio "Nombre(s)" "Apellido paterno" ["Apellido materno"]
 *
 * Reglas:
 *  - Solo funciona si NO existe ningún ADMINISTRADOR_PRINCIPAL; después,
 *    los roles se cambian en /admin/roles con re-autenticación.
 *  - El principal deberá configurar su segundo factor al iniciar sesión.
 *  - El evento queda en auditoria como INICIALIZACION_ADMINISTRADOR_PRINCIPAL.
 *
 * Este archivo está fuera de public/ y además se niega a ejecutarse vía web.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/nucleo/arranque.php';

$correoNormalizado = ValidadorEntrada::normalizarCorreo($argv[1] ?? '');
if (filter_var($correoNormalizado, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Uso: php herramientas/inicializar-administrador-principal.php correo@dominio [\"Nombre(s)\" \"Apellido paterno\" [\"Apellido materno\"]]\n");
    exit(2);
}

$datosNuevaCuenta = null;
if (isset($argv[2])) {
    // Se reutiliza la validación del formulario público para los nombres.
    $validacion = ValidadorEntrada::validarDatosSolicitud([
        'nombres'                     => $argv[2],
        'apellido_paterno'            => $argv[3] ?? '',
        'apellido_materno'            => $argv[4] ?? '',
        'tipo_integrante'             => UsuarioModel::TIPO_PROFESOR,
        'identificador_institucional' => 'CONSOLA',
        'correo_electronico'          => $correoNormalizado,
    ]);
    if (!$validacion['valido']) {
        fwrite(STDERR, 'Datos no válidos: ' . implode(' ', $validacion['errores']) . "\n");
        exit(2);
    }
    $datosNuevaCuenta = [
        'nombres'         => (string) $validacion['datos']['nombres'],
        'apellidoPaterno' => (string) $validacion['datos']['apellidoPaterno'],
        'apellidoMaterno' => $validacion['datos']['apellidoMaterno'],
    ];
}

try {
    $dependencias = Aplicacion::construirDependencias(obtenerConexionBaseDatos());
    $resultado = $dependencias['servicioAdministracion']->inicializarAdministradorPrincipal($correoNormalizado, $datosNuevaCuenta);
} catch (Throwable $excepcion) {
    RegistroAplicacion::registrarError('Fallo al inicializar administrador principal', ['mensaje' => $excepcion->getMessage()]);
    fwrite(STDERR, "Error interno. Revise storage/logs/app.log.\n");
    exit(1);
}

fwrite($resultado['exito'] ? STDOUT : STDERR, $resultado['mensaje'] . "\n");
if ($resultado['tokenActivacion'] !== null) {
    $enlace = $dependencias['servicioCorreo']->construirEnlace('/activar-cuenta', $resultado['tokenActivacion']);
    fwrite(STDOUT, $enlace !== null
        ? "Enlace de activación (un solo uso, vence en 24 h). Ábralo en el navegador de la persona titular:\n{$enlace}\n"
        : "Configure APP_URL_PUBLICA en el .env para obtener el enlace; después use \"¿Olvidó su contraseña?\" con ese correo.\n");
}
exit($resultado['exito'] ? 0 : 1);

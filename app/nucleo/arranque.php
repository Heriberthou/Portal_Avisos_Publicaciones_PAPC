<?php

/**
 * Arranque común de PAPC (web, pruebas y herramientas de consola).
 *
 * - Define la raíz del proyecto.
 * - Desactiva la visualización de errores (nunca se muestran al usuario) y
 *   los envía a storage/logs/php-errores.log.
 * - Registra el autocargador de clases.
 * - Carga constantes, .env y la función de conexión.
 */

declare(strict_types=1);

if (!defined('RUTA_RAIZ')) {
    define('RUTA_RAIZ', dirname(__DIR__, 2));
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', RUTA_RAIZ . '/storage/logs/php-errores.log');
ini_set('expose_php', '0');
error_reporting(E_ALL);

/**
 * Autocargador: busca la clase en los directorios internos de app/.
 * Solo acepta nombres de clase alfanuméricos (sin rutas).
 */
spl_autoload_register(static function (string $nombreClase): void {
    if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $nombreClase) !== 1) {
        return;
    }
    $directoriosClases = ['nucleo', 'models', 'middleware', 'controllers', 'servicios'];
    foreach ($directoriosClases as $directorio) {
        $rutaArchivo = RUTA_RAIZ . '/app/' . $directorio . '/' . $nombreClase . '.php';
        if (is_file($rutaArchivo)) {
            require_once $rutaArchivo;
            return;
        }
    }
});

require_once RUTA_RAIZ . '/config/aplicacion.php';
require_once RUTA_RAIZ . '/config/database.php';

Configuracion::cargarArchivoEntorno(RUTA_RAIZ . '/.env');

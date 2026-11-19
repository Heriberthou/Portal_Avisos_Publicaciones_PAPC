<?php

/**
 * Punto de entrada ÚNICO de PAPC. Es el único archivo PHP dentro de
 * public/, el único directorio que Apache debe servir.
 *
 * Toda la lógica vive fuera del directorio público; este archivo solo
 * carga el arranque y delega en Aplicacion.
 */

declare(strict_types=1);

// Servidor embebido de PHP (solo pruebas): deja servir archivos estáticos reales.
if (PHP_SAPI === 'cli-server') {
    $rutaArchivoEstatico = __DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($rutaArchivoEstatico !== __FILE__ && is_file($rutaArchivoEstatico)) {
        return false;
    }
}

require dirname(__DIR__) . '/app/nucleo/arranque.php';

Aplicacion::ejecutar();

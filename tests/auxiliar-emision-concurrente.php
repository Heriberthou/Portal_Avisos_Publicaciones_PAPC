<?php

/**
 * Auxiliar de la prueba R04 (solo consola): emite un enlace de recuperación
 * para una cuenta en un instante acordado, para que varios procesos lo hagan
 * a la vez con conexiones independientes (concurrencia real, hallazgo R-04).
 *
 * Uso: php tests/auxiliar-emision-concurrente.php <idUsuario> <microtimeInicio> [READ-COMMITTED]
 * La configuración de la base de pruebas llega por variables de entorno.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/nucleo/arranque.php';

$idUsuario = (int) ($argv[1] ?? 0);
$inicio = (float) ($argv[2] ?? 0);
$conexion = obtenerConexionBaseDatos();
// READ COMMITTED quita los bloqueos de rango de InnoDB: la protección debe
// venir del bloqueo explícito de la cuenta, no del nivel de aislamiento.
if (($argv[3] ?? '') === 'READ-COMMITTED') {
    $conexion->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
}
$tokenCuentaModel = new TokenCuentaModel($conexion);

// Todos los procesos esperan el mismo instante antes de emitir.
while (microtime(true) < $inicio) {
    usleep(500);
}
$tokenCuentaModel->crearToken($idUsuario, TokenCuentaModel::FINALIDAD_RECUPERACION, GeneradorTokens::calcularHashToken(GeneradorTokens::generarToken()), 600);
echo "ok\n";

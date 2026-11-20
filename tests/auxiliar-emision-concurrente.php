<?php

/**
 * Auxiliar de las pruebas R04 y R09 (solo consola): emite un enlace en un
 * instante acordado, con una conexión independiente (concurrencia real).
 *   - cuenta (predeterminado): enlace de recuperación para la cuenta indicada.
 *   - solicitud: enlace de verificación para la solicitud indicada.
 *
 * Uso: php tests/auxiliar-emision-concurrente.php <id> <microtimeInicio> [READ-COMMITTED|''] [cuenta|solicitud]
 * La configuración de la base de pruebas llega por variables de entorno.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/nucleo/arranque.php';

$idPadre = (int) ($argv[1] ?? 0);
$inicio = (float) ($argv[2] ?? 0);
$conexion = obtenerConexionBaseDatos();
// READ COMMITTED quita los bloqueos de rango de InnoDB: la protección debe
// venir del bloqueo explícito de la cuenta, no del nivel de aislamiento.
if (($argv[3] ?? '') === 'READ-COMMITTED') {
    $conexion->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
}

// Todos los procesos esperan el mismo instante antes de emitir.
while (microtime(true) < $inicio) {
    usleep(500);
}
$hashToken = GeneradorTokens::calcularHashToken(GeneradorTokens::generarToken());
if (($argv[4] ?? 'cuenta') === 'solicitud') {
    (new TokenSolicitudModel($conexion))->crearToken($idPadre, $hashToken, 600);
} else {
    (new TokenCuentaModel($conexion))->crearToken($idPadre, TokenCuentaModel::FINALIDAD_RECUPERACION, $hashToken, 600);
}
echo "ok\n";

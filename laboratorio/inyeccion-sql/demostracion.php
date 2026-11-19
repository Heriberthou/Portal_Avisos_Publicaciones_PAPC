<?php

/**
 * Demostración E8-P07: consulta vulnerable vs. consulta preparada.
 *
 * Uso (solo consola, desde la carpeta del proyecto):
 *   php laboratorio/inyeccion-sql/demostracion.php
 *   php laboratorio/inyeccion-sql/demostracion.php --mariadb   (forzar MariaDB, tabla temporal)
 *
 * No modifica la base de datos de la aplicación. Ver README.md de esta carpeta.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/app/nucleo/arranque.php';
require __DIR__ . '/LaboratorioInyeccionSql.php';

if (in_array('--mariadb', $argv, true)) {
    $entorno = ['conexion' => obtenerConexionBaseDatos(), 'motor' => 'MariaDB (tabla temporal)'];
} else {
    $entorno = LaboratorioInyeccionSql::crearConexionAislada();
}

date_default_timezone_set('America/Mexico_City');
$laboratorio = new LaboratorioInyeccionSql($entorno['conexion']);
$laboratorio->prepararDatosFicticios();

$describir = static function (array $resultado): string {
    if ($resultado['error'] !== null) {
        return 'ERROR SQL EXPUESTO';
    }
    return $resultado['acceso'] ? 'ACCESO como ' . $resultado['cuenta'] : 'rechazado';
};

echo "PAPC · Laboratorio E8 · Motor: {$entorno['motor']} · " . date('Y-m-d H:i:s') . "\n";
echo "Datos ficticios en tabla temporal " . LaboratorioInyeccionSql::TABLA . "\n";
echo str_repeat('=', 100) . "\n";

foreach ($laboratorio->ejecutarComparacion() as $resultado) {
    $caso = $resultado['caso'];
    $vulnerable = $resultado['vulnerable'];
    $segura = $resultado['segura'];
    $fallaVulnerable = $vulnerable['error'] !== null
        || $vulnerable['filas'] > 1
        || ($vulnerable['acceso'] !== $segura['acceso']);

    printf("%s · %s\n", $caso['clave'], $caso['descripcion']);
    printf("  Entrada correo: %s\n", $caso['correo']);
    printf("  ❌ Vulnerable: %-40s filas=%d%s\n", $describir($vulnerable), $vulnerable['filas'], $fallaVulnerable ? '   <-- CONSULTA ALTERADA' : '');
    printf("     SQL ejecutado: %s\n", preg_replace('/\s+/', ' ', $vulnerable['sql']));
    if ($vulnerable['error'] !== null) {
        printf("     Error mostrado: %s\n", $vulnerable['error']);
    }
    printf("  ✅ Preparada:  %-40s filas=%d\n", $describir($segura), $segura['filas']);
    printf("     SQL ejecutado: %s\n", preg_replace('/\s+/', ' ', $segura['sql']));
    echo str_repeat('-', 100) . "\n";
}
echo "La tabla temporal se elimina al terminar el script.\n";

<?php

/**
 * Conexión única a MariaDB mediante PDO.
 *
 * Es el ÚNICO punto del proyecto que crea conexiones (no se duplican).
 * Las credenciales se leen del .env mediante Configuracion; nunca se
 * escriben en este archivo.
 */

declare(strict_types=1);

/**
 * Devuelve la conexión PDO compartida, creándola en la primera llamada.
 *
 * Seguridad:
 *  - ATTR_EMULATE_PREPARES = false: el servidor prepara la consulta y los
 *    valores viajan separados del SQL (defensa real contra inyección).
 *  - ERRMODE_EXCEPTION: los errores se capturan y registran; nunca se
 *    muestran al usuario.
 *  - charset utf8mb4 en el DSN: evita ataques por conversión de juego de
 *    caracteres.
 *
 * @return PDO Conexión lista para consultas preparadas.
 * @throws PDOException Si no es posible conectar (se registra en el log).
 */
function obtenerConexionBaseDatos(): PDO
{
    static $conexion = null;

    if ($conexion instanceof PDO) {
        return $conexion;
    }

    $nombreFuenteDatos = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        Configuracion::obtener('DB_SERVIDOR', '127.0.0.1'),
        (int) Configuracion::obtener('DB_PUERTO', '3306'),
        Configuracion::obtenerObligatorio('DB_NOMBRE')
    );

    $conexion = new PDO(
        $nombreFuenteDatos,
        Configuracion::obtenerObligatorio('DB_USUARIO'),
        Configuracion::obtener('DB_CONTRASENA', ''),
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]
    );

    // Modo estricto: MariaDB rechaza valores fuera de ENUM o truncados en vez
    // de "ajustarlos" en silencio.
    $conexion->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

    return $conexion;
}

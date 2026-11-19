<?php

declare(strict_types=1);

/**
 * Prepara una base de datos DESECHABLE para las pruebas.
 *
 * Seguridad de las pruebas:
 *  - Solo opera sobre bases cuyo nombre termina en "_pruebas"; se niega a
 *    tocar equipo_7, papc_db u otra base real.
 *  - Recrea la base desde database/schema.sql + migraciones, es decir,
 *    prueba los mismos archivos que se entregan.
 *  - Crea una cuenta de aplicación con los mismos GRANT de
 *    database/usuario-aplicacion.sql para que las pruebas corran con
 *    mínimos privilegios, igual que en producción.
 */
final class BaseDatosPruebas
{
    public const NOMBRE_BASE = 'papc_db_pruebas';
    public const USUARIO_APLICACION = 'papc_app_pruebas';

    /** Contraseña aleatoria de la cuenta de pruebas, generada en cada ejecución. */
    public readonly string $contrasenaAplicacion;

    private PDO $conexionAdministrativa;

    /**
     * @param string $servidor Servidor MariaDB de pruebas.
     * @param int $puerto Puerto.
     * @param string $usuarioAdministrativo Cuenta con permisos para crear bases (solo pruebas).
     * @param string $contrasenaAdministrativa Su contraseña.
     */
    public function __construct(
        public readonly string $servidor,
        public readonly int $puerto,
        string $usuarioAdministrativo,
        string $contrasenaAdministrativa
    ) {
        if (!str_ends_with(self::NOMBRE_BASE, '_pruebas')) {
            throw new RuntimeException('Por seguridad, la base de pruebas debe terminar en _pruebas.');
        }
        $this->contrasenaAplicacion = bin2hex(random_bytes(16));
        $this->conexionAdministrativa = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $servidor, $puerto),
            $usuarioAdministrativo,
            $contrasenaAdministrativa,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    /**
     * Elimina y vuelve a crear la base y la cuenta de aplicación.
     *
     * @return void
     */
    public function recrear(): void
    {
        $base = self::NOMBRE_BASE;
        $this->conexionAdministrativa->exec("DROP DATABASE IF EXISTS `{$base}`");
        $this->conexionAdministrativa->exec("CREATE DATABASE `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->conexionAdministrativa->exec("USE `{$base}`");

        foreach ([
            'database/schema.sql',
            'database/migraciones/001-clasificacion-usuario.sql',
            'database/migraciones/002-intento-acceso.sql',
            'database/migraciones/003-nombre-atomico-usuario.sql',
        ] as $archivoSql) {
            $this->conexionAdministrativa->exec((string) file_get_contents(RUTA_RAIZ . '/' . $archivoSql));
        }

        $usuarioApp = self::USUARIO_APLICACION;
        $this->conexionAdministrativa->exec("DROP USER IF EXISTS '{$usuarioApp}'@'127.0.0.1'");
        $scriptPermisos = (string) file_get_contents(RUTA_RAIZ . '/database/usuario-aplicacion.sql');
        $scriptPermisos = str_replace(
            ["'papc_app'@'localhost'", 'equipo_7.', 'CAMBIAR_POR_CONTRASENA_LARGA_Y_ALEATORIA'],
            ["'{$usuarioApp}'@'127.0.0.1'", "{$base}.", $this->contrasenaAplicacion],
            $scriptPermisos
        );
        $this->conexionAdministrativa->exec($scriptPermisos);
    }

    /**
     * Conexión con privilegios de administración (solo para preparar datos
     * de prueba o inspeccionar resultados).
     *
     * @return PDO
     */
    public function obtenerConexionAdministrativa(): PDO
    {
        $this->conexionAdministrativa->exec('USE `' . self::NOMBRE_BASE . '`');
        return $this->conexionAdministrativa;
    }

    /**
     * Variables de entorno que apuntan la aplicación a la base de pruebas.
     *
     * @return array<string, string>
     */
    public function obtenerVariablesEntorno(): array
    {
        return [
            'APP_ENTORNO'   => 'desarrollo',
            'APP_RUTA_BASE' => '',
            'DB_SERVIDOR'   => $this->servidor,
            'DB_PUERTO'     => (string) $this->puerto,
            'DB_NOMBRE'     => self::NOMBRE_BASE,
            'DB_USUARIO'    => self::USUARIO_APLICACION,
            'DB_CONTRASENA' => $this->contrasenaAplicacion,
            'CLAVE_HUELLAS' => bin2hex(random_bytes(32)),
        ];
    }

    /**
     * Fuerza el rol de una cuenta directamente en la BD (preparación de
     * escenarios; NO es una vía de la aplicación).
     *
     * @param string $correoElectronico Cuenta.
     * @param string $rol Rol a fijar.
     * @return void
     */
    public function fijarRol(string $correoElectronico, string $rol): void
    {
        $sentencia = $this->obtenerConexionAdministrativa()->prepare(
            'UPDATE usuario SET rol_usuario = :rol WHERE correo_electronico_usuario = :correo'
        );
        $sentencia->execute(['rol' => $rol, 'correo' => $correoElectronico]);
    }

    /**
     * @param string $correoElectronico Cuenta a consultar.
     * @return array<string, mixed>|null Fila completa.
     */
    public function obtenerFilaUsuario(string $correoElectronico): ?array
    {
        $sentencia = $this->obtenerConexionAdministrativa()->prepare('SELECT * FROM usuario WHERE correo_electronico_usuario = :correo');
        $sentencia->execute(['correo' => $correoElectronico]);
        $fila = $sentencia->fetch();
        return $fila === false ? null : $fila;
    }

    /**
     * @param string $accion Valor de accion_auditoria.
     * @return int Eventos registrados con esa acción.
     */
    public function contarEventos(string $accion): int
    {
        $sentencia = $this->obtenerConexionAdministrativa()->prepare('SELECT COUNT(*) FROM auditoria WHERE accion_auditoria = :accion');
        $sentencia->execute(['accion' => $accion]);
        return (int) $sentencia->fetchColumn();
    }

    /**
     * Elimina la cuenta MariaDB temporal de las pruebas. La base
     * papc_db_pruebas se conserva para inspeccionar la auditoría.
     *
     * @return void
     */
    public function eliminarCuentaAplicacion(): void
    {
        $this->conexionAdministrativa->exec("DROP USER IF EXISTS '" . self::USUARIO_APLICACION . "'@'127.0.0.1'");
    }

    /**
     * Vacía la tabla de intentos (aislar escenarios de limitación).
     *
     * @return void
     */
    public function limpiarIntentos(): void
    {
        $this->obtenerConexionAdministrativa()->exec('DELETE FROM intento_acceso');
    }
}

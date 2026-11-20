<?php

declare(strict_types=1);

/**
 * Laboratorio E8: comparación entre una consulta de login VULNERABLE
 * (concatenación) y la versión CORREGIDA (consulta preparada con PDO).
 *
 * ADVERTENCIA: iniciarSesionVulnerable() es inseguro A PROPÓSITO y existe
 * solo para la demostración. No lo usa la aplicación: el login real está en
 * UsuarioModel::obtenerUsuarioPorCorreo() + ServicioAutenticacion.
 *
 * Entorno controlado (consideración de seguridad de E8):
 *  - Se ejecuta solo por consola (laboratorio/ está fuera de public/ y
 *    denegado por .htaccess).
 *  - Usa una tabla TEMPORAL con datos ficticios, que desaparece al cerrar la
 *    conexión. Nunca toca la tabla usuario ni datos reales.
 *  - Las contraseñas ficticias se guardan con password_hash(); el fallo que
 *    se demuestra es la inyección, no el almacenamiento.
 */
final class LaboratorioInyeccionSql
{
    /** Tabla temporal del laboratorio (NOM-02/NOM-05). */
    public const TABLA = 'laboratorio_usuario_e8';

    /** Cuentas ficticias: correo => contraseña en claro (solo para la demo). */
    public const CUENTAS_FICTICIAS = [
        'admin@laboratorio.test'  => 'clave ficticia del administrador',
        'ana@laboratorio.test'    => 'clave ficticia de ana para pruebas',
        "o'brien@laboratorio.test" => 'clave ficticia con apostrofo',
    ];

    public function __construct(private readonly PDO $conexion)
    {
        $this->conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->conexion->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    /**
     * Crea la tabla temporal y la llena con las cuentas ficticias.
     *
     * @return void
     */
    public function prepararDatosFicticios(): void
    {
        $esSqlite = $this->conexion->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $this->conexion->exec(($esSqlite ? 'DROP TABLE IF EXISTS ' : 'DROP TEMPORARY TABLE IF EXISTS ') . self::TABLA);
        $this->conexion->exec(
            'CREATE TEMPORARY TABLE ' . self::TABLA . ' (
                id_laboratorio INTEGER PRIMARY KEY,
                correo_laboratorio VARCHAR(150) NOT NULL,
                hash_contrasena_laboratorio VARCHAR(255) NOT NULL
            )'
        );
        $sentencia = $this->conexion->prepare(
            'INSERT INTO ' . self::TABLA . ' (id_laboratorio, correo_laboratorio, hash_contrasena_laboratorio)
             VALUES (:idLaboratorio, :correo, :hash)'
        );
        $idLaboratorio = 1;
        foreach (self::CUENTAS_FICTICIAS as $correo => $contrasena) {
            $sentencia->execute([
                'idLaboratorio' => $idLaboratorio++,
                'correo'        => $correo,
                'hash'          => password_hash($contrasena, PASSWORD_DEFAULT),
            ]);
        }
    }

    /**
     * ❌ VERSIÓN VULNERABLE (NO USAR): concatena la entrada en el SQL.
     *
     * @param string $correo Entrada del usuario.
     * @param string $contrasena Entrada del usuario.
     * @return array{acceso: bool, cuenta: string|null, sql: string, filas: int, error: string|null}
     */
    public function iniciarSesionVulnerable(string $correo, string $contrasena): array
    {
        // La entrada se pega dentro de la cadena SQL: el usuario puede cerrar
        // la comilla y escribir instrucciones propias.
        $sql = "SELECT id_laboratorio, correo_laboratorio, hash_contrasena_laboratorio
                FROM " . self::TABLA . " WHERE correo_laboratorio = '" . $correo . "'";

        try {
            $filas = $this->conexion->query($sql)->fetchAll();
        } catch (PDOException $excepcion) {
            // Una app vulnerable suele mostrar este error: revela el SQL.
            return ['acceso' => false, 'cuenta' => null, 'sql' => $sql, 'filas' => 0, 'error' => $excepcion->getMessage()];
        }

        $primeraFila = $filas[0] ?? null;
        $acceso = $primeraFila !== null && password_verify($contrasena, (string) $primeraFila['hash_contrasena_laboratorio']);
        return [
            'acceso' => $acceso,
            'cuenta' => $acceso ? (string) $primeraFila['correo_laboratorio'] : null,
            'sql'    => $sql,
            'filas'  => count($filas),
            'error'  => null,
        ];
    }

    /**
     * ✅ VERSIÓN CORREGIDA: consulta preparada con parámetro enlazado
     * (mismo patrón que UsuarioModel::obtenerUsuarioPorCorreo()).
     *
     * @param string $correo Entrada del usuario.
     * @param string $contrasena Entrada del usuario.
     * @return array{acceso: bool, cuenta: string|null, sql: string, filas: int, error: string|null}
     */
    public function iniciarSesionSegura(string $correo, string $contrasena): array
    {
        // La estructura del SQL es fija; :correo viaja aparte como DATO y el
        // motor nunca lo interpreta como instrucción.
        $sql = 'SELECT id_laboratorio, correo_laboratorio, hash_contrasena_laboratorio
                FROM ' . self::TABLA . ' WHERE correo_laboratorio = :correo LIMIT 1';

        $sentencia = $this->conexion->prepare($sql);
        $sentencia->bindValue(':correo', $correo, PDO::PARAM_STR);
        $sentencia->execute();
        $filas = $sentencia->fetchAll();

        $primeraFila = $filas[0] ?? null;
        $acceso = $primeraFila !== null && password_verify($contrasena, (string) $primeraFila['hash_contrasena_laboratorio']);
        return [
            'acceso' => $acceso,
            'cuenta' => $acceso ? (string) $primeraFila['correo_laboratorio'] : null,
            'sql'    => $sql . '   [parámetro :correo = ' . var_export($correo, true) . ']',
            'filas'  => count($filas),
            'error'  => null,
        ];
    }

    /**
     * Casos de prueba de E8 (normales y adversariales).
     *
     * @return list<array{clave: string, descripcion: string, correo: string, contrasena: string}>
     */
    public static function obtenerCasos(): array
    {
        // Hash que el atacante genera con una contraseña que él conoce.
        $hashDelAtacante = password_hash('clave inventada por el atacante', PASSWORD_DEFAULT);

        return [
            ['clave' => 'E8-P01', 'descripcion' => 'Credenciales normales',
             'correo' => 'ana@laboratorio.test', 'contrasena' => 'clave ficticia de ana para pruebas'],
            ['clave' => 'E8-P01b', 'descripcion' => 'Contraseña incorrecta',
             'correo' => 'ana@laboratorio.test', 'contrasena' => 'otra clave'],
            ['clave' => 'E8-P02', 'descripcion' => 'Correo legítimo con comilla simple',
             'correo' => "o'brien@laboratorio.test", 'contrasena' => 'clave ficticia con apostrofo'],
            ['clave' => 'E8-P03', 'descripcion' => "Clásico ' OR '1'='1",
             'correo' => "' OR '1'='1' -- ", 'contrasena' => 'cualquier cosa'],
            ['clave' => 'E8-P03b', 'descripcion' => 'UNION: el atacante inyecta su propio hash',
             'correo' => "nadie' UNION SELECT 1, 'admin@laboratorio.test', '" . $hashDelAtacante . "' -- ",
             'contrasena' => 'clave inventada por el atacante'],
            ['clave' => 'E8-P04', 'descripcion' => 'Usuario inexistente',
             'correo' => 'no.existe@laboratorio.test', 'contrasena' => 'lo que sea'],
        ];
    }

    /**
     * Ejecuta todos los casos con ambas versiones.
     *
     * @return list<array{caso: array<string, string>, vulnerable: array<string, mixed>, segura: array<string, mixed>}>
     */
    public function ejecutarComparacion(): array
    {
        $resultados = [];
        foreach (self::obtenerCasos() as $caso) {
            $resultados[] = [
                'caso'       => $caso,
                'vulnerable' => $this->iniciarSesionVulnerable($caso['correo'], $caso['contrasena']),
                'segura'     => $this->iniciarSesionSegura($caso['correo'], $caso['contrasena']),
            ];
        }
        return $resultados;
    }

    /**
     * Conexión aislada para el laboratorio: SQLite en memoria si está
     * disponible (no toca MariaDB); si no, la conexión de la aplicación con
     * una tabla TEMPORAL.
     *
     * @return array{conexion: PDO, motor: string}
     */
    public static function crearConexionAislada(): array
    {
        if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            return ['conexion' => new PDO('sqlite::memory:'), 'motor' => 'SQLite en memoria'];
        }
        return ['conexion' => obtenerConexionBaseDatos(), 'motor' => 'MariaDB (tabla temporal)'];
    }
}

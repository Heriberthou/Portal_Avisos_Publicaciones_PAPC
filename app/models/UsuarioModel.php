<?php

declare(strict_types=1);

/**
 * Acceso a la tabla usuario.
 *
 * Todas las consultas usan sentencias preparadas con parámetros vinculados;
 * ningún valor del usuario se concatena en el SQL. Los nombres de columnas
 * son exactamente los del esquema 1.2 más las columnas de la migración 001.
 */
final class UsuarioModel
{
    /* Roles de seguridad (rol_usuario). */
    public const ROL_USUARIO = 'USUARIO';
    public const ROL_ADMINISTRADOR = 'ADMINISTRADOR';
    public const ROL_ADMINISTRADOR_PRINCIPAL = 'ADMINISTRADOR_PRINCIPAL';
    public const ROLES = [self::ROL_USUARIO, self::ROL_ADMINISTRADOR, self::ROL_ADMINISTRADOR_PRINCIPAL];

    /* Tipo de integrante (clasificación institucional, NO es un rol). */
    public const TIPO_ALUMNO = 'ALUMNO';
    public const TIPO_PROFESOR = 'PROFESOR';
    public const TIPOS_INTEGRANTE = [self::TIPO_ALUMNO, self::TIPO_PROFESOR];

    /* Estado de verificación de la clasificación. */
    public const VERIFICACION_NO_REQUERIDA = 'NO_REQUERIDA';
    public const VERIFICACION_PENDIENTE = 'PENDIENTE';
    public const VERIFICACION_VERIFICADA = 'VERIFICADA';
    public const VERIFICACION_RECHAZADA = 'RECHAZADA';

    /**
     * Columnas públicas: nunca incluye hash_contrasena_usuario.
     * nombre_usuario es una columna calculada por MariaDB (migración 003).
     */
    private const COLUMNAS_SIN_HASH = 'id_usuario, nombres_usuario, apellido_paterno_usuario, apellido_materno_usuario,
        nombre_usuario, correo_electronico_usuario, rol_usuario, tipo_integrante_usuario,
        estado_verificacion_usuario, version_sesion_usuario, fecha_registro_usuario';

    /**
     * @param PDO $conexion Conexión de config/database.php.
     */
    public function __construct(private readonly PDO $conexion)
    {
    }

    /**
     * Busca una cuenta por correo, INCLUYENDO el hash (solo para login).
     *
     * @param string $correoElectronico Correo normalizado.
     * @return array<string, mixed>|null Fila o null si no existe.
     */
    public function obtenerUsuarioPorCorreo(string $correoElectronico): ?array
    {
        $sentencia = $this->conexion->prepare(
            'SELECT ' . self::COLUMNAS_SIN_HASH . ', hash_contrasena_usuario
             FROM usuario WHERE correo_electronico_usuario = :correoElectronico LIMIT 1'
        );
        $sentencia->execute(['correoElectronico' => $correoElectronico]);
        $usuario = $sentencia->fetch();
        return $usuario === false ? null : $usuario;
    }

    /**
     * Busca una cuenta por id, SIN el hash de contraseña.
     *
     * @param int $idUsuario Identificador.
     * @return array<string, mixed>|null Fila o null si no existe.
     */
    public function obtenerUsuarioPorId(int $idUsuario): ?array
    {
        $sentencia = $this->conexion->prepare(
            'SELECT ' . self::COLUMNAS_SIN_HASH . ' FROM usuario WHERE id_usuario = :idUsuario LIMIT 1'
        );
        $sentencia->execute(['idUsuario' => $idUsuario]);
        $usuario = $sentencia->fetch();
        return $usuario === false ? null : $usuario;
    }

    /**
     * Obtiene solo el hash de una cuenta (re-autenticación de operaciones
     * sensibles).
     *
     * @param int $idUsuario Identificador.
     * @return string|null Hash o null si la cuenta no existe.
     */
    public function obtenerHashContrasena(int $idUsuario): ?string
    {
        $sentencia = $this->conexion->prepare(
            'SELECT hash_contrasena_usuario FROM usuario WHERE id_usuario = :idUsuario LIMIT 1'
        );
        $sentencia->execute(['idUsuario' => $idUsuario]);
        $hash = $sentencia->fetchColumn();
        return $hash === false ? null : (string) $hash;
    }

    /**
     * @param string $correoElectronico Correo normalizado.
     * @return bool true si ya hay una cuenta con ese correo.
     */
    public function existeCorreo(string $correoElectronico): bool
    {
        $sentencia = $this->conexion->prepare(
            'SELECT 1 FROM usuario WHERE correo_electronico_usuario = :correoElectronico LIMIT 1'
        );
        $sentencia->execute(['correoElectronico' => $correoElectronico]);
        return $sentencia->fetchColumn() !== false;
    }

    /**
     * Inserta una cuenta creada por el registro público.
     *
     * Seguridad: rol_usuario se fija en el SQL como 'USUARIO' y NO es un
     * parámetro del método; ningún dato del formulario puede alterarlo.
     * nombre_usuario no se inserta: MariaDB lo calcula a partir de las partes.
     *
     * @param string $nombresUsuario Nombre(s) validado(s).
     * @param string $apellidoPaterno Apellido paterno validado.
     * @param string|null $apellidoMaterno Apellido materno o null si no tiene.
     * @param string $correoElectronico Correo normalizado.
     * @param string $hashContrasena Hash generado por GestorContrasenas.
     * @param string $tipoIntegrante ALUMNO o PROFESOR.
     * @param string $estadoVerificacion NO_REQUERIDA o PENDIENTE.
     * @return int Id de la cuenta creada.
     * @throws PDOException Si el correo ya existe (UNIQUE), el nombre completo
     *                      excede 100 caracteres (CHECK) u otro error.
     */
    public function crearUsuario(
        string $nombresUsuario,
        string $apellidoPaterno,
        ?string $apellidoMaterno,
        string $correoElectronico,
        string $hashContrasena,
        string $tipoIntegrante,
        string $estadoVerificacion
    ): int {
        $sentencia = $this->conexion->prepare(
            "INSERT INTO usuario (nombres_usuario, apellido_paterno_usuario, apellido_materno_usuario,
                 correo_electronico_usuario, hash_contrasena_usuario,
                 rol_usuario, tipo_integrante_usuario, estado_verificacion_usuario)
             VALUES (:nombresUsuario, :apellidoPaterno, :apellidoMaterno,
                 :correoElectronico, :hashContrasena, 'USUARIO', :tipoIntegrante, :estadoVerificacion)"
        );
        $sentencia->execute([
            'nombresUsuario'     => $nombresUsuario,
            'apellidoPaterno'    => $apellidoPaterno,
            'apellidoMaterno'    => $apellidoMaterno,
            'correoElectronico'  => $correoElectronico,
            'hashContrasena'     => $hashContrasena,
            'tipoIntegrante'     => $tipoIntegrante,
            'estadoVerificacion' => $estadoVerificacion,
        ]);
        return (int) $this->conexion->lastInsertId();
    }

    /**
     * Reemplaza el hash de contraseña.
     *
     * @param int $idUsuario Cuenta afectada.
     * @param string $hashNuevo Hash nuevo.
     * @param bool $invalidarSesiones true cuando la contraseña CAMBIÓ (cierra
     *                                todas las sesiones abiertas); false
     *                                para un simple re-hash tras el login.
     * @return void
     */
    public function actualizarHashContrasena(int $idUsuario, string $hashNuevo, bool $invalidarSesiones): void
    {
        $incrementoVersion = $invalidarSesiones ? ', version_sesion_usuario = version_sesion_usuario + 1' : '';
        $sentencia = $this->conexion->prepare(
            'UPDATE usuario SET hash_contrasena_usuario = :hashNuevo' . $incrementoVersion . ' WHERE id_usuario = :idUsuario'
        );
        $sentencia->execute(['hashNuevo' => $hashNuevo, 'idUsuario' => $idUsuario]);
    }

    /**
     * Cambia el rol de seguridad e invalida las sesiones de esa cuenta.
     *
     * La autorización NO se comprueba aquí (el modelo no decide permisos):
     * debe hacerlo ServicioAdministracionUsuarios antes de llamarlo.
     *
     * @param int $idUsuario Cuenta afectada.
     * @param string $rolNuevo Uno de self::ROLES.
     * @return bool true si se modificó una fila.
     * @throws InvalidArgumentException Si el rol no existe.
     */
    public function actualizarRol(int $idUsuario, string $rolNuevo): bool
    {
        if (!in_array($rolNuevo, self::ROLES, true)) {
            throw new InvalidArgumentException('Rol no válido.');
        }
        $sentencia = $this->conexion->prepare(
            'UPDATE usuario SET rol_usuario = :rolNuevo, version_sesion_usuario = version_sesion_usuario + 1
             WHERE id_usuario = :idUsuario'
        );
        $sentencia->execute(['rolNuevo' => $rolNuevo, 'idUsuario' => $idUsuario]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * Resuelve la verificación de un PROFESOR que está PENDIENTE.
     *
     * La condición WHERE garantiza atomicidad: solo cambia si la cuenta
     * sigue siendo PROFESOR en estado PENDIENTE.
     *
     * @param int $idUsuario Cuenta afectada.
     * @param string $estadoNuevo VERIFICADA o RECHAZADA.
     * @return bool true si se aplicó el cambio.
     * @throws InvalidArgumentException Si el estado no es una resolución.
     */
    public function resolverVerificacionProfesor(int $idUsuario, string $estadoNuevo): bool
    {
        if (!in_array($estadoNuevo, [self::VERIFICACION_VERIFICADA, self::VERIFICACION_RECHAZADA], true)) {
            throw new InvalidArgumentException('Estado de verificación no válido.');
        }
        $sentencia = $this->conexion->prepare(
            "UPDATE usuario SET estado_verificacion_usuario = :estadoNuevo
             WHERE id_usuario = :idUsuario AND tipo_integrante_usuario = 'PROFESOR'
               AND estado_verificacion_usuario = 'PENDIENTE'"
        );
        $sentencia->execute(['estadoNuevo' => $estadoNuevo, 'idUsuario' => $idUsuario]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * Lista cuentas para la administración (sin hashes).
     *
     * @return list<array<string, mixed>>
     */
    public function listarUsuarios(): array
    {
        return $this->conexion
            ->query('SELECT ' . self::COLUMNAS_SIN_HASH . ' FROM usuario ORDER BY id_usuario')
            ->fetchAll();
    }

    /**
     * @return int Número de cuentas con rol ADMINISTRADOR_PRINCIPAL.
     */
    public function contarAdministradoresPrincipales(): int
    {
        return (int) $this->conexion
            ->query("SELECT COUNT(*) FROM usuario WHERE rol_usuario = 'ADMINISTRADOR_PRINCIPAL'")
            ->fetchColumn();
    }
}

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

    /* Estado de la cuenta (migración 004): ¿puede usarse en este momento? */
    public const ESTADO_INVITADA = 'INVITADA';
    public const ESTADO_ACTIVA = 'ACTIVA';
    public const ESTADO_SUSPENDIDA = 'SUSPENDIDA';
    public const ESTADO_BAJA = 'BAJA';
    public const ESTADOS_CUENTA = [self::ESTADO_INVITADA, self::ESTADO_ACTIVA, self::ESTADO_SUSPENDIDA, self::ESTADO_BAJA];

    /** Roles que exigen segundo factor antes de abrir la sesión. */
    public const ROLES_CON_SEGUNDO_FACTOR = [self::ROL_ADMINISTRADOR, self::ROL_ADMINISTRADOR_PRINCIPAL];

    /* Estado de verificación de la clasificación. */
    public const VERIFICACION_NO_REQUERIDA = 'NO_REQUERIDA';
    public const VERIFICACION_PENDIENTE = 'PENDIENTE';
    public const VERIFICACION_VERIFICADA = 'VERIFICADA';
    public const VERIFICACION_RECHAZADA = 'RECHAZADA';

    /**
     * Columnas públicas: nunca incluyen hash_contrasena_usuario ni
     * secreto_mfa_usuario. nombre_usuario es calculada (migración 003).
     * tiene_segundo_factor_usuario indica si ya configuró el segundo factor
     * sin exponer el secreto.
     */
    private const COLUMNAS_SIN_HASH = 'id_usuario, nombres_usuario, apellido_paterno_usuario, apellido_materno_usuario,
        nombre_usuario, correo_electronico_usuario, rol_usuario, estado_cuenta_usuario, tipo_integrante_usuario,
        identificador_institucional_usuario, fecha_validacion_institucional_usuario, id_usuario_autorizador,
        estado_verificacion_usuario, version_sesion_usuario, fecha_registro_usuario,
        (secreto_mfa_usuario IS NOT NULL) AS tiene_segundo_factor_usuario';

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
     * @param string $identificador Matrícula o número de empleado normalizado.
     * @return bool true si ya hay una cuenta con ese identificador.
     */
    public function existeIdentificador(string $identificador): bool
    {
        $sentencia = $this->conexion->prepare(
            'SELECT 1 FROM usuario WHERE identificador_institucional_usuario = :identificador LIMIT 1'
        );
        $sentencia->execute(['identificador' => $identificador]);
        return $sentencia->fetchColumn() !== false;
    }

    /**
     * Crea una cuenta INVITADA (sin contraseña) tras aprobar una solicitud o
     * desde la consola del servidor.
     *
     * Seguridad: rol_usuario ('USUARIO') y estado_cuenta_usuario ('INVITADA')
     * se fijan en el SQL y NO son parámetros; ningún dato del formulario
     * puede alterarlos. hash_contrasena_usuario queda NULL: la persona crea
     * su contraseña al activar. nombre_usuario lo calcula MariaDB.
     *
     * @param array{nombres: string, apellidoPaterno: string, apellidoMaterno: string|null,
     *              correo: string, tipoIntegrante: string|null, identificador: string|null} $datosCuenta
     * @param int|null $idUsuarioAutorizador Administrador que aprobó (null = consola).
     * @return int Id de la cuenta creada.
     * @throws PDOException Si el correo o el identificador ya existen (UNIQUE).
     */
    public function crearCuentaInvitada(array $datosCuenta, ?int $idUsuarioAutorizador): int
    {
        $sentencia = $this->conexion->prepare(
            "INSERT INTO usuario (nombres_usuario, apellido_paterno_usuario, apellido_materno_usuario,
                 correo_electronico_usuario, hash_contrasena_usuario, rol_usuario, estado_cuenta_usuario,
                 tipo_integrante_usuario, identificador_institucional_usuario,
                 fecha_validacion_institucional_usuario, id_usuario_autorizador, estado_verificacion_usuario)
             VALUES (:nombres, :apellidoPaterno, :apellidoMaterno, :correo, NULL, 'USUARIO', 'INVITADA',
                 :tipoIntegrante, :identificador, NOW(), :idAutorizador, 'VERIFICADA')"
        );
        $sentencia->execute([
            'nombres'         => $datosCuenta['nombres'],
            'apellidoPaterno' => $datosCuenta['apellidoPaterno'],
            'apellidoMaterno' => $datosCuenta['apellidoMaterno'],
            'correo'          => $datosCuenta['correo'],
            'tipoIntegrante'  => $datosCuenta['tipoIntegrante'],
            'identificador'   => $datosCuenta['identificador'],
            'idAutorizador'   => $idUsuarioAutorizador,
        ]);
        return (int) $this->conexion->lastInsertId();
    }

    /**
     * INVITADA -> ACTIVA con la contraseña que eligió la persona.
     *
     * La condición WHERE hace la transición atómica: solo una petición
     * puede activar la cuenta. Incrementa la versión de sesión.
     *
     * @param int $idUsuario Cuenta.
     * @param string $hashContrasena Hash nuevo.
     * @return bool true si la cuenta estaba INVITADA y se activó.
     */
    public function activarCuenta(int $idUsuario, string $hashContrasena): bool
    {
        $sentencia = $this->conexion->prepare(
            "UPDATE usuario SET hash_contrasena_usuario = :hashContrasena, estado_cuenta_usuario = 'ACTIVA',
                 version_sesion_usuario = version_sesion_usuario + 1
             WHERE id_usuario = :idUsuario AND estado_cuenta_usuario = 'INVITADA'"
        );
        $sentencia->execute(['hashContrasena' => $hashContrasena, 'idUsuario' => $idUsuario]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * Restablece la contraseña de una cuenta ACTIVA y cierra todas sus
     * sesiones. Una cuenta SUSPENDIDA o BAJA no se reactiva por esta vía.
     *
     * @param int $idUsuario Cuenta.
     * @param string $hashContrasena Hash nuevo.
     * @return bool true si se aplicó.
     */
    public function restablecerContrasena(int $idUsuario, string $hashContrasena): bool
    {
        $sentencia = $this->conexion->prepare(
            "UPDATE usuario SET hash_contrasena_usuario = :hashContrasena,
                 version_sesion_usuario = version_sesion_usuario + 1
             WHERE id_usuario = :idUsuario AND estado_cuenta_usuario = 'ACTIVA'"
        );
        $sentencia->execute(['hashContrasena' => $hashContrasena, 'idUsuario' => $idUsuario]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * Cambia el estado de la cuenta solo si sigue en el estado esperado
     * (evita decisiones simultáneas) e invalida sus sesiones.
     *
     * @param int $idUsuario Cuenta.
     * @param string $estadoAnterior Estado leído antes de decidir.
     * @param string $estadoNuevo Estado destino (uno de self::ESTADOS_CUENTA).
     * @return bool true si se aplicó.
     * @throws InvalidArgumentException Si algún estado no existe.
     */
    public function actualizarEstadoCuenta(int $idUsuario, string $estadoAnterior, string $estadoNuevo): bool
    {
        if (!in_array($estadoAnterior, self::ESTADOS_CUENTA, true) || !in_array($estadoNuevo, self::ESTADOS_CUENTA, true)) {
            throw new InvalidArgumentException('Estado de cuenta no válido.');
        }
        $sentencia = $this->conexion->prepare(
            'UPDATE usuario SET estado_cuenta_usuario = :estadoNuevo, version_sesion_usuario = version_sesion_usuario + 1
             WHERE id_usuario = :idUsuario AND estado_cuenta_usuario = :estadoAnterior'
        );
        $sentencia->execute(['estadoNuevo' => $estadoNuevo, 'idUsuario' => $idUsuario, 'estadoAnterior' => $estadoAnterior]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * Bloquea la fila de la cuenta hasta el fin de la transacción.
     *
     * @param int $idUsuario Cuenta.
     * @return array<string, mixed>|null Fila sin hash ni secreto.
     */
    public function obtenerUsuarioParaActualizar(int $idUsuario): ?array
    {
        $sentencia = $this->conexion->prepare(
            'SELECT ' . self::COLUMNAS_SIN_HASH . ' FROM usuario WHERE id_usuario = :idUsuario LIMIT 1 FOR UPDATE'
        );
        $sentencia->execute(['idUsuario' => $idUsuario]);
        $usuario = $sentencia->fetch();
        return $usuario === false ? null : $usuario;
    }

    /**
     * @param int $idUsuario Cuenta.
     * @return array{secreto: string|null, ultimoPaso: int|null} Secreto CIFRADO.
     */
    public function obtenerSegundoFactor(int $idUsuario): array
    {
        $sentencia = $this->conexion->prepare(
            'SELECT secreto_mfa_usuario, ultimo_paso_mfa_usuario FROM usuario WHERE id_usuario = :idUsuario LIMIT 1'
        );
        $sentencia->execute(['idUsuario' => $idUsuario]);
        $fila = $sentencia->fetch() ?: [];
        return [
            'secreto'    => isset($fila['secreto_mfa_usuario']) ? (string) $fila['secreto_mfa_usuario'] : null,
            'ultimoPaso' => isset($fila['ultimo_paso_mfa_usuario']) ? (int) $fila['ultimo_paso_mfa_usuario'] : null,
        ];
    }

    /**
     * Guarda el secreto TOTP cifrado SOLO si la cuenta aún no tenía uno
     * (no se puede sobrescribir un segundo factor ya configurado).
     *
     * @param int $idUsuario Cuenta.
     * @param string $secretoCifrado Resultado de CifradorSecretos::cifrar().
     * @param int $pasoAceptado Paso TOTP con el que se confirmó.
     * @return bool true si se guardó.
     */
    public function guardarSegundoFactor(int $idUsuario, string $secretoCifrado, int $pasoAceptado): bool
    {
        $sentencia = $this->conexion->prepare(
            'UPDATE usuario SET secreto_mfa_usuario = :secreto, fecha_activacion_mfa_usuario = NOW(),
                 ultimo_paso_mfa_usuario = :paso
             WHERE id_usuario = :idUsuario AND secreto_mfa_usuario IS NULL'
        );
        $sentencia->execute(['secreto' => $secretoCifrado, 'paso' => $pasoAceptado, 'idUsuario' => $idUsuario]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * Registra el paso TOTP usado; falla si ese código (o uno posterior) ya
     * se aceptó, así un código interceptado no sirve dos veces.
     *
     * @param int $idUsuario Cuenta.
     * @param int $pasoAceptado Paso verificado.
     * @return bool true si el paso es nuevo.
     */
    public function registrarPasoSegundoFactor(int $idUsuario, int $pasoAceptado): bool
    {
        $sentencia = $this->conexion->prepare(
            'UPDATE usuario SET ultimo_paso_mfa_usuario = :paso
             WHERE id_usuario = :idUsuario AND (ultimo_paso_mfa_usuario IS NULL OR ultimo_paso_mfa_usuario < :pasoComparado)'
        );
        $sentencia->execute(['paso' => $pasoAceptado, 'idUsuario' => $idUsuario, 'pasoComparado' => $pasoAceptado]);
        return $sentencia->rowCount() === 1;
    }

    /**
     * Elimina el segundo factor (restablecimiento autorizado) y cierra las
     * sesiones de la cuenta.
     *
     * @param int $idUsuario Cuenta.
     * @return bool true si la cuenta tenía segundo factor.
     */
    public function eliminarSegundoFactor(int $idUsuario): bool
    {
        $sentencia = $this->conexion->prepare(
            'UPDATE usuario SET secreto_mfa_usuario = NULL, fecha_activacion_mfa_usuario = NULL,
                 ultimo_paso_mfa_usuario = NULL, version_sesion_usuario = version_sesion_usuario + 1
             WHERE id_usuario = :idUsuario AND secreto_mfa_usuario IS NOT NULL'
        );
        $sentencia->execute(['idUsuario' => $idUsuario]);
        return $sentencia->rowCount() === 1;
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

    /**
     * @param int $idUsuarioExcluido Cuenta que se está modificando.
     * @return int Principales ACTIVOS distintos de esa cuenta.
     */
    public function contarOtrosPrincipalesActivos(int $idUsuarioExcluido): int
    {
        $sentencia = $this->conexion->prepare(
            "SELECT COUNT(*) FROM usuario WHERE rol_usuario = 'ADMINISTRADOR_PRINCIPAL'
               AND estado_cuenta_usuario = 'ACTIVA' AND id_usuario <> :idUsuario"
        );
        $sentencia->execute(['idUsuario' => $idUsuarioExcluido]);
        return (int) $sentencia->fetchColumn();
    }
}

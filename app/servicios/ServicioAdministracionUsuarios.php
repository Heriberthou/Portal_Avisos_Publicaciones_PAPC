<?php

declare(strict_types=1);

/**
 * Operaciones protegidas sobre cuentas: cambio de rol, estado de la cuenta,
 * restablecimiento del segundo factor, verificación de profesores (cuentas
 * anteriores a la migración 004) e inicialización del administrador principal.
 *
 * Cada operación:
 *  1. Revalida el permiso aunque la ruta ya lo haya hecho (defensa en
 *     profundidad: el servicio puede llamarse desde otros puntos).
 *  2. Valida los datos de entrada contra listas cerradas.
 *  3. Ejecuta el cambio y su auditoría en UNA transacción: o se guardan
 *     ambos o ninguno.
 */
final class ServicioAdministracionUsuarios
{
    /**
     * Transiciones de estado permitidas (especificación 1.3, 6.2).
     * BAJA es definitiva; INVITADA -> ACTIVA solo ocurre al activar con el
     * enlace (no la decide un administrador).
     */
    private const TRANSICIONES_ESTADO = [
        UsuarioModel::ESTADO_INVITADA   => [UsuarioModel::ESTADO_BAJA],
        UsuarioModel::ESTADO_ACTIVA     => [UsuarioModel::ESTADO_SUSPENDIDA, UsuarioModel::ESTADO_BAJA],
        UsuarioModel::ESTADO_SUSPENDIDA => [UsuarioModel::ESTADO_ACTIVA, UsuarioModel::ESTADO_BAJA],
        UsuarioModel::ESTADO_BAJA       => [],
    ];

    /**
     * @param PDO $conexion Conexión para transacciones.
     * @param UsuarioModel $usuarioModel Acceso a cuentas.
     * @param AuditoriaModel $auditoriaModel Registro de eventos.
     * @param RoleMiddleware $roleMiddleware Consulta de la matriz RBAC.
     * @param TokenCuentaModel $tokenCuentaModel Enlaces de activación y recuperación.
     * @param CodigoRecuperacionMfaModel $codigoRecuperacionModel Códigos del segundo factor.
     */
    public function __construct(
        private readonly PDO $conexion,
        private readonly UsuarioModel $usuarioModel,
        private readonly AuditoriaModel $auditoriaModel,
        private readonly RoleMiddleware $roleMiddleware,
        private readonly TokenCuentaModel $tokenCuentaModel,
        private readonly CodigoRecuperacionMfaModel $codigoRecuperacionModel
    ) {
    }

    /**
     * @param string $estadoActual Estado de la cuenta.
     * @return list<string> Estados a los que puede pasar.
     */
    public static function obtenerTransicionesPermitidas(string $estadoActual): array
    {
        return self::TRANSICIONES_ESTADO[$estadoActual] ?? [];
    }

    /**
     * Suspende, reactiva o da de baja una cuenta.
     *
     * Reglas:
     *  - ADMINISTRADOR y PRINCIPAL pueden cambiar cuentas USUARIO; solo el
     *    PRINCIPAL puede cambiar cuentas administrativas.
     *  - Nadie cambia el estado de su propia cuenta.
     *  - Exige re-autenticación del actor.
     *  - Debe quedar al menos un ADMINISTRADOR_PRINCIPAL activo.
     *  - Cierra las sesiones de la cuenta e invalida sus enlaces pendientes.
     *
     * @param array<string, mixed> $usuarioActor Administrador autenticado.
     * @param int $idUsuarioObjetivo Cuenta a modificar.
     * @param string $estadoNuevo Estado destino.
     * @param string $contrasenaActor Contraseña del actor.
     * @return array{exito: bool, mensaje: string}
     */
    public function cambiarEstadoCuenta(array $usuarioActor, int $idUsuarioObjetivo, string $estadoNuevo, string $contrasenaActor): array
    {
        $idUsuarioActor = (int) $usuarioActor['id_usuario'];
        $rolActor = (string) $usuarioActor['rol_usuario'];

        if (!$this->roleMiddleware->tienePermiso($rolActor, 'gestionar_estado_cuenta')) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'SIN_PERMISO_ESTADO', $estadoNuevo);
            return $this->resultado(false, 'No tiene permiso para realizar esta acción.');
        }
        if (!in_array($estadoNuevo, UsuarioModel::ESTADOS_CUENTA, true)) {
            return $this->resultado(false, 'El estado solicitado no es válido.');
        }
        if ($idUsuarioObjetivo === $idUsuarioActor) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'CAMBIO_PROPIO_ESTADO', $estadoNuevo);
            return $this->resultado(false, 'No puede cambiar el estado de su propia cuenta.');
        }
        if (!$this->reautenticar($idUsuarioActor, $contrasenaActor)) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'REAUTENTICACION_FALLIDA', $estadoNuevo);
            return $this->resultado(false, 'La contraseña de confirmación es incorrecta.');
        }

        $this->conexion->beginTransaction();
        try {
            $usuarioObjetivo = $this->usuarioModel->obtenerUsuarioParaActualizar($idUsuarioObjetivo);
            if ($usuarioObjetivo === null) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'La cuenta indicada no existe.');
            }
            $rolObjetivo = (string) $usuarioObjetivo['rol_usuario'];
            $estadoAnterior = (string) $usuarioObjetivo['estado_cuenta_usuario'];

            if ($rolObjetivo !== UsuarioModel::ROL_USUARIO && !$this->roleMiddleware->tienePermiso($rolActor, 'asignar_rol_administrativo')) {
                $this->conexion->rollBack();
                $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'ESTADO_CUENTA_ADMINISTRATIVA', $estadoNuevo);
                return $this->resultado(false, 'Solo el administrador principal puede cambiar el estado de una cuenta administrativa.');
            }
            if (!in_array($estadoNuevo, self::obtenerTransicionesPermitidas($estadoAnterior), true)) {
                $this->conexion->rollBack();
                return $this->resultado(false, "No se puede pasar una cuenta {$estadoAnterior} a {$estadoNuevo}.");
            }
            if ($rolObjetivo === UsuarioModel::ROL_ADMINISTRADOR_PRINCIPAL && $estadoNuevo !== UsuarioModel::ESTADO_ACTIVA
                && $this->usuarioModel->contarOtrosPrincipalesActivos($idUsuarioObjetivo) === 0) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'Debe quedar al menos un administrador principal activo.');
            }

            if (!$this->usuarioModel->actualizarEstadoCuenta($idUsuarioObjetivo, $estadoAnterior, $estadoNuevo)) {
                throw new RuntimeException('El estado de la cuenta cambió durante la operación.');
            }
            if ($estadoNuevo !== UsuarioModel::ESTADO_ACTIVA) {
                $this->tokenCuentaModel->invalidarTokens($idUsuarioObjetivo);
            }
            $this->auditoriaModel->registrarEventoAuditoria($idUsuarioActor, AuditoriaModel::ACCION_CAMBIO_ESTADO_CUENTA, 'usuario', $idUsuarioObjetivo, [
                'estadoAnterior' => $estadoAnterior,
                'estadoNuevo'    => $estadoNuevo,
            ]);
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $excepcion;
        }

        return $this->resultado(true, 'Estado actualizado. Las sesiones abiertas de esa cuenta se cerraron.');
    }

    /**
     * Elimina el segundo factor de OTRA cuenta administrativa para que lo
     * vuelva a configurar (p. ej. perdió su teléfono y sus códigos). Solo el
     * principal, con re-autenticación; nunca sobre su propia cuenta.
     *
     * @param array<string, mixed> $usuarioActor Principal autenticado.
     * @param int $idUsuarioObjetivo Cuenta a restablecer.
     * @param string $contrasenaActor Contraseña del actor.
     * @return array{exito: bool, mensaje: string}
     */
    public function restablecerSegundoFactor(array $usuarioActor, int $idUsuarioObjetivo, string $contrasenaActor): array
    {
        $idUsuarioActor = (int) $usuarioActor['id_usuario'];
        if (!$this->roleMiddleware->tienePermiso((string) $usuarioActor['rol_usuario'], 'asignar_rol_administrativo')) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'SIN_PERMISO_SEGUNDO_FACTOR', 'RESTABLECER');
            return $this->resultado(false, 'No tiene permiso para realizar esta acción.');
        }
        if ($idUsuarioObjetivo === $idUsuarioActor) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'SEGUNDO_FACTOR_PROPIO', 'RESTABLECER');
            return $this->resultado(false, 'No puede restablecer su propio segundo factor. Debe hacerlo otro administrador principal o la consola del servidor.');
        }
        if (!$this->reautenticar($idUsuarioActor, $contrasenaActor)) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'REAUTENTICACION_FALLIDA', 'RESTABLECER');
            return $this->resultado(false, 'La contraseña de confirmación es incorrecta.');
        }
        return $this->eliminarSegundoFactor($idUsuarioObjetivo, $idUsuarioActor, 'WEB')
            ? $this->resultado(true, 'Segundo factor restablecido. La cuenta deberá configurarlo de nuevo al iniciar sesión.')
            : $this->resultado(false, 'Esa cuenta no tiene un segundo factor configurado.');
    }

    /**
     * Restablecimiento de emergencia desde la consola del servidor
     * (herramientas/restablecer-segundo-factor.php), p. ej. cuando el único
     * principal perdió su autenticador y sus códigos.
     *
     * @param string $correoNormalizado Cuenta a restablecer.
     * @return array{exito: bool, mensaje: string}
     */
    public function restablecerSegundoFactorDesdeConsola(string $correoNormalizado): array
    {
        $usuario = $this->usuarioModel->obtenerUsuarioPorCorreo($correoNormalizado);
        if ($usuario === null) {
            return $this->resultado(false, 'No existe una cuenta con ese correo.');
        }
        return $this->eliminarSegundoFactor((int) $usuario['id_usuario'], null, 'CONSOLA')
            ? $this->resultado(true, 'Segundo factor restablecido. La cuenta deberá configurarlo de nuevo al iniciar sesión.')
            : $this->resultado(false, 'Esa cuenta no tiene un segundo factor configurado.');
    }

    /**
     * Asigna o revoca un rol de seguridad.
     *
     * Reglas:
     *  - Solo ADMINISTRADOR_PRINCIPAL (permiso asignar_rol_administrativo).
     *  - Exige re-autenticación con la contraseña del actor.
     *  - Nadie puede cambiar su propio rol (evita auto-degradación accidental
     *    y garantiza que siempre quede al menos un principal: el actor).
     *  - Incrementa version_sesion_usuario del afectado: sus sesiones
     *    abiertas se invalidan en la siguiente solicitud.
     *
     * @param array<string, mixed> $usuarioActor Usuario autenticado que solicita el cambio.
     * @param int $idUsuarioObjetivo Cuenta a modificar.
     * @param string $rolNuevo Rol solicitado.
     * @param string $contrasenaActor Contraseña del actor para re-autenticación.
     * @return array{exito: bool, mensaje: string}
     */
    public function cambiarRolUsuario(array $usuarioActor, int $idUsuarioObjetivo, string $rolNuevo, string $contrasenaActor): array
    {
        $idUsuarioActor = (int) $usuarioActor['id_usuario'];

        if (!$this->roleMiddleware->tienePermiso((string) $usuarioActor['rol_usuario'], 'asignar_rol_administrativo')) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'SIN_PERMISO', $rolNuevo);
            return $this->resultado(false, 'No tiene permiso para cambiar roles.');
        }
        if (!in_array($rolNuevo, UsuarioModel::ROLES, true)) {
            return $this->resultado(false, 'El rol solicitado no es válido.');
        }
        if ($idUsuarioObjetivo === $idUsuarioActor) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'CAMBIO_PROPIO_ROL', $rolNuevo);
            return $this->resultado(false, 'No puede cambiar su propio rol. Debe hacerlo otro administrador principal.');
        }

        if (!$this->reautenticar($idUsuarioActor, $contrasenaActor)) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'REAUTENTICACION_FALLIDA', $rolNuevo);
            return $this->resultado(false, 'La contraseña de confirmación es incorrecta.');
        }

        $this->conexion->beginTransaction();
        try {
            $usuarioObjetivo = $this->usuarioModel->obtenerUsuarioParaActualizar($idUsuarioObjetivo);
            if ($usuarioObjetivo === null) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'La cuenta indicada no existe.');
            }
            $rolAnterior = (string) $usuarioObjetivo['rol_usuario'];
            if ($rolAnterior === $rolNuevo) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'La cuenta ya tiene ese rol.');
            }
            if ($rolNuevo !== UsuarioModel::ROL_USUARIO && $usuarioObjetivo['estado_cuenta_usuario'] !== UsuarioModel::ESTADO_ACTIVA) {
                $this->conexion->rollBack();
                $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'CUENTA_NO_ACTIVA', $rolNuevo);
                return $this->resultado(false, 'Solo se puede asignar un rol administrativo a una cuenta activa.');
            }

            $this->usuarioModel->actualizarRol($idUsuarioObjetivo, $rolNuevo);
            $this->auditoriaModel->registrarEventoAuditoria(
                $idUsuarioActor,
                AuditoriaModel::ACCION_CAMBIO_ROL,
                'usuario',
                $idUsuarioObjetivo,
                ['rolAnterior' => $rolAnterior, 'rolNuevo' => $rolNuevo]
            );
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $excepcion;
        }

        return $this->resultado(true, 'Rol actualizado. Las sesiones abiertas de esa cuenta se cerrarán.');
    }

    /**
     * Resuelve la verificación de la clasificación PROFESOR.
     *
     * No modifica el rol: un profesor VERIFICADO sigue siendo USUARIO. El
     * actor no puede resolver su propia verificación.
     *
     * @param array<string, mixed> $usuarioActor Administrador autenticado.
     * @param int $idUsuarioObjetivo Cuenta a resolver.
     * @param string $decision VERIFICADA o RECHAZADA.
     * @return array{exito: bool, mensaje: string}
     */
    public function resolverVerificacion(array $usuarioActor, int $idUsuarioObjetivo, string $decision): array
    {
        $idUsuarioActor = (int) $usuarioActor['id_usuario'];

        if (!$this->roleMiddleware->tienePermiso((string) $usuarioActor['rol_usuario'], 'verificar_clasificacion')) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'SIN_PERMISO_VERIFICACION', $decision);
            return $this->resultado(false, 'No tiene permiso para verificar clasificaciones.');
        }
        if (!in_array($decision, [UsuarioModel::VERIFICACION_VERIFICADA, UsuarioModel::VERIFICACION_RECHAZADA], true)) {
            return $this->resultado(false, 'La decisión no es válida.');
        }
        if ($idUsuarioObjetivo === $idUsuarioActor) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'AUTOVERIFICACION', $decision);
            return $this->resultado(false, 'No puede resolver su propia verificación.');
        }

        $this->conexion->beginTransaction();
        try {
            if (!$this->usuarioModel->resolverVerificacionProfesor($idUsuarioObjetivo, $decision)) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'La cuenta no tiene una verificación de profesor pendiente.');
            }
            $this->auditoriaModel->registrarEventoAuditoria(
                $idUsuarioActor,
                AuditoriaModel::ACCION_CAMBIO_VERIFICACION,
                'usuario',
                $idUsuarioObjetivo,
                ['estadoAnterior' => UsuarioModel::VERIFICACION_PENDIENTE, 'estadoNuevo' => $decision]
            );
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $excepcion;
        }

        return $this->resultado(true, 'Verificación registrada.');
    }

    /**
     * Crea el PRIMER ADMINISTRADOR_PRINCIPAL. Solo se invoca desde la consola
     * del servidor (herramientas/inicializar-administrador-principal.php) y
     * solo funciona si todavía no existe ninguno (especificación 7.6).
     *
     *  - Si ya hay una cuenta ACTIVA con ese correo, se promueve.
     *  - Si no existe, se crea INVITADA con los datos indicados y se genera un
     *    enlace de activación: la persona crea su propia contraseña.
     *  Nunca hay contraseñas predeterminadas ni públicas.
     *
     * @param string $correoNormalizado Correo de la cuenta.
     * @param array{nombres: string, apellidoPaterno: string, apellidoMaterno: string|null}|null $datosNuevaCuenta
     *        Datos para crearla si no existe.
     * @return array{exito: bool, mensaje: string, tokenActivacion: string|null}
     */
    public function inicializarAdministradorPrincipal(string $correoNormalizado, ?array $datosNuevaCuenta = null): array
    {
        $tokenActivacion = null;
        $this->conexion->beginTransaction();
        try {
            // Bloquea las filas de principales para evitar dos inicializaciones simultáneas.
            $this->conexion->query("SELECT id_usuario FROM usuario WHERE rol_usuario = 'ADMINISTRADOR_PRINCIPAL' FOR UPDATE")->fetchAll();
            if ($this->usuarioModel->contarAdministradoresPrincipales() > 0) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'Ya existe un administrador principal. Use la operación auditada de cambio de rol.') + ['tokenActivacion' => null];
            }

            $usuario = $this->usuarioModel->obtenerUsuarioPorCorreo($correoNormalizado);
            if ($usuario !== null) {
                if ($usuario['estado_cuenta_usuario'] !== UsuarioModel::ESTADO_ACTIVA) {
                    $this->conexion->rollBack();
                    return $this->resultado(false, 'Esa cuenta no está ACTIVA. Actívela antes de promoverla.') + ['tokenActivacion' => null];
                }
                $idUsuario = (int) $usuario['id_usuario'];
                $rolAnterior = (string) $usuario['rol_usuario'];
            } elseif ($datosNuevaCuenta !== null) {
                $idUsuario = $this->usuarioModel->crearCuentaInvitada([
                    'nombres'         => $datosNuevaCuenta['nombres'],
                    'apellidoPaterno' => $datosNuevaCuenta['apellidoPaterno'],
                    'apellidoMaterno' => $datosNuevaCuenta['apellidoMaterno'],
                    'correo'          => $correoNormalizado,
                    'tipoIntegrante'  => null,
                    'identificador'   => null,
                ], null);
                $rolAnterior = UsuarioModel::ROL_USUARIO;
                $tokenActivacion = GeneradorTokens::generarToken();
                $this->tokenCuentaModel->crearToken($idUsuario, TokenCuentaModel::FINALIDAD_ACTIVACION, GeneradorTokens::calcularHashToken($tokenActivacion), VIGENCIA_TOKEN_ACTIVACION_SEGUNDOS);
            } else {
                $this->conexion->rollBack();
                return $this->resultado(false, 'No existe una cuenta con ese correo. Indique nombre(s) y apellido paterno para crearla.') + ['tokenActivacion' => null];
            }

            $this->usuarioModel->actualizarRol($idUsuario, UsuarioModel::ROL_ADMINISTRADOR_PRINCIPAL);
            $this->auditoriaModel->registrarEventoAuditoria(
                null,
                AuditoriaModel::ACCION_INICIALIZACION_ADMINISTRADOR_PRINCIPAL,
                'usuario',
                $idUsuario,
                ['rolAnterior' => $rolAnterior, 'rolNuevo' => UsuarioModel::ROL_ADMINISTRADOR_PRINCIPAL, 'canal' => 'CONSOLA', 'cuentaNueva' => $tokenActivacion !== null]
            );
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $excepcion;
        }
        $mensaje = $tokenActivacion === null
            ? 'Cuenta promovida a ADMINISTRADOR_PRINCIPAL. Deberá configurar su segundo factor al iniciar sesión.'
            : 'Cuenta ADMINISTRADOR_PRINCIPAL creada como INVITADA. Debe activarla con el enlace y después configurar su segundo factor.';
        return $this->resultado(true, $mensaje) + ['tokenActivacion' => $tokenActivacion];
    }

    /**
     * @param int $idUsuarioActor Cuenta que confirma.
     * @param string $contrasenaActor Contraseña escrita.
     * @return bool true si la contraseña es correcta.
     */
    private function reautenticar(int $idUsuarioActor, string $contrasenaActor): bool
    {
        $hashActor = $this->usuarioModel->obtenerHashContrasena($idUsuarioActor);
        return $hashActor !== null && $hashActor !== '' && GestorContrasenas::verificar($contrasenaActor, $hashActor);
    }

    /**
     * @param int $idUsuarioObjetivo Cuenta.
     * @param int|null $idUsuarioActor Quién lo hizo (null = consola).
     * @param string $canal WEB o CONSOLA.
     * @return bool true si la cuenta tenía segundo factor.
     */
    private function eliminarSegundoFactor(int $idUsuarioObjetivo, ?int $idUsuarioActor, string $canal): bool
    {
        $this->conexion->beginTransaction();
        try {
            if (!$this->usuarioModel->eliminarSegundoFactor($idUsuarioObjetivo)) {
                $this->conexion->rollBack();
                return false;
            }
            $this->codigoRecuperacionModel->eliminarCodigos($idUsuarioObjetivo);
            $this->auditoriaModel->registrarEventoAuditoria($idUsuarioActor, AuditoriaModel::ACCION_SEGUNDO_FACTOR_RESTABLECIDO, 'usuario', $idUsuarioObjetivo, ['canal' => $canal]);
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $excepcion;
        }
        return true;
    }

    /**
     * @param int $idUsuarioActor Quién lo intentó.
     * @param int $idUsuarioObjetivo Cuenta afectada.
     * @param string $motivo Causa de la denegación.
     * @param string $valorSolicitado Rol o estado solicitado.
     * @return void
     */
    private function auditarDenegacionPrivilegio(int $idUsuarioActor, int $idUsuarioObjetivo, string $motivo, string $valorSolicitado): void
    {
        $this->auditoriaModel->registrarEventoAuditoria(
            $idUsuarioActor,
            AuditoriaModel::ACCION_INTENTO_CAMBIO_PRIVILEGIO_DENEGADO,
            'usuario',
            $idUsuarioObjetivo,
            ['motivo' => $motivo, 'valorSolicitado' => mb_substr($valorSolicitado, 0, 40)]
        );
    }

    /**
     * @param bool $exito Si la operación se completó.
     * @param string $mensaje Mensaje para el usuario.
     * @return array{exito: bool, mensaje: string}
     */
    private function resultado(bool $exito, string $mensaje): array
    {
        return ['exito' => $exito, 'mensaje' => $mensaje];
    }
}

<?php

declare(strict_types=1);

/**
 * Operaciones protegidas sobre cuentas: cambio de rol, verificación de
 * profesores e inicialización del administrador principal.
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
     * @param PDO $conexion Conexión para transacciones.
     * @param UsuarioModel $usuarioModel Acceso a cuentas.
     * @param AuditoriaModel $auditoriaModel Registro de eventos.
     * @param RoleMiddleware $roleMiddleware Consulta de la matriz RBAC.
     */
    public function __construct(
        private readonly PDO $conexion,
        private readonly UsuarioModel $usuarioModel,
        private readonly AuditoriaModel $auditoriaModel,
        private readonly RoleMiddleware $roleMiddleware
    ) {
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
            return $this->resultado(false, 'No tienes permiso para cambiar roles.');
        }
        if (!in_array($rolNuevo, UsuarioModel::ROLES, true)) {
            return $this->resultado(false, 'El rol solicitado no es válido.');
        }
        if ($idUsuarioObjetivo === $idUsuarioActor) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'CAMBIO_PROPIO_ROL', $rolNuevo);
            return $this->resultado(false, 'No puedes cambiar tu propio rol. Debe hacerlo otro administrador principal.');
        }

        $hashActor = $this->usuarioModel->obtenerHashContrasena($idUsuarioActor);
        if ($hashActor === null || !GestorContrasenas::verificar($contrasenaActor, $hashActor)) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'REAUTENTICACION_FALLIDA', $rolNuevo);
            return $this->resultado(false, 'La contraseña de confirmación es incorrecta.');
        }

        $this->conexion->beginTransaction();
        try {
            $usuarioObjetivo = $this->usuarioModel->obtenerUsuarioPorId($idUsuarioObjetivo);
            if ($usuarioObjetivo === null) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'La cuenta indicada no existe.');
            }
            $rolAnterior = (string) $usuarioObjetivo['rol_usuario'];
            if ($rolAnterior === $rolNuevo) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'La cuenta ya tiene ese rol.');
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
            return $this->resultado(false, 'No tienes permiso para verificar clasificaciones.');
        }
        if (!in_array($decision, [UsuarioModel::VERIFICACION_VERIFICADA, UsuarioModel::VERIFICACION_RECHAZADA], true)) {
            return $this->resultado(false, 'La decisión no es válida.');
        }
        if ($idUsuarioObjetivo === $idUsuarioActor) {
            $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'AUTOVERIFICACION', $decision);
            return $this->resultado(false, 'No puedes resolver tu propia verificación.');
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
     * Promueve una cuenta YA REGISTRADA a ADMINISTRADOR_PRINCIPAL cuando aún
     * no existe ninguno. Solo se invoca desde la consola del servidor
     * (herramientas/inicializar-administrador-principal.php).
     *
     * No crea cuentas ni maneja contraseñas: la persona se registra antes
     * por el formulario público con su propia contraseña.
     *
     * @param string $correoNormalizado Correo de la cuenta a promover.
     * @return array{exito: bool, mensaje: string}
     */
    public function inicializarAdministradorPrincipal(string $correoNormalizado): array
    {
        $this->conexion->beginTransaction();
        try {
            // Bloquea las filas de principales para evitar dos inicializaciones simultáneas.
            $this->conexion->query("SELECT id_usuario FROM usuario WHERE rol_usuario = 'ADMINISTRADOR_PRINCIPAL' FOR UPDATE")->fetchAll();
            if ($this->usuarioModel->contarAdministradoresPrincipales() > 0) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'Ya existe un administrador principal. Use la operación auditada de cambio de rol.');
            }
            $usuario = $this->usuarioModel->obtenerUsuarioPorCorreo($correoNormalizado);
            if ($usuario === null) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'No existe una cuenta con ese correo. Regístrela primero desde el formulario.');
            }
            $idUsuario = (int) $usuario['id_usuario'];
            $this->usuarioModel->actualizarRol($idUsuario, UsuarioModel::ROL_ADMINISTRADOR_PRINCIPAL);
            $this->auditoriaModel->registrarEventoAuditoria(
                null,
                AuditoriaModel::ACCION_INICIALIZACION_ADMINISTRADOR_PRINCIPAL,
                'usuario',
                $idUsuario,
                ['rolAnterior' => $usuario['rol_usuario'], 'rolNuevo' => UsuarioModel::ROL_ADMINISTRADOR_PRINCIPAL, 'canal' => 'CONSOLA']
            );
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $excepcion;
        }
        return $this->resultado(true, 'Cuenta promovida a ADMINISTRADOR_PRINCIPAL.');
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

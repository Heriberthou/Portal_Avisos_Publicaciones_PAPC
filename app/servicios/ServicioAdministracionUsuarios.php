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
 *  2. Exige re-autenticación con límite de intentos (hallazgo R-05).
 *  3. Dentro de UNA transacción bloquea, en este orden fijo, las filas de los
 *     principales, la del actor y la del objetivo; relee al ACTOR y comprueba
 *     que siga activo, con la misma versión de sesión y con el permiso
 *     (hallazgo R-01). Así dos principales que se degradan al mismo tiempo se
 *     ejecutan uno después del otro y el segundo ya no tiene permiso.
 *  4. Comprueba que siempre quede al menos un principal activo.
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

    private const AMBITO_REAUTENTICACION = 'reautenticacion';

    private const REAUTENTICACION_CORRECTA = 'CORRECTA';
    private const REAUTENTICACION_INCORRECTA = 'INCORRECTA';
    private const REAUTENTICACION_BLOQUEADA = 'BLOQUEADA';

    private const MENSAJE_PERMISOS_CAMBIARON = 'Sus permisos cambiaron mientras se procesaba la operación. Inicie sesión de nuevo.';

    /**
     * @param PDO $conexion Conexión para transacciones.
     * @param UsuarioModel $usuarioModel Acceso a cuentas.
     * @param AuditoriaModel $auditoriaModel Registro de eventos.
     * @param RoleMiddleware $roleMiddleware Consulta de la matriz RBAC.
     * @param TokenCuentaModel $tokenCuentaModel Enlaces de cuenta.
     * @param CodigoRecuperacionMfaModel $codigoRecuperacionModel Códigos del segundo factor.
     * @param LimitadorIntentos $limitadorIntentos Límite de re-autenticación.
     * @param ServicioSegundoFactor $servicioSegundoFactor Emisión del enlace de enrolamiento.
     * @param ServicioCorreo $servicioCorreo Envío de ese enlace.
     */
    public function __construct(
        private readonly PDO $conexion,
        private readonly UsuarioModel $usuarioModel,
        private readonly AuditoriaModel $auditoriaModel,
        private readonly RoleMiddleware $roleMiddleware,
        private readonly TokenCuentaModel $tokenCuentaModel,
        private readonly CodigoRecuperacionMfaModel $codigoRecuperacionModel,
        private readonly LimitadorIntentos $limitadorIntentos,
        private readonly ServicioSegundoFactor $servicioSegundoFactor,
        private readonly ServicioCorreo $servicioCorreo
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
     * Reactivar una cuenta heredada pendiente (suspendida por la migración
     * 005, sin validación institucional) registra en ese momento la
     * validación y a quien la autorizó (hallazgo R-03). Además la deja POR
     * ACTIVAR y sin contraseña: su contraseña se creó con el registro anterior,
     * que no aplicaba la política vigente, así que la persona crea una nueva
     * con el enlace de activación que se le envía.
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

        if (!$this->roleMiddleware->tienePermiso((string) $usuarioActor['rol_usuario'], 'gestionar_estado_cuenta')) {
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
        $errorReautenticacion = $this->exigirReautenticacion($idUsuarioActor, $idUsuarioObjetivo, $contrasenaActor, $estadoNuevo);
        if ($errorReautenticacion !== null) {
            return $errorReautenticacion;
        }

        $esValidacionHeredada = false;
        $tokenActivacion = null;
        $this->conexion->beginTransaction();
        try {
            $actorVigente = $this->bloquearYRevalidarActor($usuarioActor, 'gestionar_estado_cuenta');
            if ($actorVigente === null) {
                $this->conexion->rollBack();
                $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'ACTOR_SIN_PERMISO_VIGENTE', $estadoNuevo);
                return $this->resultado(false, self::MENSAJE_PERMISOS_CAMBIARON);
            }
            $usuarioObjetivo = $this->usuarioModel->obtenerUsuarioParaActualizar($idUsuarioObjetivo);
            if ($usuarioObjetivo === null) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'La cuenta indicada no existe.');
            }
            $rolObjetivo = (string) $usuarioObjetivo['rol_usuario'];
            $estadoAnterior = (string) $usuarioObjetivo['estado_cuenta_usuario'];

            if ($rolObjetivo !== UsuarioModel::ROL_USUARIO && !$this->roleMiddleware->tienePermiso((string) $actorVigente['rol_usuario'], 'asignar_rol_administrativo')) {
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

            $esValidacionHeredada = $estadoNuevo === UsuarioModel::ESTADO_ACTIVA && UsuarioModel::esCuentaHeredadaPendiente($usuarioObjetivo);
            $aplicado = $esValidacionHeredada
                ? $this->usuarioModel->convertirEnInvitadaSinContrasena($idUsuarioObjetivo, $estadoAnterior)
                : $this->usuarioModel->actualizarEstadoCuenta($idUsuarioObjetivo, $estadoAnterior, $estadoNuevo);
            if (!$aplicado) {
                throw new RuntimeException('La cuenta cambió mientras se procesaba la operación. Revise sus datos e intente de nuevo.');
            }
            if ($esValidacionHeredada) {
                $this->usuarioModel->registrarValidacionInstitucional($idUsuarioObjetivo, $idUsuarioActor);
                $this->auditoriaModel->registrarEventoAuditoria($idUsuarioActor, AuditoriaModel::ACCION_VALIDACION_CUENTA_HEREDADA, 'usuario', $idUsuarioObjetivo, ['canal' => 'WEB']);
                $tokenActivacion = $this->emitirActivacionHeredada($idUsuarioObjetivo);
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
            $this->revertir();
            throw $excepcion;
        }

        if ($esValidacionHeredada) {
            $enviado = $this->servicioCorreo->enviarInvitacionActivacion((string) $usuarioObjetivo['correo_electronico_usuario'], (string) $usuarioObjetivo['nombres_usuario'], (string) $tokenActivacion);
            return $this->resultado(true, 'Cuenta validada. Se registró que usted autorizó su pertenencia institucional. Su contraseña anterior ya no sirve: '
                . $this->describirEnvioActivacion($enviado, $idUsuarioObjetivo));
        }
        return $this->resultado(true, 'Estado actualizado. Las sesiones abiertas de esa cuenta se cerraron.');
    }

    /**
     * Elimina el segundo factor de OTRA cuenta administrativa para que lo
     * vuelva a configurar (p. ej. perdió su teléfono y sus códigos). Solo el
     * principal, con re-autenticación; nunca sobre su propia cuenta. El
     * nuevo enlace de configuración se envía al correo de esa cuenta (R-02).
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
            return $this->resultado(false, 'No puede restablecer su propia verificación en dos pasos. Debe hacerlo otro administrador principal.');
        }
        $errorReautenticacion = $this->exigirReautenticacion($idUsuarioActor, $idUsuarioObjetivo, $contrasenaActor, 'RESTABLECER_SEGUNDO_FACTOR');
        if ($errorReautenticacion !== null) {
            return $errorReautenticacion;
        }

        $this->conexion->beginTransaction();
        try {
            if ($this->bloquearYRevalidarActor($usuarioActor, 'asignar_rol_administrativo') === null) {
                $this->conexion->rollBack();
                return $this->resultado(false, self::MENSAJE_PERMISOS_CAMBIARON);
            }
            $resultadoRestablecer = $this->restablecerEnTransaccion($idUsuarioObjetivo, $idUsuarioActor, 'WEB');
            if ($resultadoRestablecer === null) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'Esa cuenta no tiene activada la verificación en dos pasos.');
            }
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            $this->revertir();
            throw $excepcion;
        }

        $enviado = $this->servicioCorreo->enviarEnlaceEnrolamiento($resultadoRestablecer['correo'], $resultadoRestablecer['nombres'], $resultadoRestablecer['token']);
        return $this->resultado(true, 'Verificación en dos pasos restablecida. ' . $this->describirEnvioEnlace($enviado, $idUsuarioObjetivo));
    }

    /**
     * Restablecimiento de emergencia desde la consola del servidor
     * (herramientas/restablecer-segundo-factor.php), p. ej. cuando el único
     * principal perdió su autenticador y sus códigos. El enlace nuevo se
     * entrega al operador de la consola.
     *
     * @param string $correoNormalizado Cuenta a restablecer.
     * @return array{exito: bool, mensaje: string, tokenEnrolamiento: string|null}
     */
    public function restablecerSegundoFactorDesdeConsola(string $correoNormalizado): array
    {
        $usuario = $this->usuarioModel->obtenerUsuarioPorCorreo($correoNormalizado);
        if ($usuario === null) {
            return $this->resultado(false, 'No existe una cuenta con ese correo.') + ['tokenEnrolamiento' => null];
        }
        $this->conexion->beginTransaction();
        try {
            $resultadoRestablecer = $this->restablecerEnTransaccion((int) $usuario['id_usuario'], null, 'CONSOLA');
            if ($resultadoRestablecer === null) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'Esa cuenta no tiene activada la verificación en dos pasos.') + ['tokenEnrolamiento' => null];
            }
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            $this->revertir();
            throw $excepcion;
        }
        return $this->resultado(true, 'Verificación en dos pasos restablecida. La persona deberá activarla de nuevo con el enlace.') + ['tokenEnrolamiento' => $resultadoRestablecer['token']];
    }

    /**
     * Asigna o revoca un rol de seguridad.
     *
     * Reglas:
     *  - Solo ADMINISTRADOR_PRINCIPAL (permiso asignar_rol_administrativo),
     *    revalidado dentro de la transacción (R-01).
     *  - Exige re-autenticación con límite de intentos (R-05).
     *  - Nadie cambia su propio rol y siempre queda un principal activo.
     *  - Solo cuentas ACTIVAS reciben un rol administrativo; si aún no tienen
     *    segundo factor, se les envía a su correo el enlace para configurarlo (R-02).
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
        $errorReautenticacion = $this->exigirReautenticacion($idUsuarioActor, $idUsuarioObjetivo, $contrasenaActor, $rolNuevo);
        if ($errorReautenticacion !== null) {
            return $errorReautenticacion;
        }

        $tokenEnrolamiento = null;
        $this->conexion->beginTransaction();
        try {
            if ($this->bloquearYRevalidarActor($usuarioActor, 'asignar_rol_administrativo') === null) {
                $this->conexion->rollBack();
                $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'ACTOR_SIN_PERMISO_VIGENTE', $rolNuevo);
                return $this->resultado(false, self::MENSAJE_PERMISOS_CAMBIARON);
            }
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
            if ($rolNuevo !== UsuarioModel::ROL_USUARIO && !UsuarioModel::puedeIniciarSesion($usuarioObjetivo)) {
                $this->conexion->rollBack();
                $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'CUENTA_NO_ACTIVA', $rolNuevo);
                return $this->resultado(false, 'Solo se puede asignar un rol administrativo a una cuenta activa y validada.');
            }
            if ($rolAnterior === UsuarioModel::ROL_ADMINISTRADOR_PRINCIPAL
                && $this->usuarioModel->contarOtrosPrincipalesActivos($idUsuarioObjetivo) === 0) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'Debe quedar al menos un administrador principal activo.');
            }

            $this->usuarioModel->actualizarRol($idUsuarioObjetivo, $rolNuevo);
            if ($rolNuevo === UsuarioModel::ROL_USUARIO) {
                $this->tokenCuentaModel->invalidarTokens($idUsuarioObjetivo, TokenCuentaModel::FINALIDAD_ENROLAMIENTO_MFA);
            } elseif ((int) $usuarioObjetivo['tiene_segundo_factor_usuario'] !== 1) {
                $tokenEnrolamiento = $this->servicioSegundoFactor->emitirEnlaceEnrolamiento($idUsuarioObjetivo);
                $this->auditoriaModel->registrarEventoAuditoria($idUsuarioActor, AuditoriaModel::ACCION_ENLACE_ENROLAMIENTO_EMITIDO, 'usuario', $idUsuarioObjetivo, ['canal' => 'CORREO']);
            }
            $this->auditoriaModel->registrarEventoAuditoria(
                $idUsuarioActor,
                AuditoriaModel::ACCION_CAMBIO_ROL,
                'usuario',
                $idUsuarioObjetivo,
                ['rolAnterior' => $rolAnterior, 'rolNuevo' => $rolNuevo]
            );
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            $this->revertir();
            throw $excepcion;
        }

        $mensaje = 'Rol actualizado. Las sesiones abiertas de esa cuenta se cerrarán.';
        if ($tokenEnrolamiento !== null) {
            $enviado = $this->servicioCorreo->enviarEnlaceEnrolamiento((string) $usuarioObjetivo['correo_electronico_usuario'], (string) $usuarioObjetivo['nombres_usuario'], $tokenEnrolamiento);
            $mensaje .= ' ' . $this->describirEnvioEnlace($enviado, $idUsuarioObjetivo);
        }
        return $this->resultado(true, $mensaje);
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
            $this->revertir();
            throw $excepcion;
        }

        return $this->resultado(true, 'Verificación registrada.');
    }

    /**
     * Habilita al ADMINISTRADOR_PRINCIPAL desde la consola del servidor
     * (herramientas/inicializar-administrador-principal.php). Funciona solo
     * si no hay ningún principal ACTIVO ni INVITADO pendiente de activar
     * (especificación 7.6, AC-27); los principales SUSPENDIDOS o en BAJA no
     * cuentan, para poder recuperar el acceso en una emergencia.
     *
     *  - Cuenta existente ACTIVA y validada, o heredada pendiente de
     *    validación (migración 005): la consola registra la validación, la
     *    deja ACTIVA y la promueve.
     *  - Cuenta inexistente: se crea INVITADA y se genera su enlace de activación.
     *  En todos los casos se genera el enlace para configurar el segundo
     *  factor (R-02), que la consola entrega al operador. Nunca hay
     *  contraseñas predeterminadas.
     *
     * @param string $correoNormalizado Correo de la cuenta.
     * @param array{nombres: string, apellidoPaterno: string, apellidoMaterno: string|null}|null $datosNuevaCuenta
     *        Datos para crearla si no existe.
     * @return array{exito: bool, mensaje: string, tokenActivacion: string|null, tokenEnrolamiento: string|null}
     */
    public function inicializarAdministradorPrincipal(string $correoNormalizado, ?array $datosNuevaCuenta = null): array
    {
        $sinTokens = ['tokenActivacion' => null, 'tokenEnrolamiento' => null];
        $tokenActivacion = null;
        $this->conexion->beginTransaction();
        try {
            // Bloquea las filas de principales para evitar dos inicializaciones simultáneas.
            $this->conexion->query("SELECT id_usuario FROM usuario WHERE rol_usuario = 'ADMINISTRADOR_PRINCIPAL' FOR UPDATE")->fetchAll();
            if ($this->usuarioModel->contarPrincipalesVigentes() > 0) {
                $this->conexion->rollBack();
                return $this->resultado(false, 'Ya existe un administrador principal (activo o por activar). Use la operación auditada de cambio de rol.') + $sinTokens;
            }

            $usuario = $this->usuarioModel->obtenerUsuarioPorCorreo($correoNormalizado);
            if ($usuario !== null) {
                $idUsuario = (int) $usuario['id_usuario'];
                if (UsuarioModel::esCuentaHeredadaPendiente($usuario)) {
                    // Igual que en la web: la contraseña del registro anterior no se conserva.
                    $this->usuarioModel->convertirEnInvitadaSinContrasena($idUsuario, UsuarioModel::ESTADO_SUSPENDIDA);
                    $this->usuarioModel->registrarValidacionInstitucional($idUsuario, null);
                    $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_VALIDACION_CUENTA_HEREDADA, 'usuario', $idUsuario, ['canal' => 'CONSOLA']);
                    $tokenActivacion = $this->emitirActivacionHeredada($idUsuario);
                } elseif (!UsuarioModel::puedeIniciarSesion($usuario)) {
                    $this->conexion->rollBack();
                    return $this->resultado(false, 'Esa cuenta no está activa. Actívela antes de asignarle un rol administrativo.') + $sinTokens;
                }
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
                return $this->resultado(false, 'No existe una cuenta con ese correo. Indique nombre(s) y apellido paterno para crearla.') + $sinTokens;
            }

            if ($rolAnterior !== UsuarioModel::ROL_ADMINISTRADOR_PRINCIPAL) {
                $this->usuarioModel->actualizarRol($idUsuario, UsuarioModel::ROL_ADMINISTRADOR_PRINCIPAL);
            }
            $tokenEnrolamiento = $this->servicioSegundoFactor->emitirEnlaceEnrolamiento($idUsuario);
            $this->auditoriaModel->registrarEventoAuditoria(
                null,
                AuditoriaModel::ACCION_INICIALIZACION_ADMINISTRADOR_PRINCIPAL,
                'usuario',
                $idUsuario,
                ['rolAnterior' => $rolAnterior, 'rolNuevo' => UsuarioModel::ROL_ADMINISTRADOR_PRINCIPAL, 'canal' => 'CONSOLA', 'cuentaNueva' => $tokenActivacion !== null]
            );
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            $this->revertir();
            throw $excepcion;
        }
        $mensaje = $tokenActivacion === null
            ? 'Cuenta habilitada como administrador principal. Debe activar la verificación en dos pasos con el enlace.'
            : 'Cuenta de administrador principal pendiente de activar. Use el primer enlace para crear su contraseña y el segundo para activar la verificación en dos pasos.';
        return $this->resultado(true, $mensaje) + ['tokenActivacion' => $tokenActivacion, 'tokenEnrolamiento' => $tokenEnrolamiento];
    }

    /**
     * R-01: bloquea, en orden fijo, las filas de los principales y la del
     * actor, y relee al actor desde la BD. Devuelve null si ya no puede
     * realizar la operación (cambió su rol, su estado o su versión de sesión).
     *
     * @param array<string, mixed> $usuarioActor Actor según la sesión.
     * @param string $permiso Permiso requerido.
     * @return array<string, mixed>|null Actor vigente o null.
     */
    private function bloquearYRevalidarActor(array $usuarioActor, string $permiso): ?array
    {
        $this->conexion->query("SELECT id_usuario FROM usuario WHERE rol_usuario = 'ADMINISTRADOR_PRINCIPAL' FOR UPDATE")->fetchAll();
        $actorVigente = $this->usuarioModel->obtenerUsuarioParaActualizar((int) $usuarioActor['id_usuario']);
        if ($actorVigente === null
            || !UsuarioModel::puedeIniciarSesion($actorVigente)
            || (int) $actorVigente['version_sesion_usuario'] !== (int) ($usuarioActor['version_sesion_usuario'] ?? -1)
            || !$this->roleMiddleware->tienePermiso((string) $actorVigente['rol_usuario'], $permiso)) {
            return null;
        }
        return $actorVigente;
    }

    /**
     * R-05: re-autenticación con límite de intentos por actor y origen. El
     * intento se reserva antes de comprobar la contraseña (R-06).
     *
     * @param int $idUsuarioActor Cuenta que confirma.
     * @param int $idUsuarioObjetivo Cuenta afectada (para la auditoría).
     * @param string $contrasenaActor Contraseña escrita.
     * @param string $valorSolicitado Operación pedida (para la auditoría).
     * @return array{exito: bool, mensaje: string}|null null si la contraseña es correcta.
     */
    private function exigirReautenticacion(int $idUsuarioActor, int $idUsuarioObjetivo, string $contrasenaActor, string $valorSolicitado): ?array
    {
        $resultado = $this->reautenticar($idUsuarioActor, $contrasenaActor);
        if ($resultado === self::REAUTENTICACION_CORRECTA) {
            return null;
        }
        if ($resultado === self::REAUTENTICACION_BLOQUEADA) {
            $this->auditoriaModel->registrarEventoAuditoria($idUsuarioActor, AuditoriaModel::ACCION_REAUTENTICACION_BLOQUEADA, 'usuario', $idUsuarioObjetivo, [
                'valorSolicitado' => mb_substr($valorSolicitado, 0, 40),
            ]);
            return $this->resultado(false, 'Demasiados intentos de confirmación. Por seguridad, espere unos minutos antes de volver a intentarlo.');
        }
        $this->auditarDenegacionPrivilegio($idUsuarioActor, $idUsuarioObjetivo, 'REAUTENTICACION_FALLIDA', $valorSolicitado);
        return $this->resultado(false, 'La contraseña de confirmación es incorrecta.');
    }

    /**
     * @param int $idUsuarioActor Cuenta que confirma.
     * @param string $contrasenaActor Contraseña escrita.
     * @return string REAUTENTICACION_CORRECTA, _INCORRECTA o _BLOQUEADA.
     */
    private function reautenticar(int $idUsuarioActor, string $contrasenaActor): string
    {
        $reserva = $this->limitadorIntentos->reservarIntento(self::AMBITO_REAUTENTICACION, (string) $idUsuarioActor, Solicitud::obtenerOrigen(), MAX_INTENTOS_REAUTENTICACION, MAX_INTENTOS_LOGIN_ORIGEN);
        if ($reserva === null) {
            return self::REAUTENTICACION_BLOQUEADA;
        }
        $hashActor = $this->usuarioModel->obtenerHashContrasena($idUsuarioActor);
        if ($hashActor !== null && $hashActor !== '' && GestorContrasenas::verificar($contrasenaActor, $hashActor)) {
            $this->limitadorIntentos->liberarReserva($reserva);
            return self::REAUTENTICACION_CORRECTA;
        }
        // La reserva queda como intento fallido.
        return self::REAUTENTICACION_INCORRECTA;
    }

    /**
     * Elimina el factor, sus códigos y emite el enlace nuevo (dentro de la
     * transacción de quien llama).
     *
     * @param int $idUsuarioObjetivo Cuenta.
     * @param int|null $idUsuarioActor Quién lo hizo (null = consola).
     * @param string $canal WEB o CONSOLA.
     * @return array{token: string, correo: string, nombres: string}|null null si no tenía factor.
     */
    private function restablecerEnTransaccion(int $idUsuarioObjetivo, ?int $idUsuarioActor, string $canal): ?array
    {
        $usuarioObjetivo = $this->usuarioModel->obtenerUsuarioParaActualizar($idUsuarioObjetivo);
        if ($usuarioObjetivo === null || !$this->usuarioModel->eliminarSegundoFactor($idUsuarioObjetivo)) {
            return null;
        }
        $this->codigoRecuperacionModel->eliminarCodigos($idUsuarioObjetivo);
        $token = $this->servicioSegundoFactor->emitirEnlaceEnrolamiento($idUsuarioObjetivo);
        $this->auditoriaModel->registrarEventoAuditoria($idUsuarioActor, AuditoriaModel::ACCION_SEGUNDO_FACTOR_RESTABLECIDO, 'usuario', $idUsuarioObjetivo, ['canal' => $canal]);
        return [
            'token'   => $token,
            'correo'  => (string) $usuarioObjetivo['correo_electronico_usuario'],
            'nombres' => (string) $usuarioObjetivo['nombres_usuario'],
        ];
    }

    /**
     * Enlace de activación para una cuenta heredada recién validada (dentro
     * de la transacción de quien llama).
     *
     * @param int $idUsuario Cuenta.
     * @return string Token en claro (se envía por correo o lo imprime la consola).
     */
    private function emitirActivacionHeredada(int $idUsuario): string
    {
        $token = GeneradorTokens::generarToken();
        $this->tokenCuentaModel->crearToken($idUsuario, TokenCuentaModel::FINALIDAD_ACTIVACION, GeneradorTokens::calcularHashToken($token), VIGENCIA_TOKEN_ACTIVACION_SEGUNDOS);
        return $token;
    }

    /**
     * Texto honesto sobre el envío del enlace de activación (R-07).
     *
     * @param bool $enviado Resultado de ServicioCorreo.
     * @param int $idUsuarioObjetivo Cuenta destinataria (para la auditoría).
     * @return string Frase para el mensaje al administrador.
     */
    private function describirEnvioActivacion(bool $enviado, int $idUsuarioObjetivo): string
    {
        if (!$enviado) {
            $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_ENVIO_CORREO_FALLIDO, 'usuario', $idUsuarioObjetivo, ['tipoCorreo' => 'ACTIVACION']);
            return 'no fue posible enviar el enlace para crear una contraseña nueva; la persona puede pedirlo con "¿Olvidó su contraseña?".';
        }
        return $this->servicioCorreo->esModoBuzonPruebas()
            ? 'el enlace para crear una contraseña nueva quedó en el buzón de pruebas (modo de demostración; no se envió por correo).'
            : 'se envió a su correo un enlace para crear una contraseña nueva.';
    }

    /**
     * Texto honesto sobre el envío del enlace de configuración (R-07).
     *
     * @param bool $enviado Resultado de ServicioCorreo.
     * @param int $idUsuarioObjetivo Cuenta destinataria (para la auditoría).
     * @return string Frase para el mensaje al administrador.
     */
    private function describirEnvioEnlace(bool $enviado, int $idUsuarioObjetivo): string
    {
        if (!$enviado) {
            $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_ENVIO_CORREO_FALLIDO, 'usuario', $idUsuarioObjetivo, ['tipoCorreo' => 'ENROLAMIENTO_MFA']);
            return 'No fue posible enviar el enlace para activar la verificación en dos pasos; la persona puede pedir otro al iniciar sesión.';
        }
        return $this->servicioCorreo->esModoBuzonPruebas()
            ? 'El enlace para activar la verificación en dos pasos quedó en el buzón de pruebas (modo de demostración; no se envió por correo).'
            : 'Se envió a su correo el enlace para activar la verificación en dos pasos.';
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
     * @return void
     */
    private function revertir(): void
    {
        if ($this->conexion->inTransaction()) {
            $this->conexion->rollBack();
        }
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

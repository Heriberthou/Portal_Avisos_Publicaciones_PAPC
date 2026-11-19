<?php

declare(strict_types=1);

/**
 * Administración de cuentas.
 *
 *  - /admin/usuarios (ADMINISTRADOR y PRINCIPAL): listado, suspender,
 *    reactivar o dar de baja; verificación de profesores de cuentas
 *    anteriores a la migración 004.
 *  - /admin/bitacora (ADMINISTRADOR y PRINCIPAL): eventos recientes.
 *  - /admin/roles (solo PRINCIPAL): asignar o revocar roles y restablecer
 *    el segundo factor de otro administrador.
 *
 * Ocultar botones es solo comodidad visual: cada POST se vuelve a autorizar
 * en el enrutador y en el servicio.
 */
final class AdminUsuarioController
{
    use ControlFormulario;

    private const CAMPOS_CAMBIO_ROL = ['token_csrf', 'id_usuario', 'rol_nuevo', 'contrasena_confirmacion'];
    private const CAMPOS_CAMBIO_ESTADO = ['token_csrf', 'id_usuario', 'estado_nuevo', 'contrasena_confirmacion'];
    private const CAMPOS_SEGUNDO_FACTOR = ['token_csrf', 'id_usuario', 'contrasena_confirmacion'];
    private const CAMPOS_VERIFICACION = ['token_csrf', 'id_usuario', 'decision'];

    /**
     * @param ServicioAdministracionUsuarios $servicioAdministracion Reglas administrativas.
     * @param UsuarioModel $usuarioModel Consulta de cuentas.
     * @param AuditoriaModel $auditoriaModel Bitácora.
     * @param GestorSesion $gestorSesion Mensajes flash.
     * @param RoleMiddleware $roleMiddleware Consulta de permisos para la vista.
     */
    public function __construct(
        private readonly ServicioAdministracionUsuarios $servicioAdministracion,
        private readonly UsuarioModel $usuarioModel,
        private readonly AuditoriaModel $auditoriaModel,
        private readonly GestorSesion $gestorSesion,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    /**
     * GET /admin/usuarios
     *
     * @param array<string, mixed>|null $usuarioActual Administrador autenticado.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Listado de cuentas.
     */
    public function listarUsuarios(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return Respuesta::vista('admin/usuarios', [
            'tituloPagina'     => 'Administración de cuentas',
            'usuarioActual'    => $usuarioActual,
            'mensajes'         => $this->gestorSesion->extraerMensajesFlash(),
            'listaUsuarios'    => $this->usuarioModel->listarUsuarios(),
            'esPrincipal'      => $this->roleMiddleware->tienePermiso((string) $usuarioActual['rol_usuario'], 'asignar_rol_administrativo'),
        ]);
    }

    /**
     * GET /admin/roles (solo PRINCIPAL)
     *
     * @param array<string, mixed>|null $usuarioActual Principal autenticado.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Gestión de roles.
     */
    public function listarRoles(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return Respuesta::vista('admin/roles', [
            'tituloPagina'     => 'Roles y segundo factor',
            'usuarioActual'    => $usuarioActual,
            'mensajes'         => $this->gestorSesion->extraerMensajesFlash(),
            'listaUsuarios'    => $this->usuarioModel->listarUsuarios(),
            'rolesDisponibles' => UsuarioModel::ROLES,
        ]);
    }

    /**
     * GET /admin/bitacora
     *
     * @param array<string, mixed>|null $usuarioActual Administrador autenticado.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Eventos recientes.
     */
    public function mostrarBitacora(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return Respuesta::vista('admin/bitacora', [
            'tituloPagina'  => 'Bitácora',
            'usuarioActual' => $usuarioActual,
            'eventos'       => $this->auditoriaModel->listarEventosRecientes(150),
        ]);
    }

    /**
     * POST /admin/roles/cambiar
     *
     * @param array<string, mixed>|null $usuarioActual Principal autenticado.
     * @param array<mixed> $datosFormulario Campos.
     * @return Respuesta Redirección con el resultado.
     */
    public function cambiarRol(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, self::CAMPOS_CAMBIO_ROL, 'cambiar-rol');
        $idUsuarioObjetivo = ValidadorEntrada::obtenerEnteroPositivo($datosFormulario['id_usuario'] ?? null);
        $rolNuevo = ValidadorEntrada::obtenerTexto($datosFormulario, 'rol_nuevo');
        $contrasena = ValidadorEntrada::obtenerTexto($datosFormulario, 'contrasena_confirmacion', false);
        if ($idUsuarioObjetivo === null || $rolNuevo === null || $contrasena === null) {
            throw ExcepcionHttp::solicitudInvalida();
        }
        return $this->redirigirConResultado(
            $this->servicioAdministracion->cambiarRolUsuario((array) $usuarioActual, $idUsuarioObjetivo, $rolNuevo, $contrasena),
            '/admin/roles'
        );
    }

    /**
     * POST /admin/roles/restablecer-segundo-factor
     *
     * @param array<string, mixed>|null $usuarioActual Principal autenticado.
     * @param array<mixed> $datosFormulario Campos.
     * @return Respuesta Redirección con el resultado.
     */
    public function restablecerSegundoFactor(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, self::CAMPOS_SEGUNDO_FACTOR, 'restablecer-segundo-factor');
        $idUsuarioObjetivo = ValidadorEntrada::obtenerEnteroPositivo($datosFormulario['id_usuario'] ?? null);
        $contrasena = ValidadorEntrada::obtenerTexto($datosFormulario, 'contrasena_confirmacion', false);
        if ($idUsuarioObjetivo === null || $contrasena === null) {
            throw ExcepcionHttp::solicitudInvalida();
        }
        return $this->redirigirConResultado(
            $this->servicioAdministracion->restablecerSegundoFactor((array) $usuarioActual, $idUsuarioObjetivo, $contrasena),
            '/admin/roles'
        );
    }

    /**
     * POST /admin/usuarios/estado
     *
     * @param array<string, mixed>|null $usuarioActual Administrador autenticado.
     * @param array<mixed> $datosFormulario Campos.
     * @return Respuesta Redirección con el resultado.
     */
    public function cambiarEstado(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, self::CAMPOS_CAMBIO_ESTADO, 'cambiar-estado');
        $idUsuarioObjetivo = ValidadorEntrada::obtenerEnteroPositivo($datosFormulario['id_usuario'] ?? null);
        $estadoNuevo = ValidadorEntrada::obtenerTexto($datosFormulario, 'estado_nuevo');
        $contrasena = ValidadorEntrada::obtenerTexto($datosFormulario, 'contrasena_confirmacion', false);
        if ($idUsuarioObjetivo === null || $estadoNuevo === null || $contrasena === null) {
            throw ExcepcionHttp::solicitudInvalida();
        }
        return $this->redirigirConResultado(
            $this->servicioAdministracion->cambiarEstadoCuenta((array) $usuarioActual, $idUsuarioObjetivo, $estadoNuevo, $contrasena),
            '/admin/usuarios'
        );
    }

    /**
     * POST /admin/usuarios/verificacion
     *
     * @param array<string, mixed>|null $usuarioActual Administrador autenticado.
     * @param array<mixed> $datosFormulario Campos.
     * @return Respuesta Redirección con el resultado.
     */
    public function resolverVerificacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, self::CAMPOS_VERIFICACION, 'verificacion-profesor');
        $idUsuarioObjetivo = ValidadorEntrada::obtenerEnteroPositivo($datosFormulario['id_usuario'] ?? null);
        $decision = ValidadorEntrada::obtenerTexto($datosFormulario, 'decision');
        if ($idUsuarioObjetivo === null || $decision === null) {
            throw ExcepcionHttp::solicitudInvalida();
        }
        return $this->redirigirConResultado(
            $this->servicioAdministracion->resolverVerificacion((array) $usuarioActual, $idUsuarioObjetivo, $decision),
            '/admin/usuarios'
        );
    }

    /**
     * @param array{exito: bool, mensaje: string} $resultado Resultado del servicio.
     * @param string $rutaDestino Página a la que se vuelve.
     * @return Respuesta Redirección.
     */
    private function redirigirConResultado(array $resultado, string $rutaDestino): Respuesta
    {
        return $this->redirigirConMensaje($rutaDestino, $resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
    }
}

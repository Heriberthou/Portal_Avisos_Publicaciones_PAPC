<?php

declare(strict_types=1);

/**
 * Controlador de la administración básica de cuentas.
 *
 * Alcance mínimo necesario para operar y probar el RBAC:
 *  - Listar cuentas (ADMINISTRADOR y ADMINISTRADOR_PRINCIPAL).
 *  - Resolver la verificación de profesores (ADMINISTRADOR y PRINCIPAL).
 *  - Cambiar roles (solo ADMINISTRADOR_PRINCIPAL, con re-autenticación).
 *
 * Se separa de AuthController porque son operaciones administrativas, no
 * de autenticación; cada ruta declara su permiso en public/index.php.
 */
final class AdminUsuarioController
{
    /** Campos aceptados por el formulario de cambio de rol. */
    private const CAMPOS_CAMBIO_ROL = ['token_csrf', 'id_usuario', 'rol_nuevo', 'contrasena_confirmacion'];

    /** Campos aceptados por el formulario de verificación. */
    private const CAMPOS_VERIFICACION = ['token_csrf', 'id_usuario', 'decision'];

    /**
     * @param ServicioAdministracionUsuarios $servicioAdministracion Reglas administrativas.
     * @param UsuarioModel $usuarioModel Consulta de cuentas.
     * @param GestorSesion $gestorSesion Mensajes flash.
     * @param RoleMiddleware $roleMiddleware Consulta de permisos para la vista.
     */
    public function __construct(
        private readonly ServicioAdministracionUsuarios $servicioAdministracion,
        private readonly UsuarioModel $usuarioModel,
        private readonly GestorSesion $gestorSesion,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    /**
     * GET /admin/usuarios
     *
     * @param array<string, mixed>|null $usuarioActual Administrador autenticado.
     * @param array<mixed> $datosFormulario Parámetros GET (no se usan).
     * @return Respuesta Listado de cuentas.
     */
    public function listarUsuarios(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return Respuesta::vista('admin/usuarios', [
            'tituloPagina'        => 'Administración de cuentas',
            'usuarioActual'       => $usuarioActual,
            'mensajes'            => $this->gestorSesion->extraerMensajesFlash(),
            'listaUsuarios'       => $this->usuarioModel->listarUsuarios(),
            'puedeAsignarRoles'   => $this->roleMiddleware->tienePermiso((string) $usuarioActual['rol_usuario'], 'asignar_rol_administrativo'),
            'rolesDisponibles'    => UsuarioModel::ROLES,
        ]);
    }

    /**
     * POST /admin/usuarios/rol
     *
     * @param array<string, mixed>|null $usuarioActual Administrador principal autenticado.
     * @param array<mixed> $datosFormulario Campos del formulario.
     * @return Respuesta Redirección al listado con el resultado.
     * @throws ExcepcionHttp 400 si los campos no son válidos.
     */
    public function cambiarRol(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirSoloCampos($datosFormulario, self::CAMPOS_CAMBIO_ROL);
        $idUsuarioObjetivo = ValidadorEntrada::obtenerEnteroPositivo($datosFormulario['id_usuario'] ?? null);
        $rolNuevo = ValidadorEntrada::obtenerTexto($datosFormulario, 'rol_nuevo');
        $contrasenaConfirmacion = ValidadorEntrada::obtenerTexto($datosFormulario, 'contrasena_confirmacion', false);
        if ($idUsuarioObjetivo === null || $rolNuevo === null || $contrasenaConfirmacion === null) {
            throw ExcepcionHttp::solicitudInvalida();
        }

        $resultado = $this->servicioAdministracion->cambiarRolUsuario(
            (array) $usuarioActual,
            $idUsuarioObjetivo,
            $rolNuevo,
            $contrasenaConfirmacion
        );
        return $this->redirigirConResultado($resultado);
    }

    /**
     * POST /admin/usuarios/verificacion
     *
     * @param array<string, mixed>|null $usuarioActual Administrador autenticado.
     * @param array<mixed> $datosFormulario Campos del formulario.
     * @return Respuesta Redirección al listado con el resultado.
     * @throws ExcepcionHttp 400 si los campos no son válidos.
     */
    public function resolverVerificacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirSoloCampos($datosFormulario, self::CAMPOS_VERIFICACION);
        $idUsuarioObjetivo = ValidadorEntrada::obtenerEnteroPositivo($datosFormulario['id_usuario'] ?? null);
        $decision = ValidadorEntrada::obtenerTexto($datosFormulario, 'decision');
        if ($idUsuarioObjetivo === null || $decision === null) {
            throw ExcepcionHttp::solicitudInvalida();
        }

        $resultado = $this->servicioAdministracion->resolverVerificacion((array) $usuarioActual, $idUsuarioObjetivo, $decision);
        return $this->redirigirConResultado($resultado);
    }

    /**
     * @param array<mixed> $datosFormulario Campos recibidos.
     * @param list<string> $camposPermitidos Lista blanca.
     * @return void
     * @throws ExcepcionHttp 400 si hay campos no permitidos.
     */
    private function exigirSoloCampos(array $datosFormulario, array $camposPermitidos): void
    {
        if (ValidadorEntrada::obtenerParametrosInesperados($datosFormulario, $camposPermitidos) !== []) {
            throw ExcepcionHttp::solicitudInvalida();
        }
    }

    /**
     * @param array{exito: bool, mensaje: string} $resultado Resultado del servicio.
     * @return Respuesta Redirección al listado.
     */
    private function redirigirConResultado(array $resultado): Respuesta
    {
        $this->gestorSesion->guardarMensajeFlash($resultado['exito'] ? 'exito' : 'error', $resultado['mensaje']);
        return Respuesta::redireccion('/admin/usuarios');
    }
}

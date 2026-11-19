<?php

declare(strict_types=1);

/**
 * Inicio y cierre de sesión, y pantalla de destino por rol (pantallas B e I
 * de la especificación 1.3).
 *
 * Un solo formulario para todos: el servidor decide el destino según el
 * rol leído de la base de datos. Las cuentas administrativas pasan antes
 * por el segundo factor (SegundoFactorController).
 *
 * Cuando una acción se ejecuta, el enrutador ya comprobó método HTTP,
 * autenticación/estado de invitado, permiso RBAC y token CSRF.
 */
final class AuthController
{
    use ControlFormulario;

    /** Mensaje único para cualquier fallo de credenciales o de estado (sin enumeración). */
    private const MENSAJE_CREDENCIALES_INVALIDAS = 'No fue posible iniciar sesión. Verifique los datos e intente de nuevo.';

    /** Mensaje cuando se alcanza el límite de intentos. */
    private const MENSAJE_LIMITE = 'Por seguridad, espere unos minutos antes de volver a intentarlo o use "¿Olvidó su contraseña?".';

    /**
     * @param ServicioAutenticacion $servicioAutenticacion Reglas de autenticación.
     * @param GestorSesion $gestorSesion Mensajes flash y ruta pendiente.
     * @param AuditoriaModel $auditoriaModel Registro de manipulaciones.
     * @param RoleMiddleware $roleMiddleware Consulta de permisos para la vista.
     */
    public function __construct(
        private readonly ServicioAutenticacion $servicioAutenticacion,
        private readonly GestorSesion $gestorSesion,
        private readonly AuditoriaModel $auditoriaModel,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    /**
     * GET /iniciar-sesion
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null (ruta de invitado).
     * @param array<mixed> $datosFormulario Parámetros GET (no se usan).
     * @return Respuesta Formulario de inicio de sesión.
     */
    public function mostrarFormularioLogin(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return $this->vistaLogin('', null, 200);
    }

    /**
     * POST /iniciar-sesion
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null (ruta de invitado).
     * @param array<mixed> $datosFormulario Campos del formulario.
     * @return Respuesta Redirección si tuvo éxito; formulario con error si no.
     * @throws ExcepcionHttp 400 si hay parámetros no permitidos.
     */
    public function procesarLogin(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, ValidadorEntrada::CAMPOS_LOGIN, 'login', $this->auditoriaModel);

        $validacion = ValidadorEntrada::validarDatosLogin($datosFormulario);
        if (!$validacion['valido']) {
            return $this->vistaLogin($validacion['correoPrevio'], null, 422, $validacion['errores']);
        }

        $correoElectronico = $validacion['datos']['correo_electronico'];
        // Se lee antes de iniciar sesión porque crearSesion() limpia la sesión anónima.
        $rutaPendiente = $this->gestorSesion->extraerRutaPendiente();

        $resultado = $this->servicioAutenticacion->iniciarSesion(
            $correoElectronico,
            $validacion['datos']['contrasena'],
            Solicitud::obtenerOrigen()
        );

        return match ($resultado) {
            ServicioAutenticacion::RESULTADO_EXITO => $this->redirigirConMensaje($rutaPendiente ?? '/inicio', 'exito', 'Sesión iniciada correctamente.'),
            ServicioAutenticacion::RESULTADO_REQUIERE_SEGUNDO_FACTOR => Respuesta::redireccion('/verificar-segundo-factor'),
            ServicioAutenticacion::RESULTADO_REQUIERE_CONFIGURAR_SEGUNDO_FACTOR => Respuesta::redireccion('/configurar-segundo-factor'),
            ServicioAutenticacion::RESULTADO_LIMITE_ALCANZADO => $this->vistaLogin($correoElectronico, self::MENSAJE_LIMITE, 429),
            default => $this->vistaLogin($correoElectronico, self::MENSAJE_CREDENCIALES_INVALIDAS, 401),
        };
    }

    /**
     * POST /cerrar-sesion
     *
     * @param array<string, mixed>|null $usuarioActual Usuario autenticado.
     * @param array<mixed> $datosFormulario Solo token_csrf.
     * @return Respuesta Redirección al formulario de inicio de sesión.
     */
    public function cerrarSesion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->servicioAutenticacion->cerrarSesion((array) $usuarioActual);
        return $this->redirigirConMensaje('/iniciar-sesion', 'exito', 'Sesión cerrada correctamente.');
    }

    /**
     * GET /inicio — pantalla I: destino según el rol (demostrativa).
     *
     * Muestra datos NO sensibles: nombre, tipo, rol y estado; el
     * identificador institucional va enmascarado. Las opciones visibles
     * dependen del rol, pero la barrera real es el servidor (RBAC).
     *
     * @param array<string, mixed>|null $usuarioActual Usuario autenticado.
     * @param array<mixed> $datosFormulario Parámetros GET (no se usan).
     * @return Respuesta Vista de inicio.
     */
    public function mostrarInicio(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $rol = (string) $usuarioActual['rol_usuario'];
        return Respuesta::vista('inicio', [
            'tituloPagina'            => 'Inicio',
            'usuarioActual'           => $usuarioActual,
            'mensajes'                => $this->gestorSesion->extraerMensajesFlash(),
            'puedeRevisarSolicitudes' => $this->roleMiddleware->tienePermiso($rol, 'revisar_solicitudes'),
            'puedeAdministrar'        => $this->roleMiddleware->tienePermiso($rol, 'administrar_cuentas'),
            'puedeConsultarBitacora'  => $this->roleMiddleware->tienePermiso($rol, 'consultar_bitacora'),
            'puedeAsignarRoles'       => $this->roleMiddleware->tienePermiso($rol, 'asignar_rol_administrativo'),
            'minutosInactividad'      => intdiv(GestorSesion::obtenerTiempoInactividadSegundos() + 59, 60),
        ]);
    }

    /**
     * @param string $correoPrevio Correo a conservar en el formulario.
     * @param string|null $mensajeError Error general a mostrar.
     * @param int $codigoEstado Código HTTP.
     * @param array<string, string> $errores Errores por campo (correo_electronico, contrasena).
     * @return Respuesta Vista de login.
     */
    private function vistaLogin(string $correoPrevio, ?string $mensajeError, int $codigoEstado, array $errores = []): Respuesta
    {
        return Respuesta::vista('login', [
            'tituloPagina'  => 'Iniciar sesión',
            'mensajes'      => $this->gestorSesion->extraerMensajesFlash(),
            'correoPrevio'  => $correoPrevio,
            'mensajeError'  => $mensajeError,
            'errores'       => $errores,
        ], $codigoEstado);
    }
}

<?php

declare(strict_types=1);

/**
 * Controlador de registro, inicio y cierre de sesión.
 *
 * Responsabilidad: traducir la solicitud HTTP (campos de formulario) a
 * llamadas a ServicioAutenticacion y elegir la respuesta (vista o
 * redirección). Las reglas de seguridad viven en el servicio y en los
 * middleware; aquí solo se aplica la lista blanca de campos.
 *
 * Cuando una acción se ejecuta, el enrutador ya comprobó método HTTP,
 * autenticación/estado de invitado, permiso RBAC y token CSRF.
 */
final class AuthController
{
    /** Mensaje único para cualquier fallo de credenciales (sin enumeración). */
    private const MENSAJE_CREDENCIALES_INVALIDAS = 'Correo electrónico o contraseña incorrectos.';

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
        $this->rechazarParametrosInesperados($datosFormulario, ValidadorEntrada::CAMPOS_LOGIN, 'login');

        $validacion = ValidadorEntrada::validarDatosLogin($datosFormulario);
        if (!$validacion['valido']) {
            return $this->vistaLogin('', 'Escribe tu correo electrónico y tu contraseña.', 422);
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
            ServicioAutenticacion::RESULTADO_EXITO => $this->redirigirConMensaje(
                $rutaPendiente ?? '/inicio',
                'exito',
                'Sesión iniciada correctamente.'
            ),
            ServicioAutenticacion::RESULTADO_LIMITE_ALCANZADO => $this->vistaLogin(
                $correoElectronico,
                'Demasiados intentos fallidos. Espera unos minutos antes de volver a intentarlo.',
                429
            ),
            default => $this->vistaLogin($correoElectronico, self::MENSAJE_CREDENCIALES_INVALIDAS, 401),
        };
    }

    /**
     * GET /registro
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null (ruta de invitado).
     * @param array<mixed> $datosFormulario Parámetros GET (no se usan).
     * @return Respuesta Formulario de registro.
     */
    public function mostrarFormularioRegistro(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return $this->vistaRegistro([], [], null, 200);
    }

    /**
     * POST /registro
     *
     * El rol NO se lee del formulario: siempre es USUARIO. Si el formulario
     * trae campos internos (rol_usuario, estado_verificacion_usuario...), se
     * trata como intento de escalada: se audita y se rechaza completo.
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null (ruta de invitado).
     * @param array<mixed> $datosFormulario Campos del formulario.
     * @return Respuesta Redirección al login o formulario con errores.
     * @throws ExcepcionHttp 400 ante campos no permitidos.
     */
    public function procesarRegistro(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->rechazarParametrosInesperados($datosFormulario, ValidadorEntrada::CAMPOS_REGISTRO, 'registro');

        $validacion = ValidadorEntrada::validarDatosRegistro($datosFormulario);
        $valoresPrevios = [
            'nombre_usuario'     => ValidadorEntrada::obtenerTexto($datosFormulario, 'nombre_usuario') ?? '',
            'correo_electronico' => ValidadorEntrada::obtenerTexto($datosFormulario, 'correo_electronico') ?? '',
            'tipo_integrante'    => ValidadorEntrada::obtenerTexto($datosFormulario, 'tipo_integrante') ?? '',
        ];
        if (!$validacion['valido']) {
            return $this->vistaRegistro($valoresPrevios, $validacion['errores'], null, 422);
        }

        $resultado = $this->servicioAutenticacion->registrarUsuario($validacion['datos']);
        if (!$resultado['exito']) {
            return $this->vistaRegistro(
                $valoresPrevios,
                [],
                'No fue posible completar el registro con esos datos. Si ya tienes una cuenta, inicia sesión.',
                409
            );
        }

        $mensaje = 'Cuenta creada. Ya puedes iniciar sesión.';
        if ($validacion['datos']['tipo_integrante'] === UsuarioModel::TIPO_PROFESOR) {
            $mensaje .= ' Tu clasificación como profesor quedó PENDIENTE de verificación; mientras tanto tu cuenta tiene los mismos permisos que cualquier usuario.';
        }
        return $this->redirigirConMensaje('/iniciar-sesion', 'exito', $mensaje);
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
        return $this->redirigirConMensaje('/iniciar-sesion', 'exito', 'Cerraste sesión correctamente.');
    }

    /**
     * GET /inicio — página mínima para usuarios autenticados (no es un dashboard).
     *
     * @param array<string, mixed>|null $usuarioActual Usuario autenticado.
     * @param array<mixed> $datosFormulario Parámetros GET (no se usan).
     * @return Respuesta Vista de inicio.
     */
    public function mostrarInicio(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return Respuesta::vista('inicio', [
            'tituloPagina'         => 'Inicio',
            'usuarioActual'        => $usuarioActual,
            'mensajes'             => $this->gestorSesion->extraerMensajesFlash(),
            'puedeAdministrar'     => $this->roleMiddleware->tienePermiso((string) $usuarioActual['rol_usuario'], 'administrar_cuentas'),
        ]);
    }

    /**
     * Rechaza cualquier campo fuera de la lista blanca del formulario y
     * audita si entre ellos hay campos internos protegidos.
     *
     * @param array<mixed> $datosFormulario Campos recibidos.
     * @param list<string> $camposPermitidos Lista blanca.
     * @param string $nombreFormulario Para el detalle de auditoría.
     * @return void
     * @throws ExcepcionHttp 400 si hay campos inesperados.
     */
    private function rechazarParametrosInesperados(array $datosFormulario, array $camposPermitidos, string $nombreFormulario): void
    {
        $camposInesperados = ValidadorEntrada::obtenerParametrosInesperados($datosFormulario, $camposPermitidos);
        if ($camposInesperados === []) {
            return;
        }
        $camposProtegidos = ValidadorEntrada::obtenerCamposProtegidos($datosFormulario);
        if ($camposProtegidos !== []) {
            $this->auditoriaModel->registrarEventoAuditoria(
                null,
                AuditoriaModel::ACCION_INTENTO_ESCALADA_REGISTRO,
                'usuario',
                null,
                ['formulario' => $nombreFormulario, 'camposProtegidos' => implode(',', $camposProtegidos)]
            );
        }
        throw ExcepcionHttp::solicitudInvalida();
    }

    /**
     * @param string $correoPrevio Correo a conservar en el formulario.
     * @param string|null $mensajeError Error general a mostrar.
     * @param int $codigoEstado Código HTTP.
     * @return Respuesta Vista de login.
     */
    private function vistaLogin(string $correoPrevio, ?string $mensajeError, int $codigoEstado): Respuesta
    {
        return Respuesta::vista('login', [
            'tituloPagina'  => 'Iniciar sesión',
            'mensajes'      => $this->gestorSesion->extraerMensajesFlash(),
            'correoPrevio'  => $correoPrevio,
            'mensajeError'  => $mensajeError,
        ], $codigoEstado);
    }

    /**
     * @param array<string, string> $valoresPrevios Valores a conservar (nunca contraseñas).
     * @param array<string, string> $errores Errores por campo.
     * @param string|null $mensajeError Error general.
     * @param int $codigoEstado Código HTTP.
     * @return Respuesta Vista de registro.
     */
    private function vistaRegistro(array $valoresPrevios, array $errores, ?string $mensajeError, int $codigoEstado): Respuesta
    {
        return Respuesta::vista('registro', [
            'tituloPagina'   => 'Crear cuenta',
            'mensajes'       => $this->gestorSesion->extraerMensajesFlash(),
            'valoresPrevios' => $valoresPrevios,
            'errores'        => $errores,
            'mensajeError'   => $mensajeError,
        ], $codigoEstado);
    }

    /**
     * @param string $rutaInterna Destino.
     * @param string $tipoMensaje Tipo de mensaje flash.
     * @param string $mensaje Texto del mensaje.
     * @return Respuesta Redirección 303.
     */
    private function redirigirConMensaje(string $rutaInterna, string $tipoMensaje, string $mensaje): Respuesta
    {
        $this->gestorSesion->guardarMensajeFlash($tipoMensaje, $mensaje);
        return Respuesta::redireccion($rutaInterna);
    }
}

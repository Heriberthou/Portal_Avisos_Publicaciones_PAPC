<?php

declare(strict_types=1);

/**
 * Pantallas C y D: solicitar acceso, verificar el correo y reenviar el
 * enlace. Responde de forma neutral: nunca revela si un correo ya tiene
 * cuenta o una solicitud previa.
 */
final class SolicitudAccesoController
{
    use ControlFormulario;

    /** Respuesta pública única tras enviar la solicitud o pedir el reenvío. */
    public const MENSAJE_SOLICITUD_RECIBIDA = 'Si los datos permiten continuar el trámite, recibirá instrucciones en el correo indicado. Revise también su carpeta de correo no deseado.';
    private const MENSAJE_LIMITE = 'Por seguridad, espere unos minutos antes de volver a intentarlo.';
    private const MENSAJE_ENLACE_INVALIDO = 'El enlace no es válido o ha vencido. Solicite uno nuevo.';

    /**
     * @param ServicioSolicitudAcceso $servicioSolicitudAcceso Reglas de solicitud.
     * @param GestorSesion $gestorSesion Mensajes y token del enlace.
     * @param AuditoriaModel $auditoriaModel Registro de manipulaciones.
     * @param ServicioCorreo $servicioCorreo Para indicar si el sitio está en modo de demostración (R-07).
     */
    public function __construct(
        private readonly ServicioSolicitudAcceso $servicioSolicitudAcceso,
        private readonly GestorSesion $gestorSesion,
        private readonly AuditoriaModel $auditoriaModel,
        private readonly ServicioCorreo $servicioCorreo
    ) {
    }

    /**
     * GET /registro — la ruta anterior lleva al nuevo formulario.
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Redirección.
     */
    public function redirigirRegistroAnterior(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return Respuesta::redireccion('/solicitar-acceso');
    }

    /**
     * GET /solicitar-acceso
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Formulario.
     */
    public function mostrarFormulario(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return $this->vistaFormulario([], [], null, 200);
    }

    /**
     * POST /solicitar-acceso
     *
     * Rol, estado o revisor enviados desde el navegador se rechazan y se
     * auditan (AC-02): la solicitud nunca concede privilegios.
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null.
     * @param array<mixed> $datosFormulario Campos.
     * @return Respuesta Confirmación neutral o formulario con errores.
     * @throws ExcepcionHttp 400 ante campos no permitidos.
     */
    public function procesarSolicitud(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, ValidadorEntrada::CAMPOS_SOLICITUD, 'solicitar-acceso', $this->auditoriaModel);

        $validacion = ValidadorEntrada::validarDatosSolicitud($datosFormulario);
        $valoresPrevios = [];
        foreach (['nombres', 'apellido_paterno', 'apellido_materno', 'tipo_integrante', 'identificador_institucional', 'correo_electronico'] as $campo) {
            $valoresPrevios[$campo] = ValidadorEntrada::obtenerTexto($datosFormulario, $campo) ?? '';
        }
        if (!$validacion['valido']) {
            return $this->vistaFormulario($valoresPrevios, $validacion['errores'], null, 422);
        }

        $resultado = $this->servicioSolicitudAcceso->registrarSolicitud($validacion['datos'], Solicitud::obtenerOrigen());
        if ($resultado === ServicioSolicitudAcceso::RESULTADO_LIMITE_ALCANZADO) {
            return $this->vistaFormulario($valoresPrevios, [], self::MENSAJE_LIMITE, 429);
        }
        return Respuesta::redireccion('/solicitud-recibida');
    }

    /**
     * GET /solicitud-recibida — confirmación neutral.
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Confirmación.
     */
    public function mostrarSolicitudRecibida(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return Respuesta::vista('solicitud-recibida', [
            'tituloPagina'     => 'Solicitud recibida',
            'mensajes'         => $this->gestorSesion->extraerMensajesFlash(),
            'modoBuzonPruebas' => $this->servicioCorreo->esModoBuzonPruebas(),
        ]);
    }

    /**
     * GET /verificar-solicitud[?token=...]
     *
     * Con token: lo guarda en la sesión y redirige a la URL limpia (el token
     * no queda en el historial ni en el Referer). Sin token: muestra el
     * botón "Confirmar mi correo". No consume nada en un GET, porque los
     * antivirus de correo abren los enlaces automáticamente.
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario Parámetros GET.
     * @return Respuesta Redirección o confirmación.
     */
    public function mostrarVerificacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        if (array_key_exists('token', $datosFormulario)) {
            return $this->guardarTokenYLimpiarUrl($datosFormulario['token'], GestorSesion::ENLACE_VERIFICACION, '/verificar-solicitud');
        }
        if ($this->gestorSesion->obtenerTokenEnlace(GestorSesion::ENLACE_VERIFICACION) === null) {
            return $this->vistaEnlaceInvalido();
        }
        return Respuesta::vista('verificar-solicitud', ['tituloPagina' => 'Verifique su correo electrónico']);
    }

    /**
     * POST /verificar-solicitud
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario Solo token_csrf.
     * @return Respuesta Resultado.
     */
    public function procesarVerificacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, ['token_csrf'], 'verificar-solicitud');
        $token = $this->gestorSesion->obtenerTokenEnlace(GestorSesion::ENLACE_VERIFICACION);
        $this->gestorSesion->eliminarTokenEnlace(GestorSesion::ENLACE_VERIFICACION);
        if ($token === null || $this->servicioSolicitudAcceso->verificarCorreo($token) !== ServicioSolicitudAcceso::RESULTADO_VERIFICADA) {
            return $this->vistaEnlaceInvalido();
        }
        return Respuesta::vista('mensaje-acceso', [
            'tituloPagina' => 'Correo verificado',
            'titulo'       => 'Correo verificado',
            'tipoMensaje'  => 'exito',
            'mensaje'      => 'Su correo se verificó correctamente. Su solicitud está en revisión y recibirá la respuesta en ese mismo correo.',
        ]);
    }

    /**
     * GET /reenviar-verificacion
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Formulario.
     */
    public function mostrarReenvio(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return $this->vistaReenvio('', null, 200);
    }

    /**
     * POST /reenviar-verificacion
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null.
     * @param array<mixed> $datosFormulario token_csrf y correo_electronico.
     * @return Respuesta Confirmación neutral.
     */
    public function procesarReenvio(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, ValidadorEntrada::CAMPOS_SOLO_CORREO, 'reenviar-verificacion', $this->auditoriaModel);
        $validacion = ValidadorEntrada::validarSoloCorreo($datosFormulario);
        if (!$validacion['valido']) {
            return $this->vistaReenvio($validacion['correoPrevio'], (string) $validacion['error'], 422);
        }
        if ($this->servicioSolicitudAcceso->reenviarVerificacion($validacion['correo'], Solicitud::obtenerOrigen()) === ServicioSolicitudAcceso::RESULTADO_LIMITE_ALCANZADO) {
            return $this->vistaReenvio($validacion['correoPrevio'], self::MENSAJE_LIMITE, 429);
        }
        return Respuesta::redireccion('/solicitud-recibida');
    }

    /**
     * @param mixed $token Valor recibido en la URL.
     * @param string $tipoEnlace Constante GestorSesion::ENLACE_*.
     * @param string $rutaLimpia Ruta sin token.
     * @return Respuesta Redirección o enlace inválido.
     */
    private function guardarTokenYLimpiarUrl(mixed $token, string $tipoEnlace, string $rutaLimpia): Respuesta
    {
        if (!GeneradorTokens::esFormatoValido($token)) {
            return $this->vistaEnlaceInvalido();
        }
        $this->gestorSesion->guardarTokenEnlace($tipoEnlace, (string) $token);
        return Respuesta::redireccion($rutaLimpia);
    }

    /**
     * @return Respuesta Pantalla de enlace no válido con la acción "Reenviar enlace".
     */
    private function vistaEnlaceInvalido(): Respuesta
    {
        return Respuesta::vista('mensaje-acceso', [
            'tituloPagina'  => 'Enlace no válido',
            'titulo'        => 'Verifique su correo electrónico',
            'tipoMensaje'   => 'error',
            'mensaje'       => self::MENSAJE_ENLACE_INVALIDO,
            'enlaceAccion'  => '/reenviar-verificacion',
            'textoAccion'   => 'Reenviar enlace',
        ], 400);
    }

    /**
     * @param array<string, string> $valoresPrevios Valores a conservar.
     * @param array<string, string> $errores Errores por campo.
     * @param string|null $mensajeError Error general.
     * @param int $codigoEstado Código HTTP.
     * @return Respuesta Vista del formulario.
     */
    private function vistaFormulario(array $valoresPrevios, array $errores, ?string $mensajeError, int $codigoEstado): Respuesta
    {
        return Respuesta::vista('solicitar-acceso', [
            'tituloPagina'   => 'Solicitar acceso',
            'mensajes'       => $this->gestorSesion->extraerMensajesFlash(),
            'valoresPrevios' => $valoresPrevios,
            'errores'        => $errores,
            'mensajeError'   => $mensajeError,
        ], $codigoEstado);
    }

    /**
     * @param string $correoPrevio Correo a conservar.
     * @param string|null $mensajeError Error.
     * @param int $codigoEstado Código HTTP.
     * @return Respuesta Vista del reenvío.
     */
    private function vistaReenvio(string $correoPrevio, ?string $mensajeError, int $codigoEstado): Respuesta
    {
        return Respuesta::vista('solo-correo', [
            'tituloPagina'  => 'Reenviar enlace de verificación',
            'titulo'        => 'Reenviar enlace de verificación',
            'descripcion'   => 'Escriba el correo con el que solicitó acceso. Si tiene una solicitud pendiente de verificar, le enviaremos un enlace nuevo; el anterior dejará de funcionar.',
            'rutaFormulario' => '/reenviar-verificacion',
            'textoBoton'    => 'Reenviar enlace',
            'correoPrevio'  => $correoPrevio,
            'mensajeError'  => $mensajeError,
            'mensajes'      => $this->gestorSesion->extraerMensajesFlash(),
        ], $codigoEstado);
    }
}

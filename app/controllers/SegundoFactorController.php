<?php

declare(strict_types=1);

/**
 * Pantalla H: segundo factor de ADMINISTRADOR y ADMINISTRADOR_PRINCIPAL.
 *
 * Estas rutas son de "solo invitado": la persona todavía NO tiene sesión
 * autenticada; solo un estado pendiente (5 minutos) tras escribir bien su
 * contraseña. Sin ese estado, se redirige al inicio de sesión.
 */
final class SegundoFactorController
{
    use ControlFormulario;

    private const MENSAJE_SIN_PENDIENTE = 'Su sesión finalizó. Inicie sesión nuevamente para continuar.';
    private const MENSAJE_LIMITE = 'Por seguridad, espere unos minutos e inicie sesión de nuevo.';

    /**
     * @param ServicioSegundoFactor $servicioSegundoFactor Reglas del segundo factor.
     * @param GestorSesion $gestorSesion Mensajes y datos de un solo uso.
     */
    public function __construct(
        private readonly ServicioSegundoFactor $servicioSegundoFactor,
        private readonly GestorSesion $gestorSesion
    ) {
    }

    /**
     * GET /verificar-segundo-factor
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Formulario del código.
     */
    public function mostrarVerificacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $usuario = $this->servicioSegundoFactor->obtenerCuentaPendiente();
        if ($usuario === null || (int) $usuario['tiene_segundo_factor_usuario'] !== 1) {
            return $this->redirigirConMensaje('/iniciar-sesion', 'aviso', self::MENSAJE_SIN_PENDIENTE);
        }
        return $this->vistaVerificacion(null, 200);
    }

    /**
     * POST /verificar-segundo-factor
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null.
     * @param array<mixed> $datosFormulario token_csrf y codigo.
     * @return Respuesta Inicio si el código es válido; formulario si no.
     */
    public function procesarVerificacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, ValidadorEntrada::CAMPOS_SEGUNDO_FACTOR, 'segundo-factor');
        $codigo = ValidadorEntrada::obtenerTexto($datosFormulario, 'codigo') ?? '';
        if ($codigo === '') {
            return $this->vistaVerificacion('Escriba el código de su aplicación de autenticación.', 422);
        }

        return match ($this->servicioSegundoFactor->verificarCodigo($codigo, Solicitud::obtenerOrigen())) {
            ServicioSegundoFactor::RESULTADO_EXITO => $this->redirigirConMensaje('/inicio', 'exito', 'Sesión iniciada correctamente.'),
            ServicioSegundoFactor::RESULTADO_CODIGO_INVALIDO => $this->vistaVerificacion('El código no es válido o ya se usó. Intente con el código actual.', 401),
            ServicioSegundoFactor::RESULTADO_LIMITE_ALCANZADO => $this->redirigirConMensaje('/iniciar-sesion', 'error', self::MENSAJE_LIMITE),
            default => $this->redirigirConMensaje('/iniciar-sesion', 'aviso', self::MENSAJE_SIN_PENDIENTE),
        };
    }

    /**
     * GET /configurar-segundo-factor
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Instrucciones con el secreto.
     */
    public function mostrarConfiguracion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        // Enlace del correo o de la consola: se guarda en la sesión y se
        // redirige a la URL sin token (R-02).
        if (array_key_exists('token', $datosFormulario)) {
            if (GeneradorTokens::esFormatoValido($datosFormulario['token'])) {
                $this->gestorSesion->guardarTokenEnlace(GestorSesion::ENLACE_ENROLAMIENTO, (string) $datosFormulario['token']);
            }
            return Respuesta::redireccion('/configurar-segundo-factor');
        }
        if ($this->servicioSegundoFactor->obtenerCuentaPendiente() === null) {
            $mensaje = $this->gestorSesion->obtenerTokenEnlace(GestorSesion::ENLACE_ENROLAMIENTO) !== null
                ? 'Inicie sesión para continuar con la verificación en dos pasos.'
                : self::MENSAJE_SIN_PENDIENTE;
            return $this->redirigirConMensaje('/iniciar-sesion', 'aviso', $mensaje);
        }
        $configuracion = $this->servicioSegundoFactor->prepararConfiguracion();
        if ($configuracion === null) {
            return $this->vistaEnlacePendiente(null, 200);
        }
        return $this->vistaConfiguracion($configuracion, null, 200);
    }

    /**
     * POST /configurar-segundo-factor/enlace — envía el enlace de
     * configuración al correo de la cuenta cuya contraseña ya se validó.
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null.
     * @param array<mixed> $datosFormulario Solo token_csrf.
     * @return Respuesta Pantalla con el resultado.
     */
    public function solicitarEnlace(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, ['token_csrf'], 'enlace-segundo-factor');
        return match ($this->servicioSegundoFactor->solicitarEnlaceEnrolamiento(Solicitud::obtenerOrigen())) {
            ServicioSegundoFactor::RESULTADO_ENLACE_ENVIADO => $this->vistaEnlacePendiente(
                'Le enviamos un enlace nuevo. Ábralo en este navegador; vence en 1 hora.',
                200
            ),
            ServicioSegundoFactor::RESULTADO_ENVIO_FALLIDO => $this->vistaEnlacePendiente(
                'No fue posible enviar el enlace. Intente más tarde o comuníquese con el administrador principal.',
                503
            ),
            ServicioSegundoFactor::RESULTADO_LIMITE_ALCANZADO => $this->vistaEnlacePendiente(
                'Por seguridad, espere unos minutos antes de pedir otro enlace.',
                429
            ),
            default => $this->redirigirConMensaje('/iniciar-sesion', 'aviso', self::MENSAJE_SIN_PENDIENTE),
        };
    }

    /**
     * POST /configurar-segundo-factor
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null.
     * @param array<mixed> $datosFormulario token_csrf y codigo.
     * @return Respuesta Códigos de recuperación si se confirmó.
     */
    public function procesarConfiguracion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, ValidadorEntrada::CAMPOS_SEGUNDO_FACTOR, 'configurar-segundo-factor');
        $codigo = ValidadorEntrada::obtenerTexto($datosFormulario, 'codigo') ?? '';

        $resultado = $this->servicioSegundoFactor->confirmarConfiguracion($codigo, Solicitud::obtenerOrigen());
        if ($resultado['resultado'] === ServicioSegundoFactor::RESULTADO_EXITO) {
            $this->gestorSesion->guardarDatoUnaVez('codigosRecuperacion', $resultado['codigosRecuperacion']);
            return Respuesta::redireccion('/codigos-recuperacion');
        }
        if ($resultado['resultado'] === ServicioSegundoFactor::RESULTADO_SIN_AUTORIZACION) {
            return $this->vistaEnlacePendiente(null, 403);
        }
        if ($resultado['resultado'] === ServicioSegundoFactor::RESULTADO_CODIGO_INVALIDO) {
            $configuracion = $this->servicioSegundoFactor->prepararConfiguracion();
            if ($configuracion !== null) {
                return $this->vistaConfiguracion($configuracion, 'El código no coincide. Revise la hora de su teléfono y escriba el código actual.', 401);
            }
        }
        $mensaje = $resultado['resultado'] === ServicioSegundoFactor::RESULTADO_LIMITE_ALCANZADO ? self::MENSAJE_LIMITE : self::MENSAJE_SIN_PENDIENTE;
        return $this->redirigirConMensaje('/iniciar-sesion', 'aviso', $mensaje);
    }

    /**
     * GET /codigos-recuperacion — se muestran UNA sola vez.
     *
     * @param array<string, mixed>|null $usuarioActual Administrador autenticado.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Códigos o redirección si ya se mostraron.
     */
    public function mostrarCodigosRecuperacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $codigos = $this->gestorSesion->extraerDatoUnaVez('codigosRecuperacion');
        if (!is_array($codigos) || $codigos === []) {
            return Respuesta::redireccion('/inicio');
        }
        return Respuesta::vista('codigos-recuperacion', [
            'tituloPagina'         => 'Códigos de recuperación',
            'usuarioActual'        => $usuarioActual,
            'codigosRecuperacion'  => array_values(array_map('strval', $codigos)),
        ]);
    }

    /**
     * @param string|null $mensajeError Error a mostrar.
     * @param int $codigoEstado Código HTTP.
     * @return Respuesta Vista de verificación.
     */
    private function vistaVerificacion(?string $mensajeError, int $codigoEstado): Respuesta
    {
        return Respuesta::vista('segundo-factor', [
            'tituloPagina' => 'Verificación en dos pasos',
            'mensajes'     => $this->gestorSesion->extraerMensajesFlash(),
            'mensajeError' => $mensajeError,
        ], $codigoEstado);
    }

    /**
     * Pantalla "Revise su correo": falta abrir el enlace de configuración.
     *
     * @param string|null $mensaje Resultado de la última acción.
     * @param int $codigoEstado Código HTTP.
     * @return Respuesta Vista.
     */
    private function vistaEnlacePendiente(?string $mensaje, int $codigoEstado): Respuesta
    {
        return Respuesta::vista('enlace-segundo-factor', [
            'tituloPagina' => 'Activar la verificación en dos pasos',
            'mensajes'     => $this->gestorSesion->extraerMensajesFlash(),
            'mensaje'      => $mensaje,
        ], $codigoEstado);
    }

    /**
     * @param array{secreto: string, uri: string, correo: string} $configuracion Datos del secreto.
     * @param string|null $mensajeError Error a mostrar.
     * @param int $codigoEstado Código HTTP.
     * @return Respuesta Vista de configuración.
     */
    private function vistaConfiguracion(array $configuracion, ?string $mensajeError, int $codigoEstado): Respuesta
    {
        return Respuesta::vista('configurar-segundo-factor', [
            'tituloPagina'      => 'Activar la verificación en dos pasos',
            'mensajes'          => $this->gestorSesion->extraerMensajesFlash(),
            'secretoFormateado' => SegundoFactorTotp::formatearSecreto($configuracion['secreto']),
            'uriAprovisionamiento' => $configuracion['uri'],
            'correo'            => $configuracion['correo'],
            'mensajeError'      => $mensajeError,
        ], $codigoEstado);
    }
}

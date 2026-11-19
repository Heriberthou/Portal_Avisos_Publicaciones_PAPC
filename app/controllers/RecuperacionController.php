<?php

declare(strict_types=1);

/**
 * Pantalla G: recuperar y restablecer la contraseña.
 *
 * La solicitud responde siempre igual (exista o no la cuenta). El enlace
 * dura 30 minutos, sirve una vez y al usarlo se cierran todas las sesiones
 * de la cuenta; después se exige un inicio de sesión nuevo.
 */
final class RecuperacionController
{
    use ControlFormulario;

    /** Respuesta neutral del catálogo 5.10. */
    public const MENSAJE_RECUPERACION = 'Si existe una cuenta asociada a ese correo, recibirá instrucciones para continuar.';

    /**
     * @param ServicioCredenciales $servicioCredenciales Reglas de recuperación.
     * @param GestorSesion $gestorSesion Token del enlace y mensajes.
     */
    public function __construct(
        private readonly ServicioCredenciales $servicioCredenciales,
        private readonly GestorSesion $gestorSesion
    ) {
    }

    /**
     * GET /recuperar-contrasena
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Formulario.
     */
    public function mostrarRecuperacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return $this->vistaRecuperacion('', null, 200);
    }

    /**
     * POST /recuperar-contrasena
     *
     * @param array<string, mixed>|null $usuarioActual Siempre null.
     * @param array<mixed> $datosFormulario token_csrf y correo_electronico.
     * @return Respuesta Mensaje neutral.
     */
    public function procesarRecuperacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, ValidadorEntrada::CAMPOS_SOLO_CORREO, 'recuperar-contrasena');
        $validacion = ValidadorEntrada::validarSoloCorreo($datosFormulario);
        if (!$validacion['valido']) {
            return $this->vistaRecuperacion($validacion['correoPrevio'], (string) $validacion['error'], 422);
        }
        if ($this->servicioCredenciales->solicitarRecuperacion($validacion['correo'], Solicitud::obtenerOrigen()) === ServicioCredenciales::RESULTADO_LIMITE_ALCANZADO) {
            return $this->vistaRecuperacion($validacion['correoPrevio'], 'Por seguridad, espere unos minutos antes de volver a intentarlo.', 429);
        }
        return $this->redirigirConMensaje('/iniciar-sesion', 'aviso', self::MENSAJE_RECUPERACION);
    }

    /**
     * GET /restablecer-contrasena[?token=...]
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario Parámetros GET.
     * @return Respuesta Formulario, redirección o enlace inválido.
     */
    public function mostrarRestablecimiento(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        if (array_key_exists('token', $datosFormulario)) {
            if (!GeneradorTokens::esFormatoValido($datosFormulario['token'])) {
                return $this->vistaEnlaceInvalido();
            }
            $this->gestorSesion->guardarTokenEnlace(GestorSesion::ENLACE_RECUPERACION, (string) $datosFormulario['token']);
            return Respuesta::redireccion('/restablecer-contrasena');
        }
        $token = $this->gestorSesion->obtenerTokenEnlace(GestorSesion::ENLACE_RECUPERACION);
        $cuenta = $token !== null ? $this->servicioCredenciales->obtenerCuentaDeEnlace($token, TokenCuentaModel::FINALIDAD_RECUPERACION) : null;
        if ($cuenta === null) {
            $this->gestorSesion->eliminarTokenEnlace(GestorSesion::ENLACE_RECUPERACION);
            return $this->vistaEnlaceInvalido();
        }
        return $this->vistaFormulario($cuenta, null, 200);
    }

    /**
     * POST /restablecer-contrasena
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario token_csrf y contrasena.
     * @return Respuesta Login con mensaje, formulario con error o enlace inválido.
     */
    public function procesarRestablecimiento(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, ValidadorEntrada::CAMPOS_NUEVA_CONTRASENA, 'restablecer-contrasena');
        $token = $this->gestorSesion->obtenerTokenEnlace(GestorSesion::ENLACE_RECUPERACION);
        if ($token === null) {
            return $this->vistaEnlaceInvalido();
        }

        $resultado = $this->servicioCredenciales->restablecerContrasena($token, ValidadorEntrada::obtenerTexto($datosFormulario, 'contrasena', false));
        if ($resultado['resultado'] === ServicioCredenciales::RESULTADO_CONTRASENA_RECHAZADA) {
            $cuenta = $this->servicioCredenciales->obtenerCuentaDeEnlace($token, TokenCuentaModel::FINALIDAD_RECUPERACION);
            if ($cuenta !== null) {
                return $this->vistaFormulario($cuenta, $resultado['error'], 422);
            }
        }
        $this->gestorSesion->eliminarTokenEnlace(GestorSesion::ENLACE_RECUPERACION);
        if ($resultado['resultado'] !== ServicioCredenciales::RESULTADO_EXITO) {
            return $this->vistaEnlaceInvalido();
        }
        // Las sesiones de la cuenta ya se invalidaron (version_sesion_usuario).
        return $this->redirigirConMensaje('/iniciar-sesion', 'exito', 'Su contraseña se cambió correctamente. Inicie sesión con su nueva contraseña.');
    }

    /**
     * @param string $correoPrevio Correo a conservar.
     * @param string|null $mensajeError Error.
     * @param int $codigoEstado Código HTTP.
     * @return Respuesta Vista "solo correo".
     */
    private function vistaRecuperacion(string $correoPrevio, ?string $mensajeError, int $codigoEstado): Respuesta
    {
        return Respuesta::vista('solo-correo', [
            'tituloPagina'   => 'Recuperar contraseña',
            'titulo'         => 'Recuperar contraseña',
            'descripcion'    => 'Escriba el correo asociado a su cuenta. Si existe y puede recuperarse, le enviaremos instrucciones.',
            'rutaFormulario' => '/recuperar-contrasena',
            'textoBoton'     => 'Recuperar contraseña',
            'correoPrevio'   => $correoPrevio,
            'mensajeError'   => $mensajeError,
            'mensajes'       => $this->gestorSesion->extraerMensajesFlash(),
        ], $codigoEstado);
    }

    /**
     * @return Respuesta Enlace no válido.
     */
    private function vistaEnlaceInvalido(): Respuesta
    {
        return Respuesta::vista('mensaje-acceso', [
            'tituloPagina' => 'Enlace no válido',
            'titulo'       => 'Restablecer contraseña',
            'tipoMensaje'  => 'error',
            'mensaje'      => 'El enlace no es válido o ha vencido. Solicite uno nuevo.',
            'enlaceAccion' => '/recuperar-contrasena',
            'textoAccion'  => 'Solicitar un enlace nuevo',
        ], 400);
    }

    /**
     * @param array<string, mixed> $cuenta Cuenta del enlace.
     * @param string|null $errorContrasena Error de la contraseña.
     * @param int $codigoEstado Código HTTP.
     * @return Respuesta Formulario de contraseña nueva.
     */
    private function vistaFormulario(array $cuenta, ?string $errorContrasena, int $codigoEstado): Respuesta
    {
        return Respuesta::vista('nueva-contrasena', [
            'tituloPagina'    => 'Restablecer contraseña',
            'titulo'          => 'Restablecer contraseña',
            'descripcion'     => 'Cree una contraseña nueva. Al guardarla se cerrarán todas sus sesiones abiertas.',
            'rutaFormulario'  => '/restablecer-contrasena',
            'textoBoton'      => 'Guardar contraseña',
            'correoCuenta'    => (string) $cuenta['correo_electronico_usuario'],
            'errorContrasena' => $errorContrasena,
        ], $codigoEstado);
    }
}

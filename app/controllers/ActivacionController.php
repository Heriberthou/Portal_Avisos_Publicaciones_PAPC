<?php

declare(strict_types=1);

/**
 * Pantalla F: activar una cuenta INVITADA con el enlace de un solo uso.
 *
 * El token pasa de la URL a la sesión y se redirige a /activar-cuenta sin
 * token. Activar NO inicia sesión: se exige un login separado.
 */
final class ActivacionController
{
    use ControlFormulario;

    /**
     * @param ServicioCredenciales $servicioCredenciales Reglas de activación.
     * @param GestorSesion $gestorSesion Token del enlace y mensajes.
     */
    public function __construct(
        private readonly ServicioCredenciales $servicioCredenciales,
        private readonly GestorSesion $gestorSesion
    ) {
    }

    /**
     * GET /activar-cuenta[?token=...]
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario Parámetros GET.
     * @return Respuesta Formulario, redirección o enlace inválido.
     */
    public function mostrarActivacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        if (array_key_exists('token', $datosFormulario)) {
            if (!GeneradorTokens::esFormatoValido($datosFormulario['token'])) {
                return self::vistaEnlaceInvalido();
            }
            $this->gestorSesion->guardarTokenEnlace(GestorSesion::ENLACE_ACTIVACION, (string) $datosFormulario['token']);
            return Respuesta::redireccion('/activar-cuenta');
        }
        $token = $this->gestorSesion->obtenerTokenEnlace(GestorSesion::ENLACE_ACTIVACION);
        $cuenta = $token !== null ? $this->servicioCredenciales->obtenerCuentaDeEnlace($token, TokenCuentaModel::FINALIDAD_ACTIVACION) : null;
        if ($cuenta === null) {
            $this->gestorSesion->eliminarTokenEnlace(GestorSesion::ENLACE_ACTIVACION);
            return self::vistaEnlaceInvalido();
        }
        return $this->vistaFormulario($cuenta, null, 200);
    }

    /**
     * POST /activar-cuenta
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario token_csrf y contrasena.
     * @return Respuesta Login con mensaje, formulario con error o enlace inválido.
     */
    public function procesarActivacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, ValidadorEntrada::CAMPOS_NUEVA_CONTRASENA, 'activar-cuenta');
        $token = $this->gestorSesion->obtenerTokenEnlace(GestorSesion::ENLACE_ACTIVACION);
        if ($token === null) {
            return self::vistaEnlaceInvalido();
        }

        $resultado = $this->servicioCredenciales->activarCuenta($token, ValidadorEntrada::obtenerTexto($datosFormulario, 'contrasena', false));
        if ($resultado['resultado'] === ServicioCredenciales::RESULTADO_CONTRASENA_RECHAZADA) {
            $cuenta = $this->servicioCredenciales->obtenerCuentaDeEnlace($token, TokenCuentaModel::FINALIDAD_ACTIVACION);
            if ($cuenta !== null) {
                return $this->vistaFormulario($cuenta, $resultado['error'], 422);
            }
        }
        $this->gestorSesion->eliminarTokenEnlace(GestorSesion::ENLACE_ACTIVACION);
        if ($resultado['resultado'] !== ServicioCredenciales::RESULTADO_EXITO) {
            return self::vistaEnlaceInvalido();
        }
        // Si había una sesión abierta en este navegador, se conserva; la cuenta nueva exige login.
        return $this->redirigirConMensaje(
            $usuarioActual === null ? '/iniciar-sesion' : '/inicio',
            'exito',
            'Su cuenta se activó correctamente. Ya puede iniciar sesión.'
        );
    }

    /**
     * @return Respuesta Enlace no válido con la acción para pedir otro.
     */
    public static function vistaEnlaceInvalido(): Respuesta
    {
        return Respuesta::vista('mensaje-acceso', [
            'tituloPagina' => 'Enlace no válido',
            'titulo'       => 'Activar mi cuenta',
            'tipoMensaje'  => 'error',
            'mensaje'      => 'El enlace no es válido o ha vencido. Solicite uno nuevo con "¿Olvidó su contraseña?" usando el mismo correo.',
            'enlaceAccion' => '/recuperar-contrasena',
            'textoAccion'  => 'Solicitar un enlace nuevo',
        ], 400);
    }

    /**
     * @param array<string, mixed> $cuenta Cuenta del enlace.
     * @param string|null $errorContrasena Error de la contraseña.
     * @param int $codigoEstado Código HTTP.
     * @return Respuesta Formulario de activación.
     */
    private function vistaFormulario(array $cuenta, ?string $errorContrasena, int $codigoEstado): Respuesta
    {
        return Respuesta::vista('nueva-contrasena', [
            'tituloPagina'    => 'Activar mi cuenta',
            'titulo'          => 'Activar mi cuenta',
            'descripcion'     => 'Su acceso fue autorizado. Cree una contraseña para activar su cuenta.',
            'rutaFormulario'  => '/activar-cuenta',
            'textoBoton'      => 'Activar cuenta',
            'correoCuenta'    => (string) $cuenta['correo_electronico_usuario'],
            'errorContrasena' => $errorContrasena,
        ], $codigoEstado);
    }
}

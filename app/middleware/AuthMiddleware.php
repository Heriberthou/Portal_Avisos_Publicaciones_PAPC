<?php

declare(strict_types=1);

/**
 * Middleware de AUTENTICACIÓN: responde "¿quién eres?".
 *
 * No decide permisos (eso es RoleMiddleware). Obtiene el usuario de la
 * sesión validada contra la base de datos y bloquea las rutas protegidas
 * cuando no hay sesión.
 */
final class AuthMiddleware
{
    /** Mensajes al usuario según el motivo de expiración. */
    private const MENSAJES_EXPIRACION = [
        GestorSesion::MOTIVO_INACTIVIDAD        => 'Su sesión se cerró por inactividad. Inicie sesión de nuevo.',
        GestorSesion::MOTIVO_DURACION_MAXIMA    => 'Su sesión llegó a su tiempo máximo. Inicie sesión de nuevo.',
        GestorSesion::MOTIVO_VERSION_CAMBIADA   => 'Sus permisos o su contraseña cambiaron. Inicie sesión de nuevo.',
        GestorSesion::MOTIVO_CUENTA_INEXISTENTE => 'Su sesión ya no es válida. Inicie sesión de nuevo.',
        // Cuenta suspendida o dada de baja: mismo texto genérico (no se revela el estado).
        GestorSesion::MOTIVO_CUENTA_NO_ACTIVA   => 'Su sesión ya no es válida. Inicie sesión de nuevo.',
        GestorSesion::MOTIVO_SEGUNDO_FACTOR_REQUERIDO => 'Su sesión ya no es válida. Inicie sesión de nuevo.',
    ];

    /**
     * @param GestorSesion $gestorSesion Validación de la sesión.
     * @param AuditoriaModel $auditoriaModel Registro de expiraciones.
     */
    public function __construct(
        private readonly GestorSesion $gestorSesion,
        private readonly AuditoriaModel $auditoriaModel
    ) {
    }

    /**
     * Devuelve el usuario autenticado (o null para un invitado).
     *
     * Si la sesión expiró, lo audita y deja un aviso para el usuario.
     *
     * @return array<string, mixed>|null Usuario con datos frescos de la BD.
     */
    public function obtenerUsuarioActual(): ?array
    {
        $usuarioActual = $this->gestorSesion->validarSesion();
        $motivoExpiracion = $this->gestorSesion->obtenerMotivoUltimaExpiracion();

        if ($motivoExpiracion !== null) {
            $idUsuarioExpirado = $this->gestorSesion->obtenerIdUsuarioExpirado();
            $this->auditoriaModel->registrarEventoAuditoria(
                $motivoExpiracion === GestorSesion::MOTIVO_CUENTA_INEXISTENTE ? null : $idUsuarioExpirado,
                AuditoriaModel::ACCION_SESION_EXPIRADA,
                'usuario',
                $idUsuarioExpirado,
                ['motivo' => $motivoExpiracion]
            );
            $this->gestorSesion->guardarMensajeFlash('aviso', self::MENSAJES_EXPIRACION[$motivoExpiracion]);
        }

        return $usuarioActual;
    }

    /**
     * Exige una sesión autenticada.
     *
     * Para solicitudes GET recuerda la ruta para volver a ella tras el
     * login (solo rutas internas); el punto de entrada convierte el 401 de
     * un GET en una redirección al formulario de inicio de sesión.
     *
     * @param array<string, mixed>|null $usuarioActual Resultado de obtenerUsuarioActual().
     * @param string $metodo Método HTTP.
     * @param string $ruta Ruta solicitada.
     * @return array<string, mixed> Usuario autenticado.
     * @throws ExcepcionHttp 401 si no hay sesión.
     */
    public function verificarAutenticacion(?array $usuarioActual, string $metodo, string $ruta): array
    {
        if ($usuarioActual === null) {
            if ($metodo === 'GET') {
                $this->gestorSesion->guardarRutaPendiente($ruta);
            }
            throw ExcepcionHttp::noAutenticado();
        }
        return $usuarioActual;
    }
}

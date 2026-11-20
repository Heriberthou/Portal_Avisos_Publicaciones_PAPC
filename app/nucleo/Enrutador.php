<?php

declare(strict_types=1);

/**
 * Asocia método + ruta con una acción de controlador y aplica, en orden,
 * los controles de seguridad declarados para esa ruta:
 *
 *   1. Ruta y método existentes (404 / 405).
 *   2. AuthMiddleware: sesión válida o solo invitados.
 *   3. RoleMiddleware: permiso RBAC (denegación por defecto).
 *   4. Token CSRF en toda solicitud POST.
 *   5. Acción del controlador.
 */
final class Enrutador
{
    /** La ruta es accesible con o sin sesión. */
    public const ACCESO_PUBLICO = 'publico';

    /** La ruta exige sesión autenticada. */
    public const ACCESO_AUTENTICADO = 'autenticado';

    /** La ruta es solo para visitantes sin sesión (login, registro). */
    public const ACCESO_SOLO_INVITADO = 'solo_invitado';

    /** @var array<string, array<string, array{accion: callable, acceso: string, permiso: ?string}>> */
    private array $rutas = [];

    /**
     * @param AuthMiddleware $authMiddleware Verificación de autenticación.
     * @param RoleMiddleware $roleMiddleware Verificación de permisos RBAC.
     * @param AuditoriaModel $auditoriaModel Registro de rechazos CSRF.
     */
    public function __construct(
        private readonly AuthMiddleware $authMiddleware,
        private readonly RoleMiddleware $roleMiddleware,
        private readonly AuditoriaModel $auditoriaModel
    ) {
    }

    /**
     * Registra una ruta.
     *
     * @param string $metodo 'GET' o 'POST'.
     * @param string $ruta Ruta en minúsculas/kebab-case (NOM-14).
     * @param callable $accion Función (?array $usuarioActual, array $datos): Respuesta.
     * @param string $acceso Una de las constantes ACCESO_*.
     * @param string|null $permiso Permiso RBAC requerido (config/permisos.php).
     * @return void
     */
    public function agregarRuta(string $metodo, string $ruta, callable $accion, string $acceso, ?string $permiso = null): void
    {
        $this->rutas[$ruta][$metodo] = ['accion' => $accion, 'acceso' => $acceso, 'permiso' => $permiso];
    }

    /**
     * Ejecuta la ruta solicitada tras pasar todos los controles.
     *
     * @param string $metodo Método HTTP (vacío si no es admitido).
     * @param string $ruta Ruta normalizada.
     * @param array<mixed> $datosFormulario $_POST o $_GET según el método.
     * @return Respuesta Resultado de la acción.
     * @throws ExcepcionHttp Ante cualquier control no superado.
     */
    public function despachar(string $metodo, string $ruta, array $datosFormulario): Respuesta
    {
        if (!isset($this->rutas[$ruta])) {
            throw ExcepcionHttp::noEncontrado();
        }
        if ($metodo === '' || !isset($this->rutas[$ruta][$metodo])) {
            throw ExcepcionHttp::metodoNoPermitido();
        }

        $definicionRuta = $this->rutas[$ruta][$metodo];
        $usuarioActual = $this->authMiddleware->obtenerUsuarioActual();

        if ($definicionRuta['acceso'] === self::ACCESO_AUTENTICADO) {
            $usuarioActual = $this->authMiddleware->verificarAutenticacion($usuarioActual, $metodo, $ruta);
        } elseif ($definicionRuta['acceso'] === self::ACCESO_SOLO_INVITADO && $usuarioActual !== null) {
            return Respuesta::redireccion('/inicio');
        }

        if ($definicionRuta['permiso'] !== null) {
            $this->roleMiddleware->verificarPermiso($usuarioActual, $definicionRuta['permiso']);
        }

        if ($metodo === 'POST' && !ProtectorCsrf::validarTokenCsrf($datosFormulario[ProtectorCsrf::NOMBRE_CAMPO_TOKEN] ?? null)) {
            $this->auditoriaModel->registrarEventoAuditoria(
                $usuarioActual !== null ? (int) $usuarioActual['id_usuario'] : null,
                AuditoriaModel::ACCION_CSRF_INVALIDO,
                'solicitud',
                null,
                ['ruta' => $ruta]
            );
            throw ExcepcionHttp::solicitudInvalida();
        }

        return ($definicionRuta['accion'])($usuarioActual, $datosFormulario);
    }
}

<?php

declare(strict_types=1);

/**
 * Middleware de AUTORIZACIÓN (RBAC): responde "¿puedes hacer esto?".
 *
 * Reglas (Fase I, Actividad 5 y OWASP Authorization Cheat Sheet):
 *  - Denegación por defecto: un permiso desconocido o un rol desconocido
 *    se deniegan.
 *  - El rol proviene SIEMPRE del registro leído de la BD en esta misma
 *    solicitud (GestorSesion::validarSesion), nunca de la sesión, campos
 *    ocultos, la URL ni JavaScript.
 *  - Toda denegación se audita; las relativas a privilegios se marcan de
 *    forma específica para facilitar su revisión.
 *
 * La comprobación de PROPIEDAD de un recurso (p. ej. "este comentario es
 * mío") corresponde a cada módulo, después de este middleware.
 */
final class RoleMiddleware
{
    /** Pseudo-rol de una solicitud sin sesión. No existe en la BD. */
    public const ROL_INVITADO = 'INVITADO';

    /** Permisos cuya denegación se considera intento de escalada. */
    private const PERMISOS_DE_PRIVILEGIO = ['asignar_rol_administrativo'];

    /**
     * @param array<string, list<string>> $matrizPermisos Contenido de config/permisos.php.
     * @param AuditoriaModel $auditoriaModel Registro de denegaciones.
     */
    public function __construct(
        private readonly array $matrizPermisos,
        private readonly AuditoriaModel $auditoriaModel
    ) {
    }

    /**
     * Consulta la matriz RBAC sin efectos secundarios.
     *
     * @param string|null $rolUsuario Rol leído de la BD, o null si es invitado.
     * @param string $permiso Clave de config/permisos.php.
     * @return bool true solo si el permiso existe y lista ese rol.
     */
    public function tienePermiso(?string $rolUsuario, string $permiso): bool
    {
        $rolEfectivo = $rolUsuario ?? self::ROL_INVITADO;
        if ($rolEfectivo !== self::ROL_INVITADO && !in_array($rolEfectivo, UsuarioModel::ROLES, true)) {
            return false;
        }
        $rolesPermitidos = $this->matrizPermisos[$permiso] ?? [];
        return $this->tieneRolPermitido($rolEfectivo, $rolesPermitidos);
    }

    /**
     * Exige un permiso; si falta, audita y detiene la solicitud.
     *
     * @param array<string, mixed>|null $usuarioActual Usuario de la sesión validada.
     * @param string $permiso Permiso requerido.
     * @return void
     * @throws ExcepcionHttp 401 para invitados en rutas que requieren cuenta; 403 si el rol no basta.
     */
    public function verificarPermiso(?array $usuarioActual, string $permiso): void
    {
        $rolUsuario = $usuarioActual['rol_usuario'] ?? null;
        if ($this->tienePermiso($rolUsuario, $permiso)) {
            return;
        }
        $this->denegar($usuarioActual, ['permiso' => $permiso]);
    }

    /**
     * Exige que el rol del usuario pertenezca a una lista explícita.
     * Útil para reglas puntuales que no son un permiso de la matriz.
     *
     * @param array<string, mixed>|null $usuarioActual Usuario de la sesión validada.
     * @param list<string> $rolesPermitidos Roles aceptados.
     * @return void
     * @throws ExcepcionHttp 401/403 si no cumple.
     */
    public function verificarRol(?array $usuarioActual, array $rolesPermitidos): void
    {
        $rolUsuario = $usuarioActual['rol_usuario'] ?? self::ROL_INVITADO;
        if ($this->tieneRolPermitido((string) $rolUsuario, $rolesPermitidos)) {
            return;
        }
        $this->denegar($usuarioActual, ['rolesRequeridos' => implode(',', $rolesPermitidos)]);
    }

    /**
     * Comprueba si un rol está en la lista de roles permitidos.
     *
     * @param string $rolUsuario Rol obtenido del servidor.
     * @param array<string> $rolesPermitidos Roles que pueden acceder.
     * @return bool Indica si el acceso está autorizado.
     */
    private function tieneRolPermitido(string $rolUsuario, array $rolesPermitidos): bool
    {
        return in_array($rolUsuario, $rolesPermitidos, true);
    }

    /**
     * Audita la denegación y lanza el error HTTP adecuado.
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<string, string> $detalle Contexto de la denegación.
     * @return never
     * @throws ExcepcionHttp Siempre.
     */
    private function denegar(?array $usuarioActual, array $detalle): never
    {
        $idUsuario = $usuarioActual !== null ? (int) $usuarioActual['id_usuario'] : null;
        $esIntentoDePrivilegio = in_array($detalle['permiso'] ?? '', self::PERMISOS_DE_PRIVILEGIO, true);

        $this->auditoriaModel->registrarEventoAuditoria(
            $idUsuario,
            $esIntentoDePrivilegio
                ? AuditoriaModel::ACCION_INTENTO_CAMBIO_PRIVILEGIO_DENEGADO
                : AuditoriaModel::ACCION_ACCESO_DENEGADO,
            'permiso',
            $idUsuario,
            $detalle + ['rol' => $usuarioActual['rol_usuario'] ?? self::ROL_INVITADO, 'ruta' => Solicitud::obtenerRuta()]
        );

        throw $usuarioActual === null ? ExcepcionHttp::noAutenticado() : ExcepcionHttp::accesoDenegado();
    }
}

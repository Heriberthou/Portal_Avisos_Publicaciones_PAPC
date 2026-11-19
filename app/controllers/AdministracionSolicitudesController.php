<?php

declare(strict_types=1);

/**
 * Pantalla E: bandeja administrativa de solicitudes de acceso.
 *
 * La ruta ya exige revisar_solicitudes / resolver_solicitud; el servicio
 * vuelve a comprobarlo (defensa en profundidad), junto con el estado de la
 * solicitud, la verificación del correo y que no sea una solicitud propia.
 */
final class AdministracionSolicitudesController
{
    use ControlFormulario;

    private const CAMPOS_APROBAR = ['token_csrf', 'id_solicitud', 'metodo_verificacion', 'confirmacion_pertenencia'];
    private const CAMPOS_RECHAZAR = ['token_csrf', 'id_solicitud', 'motivo_rechazo'];
    private const CAMPOS_REENVIAR = ['token_csrf', 'id_solicitud'];

    /**
     * @param ServicioSolicitudAcceso $servicioSolicitudAcceso Decisiones.
     * @param SolicitudAccesoModel $solicitudAccesoModel Consultas de la bandeja.
     * @param UsuarioModel $usuarioModel Estado de la cuenta vinculada.
     * @param GestorSesion $gestorSesion Mensajes flash.
     */
    public function __construct(
        private readonly ServicioSolicitudAcceso $servicioSolicitudAcceso,
        private readonly SolicitudAccesoModel $solicitudAccesoModel,
        private readonly UsuarioModel $usuarioModel,
        private readonly GestorSesion $gestorSesion
    ) {
    }

    /**
     * GET /admin/solicitudes?estado=&tipo=&busqueda=
     *
     * @param array<string, mixed>|null $usuarioActual Administrador.
     * @param array<mixed> $datosFormulario Filtros GET.
     * @return Respuesta Bandeja.
     */
    public function listarSolicitudes(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $estado = ValidadorEntrada::obtenerTexto($datosFormulario, 'estado') ?? SolicitudAccesoModel::ESTADO_PENDIENTE;
        if (!in_array($estado, SolicitudAccesoModel::ESTADOS, true)) {
            $estado = SolicitudAccesoModel::ESTADO_PENDIENTE;
        }
        $tipo = ValidadorEntrada::obtenerTexto($datosFormulario, 'tipo');
        if (!in_array($tipo, UsuarioModel::TIPOS_INTEGRANTE, true)) {
            $tipo = null;
        }
        $busqueda = mb_substr((string) ValidadorEntrada::obtenerTexto($datosFormulario, 'busqueda'), 0, 60);

        return Respuesta::vista('admin/solicitudes', [
            'tituloPagina'  => 'Solicitudes de acceso',
            'usuarioActual' => $usuarioActual,
            'mensajes'      => $this->gestorSesion->extraerMensajesFlash(),
            'solicitudes'   => $this->solicitudAccesoModel->listarSolicitudes($estado, $tipo, $busqueda),
            'filtroEstado'  => $estado,
            'filtroTipo'    => $tipo,
            'busqueda'      => $busqueda,
        ]);
    }

    /**
     * GET /admin/solicitudes/detalle?id=N
     *
     * @param array<string, mixed>|null $usuarioActual Administrador.
     * @param array<mixed> $datosFormulario id.
     * @return Respuesta Detalle con acciones.
     * @throws ExcepcionHttp 404 si no existe.
     */
    public function mostrarDetalle(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $idSolicitud = ValidadorEntrada::obtenerEnteroPositivo($datosFormulario['id'] ?? null);
        $solicitud = $idSolicitud !== null ? $this->solicitudAccesoModel->obtenerSolicitudPorId($idSolicitud) : null;
        if ($solicitud === null) {
            throw ExcepcionHttp::noEncontrado();
        }
        $cuentaVinculada = $solicitud['id_usuario_vinculado'] !== null
            ? $this->usuarioModel->obtenerUsuarioPorId((int) $solicitud['id_usuario_vinculado'])
            : null;

        return Respuesta::vista('admin/solicitud-detalle', [
            'tituloPagina'     => 'Detalle de solicitud',
            'usuarioActual'    => $usuarioActual,
            'mensajes'         => $this->gestorSesion->extraerMensajesFlash(),
            'solicitud'        => $solicitud,
            'cuentaVinculada'  => $cuentaVinculada,
        ]);
    }

    /**
     * POST /admin/solicitudes/aprobar
     *
     * @param array<string, mixed>|null $usuarioActual Administrador.
     * @param array<mixed> $datosFormulario Campos.
     * @return Respuesta Redirección con el resultado.
     */
    public function aprobarSolicitud(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, self::CAMPOS_APROBAR, 'aprobar-solicitud');
        $idSolicitud = $this->exigirIdSolicitud($datosFormulario);
        $resultado = $this->servicioSolicitudAcceso->aprobarSolicitud(
            (array) $usuarioActual,
            $idSolicitud,
            ValidadorEntrada::obtenerTextoAcotado($datosFormulario, 'metodo_verificacion', LONGITUD_MINIMA_METODO_VERIFICACION, LONGITUD_MAXIMA_METODO_VERIFICACION),
            ($datosFormulario['confirmacion_pertenencia'] ?? null) === 'si'
        );
        return $this->redirigirConResultado($resultado, $idSolicitud);
    }

    /**
     * POST /admin/solicitudes/rechazar
     *
     * @param array<string, mixed>|null $usuarioActual Administrador.
     * @param array<mixed> $datosFormulario Campos.
     * @return Respuesta Redirección con el resultado.
     */
    public function rechazarSolicitud(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, self::CAMPOS_RECHAZAR, 'rechazar-solicitud');
        $idSolicitud = $this->exigirIdSolicitud($datosFormulario);
        $resultado = $this->servicioSolicitudAcceso->rechazarSolicitud(
            (array) $usuarioActual,
            $idSolicitud,
            ValidadorEntrada::obtenerTextoAcotado($datosFormulario, 'motivo_rechazo', LONGITUD_MINIMA_MOTIVO_RECHAZO, LONGITUD_MAXIMA_MOTIVO_RECHAZO)
        );
        return $this->redirigirConResultado($resultado, $idSolicitud);
    }

    /**
     * POST /admin/solicitudes/reenviar-invitacion
     *
     * @param array<string, mixed>|null $usuarioActual Administrador.
     * @param array<mixed> $datosFormulario Campos.
     * @return Respuesta Redirección con el resultado.
     */
    public function reenviarInvitacion(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        $this->exigirCamposPermitidos($datosFormulario, self::CAMPOS_REENVIAR, 'reenviar-invitacion');
        $idSolicitud = $this->exigirIdSolicitud($datosFormulario);
        return $this->redirigirConResultado($this->servicioSolicitudAcceso->reenviarInvitacion((array) $usuarioActual, $idSolicitud), $idSolicitud);
    }

    /**
     * @param array<mixed> $datosFormulario Campos.
     * @return int Id válido.
     * @throws ExcepcionHttp 400 si falta o no es válido.
     */
    private function exigirIdSolicitud(array $datosFormulario): int
    {
        $idSolicitud = ValidadorEntrada::obtenerEnteroPositivo($datosFormulario['id_solicitud'] ?? null);
        if ($idSolicitud === null) {
            throw ExcepcionHttp::solicitudInvalida();
        }
        return $idSolicitud;
    }

    /**
     * Tras una decisión exitosa vuelve a la bandeja; si falló, al detalle.
     *
     * @param array{exito: bool, mensaje: string} $resultado Resultado del servicio.
     * @param int $idSolicitud Solicitud.
     * @return Respuesta Redirección.
     */
    private function redirigirConResultado(array $resultado, int $idSolicitud): Respuesta
    {
        return $this->redirigirConMensaje(
            $resultado['exito'] ? '/admin/solicitudes' : '/admin/solicitudes/detalle?id=' . $idSolicitud,
            $resultado['exito'] ? 'exito' : 'error',
            $resultado['mensaje']
        );
    }
}

<?php

declare(strict_types=1);

/**
 * Reglas del circuito "Solicitar acceso" (especificación 1.3, secciones 4 y 6):
 *
 *   visitante -> solicitud PENDIENTE (correo sin verificar)
 *             -> verifica su correo con un enlace de un solo uso
 *             -> un administrador coteja la pertenencia y APRUEBA o RECHAZA
 *             -> si aprueba: cuenta INVITADA (rol USUARIO) + enlace de activación
 *
 * Garantías:
 *  - La respuesta pública es siempre neutral: no revela si el correo ya
 *    tiene cuenta, si hay una solicitud previa ni qué controles se aplican.
 *  - Las peticiones repetidas no crean solicitudes, cuentas ni enlaces
 *    vigentes múltiples (UNIQUE de correo pendiente + reemplazo de tokens).
 *  - Aprobar = bloqueo de la fila + crear cuenta + resolver + token + auditoría
 *    en UNA transacción. El correo se envía después; si falla, se informa y
 *    puede reintentarse sin duplicar nada.
 *  - Nadie resuelve una solicitud con su propio correo.
 */
final class ServicioSolicitudAcceso
{
    public const RESULTADO_RECIBIDA = 'RECIBIDA';
    public const RESULTADO_LIMITE_ALCANZADO = 'LIMITE_ALCANZADO';
    public const RESULTADO_VERIFICADA = 'VERIFICADA';
    public const RESULTADO_ENLACE_INVALIDO = 'ENLACE_INVALIDO';

    private const AMBITO_LIMITE = 'solicitud';
    private const ERROR_MARIADB_DUPLICADO = 1062;

    /**
     * @param PDO $conexion Conexión para transacciones.
     * @param SolicitudAccesoModel $solicitudAccesoModel Solicitudes.
     * @param TokenSolicitudModel $tokenSolicitudModel Enlaces de verificación.
     * @param UsuarioModel $usuarioModel Cuentas.
     * @param TokenCuentaModel $tokenCuentaModel Enlaces de activación.
     * @param AuditoriaModel $auditoriaModel Bitácora.
     * @param LimitadorIntentos $limitadorIntentos Antiautomatización.
     * @param ServicioCorreo $servicioCorreo Notificaciones.
     * @param RoleMiddleware $roleMiddleware Matriz RBAC (defensa en profundidad).
     */
    public function __construct(
        private readonly PDO $conexion,
        private readonly SolicitudAccesoModel $solicitudAccesoModel,
        private readonly TokenSolicitudModel $tokenSolicitudModel,
        private readonly UsuarioModel $usuarioModel,
        private readonly TokenCuentaModel $tokenCuentaModel,
        private readonly AuditoriaModel $auditoriaModel,
        private readonly LimitadorIntentos $limitadorIntentos,
        private readonly ServicioCorreo $servicioCorreo,
        private readonly RoleMiddleware $roleMiddleware
    ) {
    }

    /**
     * Registra una solicitud pública (o reacciona a una repetida).
     *
     * @param array{nombres: string, apellidoPaterno: string, apellidoMaterno: string|null,
     *              tipoIntegrante: string, identificador: string, correo: string} $datosSolicitud
     *        Salida de ValidadorEntrada::validarDatosSolicitud().
     * @param string $origen IP del cliente.
     * @return string RESULTADO_RECIBIDA (siempre, salvo límite de frecuencia).
     */
    public function registrarSolicitud(array $datosSolicitud, string $origen): string
    {
        $correo = $datosSolicitud['correo'];
        // Se reserva el envío antes de procesarlo (R-06): cuenta aunque lleguen varios a la vez.
        if ($this->limitadorIntentos->reservarIntento(self::AMBITO_LIMITE, $correo, $origen, MAX_ENVIOS_FORMULARIO_CORREO, MAX_ENVIOS_FORMULARIO_ORIGEN) === null) {
            $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_SOLICITUD_LIMITADA, 'solicitud_acceso', null, [
                'huellaCorreo' => $this->limitadorIntentos->calcularHuella($correo),
            ]);
            return self::RESULTADO_LIMITE_ALCANZADO;
        }

        if ($this->usuarioModel->existeCorreo($correo)) {
            $this->atenderSolicitudRepetida($correo, null, 'CUENTA_EXISTENTE');
            return self::RESULTADO_RECIBIDA;
        }

        $solicitudPrevia = $this->solicitudAccesoModel->obtenerUltimaSolicitudPorCorreo($correo);
        if ($solicitudPrevia !== null) {
            $this->atenderSolicitudPrevia($solicitudPrevia);
            return self::RESULTADO_RECIBIDA;
        }

        try {
            $idSolicitud = $this->solicitudAccesoModel->crearSolicitud($datosSolicitud);
        } catch (PDOException $excepcion) {
            // Dos envíos simultáneos: el UNIQUE del correo pendiente lo resuelve.
            if ((int) ($excepcion->errorInfo[1] ?? 0) === self::ERROR_MARIADB_DUPLICADO) {
                $this->atenderSolicitudRepetida($correo, null, 'EN_REVISION');
                return self::RESULTADO_RECIBIDA;
            }
            throw $excepcion;
        }

        $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_SOLICITUD_CREADA, 'solicitud_acceso', $idSolicitud, [
            'tipoIntegrante' => $datosSolicitud['tipoIntegrante'],
        ]);
        $this->enviarEnlaceVerificacion($idSolicitud, $correo, $datosSolicitud['nombres']);
        return self::RESULTADO_RECIBIDA;
    }

    /**
     * "Reenviar enlace": solo actúa si existe una solicitud PENDIENTE sin
     * verificar con ese correo. La respuesta pública es la misma siempre.
     *
     * @param string $correo Correo normalizado.
     * @param string $origen IP del cliente.
     * @return string RESULTADO_RECIBIDA o RESULTADO_LIMITE_ALCANZADO.
     */
    public function reenviarVerificacion(string $correo, string $origen): string
    {
        // Se reserva el envío antes de procesarlo (R-06): cuenta aunque lleguen varios a la vez.
        if ($this->limitadorIntentos->reservarIntento(self::AMBITO_LIMITE, $correo, $origen, MAX_ENVIOS_FORMULARIO_CORREO, MAX_ENVIOS_FORMULARIO_ORIGEN) === null) {
            return self::RESULTADO_LIMITE_ALCANZADO;
        }

        $solicitud = $this->solicitudAccesoModel->obtenerUltimaSolicitudPorCorreo($correo);
        if ($solicitud !== null && $solicitud['estado_solicitud'] === SolicitudAccesoModel::ESTADO_PENDIENTE
            && $solicitud['fecha_verificacion_correo_solicitud'] === null) {
            $this->enviarEnlaceVerificacion((int) $solicitud['id_solicitud_acceso'], $correo, (string) $solicitud['nombres_solicitante']);
        }
        return self::RESULTADO_RECIBIDA;
    }

    /**
     * Consume el enlace de verificación del correo.
     *
     * @param string $token Token en claro (formato ya validado).
     * @return string RESULTADO_VERIFICADA o RESULTADO_ENLACE_INVALIDO.
     */
    public function verificarCorreo(string $token): string
    {
        $this->conexion->beginTransaction();
        try {
            $tokenVigente = $this->tokenSolicitudModel->obtenerTokenVigente(GeneradorTokens::calcularHashToken($token));
            if ($tokenVigente === null
                || !$this->tokenSolicitudModel->consumirToken((int) $tokenVigente['id_token_solicitud'])
                || !$this->solicitudAccesoModel->marcarCorreoVerificado((int) $tokenVigente['id_solicitud_acceso_destinataria'])) {
                $this->conexion->rollBack();
                $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_ENLACE_INVALIDO, 'token_solicitud', null, ['finalidad' => 'VERIFICACION']);
                return self::RESULTADO_ENLACE_INVALIDO;
            }
            $idSolicitud = (int) $tokenVigente['id_solicitud_acceso_destinataria'];
            $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_CORREO_SOLICITUD_VERIFICADO, 'solicitud_acceso', $idSolicitud);
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            $this->revertir();
            throw $excepcion;
        }
        return self::RESULTADO_VERIFICADA;
    }

    /**
     * Aprueba una solicitud verificada y genera la invitación.
     *
     * @param array<string, mixed> $usuarioActor Administrador autenticado.
     * @param int $idSolicitud Solicitud.
     * @param string|null $metodoVerificacion Cómo se cotejó la pertenencia (validado).
     * @param bool $confirmacionPertenencia Casilla "Confirmo que verifiqué...".
     * @return array{exito: bool, mensaje: string}
     */
    public function aprobarSolicitud(array $usuarioActor, int $idSolicitud, ?string $metodoVerificacion, bool $confirmacionPertenencia): array
    {
        $idActor = (int) $usuarioActor['id_usuario'];
        if (!$this->roleMiddleware->tienePermiso((string) $usuarioActor['rol_usuario'], 'resolver_solicitud')) {
            $this->auditarDecisionDenegada($idActor, $idSolicitud, 'SIN_PERMISO');
            return $this->resultado(false, 'No tiene permiso para realizar esta acción.');
        }
        if (!$confirmacionPertenencia) {
            return $this->resultado(false, 'Confirme que verificó la pertenencia institucional antes de aprobar.');
        }
        if ($metodoVerificacion === null) {
            return $this->resultado(false, sprintf(
                'Indique cómo verificó la pertenencia (entre %d y %d caracteres).',
                LONGITUD_MINIMA_METODO_VERIFICACION,
                LONGITUD_MAXIMA_METODO_VERIFICACION
            ));
        }

        $tokenActivacion = GeneradorTokens::generarToken();
        $this->conexion->beginTransaction();
        try {
            $solicitud = $this->solicitudAccesoModel->obtenerSolicitudPorId($idSolicitud, true);
            $errorDecision = $this->validarDecisionPosible($solicitud, $usuarioActor);
            if ($errorDecision !== null) {
                $this->conexion->rollBack();
                $this->auditarDecisionDenegada($idActor, $idSolicitud, $errorDecision['motivo']);
                return $this->resultado(false, $errorDecision['mensaje']);
            }
            $correo = (string) $solicitud['correo_electronico_solicitante'];
            $identificador = (string) $solicitud['identificador_institucional_solicitante'];
            if ($this->usuarioModel->existeCorreo($correo) || $this->usuarioModel->existeIdentificador($identificador)) {
                $this->conexion->rollBack();
                $this->auditarDecisionDenegada($idActor, $idSolicitud, 'CUENTA_EXISTENTE');
                return $this->resultado(false, 'Ya existe una cuenta con ese correo o identificador institucional. Rechace la solicitud indicando el motivo o consulte a soporte.');
            }

            $idUsuarioCreado = $this->usuarioModel->crearCuentaInvitada([
                'nombres'         => (string) $solicitud['nombres_solicitante'],
                'apellidoPaterno' => (string) $solicitud['apellido_paterno_solicitante'],
                'apellidoMaterno' => $solicitud['apellido_materno_solicitante'],
                'correo'          => $correo,
                'tipoIntegrante'  => (string) $solicitud['tipo_integrante_solicitado'],
                'identificador'   => $identificador,
            ], $idActor);
            if (!$this->solicitudAccesoModel->aprobarSolicitud($idSolicitud, $idActor, $metodoVerificacion, $idUsuarioCreado)) {
                throw new RuntimeException('La solicitud cambió durante la aprobación.');
            }
            $this->tokenCuentaModel->crearToken($idUsuarioCreado, TokenCuentaModel::FINALIDAD_ACTIVACION, GeneradorTokens::calcularHashToken($tokenActivacion), VIGENCIA_TOKEN_ACTIVACION_SEGUNDOS);

            $this->auditoriaModel->registrarEventoAuditoria($idActor, AuditoriaModel::ACCION_SOLICITUD_APROBADA, 'solicitud_acceso', $idSolicitud, [
                'idUsuarioCreado' => $idUsuarioCreado,
                'metodoVerificacion' => $metodoVerificacion,
                'tipoIntegrante' => $solicitud['tipo_integrante_solicitado'],
                'rolAsignado' => UsuarioModel::ROL_USUARIO,
                'estadoCuenta' => UsuarioModel::ESTADO_INVITADA,
            ]);
            $this->auditoriaModel->registrarEventoAuditoria($idActor, AuditoriaModel::ACCION_INVITACION_GENERADA, 'usuario', $idUsuarioCreado);
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            $this->revertir();
            throw $excepcion;
        }

        if (!$this->servicioCorreo->enviarInvitacionActivacion($correo, (string) $solicitud['nombres_solicitante'], $tokenActivacion)) {
            $this->auditoriaModel->registrarEventoAuditoria($idActor, AuditoriaModel::ACCION_ENVIO_CORREO_FALLIDO, 'usuario', $idUsuarioCreado, ['tipoCorreo' => 'INVITACION']);
            return $this->resultado(true, 'Solicitud aprobada. El envío de la invitación está pendiente; reintente desde la administración de solicitudes.');
        }
        return $this->resultado(true, $this->servicioCorreo->esModoBuzonPruebas()
            ? 'Solicitud aprobada. Se generó una invitación de activación; quedó en el buzón de pruebas del servidor (no se envió por correo).'
            : 'Solicitud aprobada. Se generó una invitación de activación y se entregó al servicio de correo.');
    }

    /**
     * Rechaza una solicitud verificada (no crea cuenta).
     *
     * @param array<string, mixed> $usuarioActor Administrador autenticado.
     * @param int $idSolicitud Solicitud.
     * @param string|null $motivo Motivo interno validado.
     * @return array{exito: bool, mensaje: string}
     */
    public function rechazarSolicitud(array $usuarioActor, int $idSolicitud, ?string $motivo): array
    {
        $idActor = (int) $usuarioActor['id_usuario'];
        if (!$this->roleMiddleware->tienePermiso((string) $usuarioActor['rol_usuario'], 'resolver_solicitud')) {
            $this->auditarDecisionDenegada($idActor, $idSolicitud, 'SIN_PERMISO');
            return $this->resultado(false, 'No tiene permiso para realizar esta acción.');
        }
        if ($motivo === null) {
            return $this->resultado(false, sprintf(
                'Indique un motivo de revisión para dejar constancia de la decisión (entre %d y %d caracteres).',
                LONGITUD_MINIMA_MOTIVO_RECHAZO,
                LONGITUD_MAXIMA_MOTIVO_RECHAZO
            ));
        }

        $this->conexion->beginTransaction();
        try {
            $solicitud = $this->solicitudAccesoModel->obtenerSolicitudPorId($idSolicitud, true);
            $errorDecision = $this->validarDecisionPosible($solicitud, $usuarioActor);
            if ($errorDecision !== null) {
                $this->conexion->rollBack();
                $this->auditarDecisionDenegada($idActor, $idSolicitud, $errorDecision['motivo']);
                return $this->resultado(false, $errorDecision['mensaje']);
            }
            if (!$this->solicitudAccesoModel->rechazarSolicitud($idSolicitud, $idActor, $motivo)) {
                throw new RuntimeException('La solicitud cambió durante el rechazo.');
            }
            // El motivo queda en solicitud_acceso (acceso restringido); la bitácora solo registra la decisión.
            $this->auditoriaModel->registrarEventoAuditoria($idActor, AuditoriaModel::ACCION_SOLICITUD_RECHAZADA, 'solicitud_acceso', $idSolicitud);
            $this->conexion->commit();
        } catch (Throwable $excepcion) {
            $this->revertir();
            throw $excepcion;
        }

        if (!$this->servicioCorreo->enviarRechazoSolicitud((string) $solicitud['correo_electronico_solicitante'])) {
            $this->auditoriaModel->registrarEventoAuditoria($idActor, AuditoriaModel::ACCION_ENVIO_CORREO_FALLIDO, 'solicitud_acceso', $idSolicitud, ['tipoCorreo' => 'RECHAZO']);
            return $this->resultado(true, 'Solicitud rechazada. No fue posible enviar la notificación al solicitante.');
        }
        return $this->resultado(true, 'Solicitud rechazada. Se registró el motivo.');
    }

    /**
     * Genera un enlace de activación nuevo para una solicitud APROBADA cuya
     * cuenta sigue INVITADA (envío fallido o enlace vencido). Invalida el
     * enlace anterior.
     *
     * @param array<string, mixed> $usuarioActor Administrador autenticado.
     * @param int $idSolicitud Solicitud aprobada.
     * @return array{exito: bool, mensaje: string}
     */
    public function reenviarInvitacion(array $usuarioActor, int $idSolicitud): array
    {
        $idActor = (int) $usuarioActor['id_usuario'];
        if (!$this->roleMiddleware->tienePermiso((string) $usuarioActor['rol_usuario'], 'resolver_solicitud')) {
            $this->auditarDecisionDenegada($idActor, $idSolicitud, 'SIN_PERMISO');
            return $this->resultado(false, 'No tiene permiso para realizar esta acción.');
        }
        $solicitud = $this->solicitudAccesoModel->obtenerSolicitudPorId($idSolicitud);
        $idUsuario = (int) ($solicitud['id_usuario_vinculado'] ?? 0);
        $cuenta = $idUsuario > 0 ? $this->usuarioModel->obtenerUsuarioPorId($idUsuario) : null;
        if ($solicitud === null || $solicitud['estado_solicitud'] !== SolicitudAccesoModel::ESTADO_APROBADA
            || $cuenta === null || $cuenta['estado_cuenta_usuario'] !== UsuarioModel::ESTADO_INVITADA) {
            return $this->resultado(false, 'Solo se puede reenviar la invitación de una solicitud aprobada cuya cuenta aún no se activa.');
        }

        $tokenActivacion = GeneradorTokens::generarToken();
        $this->tokenCuentaModel->crearToken($idUsuario, TokenCuentaModel::FINALIDAD_ACTIVACION, GeneradorTokens::calcularHashToken($tokenActivacion), VIGENCIA_TOKEN_ACTIVACION_SEGUNDOS);
        $this->auditoriaModel->registrarEventoAuditoria($idActor, AuditoriaModel::ACCION_INVITACION_GENERADA, 'usuario', $idUsuario, ['reenvio' => true]);

        if (!$this->servicioCorreo->enviarInvitacionActivacion((string) $cuenta['correo_electronico_usuario'], (string) $cuenta['nombres_usuario'], $tokenActivacion)) {
            $this->auditoriaModel->registrarEventoAuditoria($idActor, AuditoriaModel::ACCION_ENVIO_CORREO_FALLIDO, 'usuario', $idUsuario, ['tipoCorreo' => 'INVITACION']);
            return $this->resultado(false, 'Se generó un enlace nuevo, pero el envío sigue pendiente. Intente de nuevo más tarde.');
        }
        return $this->resultado(true, $this->servicioCorreo->esModoBuzonPruebas()
            ? 'Se generó una invitación nueva; quedó en el buzón de pruebas del servidor. El enlace anterior dejó de funcionar.'
            : 'Se generó una invitación nueva y se entregó al servicio de correo. El enlace anterior dejó de funcionar.');
    }

    /**
     * Comprueba que la solicitud pueda resolverse por este actor.
     *
     * @param array<string, mixed>|null $solicitud Fila bloqueada.
     * @param array<string, mixed> $usuarioActor Administrador.
     * @return array{motivo: string, mensaje: string}|null Error o null si procede.
     */
    private function validarDecisionPosible(?array $solicitud, array $usuarioActor): ?array
    {
        if ($solicitud === null) {
            return ['motivo' => 'INEXISTENTE', 'mensaje' => 'La solicitud indicada no existe.'];
        }
        if ($solicitud['estado_solicitud'] !== SolicitudAccesoModel::ESTADO_PENDIENTE) {
            return ['motivo' => 'YA_RESUELTA', 'mensaje' => 'Esta solicitud ya fue resuelta; no se admiten decisiones duplicadas.'];
        }
        if ($solicitud['fecha_verificacion_correo_solicitud'] === null) {
            return ['motivo' => 'CORREO_NO_VERIFICADO', 'mensaje' => 'La solicitud no puede resolverse hasta que la persona verifique su correo.'];
        }
        if (hash_equals((string) $usuarioActor['correo_electronico_usuario'], (string) $solicitud['correo_electronico_solicitante'])
            || ($usuarioActor['identificador_institucional_usuario'] ?? null) === $solicitud['identificador_institucional_solicitante']) {
            return ['motivo' => 'SOLICITUD_PROPIA', 'mensaje' => 'No puede resolver una solicitud asociada a su propia identidad.'];
        }
        return null;
    }

    /**
     * Respuesta a una solicitud previa con el mismo correo (sin crear otra).
     *
     * @param array<string, mixed> $solicitudPrevia Fila más reciente.
     * @return void
     */
    private function atenderSolicitudPrevia(array $solicitudPrevia): void
    {
        $correo = (string) $solicitudPrevia['correo_electronico_solicitante'];
        $idSolicitud = (int) $solicitudPrevia['id_solicitud_acceso'];
        if ($solicitudPrevia['estado_solicitud'] === SolicitudAccesoModel::ESTADO_PENDIENTE) {
            if ($solicitudPrevia['fecha_verificacion_correo_solicitud'] === null) {
                // Sin verificar: se reenvía el enlace (el anterior deja de servir).
                $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_SOLICITUD_REPETIDA, 'solicitud_acceso', $idSolicitud, ['situacion' => 'REENVIO_VERIFICACION']);
                $this->enviarEnlaceVerificacion($idSolicitud, $correo, (string) $solicitudPrevia['nombres_solicitante']);
                return;
            }
            $this->atenderSolicitudRepetida($correo, $idSolicitud, 'EN_REVISION');
            return;
        }
        // RECHAZADA: solo se reconsidera por un procedimiento explícito (soporte).
        // APROBADA: ya existe una cuenta vinculada.
        $situacion = $solicitudPrevia['estado_solicitud'] === SolicitudAccesoModel::ESTADO_RECHAZADA ? 'RECHAZADA_PREVIA' : 'CUENTA_EXISTENTE';
        $this->atenderSolicitudRepetida($correo, $idSolicitud, $situacion);
    }

    /**
     * @param string $correo Destinatario.
     * @param int|null $idSolicitud Solicitud relacionada.
     * @param string $situacion CUENTA_EXISTENTE, EN_REVISION o RECHAZADA_PREVIA.
     * @return void
     */
    private function atenderSolicitudRepetida(string $correo, ?int $idSolicitud, string $situacion): void
    {
        $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_SOLICITUD_REPETIDA, 'solicitud_acceso', $idSolicitud, ['situacion' => $situacion]);
        $this->servicioCorreo->enviarAvisoSolicitudRepetida($correo, $situacion);
    }

    /**
     * @param int $idSolicitud Solicitud.
     * @param string $correo Destinatario.
     * @param string $nombre Nombre del solicitante.
     * @return void
     */
    private function enviarEnlaceVerificacion(int $idSolicitud, string $correo, string $nombre): void
    {
        $token = GeneradorTokens::generarToken();
        $this->tokenSolicitudModel->crearToken($idSolicitud, GeneradorTokens::calcularHashToken($token), VIGENCIA_TOKEN_SOLICITUD_SEGUNDOS);
        if (!$this->servicioCorreo->enviarVerificacionSolicitud($correo, $nombre, $token)) {
            $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_ENVIO_CORREO_FALLIDO, 'solicitud_acceso', $idSolicitud, ['tipoCorreo' => 'VERIFICACION']);
        }
    }

    /**
     * @param int $idActor Administrador.
     * @param int $idSolicitud Solicitud.
     * @param string $motivo Motivo de la denegación.
     * @return void
     */
    private function auditarDecisionDenegada(int $idActor, int $idSolicitud, string $motivo): void
    {
        $this->auditoriaModel->registrarEventoAuditoria($idActor, AuditoriaModel::ACCION_DECISION_SOLICITUD_DENEGADA, 'solicitud_acceso', $idSolicitud, ['motivo' => $motivo]);
    }

    /**
     * @return void
     */
    private function revertir(): void
    {
        if ($this->conexion->inTransaction()) {
            $this->conexion->rollBack();
        }
    }

    /**
     * @param bool $exito Si la operación se completó.
     * @param string $mensaje Mensaje para la interfaz.
     * @return array{exito: bool, mensaje: string}
     */
    private function resultado(bool $exito, string $mensaje): array
    {
        return ['exito' => $exito, 'mensaje' => $mensaje];
    }
}

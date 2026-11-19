<?php
/**
 * Detalle de una solicitud con las decisiones disponibles.
 *
 * Aprobar exige confirmar que se verificó la pertenencia y registrar el
 * método; rechazar exige un motivo. El servidor revalida todo.
 *
 * Variables: solicitud (array), cuentaVinculada (array|null).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$solicitud = $datosVista['solicitud'];
$idSolicitud = (int) $solicitud['id_solicitud_acceso'];
$esPendiente = $solicitud['estado_solicitud'] === SolicitudAccesoModel::ESTADO_PENDIENTE;
$correoVerificado = $solicitud['fecha_verificacion_correo_solicitud'] !== null;
$cuentaVinculada = $datosVista['cuentaVinculada'];
?>
<section class="tarjeta-amplia">
    <?php require RUTA_RAIZ . '/app/views/parciales/navegacion-admin.php'; ?>
    <h1>Solicitud de acceso</h1>

    <dl class="datos-cuenta">
        <dt>Nombre</dt>
        <dd><?= Vista::escapar(trim($solicitud['nombres_solicitante'] . ' ' . $solicitud['apellido_paterno_solicitante'] . ' ' . ($solicitud['apellido_materno_solicitante'] ?? ''))) ?></dd>
        <dt>Tipo de integrante solicitado</dt>
        <dd><?= Vista::escapar($solicitud['tipo_integrante_solicitado']) ?></dd>
        <dt>Matrícula o identificador</dt>
        <dd><?= Vista::escapar($solicitud['identificador_institucional_solicitante']) ?></dd>
        <dt>Correo de contacto</dt>
        <dd><?= Vista::escapar($solicitud['correo_electronico_solicitante']) ?>
            — <?= $correoVerificado ? 'verificado el ' . Vista::escapar($solicitud['fecha_verificacion_correo_solicitud']) : '<strong>sin verificar</strong>' ?></dd>
        <dt>Fecha de la solicitud</dt>
        <dd><?= Vista::escapar($solicitud['fecha_creacion_solicitud']) ?></dd>
        <dt>Estado</dt>
        <dd><span class="etiqueta-estado estado-<?= Vista::escapar(strtolower($solicitud['estado_solicitud'])) ?>"><?= Vista::escapar($solicitud['estado_solicitud']) ?></span></dd>
        <?php if (!$esPendiente): ?>
            <dt>Resuelta por</dt>
            <dd><?= Vista::escapar($solicitud['nombre_revisor'] ?? '—') ?>, el <?= Vista::escapar($solicitud['fecha_resolucion_solicitud']) ?></dd>
            <?php if ($solicitud['metodo_verificacion_solicitud'] !== null): ?>
                <dt>Método de verificación</dt>
                <dd><?= Vista::escapar($solicitud['metodo_verificacion_solicitud']) ?></dd>
            <?php endif; ?>
            <?php if ($solicitud['motivo_resolucion_solicitud'] !== null): ?>
                <dt>Motivo interno del rechazo</dt>
                <dd><?= Vista::escapar($solicitud['motivo_resolucion_solicitud']) ?></dd>
            <?php endif; ?>
            <?php if ($cuentaVinculada !== null): ?>
                <dt>Cuenta creada</dt>
                <dd><?= Vista::escapar($cuentaVinculada['estado_cuenta_usuario']) ?> · rol <?= Vista::escapar($cuentaVinculada['rol_usuario']) ?></dd>
            <?php endif; ?>
        <?php endif; ?>
    </dl>

    <?php if ($esPendiente && $correoVerificado): ?>
        <div class="decisiones-solicitud">
            <form method="post" action="<?= Vista::escapar(Vista::url('/admin/solicitudes/aprobar')) ?>" class="formulario-decision" data-formulario="aprobar">
                <?= Vista::campoTokenCsrf() ?>
                <input type="hidden" name="id_solicitud" value="<?= $idSolicitud ?>">
                <h2>Aprobar</h2>
                <label for="metodo-verificacion">¿Cómo verificó la pertenencia institucional?</label>
                <input id="metodo-verificacion" name="metodo_verificacion" type="text" required
                       minlength="<?= LONGITUD_MINIMA_METODO_VERIFICACION ?>" maxlength="<?= LONGITUD_MAXIMA_METODO_VERIFICACION ?>"
                       placeholder="Ej.: cotejo con el padrón de alumnos 2026-B" data-validar="requerido"
                       data-mensaje-vacio="Indique cómo verificó la pertenencia." aria-describedby="error-metodo-verificacion">
                <p class="error-campo" id="error-metodo-verificacion" aria-live="polite"></p>
                <label class="casilla">
                    <input type="checkbox" name="confirmacion_pertenencia" value="si" required>
                    ¿Confirma que verificó la pertenencia institucional y desea autorizar una cuenta de usuario común?
                </label>
                <button type="submit" class="boton-principal">Aprobar solicitud</button>
            </form>

            <form method="post" action="<?= Vista::escapar(Vista::url('/admin/solicitudes/rechazar')) ?>" class="formulario-decision" data-formulario="rechazar">
                <?= Vista::campoTokenCsrf() ?>
                <input type="hidden" name="id_solicitud" value="<?= $idSolicitud ?>">
                <h2>Rechazar</h2>
                <label for="motivo-rechazo">Indique un motivo de revisión para dejar constancia de la decisión</label>
                <textarea id="motivo-rechazo" name="motivo_rechazo" rows="3" required
                          minlength="<?= LONGITUD_MINIMA_MOTIVO_RECHAZO ?>" maxlength="<?= LONGITUD_MAXIMA_MOTIVO_RECHAZO ?>"
                          data-validar="requerido" data-mensaje-vacio="Escriba el motivo del rechazo."
                          aria-describedby="ayuda-motivo error-motivo-rechazo"></textarea>
                <small class="texto-secundario" id="ayuda-motivo">Solo lo verá el personal autorizado. Al solicitante se le informa que la solicitud no fue autorizada.</small>
                <p class="error-campo" id="error-motivo-rechazo" aria-live="polite"></p>
                <button type="submit" class="boton-secundario">Rechazar solicitud</button>
            </form>
        </div>
    <?php elseif ($esPendiente): ?>
        <p class="mensaje mensaje-aviso">La persona aún no verifica su correo: la solicitud no puede resolverse.</p>
    <?php elseif ($cuentaVinculada !== null && $cuentaVinculada['estado_cuenta_usuario'] === UsuarioModel::ESTADO_INVITADA): ?>
        <form method="post" action="<?= Vista::escapar(Vista::url('/admin/solicitudes/reenviar-invitacion')) ?>">
            <?= Vista::campoTokenCsrf() ?>
            <input type="hidden" name="id_solicitud" value="<?= $idSolicitud ?>">
            <p class="texto-secundario">La cuenta aún no se activa. Si el envío falló o el enlace venció, genere uno nuevo (el anterior dejará de funcionar).</p>
            <button type="submit" class="boton-secundario">Reenviar invitación</button>
        </form>
    <?php endif; ?>

    <p><a href="<?= Vista::escapar(Vista::url('/admin/solicitudes')) ?>">Volver a la bandeja</a></p>
</section>
<script src="<?= Vista::escapar(Vista::url('/js/validacion-formularios.js')) ?>" defer></script>

<?php
/**
 * Pantalla E: bandeja de solicitudes de acceso.
 *
 * En "Pendientes" solo aparecen solicitudes con el correo ya verificado.
 * Los estados se muestran con texto (no solo con color).
 *
 * Variables: solicitudes (list), filtroEstado, filtroTipo, busqueda.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$estadosFiltro = ['PENDIENTE' => 'Pendientes', 'APROBADA' => 'Aprobadas', 'RECHAZADA' => 'Rechazadas'];
?>
<section class="tarjeta-amplia">
    <?php require RUTA_RAIZ . '/app/views/parciales/navegacion-admin.php'; ?>
    <h1>Solicitudes de acceso</h1>

    <form method="get" action="<?= Vista::escapar(Vista::url('/admin/solicitudes')) ?>" class="filtros-bandeja">
        <label for="filtro-estado">Estado</label>
        <select id="filtro-estado" name="estado">
            <?php foreach ($estadosFiltro as $valorEstado => $textoEstado): ?>
                <option value="<?= Vista::escapar($valorEstado) ?>" <?= $datosVista['filtroEstado'] === $valorEstado ? 'selected' : '' ?>><?= Vista::escapar($textoEstado) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="filtro-tipo">Tipo</label>
        <select id="filtro-tipo" name="tipo">
            <option value="">Todos</option>
            <?php foreach (UsuarioModel::TIPOS_INTEGRANTE as $tipo): ?>
                <option value="<?= Vista::escapar($tipo) ?>" <?= $datosVista['filtroTipo'] === $tipo ? 'selected' : '' ?>><?= Vista::escapar(ucfirst(strtolower($tipo))) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="filtro-busqueda">Buscar</label>
        <input id="filtro-busqueda" name="busqueda" type="search" maxlength="60" placeholder="Nombre, correo o identificador"
               value="<?= Vista::escapar($datosVista['busqueda']) ?>">
        <button type="submit" class="boton-secundario">Filtrar</button>
    </form>

    <?php if ($datosVista['filtroEstado'] === 'PENDIENTE'): ?>
        <p class="texto-secundario">Solo se muestran solicitudes cuyo correo ya fue verificado. Las que no lo verifican no entran a revisión.</p>
    <?php endif; ?>

    <?php if ($datosVista['solicitudes'] === []): ?>
        <p class="mensaje mensaje-aviso">No hay solicitudes con esos filtros.</p>
    <?php else: ?>
        <div class="contenedor-tabla">
        <table class="tabla-usuarios">
            <caption class="etiqueta-oculta">Solicitudes de acceso</caption>
            <thead>
            <tr><th scope="col">Fecha</th><th scope="col">Nombre</th><th scope="col">Tipo</th><th scope="col">Identificador</th><th scope="col">Correo</th><th scope="col">Estado</th><th scope="col">Acción</th></tr>
            </thead>
            <tbody>
            <?php foreach ($datosVista['solicitudes'] as $solicitud): ?>
                <tr>
                    <td><?= Vista::escapar($solicitud['fecha_creacion_solicitud']) ?></td>
                    <td><?= Vista::escapar(trim($solicitud['nombres_solicitante'] . ' ' . $solicitud['apellido_paterno_solicitante'] . ' ' . ($solicitud['apellido_materno_solicitante'] ?? ''))) ?></td>
                    <td><?= Vista::escapar($solicitud['tipo_integrante_solicitado']) ?></td>
                    <td><?= Vista::escapar($solicitud['identificador_institucional_solicitante']) ?></td>
                    <td><?= Vista::escapar($solicitud['correo_electronico_solicitante']) ?></td>
                    <td>
                        <span class="etiqueta-estado estado-<?= Vista::escapar(strtolower($solicitud['estado_solicitud'])) ?>"><?= Vista::escapar($solicitud['estado_solicitud']) ?></span>
                        <?php if ($solicitud['fecha_verificacion_correo_solicitud'] !== null): ?>
                            <br><small class="texto-secundario">Correo verificado</small>
                        <?php endif; ?>
                    </td>
                    <td><a href="<?= Vista::escapar(Vista::url('/admin/solicitudes/detalle?id=' . (int) $solicitud['id_solicitud_acceso'])) ?>">Ver detalle</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

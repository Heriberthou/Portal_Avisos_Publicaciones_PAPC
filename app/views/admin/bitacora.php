<?php
/**
 * Bitácora de eventos recientes (solo ADMINISTRADOR y PRINCIPAL).
 *
 * No muestra IP ni navegador, y la tabla nunca contiene contraseñas,
 * tokens ni identificadores de sesión (SEG-16).
 *
 * Variables: eventos (list).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
?>
<section class="tarjeta-amplia">
    <?php require RUTA_RAIZ . '/app/views/parciales/navegacion-admin.php'; ?>
    <h1>Bitácora</h1>
    <p class="texto-secundario">Últimos 150 eventos. Permite reconstruir aprobaciones, rechazos y cambios de rol o de estado.</p>

    <div class="contenedor-tabla">
    <table class="tabla-usuarios">
        <caption class="etiqueta-oculta">Eventos de auditoría</caption>
        <thead>
        <tr><th scope="col">Fecha</th><th scope="col">Acción</th><th scope="col">Actor</th><th scope="col">Entidad</th><th scope="col">Detalle</th></tr>
        </thead>
        <tbody>
        <?php foreach ($datosVista['eventos'] as $evento): ?>
            <tr>
                <td><?= Vista::escapar($evento['fecha_evento_auditoria']) ?></td>
                <td><?= Vista::escapar($evento['accion_auditoria']) ?></td>
                <td><?= Vista::escapar($evento['nombre_actor'] ?? ($evento['id_usuario_actor'] === null ? 'Visitante / sistema' : '#' . $evento['id_usuario_actor'])) ?></td>
                <td><?= Vista::escapar($evento['entidad_afectada_auditoria']) ?><?= $evento['id_registro_afectado'] !== null ? ' #' . (int) $evento['id_registro_afectado'] : '' ?></td>
                <td>
                    <?php foreach ($evento['detalle_auditoria'] as $clave => $valor): ?>
                        <?php if (!str_starts_with((string) $clave, 'huella')): ?>
                            <span class="dato-detalle"><?= Vista::escapar($clave) ?>: <?= Vista::escapar(is_bool($valor) ? ($valor ? 'sí' : 'no') : $valor) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

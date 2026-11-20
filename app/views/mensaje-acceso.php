<?php
/**
 * Mensaje de resultado con el diseño de las pantallas de acceso
 * (correo verificado, enlace no válido o vencido, etc.).
 *
 * Variables: titulo, tipoMensaje (exito|error|aviso), mensaje,
 * enlaceAccion (ruta interna, opcional), textoAccion (opcional).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$tipoMensaje = in_array($datosVista['tipoMensaje'] ?? '', ['exito', 'error', 'aviso'], true) ? $datosVista['tipoMensaje'] : 'aviso';
?>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-inicio.php'; ?>
<h1 class="titulo-acceso"><?= Vista::escapar($datosVista['titulo'] ?? '') ?></h1>
<p class="mensaje mensaje-<?= Vista::escapar($tipoMensaje) ?>" role="<?= $tipoMensaje === 'error' ? 'alert' : 'status' ?>">
    <?= Vista::escapar($datosVista['mensaje'] ?? '') ?>
</p>
<div class="acciones-acceso">
    <?php if (!empty($datosVista['enlaceAccion'])): ?>
        <a class="boton-principal boton-enlace" href="<?= Vista::escapar(Vista::url((string) $datosVista['enlaceAccion'])) ?>"><?= Vista::escapar($datosVista['textoAccion'] ?? 'Continuar') ?></a>
    <?php endif; ?>
    <p class="texto-secundario"><a href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Ir a iniciar sesión</a></p>
</div>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-fin.php'; ?>

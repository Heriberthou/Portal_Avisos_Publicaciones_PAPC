<?php
/**
 * Página de error genérica. Nunca muestra detalles internos.
 *
 * Variables: codigoEstado (int), mensajeError (string).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
?>
<section class="tarjeta-formulario">
    <h1><?= Vista::escapar($datosVista['tituloPagina'] ?? 'Algo salió mal') ?></h1>
    <p class="mensaje mensaje-error"><?= Vista::escapar($datosVista['mensajeError'] ?? 'No fue posible completar la operación.') ?></p>
    <p><a href="<?= Vista::escapar(Vista::url('/')) ?>">Volver al inicio</a></p>
</section>

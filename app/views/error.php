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
    <h1>Error <?= (int) ($datosVista['codigoEstado'] ?? 500) ?></h1>
    <p class="mensaje mensaje-error"><?= Vista::escapar($datosVista['mensajeError'] ?? 'Ocurrió un error.') ?></p>
    <p><a href="<?= Vista::escapar(Vista::url('/')) ?>">Ir al inicio del portal</a></p>
</section>

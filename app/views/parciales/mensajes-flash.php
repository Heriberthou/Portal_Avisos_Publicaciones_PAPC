<?php
/**
 * Mensajes de una sola vez (flash): confirmaciones y avisos que llegan
 * después de una redirección. Variable: $datosVista['mensajes'].
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
?>
<?php foreach ($datosVista['mensajes'] ?? [] as $mensajeFlash): ?>
    <p class="mensaje mensaje-<?= Vista::escapar($mensajeFlash['tipo'] ?? 'aviso') ?>" role="status">
        <?= Vista::escapar($mensajeFlash['mensaje'] ?? '') ?>
    </p>
<?php endforeach; ?>

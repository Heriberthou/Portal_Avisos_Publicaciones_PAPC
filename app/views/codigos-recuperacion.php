<?php
/**
 * Códigos de recuperación del segundo factor. Se muestran UNA sola vez;
 * en la base de datos solo queda su huella (HMAC).
 *
 * Variables: codigosRecuperacion (list<string>).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
?>
<section class="tarjeta-formulario">
    <h1>Guarde sus códigos de recuperación</h1>
    <p class="mensaje mensaje-aviso" role="status">Esta es la única vez que se muestran. Guárdelos en un lugar seguro (por ejemplo, impresos o en su gestor de contraseñas).</p>
    <p>Si pierde su teléfono, cada código le permite entrar <strong>una sola vez</strong> en lugar del código de la aplicación.</p>
    <ul class="lista-codigos">
        <?php foreach ($datosVista['codigosRecuperacion'] as $codigo): ?>
            <li><code><?= Vista::escapar($codigo) ?></code></li>
        <?php endforeach; ?>
    </ul>
    <p><a class="boton-principal boton-enlace" href="<?= Vista::escapar(Vista::url('/inicio')) ?>">Ya los guardé, continuar</a></p>
</section>

<?php
/**
 * Página mínima tras iniciar sesión (no es un dashboard): muestra los
 * datos de la cuenta leídos del servidor y el botón de cierre de sesión.
 *
 * Variables: usuarioActual (array), puedeAdministrar (bool),
 * minutosInactividad (int, política de expiración vigente).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$usuarioActual = $datosVista['usuarioActual'];
?>
<section class="tarjeta-formulario">
    <h1>Bienvenido(a), <?= Vista::escapar($usuarioActual['nombre_usuario']) ?></h1>

    <dl class="datos-cuenta">
        <dt>Correo electrónico</dt>
        <dd><?= Vista::escapar($usuarioActual['correo_electronico_usuario']) ?></dd>
        <dt>Tipo de integrante</dt>
        <dd><?= Vista::escapar($usuarioActual['tipo_integrante_usuario'] ?? 'SIN CLASIFICAR') ?></dd>
        <dt>Estado de verificación</dt>
        <dd><?= Vista::escapar($usuarioActual['estado_verificacion_usuario']) ?></dd>
        <dt>Rol de seguridad</dt>
        <dd><?= Vista::escapar($usuarioActual['rol_usuario']) ?></dd>
    </dl>

    <?php if ($datosVista['puedeAdministrar']): ?>
        <p><a href="<?= Vista::escapar(Vista::url('/admin/usuarios')) ?>">Administrar cuentas</a></p>
    <?php endif; ?>

    <p class="texto-secundario">Por seguridad, su sesión se cierra después de <?= (int) $datosVista['minutosInactividad'] ?> minuto(s) sin actividad.</p>

    <form method="post" action="<?= Vista::escapar(Vista::url('/cerrar-sesion')) ?>">
        <?= Vista::campoTokenCsrf() ?>
        <button type="submit" class="boton-secundario">Cerrar sesión</button>
    </form>
</section>

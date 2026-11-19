<?php
/**
 * Pantalla I: destino tras iniciar sesión (demostrativa, no es un panel).
 *
 * Muestra datos NO sensibles leídos del servidor: nombre, tipo de
 * integrante, rol y estado. El identificador institucional se enmascara y
 * nunca se muestran hashes. Las opciones dependen del rol, pero la barrera
 * real es el servidor (RBAC en cada ruta).
 *
 * Variables: usuarioActual (array), puedeRevisarSolicitudes, puedeAdministrar,
 * puedeConsultarBitacora, puedeAsignarRoles (bool), minutosInactividad (int).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$usuarioActual = $datosVista['usuarioActual'];
$identificador = (string) ($usuarioActual['identificador_institucional_usuario'] ?? '');
$identificadorEnmascarado = $identificador === ''
    ? 'No registrado'
    : str_repeat('•', max(0, mb_strlen($identificador) - 3)) . mb_substr($identificador, -3);
?>
<section class="tarjeta-formulario">
    <h1>Bienvenido(a), <?= Vista::escapar($usuarioActual['nombre_usuario']) ?></h1>

    <dl class="datos-cuenta">
        <dt>Correo electrónico</dt>
        <dd><?= Vista::escapar($usuarioActual['correo_electronico_usuario']) ?></dd>
        <dt>Tipo de integrante</dt>
        <dd><?= Vista::escapar($usuarioActual['tipo_integrante_usuario'] ?? 'Sin clasificar') ?></dd>
        <dt>Matrícula o identificador</dt>
        <dd><?= Vista::escapar($identificadorEnmascarado) ?></dd>
        <dt>Rol de seguridad</dt>
        <dd><?= Vista::escapar($usuarioActual['rol_usuario']) ?></dd>
        <dt>Estado de la cuenta</dt>
        <dd><?= Vista::escapar($usuarioActual['estado_cuenta_usuario']) ?></dd>
    </dl>

    <?php if ($datosVista['puedeRevisarSolicitudes'] || $datosVista['puedeAdministrar']): ?>
        <h2 class="subtitulo-seccion">Administración</h2>
        <ul class="lista-enlaces">
            <?php if ($datosVista['puedeRevisarSolicitudes']): ?>
                <li><a href="<?= Vista::escapar(Vista::url('/admin/solicitudes')) ?>">Solicitudes de acceso</a></li>
            <?php endif; ?>
            <?php if ($datosVista['puedeAdministrar']): ?>
                <li><a href="<?= Vista::escapar(Vista::url('/admin/usuarios')) ?>">Cuentas</a></li>
            <?php endif; ?>
            <?php if ($datosVista['puedeConsultarBitacora']): ?>
                <li><a href="<?= Vista::escapar(Vista::url('/admin/bitacora')) ?>">Bitácora</a></li>
            <?php endif; ?>
            <?php if ($datosVista['puedeAsignarRoles']): ?>
                <li><a href="<?= Vista::escapar(Vista::url('/admin/roles')) ?>">Roles y segundo factor (solo principal)</a></li>
            <?php endif; ?>
        </ul>
    <?php else: ?>
        <p class="mensaje mensaje-aviso">El envío de propuestas de avisos estará disponible en una próxima etapa del portal.</p>
    <?php endif; ?>

    <p class="texto-secundario">Por seguridad, su sesión se cierra después de <?= (int) $datosVista['minutosInactividad'] ?> minuto(s) sin actividad.</p>

    <form method="post" action="<?= Vista::escapar(Vista::url('/cerrar-sesion')) ?>">
        <?= Vista::campoTokenCsrf() ?>
        <button type="submit" class="boton-secundario">Cerrar sesión</button>
    </form>
</section>

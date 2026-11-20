<?php
/**
 * Navegación de las páginas administrativas.
 *
 * Mostrar u ocultar "Roles" según el rol es solo comodidad visual: la ruta
 * /admin/roles la protege el servidor (permiso asignar_rol_administrativo).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$esPrincipalNavegacion = ($datosVista['usuarioActual']['rol_usuario'] ?? '') === UsuarioModel::ROL_ADMINISTRADOR_PRINCIPAL;
?>
<nav class="navegacion-admin" aria-label="Administración">
    <a href="<?= Vista::escapar(Vista::url('/inicio')) ?>">Inicio</a>
    <a href="<?= Vista::escapar(Vista::url('/admin/solicitudes')) ?>">Solicitudes</a>
    <a href="<?= Vista::escapar(Vista::url('/admin/usuarios')) ?>">Cuentas</a>
    <a href="<?= Vista::escapar(Vista::url('/admin/bitacora')) ?>">Bitácora</a>
    <?php if ($esPrincipalNavegacion): ?>
        <a href="<?= Vista::escapar(Vista::url('/admin/roles')) ?>">Roles</a>
    <?php endif; ?>
</nav>

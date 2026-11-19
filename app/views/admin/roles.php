<?php
/**
 * Sección exclusiva del ADMINISTRADOR_PRINCIPAL: asignar o revocar roles y
 * restablecer el segundo factor de otro administrador. Cada acción pide la
 * contraseña del principal (re-autenticación) y queda en la bitácora.
 *
 * Variables: listaUsuarios (array), rolesDisponibles (list), usuarioActual.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$idUsuarioActual = (int) $datosVista['usuarioActual']['id_usuario'];
?>
<section class="tarjeta-amplia">
    <?php require RUTA_RAIZ . '/app/views/parciales/navegacion-admin.php'; ?>
    <h1>Roles y segundo factor</h1>
    <p class="texto-secundario">Solo las cuentas ACTIVAS pueden recibir un rol administrativo. Quien recibe un rol administrativo debe configurar su segundo factor en su siguiente inicio de sesión. No puede cambiar su propio rol.</p>

    <div class="contenedor-tabla">
    <table class="tabla-usuarios">
        <caption class="etiqueta-oculta">Roles de las cuentas</caption>
        <thead>
        <tr><th scope="col">Nombre</th><th scope="col">Correo</th><th scope="col">Estado</th><th scope="col">Rol</th><th scope="col">Segundo factor</th><th scope="col">Acciones</th></tr>
        </thead>
        <tbody>
        <?php foreach ($datosVista['listaUsuarios'] as $usuarioFila): ?>
            <?php
            $idUsuarioFila = (int) $usuarioFila['id_usuario'];
            $tieneSegundoFactor = (int) $usuarioFila['tiene_segundo_factor_usuario'] === 1;
            ?>
            <tr>
                <td><?= Vista::escapar($usuarioFila['nombre_usuario']) ?></td>
                <td><?= Vista::escapar($usuarioFila['correo_electronico_usuario']) ?></td>
                <td><?= Vista::escapar($usuarioFila['estado_cuenta_usuario']) ?></td>
                <td><?= Vista::escapar($usuarioFila['rol_usuario']) ?></td>
                <td><?= $tieneSegundoFactor ? 'Configurado' : 'No configurado' ?></td>
                <td class="celda-acciones">
                    <?php if ($idUsuarioFila !== $idUsuarioActual): ?>
                        <form method="post" action="<?= Vista::escapar(Vista::url('/admin/roles/cambiar')) ?>" class="formulario-en-linea">
                            <?= Vista::campoTokenCsrf() ?>
                            <input type="hidden" name="id_usuario" value="<?= $idUsuarioFila ?>">
                            <label class="etiqueta-oculta" for="rol-nuevo-<?= $idUsuarioFila ?>">Rol nuevo</label>
                            <select id="rol-nuevo-<?= $idUsuarioFila ?>" name="rol_nuevo">
                                <?php foreach ($datosVista['rolesDisponibles'] as $rolDisponible): ?>
                                    <option value="<?= Vista::escapar($rolDisponible) ?>" <?= $rolDisponible === $usuarioFila['rol_usuario'] ? 'selected' : '' ?>><?= Vista::escapar($rolDisponible) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="etiqueta-oculta" for="contrasena-rol-<?= $idUsuarioFila ?>">Su contraseña</label>
                            <input id="contrasena-rol-<?= $idUsuarioFila ?>" type="password" name="contrasena_confirmacion"
                                   placeholder="Su contraseña" autocomplete="current-password" required>
                            <button type="submit" class="boton-secundario">Cambiar rol</button>
                        </form>
                        <?php if ($tieneSegundoFactor): ?>
                            <form method="post" action="<?= Vista::escapar(Vista::url('/admin/roles/restablecer-segundo-factor')) ?>" class="formulario-en-linea">
                                <?= Vista::campoTokenCsrf() ?>
                                <input type="hidden" name="id_usuario" value="<?= $idUsuarioFila ?>">
                                <label class="etiqueta-oculta" for="contrasena-mfa-<?= $idUsuarioFila ?>">Su contraseña</label>
                                <input id="contrasena-mfa-<?= $idUsuarioFila ?>" type="password" name="contrasena_confirmacion"
                                       placeholder="Su contraseña" autocomplete="current-password" required>
                                <button type="submit" class="boton-secundario">Restablecer segundo factor</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="texto-secundario">Su cuenta</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<?php
/**
 * Listado administrativo de cuentas.
 *
 * Variables: listaUsuarios (array), puedeAsignarRoles (bool),
 * rolesDisponibles (list<string>), usuarioActual (array).
 *
 * Ocultar el formulario de roles a un ADMINISTRADOR es solo comodidad
 * visual: el servidor vuelve a comprobar el permiso en cada POST.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$idUsuarioActual = (int) $datosVista['usuarioActual']['id_usuario'];
?>
<section class="tarjeta-amplia">
    <h1>Administración de cuentas</h1>
    <p><a href="<?= Vista::escapar(Vista::url('/inicio')) ?>">Volver al inicio</a></p>

    <table class="tabla-usuarios">
        <thead>
        <tr>
            <th>Id</th><th>Nombre</th><th>Correo</th><th>Tipo</th><th>Verificación</th><th>Rol</th><th>Acciones</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($datosVista['listaUsuarios'] as $usuarioFila): ?>
            <?php $idUsuarioFila = (int) $usuarioFila['id_usuario']; ?>
            <tr>
                <td><?= $idUsuarioFila ?></td>
                <td>
                    <?= Vista::escapar($usuarioFila['nombre_usuario']) ?>
                    <?php if ($usuarioFila['apellido_paterno_usuario'] === null): ?>
                        <br><small class="texto-secundario">Faltan los apellidos (cuenta creada antes del cambio de nombre)</small>
                    <?php endif; ?>
                </td>
                <td><?= Vista::escapar($usuarioFila['correo_electronico_usuario']) ?></td>
                <td><?= Vista::escapar($usuarioFila['tipo_integrante_usuario'] ?? 'SIN CLASIFICAR') ?></td>
                <td><?= Vista::escapar($usuarioFila['estado_verificacion_usuario']) ?></td>
                <td><?= Vista::escapar($usuarioFila['rol_usuario']) ?></td>
                <td class="celda-acciones">
                    <?php if ($idUsuarioFila !== $idUsuarioActual && $usuarioFila['estado_verificacion_usuario'] === 'PENDIENTE'): ?>
                        <form method="post" action="<?= Vista::escapar(Vista::url('/admin/usuarios/verificacion')) ?>" class="formulario-en-linea">
                            <?= Vista::campoTokenCsrf() ?>
                            <input type="hidden" name="id_usuario" value="<?= $idUsuarioFila ?>">
                            <button type="submit" name="decision" value="VERIFICADA" class="boton-secundario">Verificar profesor</button>
                            <button type="submit" name="decision" value="RECHAZADA" class="boton-secundario">Rechazar</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($datosVista['puedeAsignarRoles'] && $idUsuarioFila !== $idUsuarioActual): ?>
                        <form method="post" action="<?= Vista::escapar(Vista::url('/admin/usuarios/rol')) ?>" class="formulario-en-linea">
                            <?= Vista::campoTokenCsrf() ?>
                            <input type="hidden" name="id_usuario" value="<?= $idUsuarioFila ?>">
                            <label class="etiqueta-oculta" for="rol-nuevo-<?= $idUsuarioFila ?>">Rol nuevo</label>
                            <select id="rol-nuevo-<?= $idUsuarioFila ?>" name="rol_nuevo">
                                <?php foreach ($datosVista['rolesDisponibles'] as $rolDisponible): ?>
                                    <option value="<?= Vista::escapar($rolDisponible) ?>" <?= $rolDisponible === $usuarioFila['rol_usuario'] ? 'selected' : '' ?>>
                                        <?= Vista::escapar($rolDisponible) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label class="etiqueta-oculta" for="contrasena-confirmacion-<?= $idUsuarioFila ?>">Su contraseña</label>
                            <input id="contrasena-confirmacion-<?= $idUsuarioFila ?>" type="password" name="contrasena_confirmacion"
                                   placeholder="Su contraseña" autocomplete="current-password" required>
                            <button type="submit" class="boton-secundario">Cambiar rol</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

<?php
/**
 * Listado administrativo de cuentas con su estado.
 *
 * Variables: listaUsuarios (array), esPrincipal (bool), usuarioActual (array).
 *
 * Un ADMINISTRADOR solo ve acciones de estado sobre cuentas USUARIO; el
 * PRINCIPAL, sobre cualquier cuenta excepto la propia. Es comodidad visual:
 * el servidor vuelve a comprobarlo en cada POST.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$idUsuarioActual = (int) $datosVista['usuarioActual']['id_usuario'];
$textosEstado = [
    UsuarioModel::ESTADO_ACTIVA     => 'Reactivar',
    UsuarioModel::ESTADO_SUSPENDIDA => 'Suspender',
    UsuarioModel::ESTADO_BAJA       => 'Dar de baja',
];
?>
<section class="tarjeta-amplia">
    <?php require RUTA_RAIZ . '/app/views/parciales/navegacion-admin.php'; ?>
    <h1>Cuentas</h1>
    <p class="texto-secundario">Las cuentas creadas con el registro anterior quedaron suspendidas hasta que un administrador valide su pertenencia institucional ("Validar y reactivar").</p>
    <p class="texto-secundario">Las cuentas nuevas solo se crean al aprobar una solicitud. Suspender o dar de baja cierra de inmediato las sesiones de la cuenta. Dar de baja es definitivo.</p>

    <div class="contenedor-tabla">
    <table class="tabla-usuarios">
        <caption class="etiqueta-oculta">Cuentas registradas</caption>
        <thead>
        <tr>
            <th scope="col">Id</th><th scope="col">Nombre</th><th scope="col">Correo</th><th scope="col">Tipo</th><th scope="col">Rol</th><th scope="col">Estado</th><th scope="col">Acciones</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($datosVista['listaUsuarios'] as $usuarioFila): ?>
            <?php
            $idUsuarioFila = (int) $usuarioFila['id_usuario'];
            $puedeGestionar = $idUsuarioFila !== $idUsuarioActual
                && ($datosVista['esPrincipal'] || $usuarioFila['rol_usuario'] === UsuarioModel::ROL_USUARIO);
            $transiciones = ServicioAdministracionUsuarios::obtenerTransicionesPermitidas((string) $usuarioFila['estado_cuenta_usuario']);
            $esHeredadaPendiente = UsuarioModel::esCuentaHeredadaPendiente($usuarioFila);
            ?>
            <tr>
                <td><?= $idUsuarioFila ?></td>
                <td>
                    <?= Vista::escapar($usuarioFila['nombre_usuario']) ?>
                    <?php if ($usuarioFila['apellido_paterno_usuario'] === null): ?>
                        <br><small class="texto-secundario">Faltan los apellidos (cuenta creada antes del cambio de nombre)</small>
                    <?php endif; ?>
                </td>
                <td><?= Vista::escapar($usuarioFila['correo_electronico_usuario']) ?></td>
                <td><?= Vista::escapar(Etiquetas::tipoIntegrante($usuarioFila['tipo_integrante_usuario'] ?? null)) ?></td>
                <td><?= Vista::escapar(Etiquetas::rol($usuarioFila['rol_usuario'])) ?></td>
                <td>
                    <span class="etiqueta-estado estado-<?= Vista::escapar(strtolower($usuarioFila['estado_cuenta_usuario'])) ?>"><?= Vista::escapar(Etiquetas::estadoCuenta($usuarioFila['estado_cuenta_usuario'])) ?></span>
                    <?php if ($esHeredadaPendiente): ?>
                        <br><small class="texto-secundario">Cuenta anterior pendiente de validación institucional</small>
                    <?php endif; ?>
                </td>
                <td class="celda-acciones">
                    <?php if ($puedeGestionar && $transiciones !== []): ?>
                        <form method="post" action="<?= Vista::escapar(Vista::url('/admin/usuarios/estado')) ?>" class="formulario-en-linea">
                            <?= Vista::campoTokenCsrf() ?>
                            <input type="hidden" name="id_usuario" value="<?= $idUsuarioFila ?>">
                            <label class="etiqueta-oculta" for="estado-nuevo-<?= $idUsuarioFila ?>">Nuevo estado</label>
                            <select id="estado-nuevo-<?= $idUsuarioFila ?>" name="estado_nuevo">
                                <?php foreach ($transiciones as $estadoDestino): ?>
                                    <option value="<?= Vista::escapar($estadoDestino) ?>"><?= Vista::escapar($esHeredadaPendiente && $estadoDestino === UsuarioModel::ESTADO_ACTIVA ? 'Validar y reactivar' : ($textosEstado[$estadoDestino] ?? $estadoDestino)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="etiqueta-oculta" for="confirmacion-estado-<?= $idUsuarioFila ?>">Su contraseña</label>
                            <input id="confirmacion-estado-<?= $idUsuarioFila ?>" type="password" name="contrasena_confirmacion"
                                   placeholder="Su contraseña" autocomplete="current-password" required>
                            <button type="submit" class="boton-secundario">Aplicar</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($idUsuarioFila !== $idUsuarioActual && $usuarioFila['estado_verificacion_usuario'] === UsuarioModel::VERIFICACION_PENDIENTE): ?>
                        <form method="post" action="<?= Vista::escapar(Vista::url('/admin/usuarios/verificacion')) ?>" class="formulario-en-linea">
                            <?= Vista::campoTokenCsrf() ?>
                            <input type="hidden" name="id_usuario" value="<?= $idUsuarioFila ?>">
                            <button type="submit" name="decision" value="VERIFICADA" class="boton-secundario">Verificar profesor</button>
                            <button type="submit" name="decision" value="RECHAZADA" class="boton-secundario">Rechazar</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

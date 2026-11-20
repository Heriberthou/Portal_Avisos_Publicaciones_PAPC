<?php
/**
 * Pantalla B: inicio de sesión (un solo formulario para todos los roles).
 *
 * Variables: correoPrevio (string), mensajeError (string|null),
 * errores (array por campo: correo_electronico, contrasena), mensajes (flash).
 *
 * La contraseña nunca se vuelve a mostrar. autocomplete permite usar
 * gestores de contraseñas (WCAG 3.3.8 y OWASP). La validación del
 * navegador (validacion-formularios.js) es solo una ayuda: el servidor
 * valida de nuevo todos los datos.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$errores = $datosVista['errores'] ?? [];
$errorCorreo = $errores['correo_electronico'] ?? '';
$errorContrasena = $errores['contrasena'] ?? '';
require RUTA_RAIZ . '/app/views/parciales/acceso-inicio.php';
?>
<h1 class="titulo-acceso">Iniciar sesión</h1>
<p class="subtitulo-acceso">Ingrese a su cuenta para participar en la comunidad universitaria.</p>

<?php if (!empty($datosVista['mensajeError'])): ?>
    <p class="mensaje mensaje-error" role="alert"><?= Vista::escapar($datosVista['mensajeError']) ?></p>
<?php endif; ?>

<form method="post" action="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>" class="rejilla-campos" data-formulario="login">
    <?= Vista::campoTokenCsrf() ?>

    <div class="campo campo-ancho">
        <label for="correo-electronico"><?= Vista::icono('correo') ?>Correo electrónico</label>
        <input id="correo-electronico" name="correo_electronico" type="email" required
               maxlength="<?= LONGITUD_MAXIMA_CORREO_ELECTRONICO ?>" autocomplete="username"
               placeholder="Escriba su correo electrónico" data-validar="correo"
               aria-describedby="error-correo-electronico"
               <?= $errorCorreo !== '' ? 'aria-invalid="true"' : '' ?>
               value="<?= Vista::escapar($datosVista['correoPrevio'] ?? '') ?>">
        <p class="error-campo" id="error-correo-electronico" aria-live="polite"><?= Vista::escapar($errorCorreo) ?></p>
    </div>

    <div class="campo campo-ancho">
        <label for="contrasena"><?= Vista::icono('candado') ?>Contraseña</label>
        <div class="control-contrasena">
            <input id="contrasena" name="contrasena" type="password" required
                   maxlength="<?= LONGITUD_MAXIMA_CONTRASENA ?>" autocomplete="current-password"
                   placeholder="Escriba su contraseña" data-validar="requerido"
                   data-mensaje-vacio="Escriba su contraseña."
                   aria-describedby="error-contrasena"
                   <?= $errorContrasena !== '' ? 'aria-invalid="true"' : '' ?>>
            <button type="button" class="boton-mostrar" data-mostrar="contrasena" aria-controls="contrasena" aria-pressed="false" hidden>Mostrar</button>
        </div>
        <p class="error-campo" id="error-contrasena" aria-live="polite"><?= Vista::escapar($errorContrasena) ?></p>
        <a class="enlace-secundario" href="<?= Vista::escapar(Vista::url('/recuperar-contrasena')) ?>">¿Olvidó su contraseña?</a>
    </div>

    <div class="acciones-acceso">
        <button type="submit" class="boton-principal"><?= Vista::icono('entrar') ?>Iniciar sesión</button>
    </div>
</form>

<p class="invitacion-acceso">
    ¿Aún no tiene cuenta?
    <a class="enlace-destacado" href="<?= Vista::escapar(Vista::url('/solicitar-acceso')) ?>">Solicitar acceso</a>
</p>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-fin.php'; ?>
<script src="<?= Vista::escapar(Vista::url('/js/validacion-formularios.js')) ?>" defer></script>

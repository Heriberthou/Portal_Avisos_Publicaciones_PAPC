<?php
/**
 * Formulario de inicio de sesión.
 *
 * Variables: correoPrevio (string), mensajeError (string|null),
 * errores (array por campo: correo_electronico, contrasena).
 *
 * La contraseña nunca se vuelve a mostrar. autocomplete permite usar
 * gestores de contraseñas (recomendación OWASP). La validación del
 * navegador (validacion-formularios.js) es solo una ayuda: el servidor
 * valida de nuevo todos los datos.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$errores = $datosVista['errores'] ?? [];
$errorCorreo = $errores['correo_electronico'] ?? '';
$errorContrasena = $errores['contrasena'] ?? '';
?>
<div class="pantalla-acceso">
    <?php require RUTA_RAIZ . '/app/views/parciales/panel-institucional.php'; ?>

    <section class="panel-formulario">
        <h1 class="titulo-acceso">Iniciar sesión</h1>

        <?php if (!empty($datosVista['mensajeError'])): ?>
            <p class="mensaje mensaje-error" role="alert"><?= Vista::escapar($datosVista['mensajeError']) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>" class="rejilla-campos" data-formulario="login">
            <?= Vista::campoTokenCsrf() ?>

            <div class="campo campo-ancho">
                <label for="correo-electronico">Correo electrónico</label>
                <input id="correo-electronico" name="correo_electronico" type="email" required
                       maxlength="<?= LONGITUD_MAXIMA_CORREO_ELECTRONICO ?>" autocomplete="username"
                       placeholder="usuario@ejemplo.com" data-validar="requerido"
                       data-mensaje-vacio="Escriba su correo electrónico."
                       aria-describedby="error-correo-electronico"
                       <?= $errorCorreo !== '' ? 'aria-invalid="true"' : '' ?>
                       value="<?= Vista::escapar($datosVista['correoPrevio'] ?? '') ?>">
                <p class="error-campo" id="error-correo-electronico" aria-live="polite"><?= Vista::escapar($errorCorreo) ?></p>
            </div>

            <div class="campo campo-ancho">
                <label for="contrasena">Contraseña</label>
                <div class="control-contrasena">
                    <input id="contrasena" name="contrasena" type="password" required
                           maxlength="<?= LONGITUD_MAXIMA_CONTRASENA ?>" autocomplete="current-password"
                           placeholder="••••••••••••" data-validar="requerido"
                           data-mensaje-vacio="Escriba su contraseña."
                           aria-describedby="error-contrasena"
                           <?= $errorContrasena !== '' ? 'aria-invalid="true"' : '' ?>>
                    <button type="button" class="boton-mostrar" data-mostrar="contrasena" aria-controls="contrasena" aria-pressed="false" hidden>Mostrar</button>
                </div>
                <p class="error-campo" id="error-contrasena" aria-live="polite"><?= Vista::escapar($errorContrasena) ?></p>
            </div>

            <div class="acciones-acceso">
                <button type="submit" class="boton-principal">Entrar</button>
                <p class="texto-secundario">¿No tiene cuenta? <a href="<?= Vista::escapar(Vista::url('/registro')) ?>">Regístrese</a></p>
            </div>
        </form>
    </section>
</div>
<script src="<?= Vista::escapar(Vista::url('/js/validacion-formularios.js')) ?>" defer></script>

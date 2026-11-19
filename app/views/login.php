<?php
/**
 * Formulario de inicio de sesión.
 *
 * Variables: correoPrevio (string), mensajeError (string|null).
 * La contraseña nunca se vuelve a mostrar. autocomplete permite usar
 * gestores de contraseñas (recomendación OWASP).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
?>
<div class="pantalla-acceso">
    <?php require RUTA_RAIZ . '/app/views/parciales/panel-institucional.php'; ?>

    <section class="panel-formulario">
        <h1 class="titulo-acceso">Iniciar sesión</h1>

        <?php if (!empty($datosVista['mensajeError'])): ?>
            <p class="mensaje mensaje-error" role="alert"><?= Vista::escapar($datosVista['mensajeError']) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>" class="rejilla-campos" novalidate>
            <?= Vista::campoTokenCsrf() ?>

            <div class="campo campo-ancho">
                <label for="correo-electronico">Correo electrónico</label>
                <input id="correo-electronico" name="correo_electronico" type="email" required
                       maxlength="<?= LONGITUD_MAXIMA_CORREO_ELECTRONICO ?>" autocomplete="username"
                       placeholder="usuario@ejemplo.com"
                       value="<?= Vista::escapar($datosVista['correoPrevio'] ?? '') ?>">
            </div>

            <div class="campo campo-ancho">
                <label for="contrasena">Contraseña</label>
                <input id="contrasena" name="contrasena" type="password" required
                       maxlength="<?= LONGITUD_MAXIMA_CONTRASENA ?>" autocomplete="current-password"
                       placeholder="••••••••••••">
            </div>

            <div class="acciones-acceso">
                <button type="submit" class="boton-principal">Entrar</button>
                <p class="texto-secundario">¿No tienes cuenta? <a href="<?= Vista::escapar(Vista::url('/registro')) ?>">Regístrate</a></p>
            </div>
        </form>
    </section>
</div>

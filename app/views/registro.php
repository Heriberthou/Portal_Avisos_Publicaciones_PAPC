<?php
/**
 * Formulario de registro público.
 *
 * Variables: valoresPrevios (array), errores (array por campo),
 * mensajeError (string|null).
 *
 * No existe campo de rol: toda cuenta pública es USUARIO. El tipo de
 * integrante es una clasificación declarada, no un permiso.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$valoresPrevios = $datosVista['valoresPrevios'] ?? [];
$errores = $datosVista['errores'] ?? [];
$tipoPrevio = $valoresPrevios['tipo_integrante'] ?? '';

/**
 * Imprime el error de un campo, si existe.
 *
 * @param array<string, string> $errores Errores por campo.
 * @param string $campo Nombre del campo.
 * @return string HTML escapado.
 */
$mostrarErrorCampo = static function (array $errores, string $campo): string {
    return isset($errores[$campo])
        ? '<span class="error-campo">' . Vista::escapar($errores[$campo]) . '</span>'
        : '';
};
?>
<div class="pantalla-acceso">
    <?php require RUTA_RAIZ . '/app/views/parciales/panel-institucional.php'; ?>

    <section class="panel-formulario">
        <h1 class="titulo-acceso">Crear cuenta</h1>

        <?php if (!empty($datosVista['mensajeError'])): ?>
            <p class="mensaje mensaje-error" role="alert"><?= Vista::escapar($datosVista['mensajeError']) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= Vista::escapar(Vista::url('/registro')) ?>" class="rejilla-campos" novalidate>
            <?= Vista::campoTokenCsrf() ?>

            <div class="campo campo-ancho">
                <label for="nombres-usuario">Nombre(s)</label>
                <input id="nombres-usuario" name="nombres_usuario" type="text" required
                       maxlength="<?= LONGITUD_MAXIMA_NOMBRES_USUARIO ?>" autocomplete="given-name"
                       placeholder="Nombre(s)"
                       value="<?= Vista::escapar($valoresPrevios['nombres_usuario'] ?? '') ?>">
                <?= $mostrarErrorCampo($errores, 'nombres_usuario') ?>
            </div>

            <div class="campo">
                <label for="apellido-paterno-usuario">Apellido paterno</label>
                <input id="apellido-paterno-usuario" name="apellido_paterno_usuario" type="text" required
                       maxlength="<?= LONGITUD_MAXIMA_APELLIDO_USUARIO ?>" autocomplete="family-name"
                       placeholder="Apellido paterno"
                       value="<?= Vista::escapar($valoresPrevios['apellido_paterno_usuario'] ?? '') ?>">
                <?= $mostrarErrorCampo($errores, 'apellido_paterno_usuario') ?>
            </div>

            <div class="campo">
                <label for="apellido-materno-usuario">Apellido materno <span class="texto-secundario">(opcional)</span></label>
                <input id="apellido-materno-usuario" name="apellido_materno_usuario" type="text"
                       maxlength="<?= LONGITUD_MAXIMA_APELLIDO_USUARIO ?>" autocomplete="additional-name"
                       placeholder="Apellido materno"
                       value="<?= Vista::escapar($valoresPrevios['apellido_materno_usuario'] ?? '') ?>">
                <?= $mostrarErrorCampo($errores, 'apellido_materno_usuario') ?>
            </div>

            <div class="campo campo-ancho">
                <label for="correo-electronico">Correo electrónico</label>
                <input id="correo-electronico" name="correo_electronico" type="email" required
                       maxlength="<?= LONGITUD_MAXIMA_CORREO_ELECTRONICO ?>" autocomplete="email"
                       placeholder="usuario@ejemplo.com"
                       value="<?= Vista::escapar($valoresPrevios['correo_electronico'] ?? '') ?>">
                <?= $mostrarErrorCampo($errores, 'correo_electronico') ?>
            </div>

            <div class="campo campo-ancho">
                <fieldset class="grupo-opciones">
                    <legend>Me registro como</legend>
                    <label><input type="radio" name="tipo_integrante" value="ALUMNO" <?= $tipoPrevio === 'ALUMNO' ? 'checked' : '' ?>> Alumno</label>
                    <label><input type="radio" name="tipo_integrante" value="PROFESOR" <?= $tipoPrevio === 'PROFESOR' ? 'checked' : '' ?>> Profesor</label>
                    <small class="texto-secundario">La condición de profesor queda pendiente de verificación y no otorga permisos adicionales.</small>
                </fieldset>
                <?= $mostrarErrorCampo($errores, 'tipo_integrante') ?>
            </div>

            <div class="campo">
                <label for="contrasena">Contraseña</label>
                <input id="contrasena" name="contrasena" type="password" required
                       minlength="<?= LONGITUD_MINIMA_CONTRASENA ?>" maxlength="<?= LONGITUD_MAXIMA_CONTRASENA ?>"
                       autocomplete="new-password" placeholder="••••••••••••">
                <small class="texto-secundario">Mínimo <?= LONGITUD_MINIMA_CONTRASENA ?> caracteres. Se recomienda una frase larga.</small>
                <?= $mostrarErrorCampo($errores, 'contrasena') ?>
            </div>

            <div class="campo">
                <label for="confirmacion-contrasena">Confirmar contraseña</label>
                <input id="confirmacion-contrasena" name="confirmacion_contrasena" type="password" required
                       maxlength="<?= LONGITUD_MAXIMA_CONTRASENA ?>" autocomplete="new-password"
                       placeholder="••••••••••••">
                <?= $mostrarErrorCampo($errores, 'confirmacion_contrasena') ?>
            </div>

            <div class="acciones-acceso">
                <button type="submit" class="boton-principal">Registrarme</button>
                <p class="texto-secundario">¿Ya tienes cuenta? <a href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Inicia sesión</a></p>
            </div>
        </form>
    </section>
</div>

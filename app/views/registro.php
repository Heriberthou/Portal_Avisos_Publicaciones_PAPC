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
<section class="tarjeta-formulario">
    <h1>Crear cuenta</h1>

    <?php if (!empty($datosVista['mensajeError'])): ?>
        <p class="mensaje mensaje-error" role="alert"><?= Vista::escapar($datosVista['mensajeError']) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= Vista::escapar(Vista::url('/registro')) ?>" class="formulario-autenticacion" novalidate>
        <?= Vista::campoTokenCsrf() ?>

        <label for="nombre-usuario">Nombre completo</label>
        <input id="nombre-usuario" name="nombre_usuario" type="text" required
               maxlength="<?= LONGITUD_MAXIMA_NOMBRE_USUARIO ?>" autocomplete="name"
               value="<?= Vista::escapar($valoresPrevios['nombre_usuario'] ?? '') ?>">
        <?= $mostrarErrorCampo($errores, 'nombre_usuario') ?>

        <label for="correo-electronico">Correo electrónico</label>
        <input id="correo-electronico" name="correo_electronico" type="email" required
               maxlength="<?= LONGITUD_MAXIMA_CORREO_ELECTRONICO ?>" autocomplete="email"
               value="<?= Vista::escapar($valoresPrevios['correo_electronico'] ?? '') ?>">
        <?= $mostrarErrorCampo($errores, 'correo_electronico') ?>

        <fieldset class="grupo-opciones">
            <legend>Me registro como</legend>
            <label><input type="radio" name="tipo_integrante" value="ALUMNO" <?= $tipoPrevio === 'ALUMNO' ? 'checked' : '' ?>> Alumno</label>
            <label><input type="radio" name="tipo_integrante" value="PROFESOR" <?= $tipoPrevio === 'PROFESOR' ? 'checked' : '' ?>> Profesor</label>
            <small class="texto-secundario">La condición de profesor queda pendiente de verificación y no otorga permisos adicionales.</small>
        </fieldset>
        <?= $mostrarErrorCampo($errores, 'tipo_integrante') ?>

        <label for="contrasena">Contraseña</label>
        <input id="contrasena" name="contrasena" type="password" required
               minlength="<?= LONGITUD_MINIMA_CONTRASENA ?>" maxlength="<?= LONGITUD_MAXIMA_CONTRASENA ?>"
               autocomplete="new-password">
        <small class="texto-secundario">Mínimo <?= LONGITUD_MINIMA_CONTRASENA ?> caracteres. Se recomienda una frase larga.</small>
        <?= $mostrarErrorCampo($errores, 'contrasena') ?>

        <label for="confirmacion-contrasena">Confirmar contraseña</label>
        <input id="confirmacion-contrasena" name="confirmacion_contrasena" type="password" required
               maxlength="<?= LONGITUD_MAXIMA_CONTRASENA ?>" autocomplete="new-password">
        <?= $mostrarErrorCampo($errores, 'confirmacion_contrasena') ?>

        <button type="submit" class="boton-principal">Registrarme</button>
    </form>

    <p class="texto-secundario">¿Ya tienes cuenta? <a href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Inicia sesión</a></p>
</section>

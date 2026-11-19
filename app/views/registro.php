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
 * La validación del navegador (validacion-formularios.js) repite las reglas
 * de ValidadorEntrada solo como ayuda; con JavaScript desactivado o con el
 * HTML modificado, el servidor sigue rechazando los datos inválidos.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$valoresPrevios = $datosVista['valoresPrevios'] ?? [];
$errores = $datosVista['errores'] ?? [];
$tipoPrevio = $valoresPrevios['tipo_integrante'] ?? '';

/**
 * Párrafo de error de un campo (vacío si no hay error). Siempre existe para
 * que el script y los lectores de pantalla tengan dónde anunciar mensajes.
 *
 * @param array<string, string> $errores Errores por campo.
 * @param string $campo Nombre del campo.
 * @param string $idElemento id del párrafo (referido por aria-describedby).
 * @return string HTML escapado.
 */
$mostrarErrorCampo = static function (array $errores, string $campo, string $idElemento): string {
    return '<p class="error-campo" id="' . Vista::escapar($idElemento) . '" aria-live="polite">'
        . Vista::escapar($errores[$campo] ?? '') . '</p>';
};

/**
 * Atributo aria-invalid cuando el servidor marcó error en el campo.
 *
 * @param array<string, string> $errores Errores por campo.
 * @param string $campo Nombre del campo.
 * @return string Atributo o cadena vacía.
 */
$marcarInvalido = static function (array $errores, string $campo): string {
    return isset($errores[$campo]) ? 'aria-invalid="true"' : '';
};
?>
<div class="pantalla-acceso">
    <?php require RUTA_RAIZ . '/app/views/parciales/panel-institucional.php'; ?>

    <section class="panel-formulario">
        <h1 class="titulo-acceso">Crear cuenta</h1>

        <?php if (!empty($datosVista['mensajeError'])): ?>
            <p class="mensaje mensaje-error" role="alert"><?= Vista::escapar($datosVista['mensajeError']) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= Vista::escapar(Vista::url('/registro')) ?>" class="rejilla-campos" data-formulario="registro">
            <?= Vista::campoTokenCsrf() ?>

            <div class="campo campo-ancho">
                <label for="nombres-usuario">Nombre(s)</label>
                <input id="nombres-usuario" name="nombres_usuario" type="text" required
                       minlength="<?= LONGITUD_MINIMA_PARTE_NOMBRE ?>" maxlength="<?= LONGITUD_MAXIMA_NOMBRES_USUARIO ?>"
                       autocomplete="given-name" placeholder="Nombre(s)"
                       data-validar="nombre" data-descripcion="El nombre"
                       aria-describedby="error-nombres-usuario" <?= $marcarInvalido($errores, 'nombres_usuario') ?>
                       value="<?= Vista::escapar($valoresPrevios['nombres_usuario'] ?? '') ?>">
                <?= $mostrarErrorCampo($errores, 'nombres_usuario', 'error-nombres-usuario') ?>
            </div>

            <div class="campo">
                <label for="apellido-paterno-usuario">Apellido paterno</label>
                <input id="apellido-paterno-usuario" name="apellido_paterno_usuario" type="text" required
                       minlength="<?= LONGITUD_MINIMA_PARTE_NOMBRE ?>" maxlength="<?= LONGITUD_MAXIMA_APELLIDO_USUARIO ?>"
                       autocomplete="family-name" placeholder="Apellido paterno"
                       data-validar="nombre" data-descripcion="El apellido paterno"
                       aria-describedby="error-apellido-paterno-usuario" <?= $marcarInvalido($errores, 'apellido_paterno_usuario') ?>
                       value="<?= Vista::escapar($valoresPrevios['apellido_paterno_usuario'] ?? '') ?>">
                <?= $mostrarErrorCampo($errores, 'apellido_paterno_usuario', 'error-apellido-paterno-usuario') ?>
            </div>

            <div class="campo">
                <label for="apellido-materno-usuario">Apellido materno <span class="texto-secundario">(opcional)</span></label>
                <input id="apellido-materno-usuario" name="apellido_materno_usuario" type="text"
                       minlength="<?= LONGITUD_MINIMA_PARTE_NOMBRE ?>" maxlength="<?= LONGITUD_MAXIMA_APELLIDO_USUARIO ?>"
                       autocomplete="additional-name" placeholder="Apellido materno"
                       data-validar="nombre" data-descripcion="El apellido materno"
                       aria-describedby="error-apellido-materno-usuario" <?= $marcarInvalido($errores, 'apellido_materno_usuario') ?>
                       value="<?= Vista::escapar($valoresPrevios['apellido_materno_usuario'] ?? '') ?>">
                <?= $mostrarErrorCampo($errores, 'apellido_materno_usuario', 'error-apellido-materno-usuario') ?>
            </div>

            <div class="campo campo-ancho">
                <label for="correo-electronico">Correo electrónico</label>
                <input id="correo-electronico" name="correo_electronico" type="email" required
                       maxlength="<?= LONGITUD_MAXIMA_CORREO_ELECTRONICO ?>" autocomplete="email"
                       placeholder="usuario@ejemplo.com" data-validar="correo"
                       aria-describedby="error-correo-electronico" <?= $marcarInvalido($errores, 'correo_electronico') ?>
                       value="<?= Vista::escapar($valoresPrevios['correo_electronico'] ?? '') ?>">
                <?= $mostrarErrorCampo($errores, 'correo_electronico', 'error-correo-electronico') ?>
            </div>

            <div class="campo campo-ancho">
                <fieldset class="grupo-opciones" data-validar="opcion" data-nombre="tipo_integrante"
                          aria-describedby="error-tipo-integrante">
                    <legend>Me registro como</legend>
                    <label><input type="radio" name="tipo_integrante" value="ALUMNO" required <?= $tipoPrevio === 'ALUMNO' ? 'checked' : '' ?>> Alumno</label>
                    <label><input type="radio" name="tipo_integrante" value="PROFESOR" <?= $tipoPrevio === 'PROFESOR' ? 'checked' : '' ?>> Profesor</label>
                    <small class="texto-secundario">Si elige Profesor, su registro quedará pendiente de verificación. No da permisos adicionales.</small>
                </fieldset>
                <?= $mostrarErrorCampo($errores, 'tipo_integrante', 'error-tipo-integrante') ?>
            </div>

            <div class="campo">
                <label for="contrasena">Contraseña</label>
                <div class="control-contrasena">
                    <input id="contrasena" name="contrasena" type="password" required
                           minlength="<?= LONGITUD_MINIMA_CONTRASENA ?>" maxlength="<?= LONGITUD_MAXIMA_CONTRASENA ?>"
                           autocomplete="new-password" placeholder="••••••••••••" data-validar="contrasena-nueva"
                           aria-describedby="ayuda-contrasena contador-contrasena error-contrasena" <?= $marcarInvalido($errores, 'contrasena') ?>>
                    <button type="button" class="boton-mostrar" data-mostrar="contrasena" aria-controls="contrasena" aria-pressed="false" hidden>Mostrar</button>
                </div>
                <small class="texto-secundario" id="ayuda-contrasena">Mínimo <?= LONGITUD_MINIMA_CONTRASENA ?> caracteres. Puede usar una frase con espacios. Evite contraseñas comunes.</small>
                <small class="contador-contrasena" id="contador-contrasena" data-contador="contrasena" aria-live="polite"></small>
                <?= $mostrarErrorCampo($errores, 'contrasena', 'error-contrasena') ?>
            </div>

            <div class="campo">
                <label for="confirmacion-contrasena">Confirmar contraseña</label>
                <div class="control-contrasena">
                    <input id="confirmacion-contrasena" name="confirmacion_contrasena" type="password" required
                           maxlength="<?= LONGITUD_MAXIMA_CONTRASENA ?>" autocomplete="new-password"
                           placeholder="••••••••••••" data-validar="confirmacion" data-confirma="contrasena"
                           aria-describedby="error-confirmacion-contrasena" <?= $marcarInvalido($errores, 'confirmacion_contrasena') ?>>
                    <button type="button" class="boton-mostrar" data-mostrar="confirmacion-contrasena" aria-controls="confirmacion-contrasena" aria-pressed="false" hidden>Mostrar</button>
                </div>
                <?= $mostrarErrorCampo($errores, 'confirmacion_contrasena', 'error-confirmacion-contrasena') ?>
            </div>

            <div class="acciones-acceso">
                <button type="submit" class="boton-principal">Registrarme</button>
                <p class="texto-secundario">¿Ya tiene cuenta? <a href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Inicie sesión</a></p>
            </div>
        </form>
    </section>
</div>
<script src="<?= Vista::escapar(Vista::url('/js/validacion-formularios.js')) ?>" defer></script>

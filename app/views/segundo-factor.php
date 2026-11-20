<?php
/**
 * Pantalla H: verificar el segundo factor (ADMINISTRADOR y PRINCIPAL).
 *
 * Acepta el código de 6 dígitos de la aplicación de autenticación o uno de
 * los códigos de recuperación (formato XXXXX-XXXXX). Hasta completarlo no
 * existe una sesión administrativa.
 *
 * Variables: mensajeError (string|null).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$mensajeError = (string) ($datosVista['mensajeError'] ?? '');
?>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-inicio.php'; ?>
<h1 class="titulo-acceso">Verificación en dos pasos</h1>
<p class="subtitulo-acceso">Su cuenta tiene funciones administrativas. Para continuar, escriba el código que muestra su aplicación de autenticación.</p>

<form method="post" action="<?= Vista::escapar(Vista::url('/verificar-segundo-factor')) ?>" class="rejilla-campos" data-formulario="segundo-factor">
    <?= Vista::campoTokenCsrf() ?>

    <div class="campo campo-ancho">
        <label for="codigo"><?= Vista::icono('escudo') ?>Código de verificación</label>
        <input id="codigo" name="codigo" type="text" required maxlength="20"
               autocomplete="one-time-code" spellcheck="false" autofocus
               data-validar="requerido" data-mensaje-vacio="Escriba el código de su aplicación de autenticación."
               aria-describedby="ayuda-codigo error-codigo" <?= $mensajeError !== '' ? 'aria-invalid="true"' : '' ?>>
        <small class="texto-secundario" id="ayuda-codigo">Son 6 dígitos y cambian cada 30 segundos. ¿No tiene su teléfono? Escriba uno de sus códigos de recuperación (por ejemplo, ABCDE-FGHJK).</small>
        <p class="error-campo" id="error-codigo" aria-live="polite"><?= Vista::escapar($mensajeError) ?></p>
    </div>

    <div class="acciones-acceso">
        <button type="submit" class="boton-principal">Verificar</button>
        <p class="texto-secundario"><a href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Volver al inicio de sesión</a></p>
    </div>
</form>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-fin.php'; ?>
<script src="<?= Vista::escapar(Vista::url('/js/validacion-formularios.js')) ?>" defer></script>

<?php
/**
 * Formulario de un solo campo de correo (recuperar contraseña y reenviar
 * el enlace de verificación). La respuesta del servidor es neutral.
 *
 * Variables: titulo, descripcion, rutaFormulario, textoBoton,
 * correoPrevio, mensajeError.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$mensajeError = (string) ($datosVista['mensajeError'] ?? '');
?>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-inicio.php'; ?>
<h1 class="titulo-acceso"><?= Vista::escapar($datosVista['titulo'] ?? '') ?></h1>
<p class="subtitulo-acceso"><?= Vista::escapar($datosVista['descripcion'] ?? '') ?></p>

<form method="post" action="<?= Vista::escapar(Vista::url((string) $datosVista['rutaFormulario'])) ?>" class="rejilla-campos" data-formulario="solo-correo">
    <?= Vista::campoTokenCsrf() ?>

    <div class="campo campo-ancho">
        <label for="correo-electronico"><?= Vista::icono('correo') ?>Correo electrónico</label>
        <input id="correo-electronico" name="correo_electronico" type="email" required
               maxlength="<?= LONGITUD_MAXIMA_CORREO_ELECTRONICO ?>" autocomplete="email"
               placeholder="nombre@ejemplo.com" data-validar="correo"
               aria-describedby="error-correo-electronico" <?= $mensajeError !== '' ? 'aria-invalid="true"' : '' ?>
               value="<?= Vista::escapar($datosVista['correoPrevio'] ?? '') ?>">
        <p class="error-campo" id="error-correo-electronico" aria-live="polite"><?= Vista::escapar($mensajeError) ?></p>
    </div>

    <div class="acciones-acceso">
        <button type="submit" class="boton-principal"><?= Vista::escapar($datosVista['textoBoton'] ?? 'Enviar') ?></button>
        <p class="texto-secundario"><a href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Volver al inicio de sesión</a></p>
    </div>
</form>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-fin.php'; ?>
<script src="<?= Vista::escapar(Vista::url('/js/validacion-formularios.js')) ?>" defer></script>

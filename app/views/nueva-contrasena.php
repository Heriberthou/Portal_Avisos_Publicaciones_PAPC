<?php
/**
 * Pantallas F y G: crear una contraseña nueva (activar cuenta o
 * restablecer). Un solo campo con Mostrar/Ocultar, sin confirmación, para
 * no poner obstáculos a los gestores de contraseñas (especificación 5.6).
 *
 * El campo oculto "username" ayuda al gestor de contraseñas a asociar la
 * contraseña con la cuenta; NO se envía al servidor (no tiene name).
 *
 * Variables: titulo, descripcion, rutaFormulario, textoBoton,
 * correoCuenta, errorContrasena.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$errorContrasena = (string) ($datosVista['errorContrasena'] ?? '');
?>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-inicio.php'; ?>
<h1 class="titulo-acceso"><?= Vista::escapar($datosVista['titulo'] ?? '') ?></h1>
<p class="subtitulo-acceso"><?= Vista::escapar($datosVista['descripcion'] ?? '') ?></p>

<form method="post" action="<?= Vista::escapar(Vista::url((string) $datosVista['rutaFormulario'])) ?>" class="rejilla-campos" data-formulario="nueva-contrasena">
    <?= Vista::campoTokenCsrf() ?>
    <input type="email" autocomplete="username" value="<?= Vista::escapar($datosVista['correoCuenta'] ?? '') ?>" hidden readonly aria-hidden="true" tabindex="-1">

    <div class="campo campo-ancho">
        <p class="texto-secundario">Cuenta: <strong><?= Vista::escapar($datosVista['correoCuenta'] ?? '') ?></strong></p>
        <label for="contrasena"><?= Vista::icono('candado') ?>Nueva contraseña</label>
        <div class="control-contrasena">
            <input id="contrasena" name="contrasena" type="password" required
                   minlength="<?= LONGITUD_MINIMA_CONTRASENA ?>" maxlength="<?= LONGITUD_MAXIMA_CONTRASENA ?>"
                   autocomplete="new-password" data-validar="contrasena-nueva"
                   aria-describedby="ayuda-contrasena contador-contrasena error-contrasena"
                   <?= $errorContrasena !== '' ? 'aria-invalid="true"' : '' ?>>
            <button type="button" class="boton-mostrar" data-mostrar="contrasena" aria-controls="contrasena" aria-pressed="false" hidden>Mostrar</button>
        </div>
        <small class="texto-secundario" id="ayuda-contrasena">Use al menos <?= LONGITUD_MINIMA_CONTRASENA ?> caracteres. Una frase fácil de recordar funciona bien; no hace falta combinar mayúsculas, números ni símbolos. No incluya su nombre ni su correo.</small>
        <small class="contador-contrasena" id="contador-contrasena" data-contador="contrasena" aria-live="polite"></small>
        <p class="error-campo" id="error-contrasena" aria-live="polite"><?= Vista::escapar($errorContrasena) ?></p>
    </div>

    <div class="acciones-acceso">
        <button type="submit" class="boton-principal"><?= Vista::escapar($datosVista['textoBoton'] ?? 'Guardar') ?></button>
    </div>
</form>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-fin.php'; ?>
<script src="<?= Vista::escapar(Vista::url('/js/validacion-formularios.js')) ?>" defer></script>

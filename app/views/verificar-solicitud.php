<?php
/**
 * Pantalla D: confirmar el correo de una solicitud.
 *
 * El enlace del correo solo guarda el token en la sesión; la verificación
 * ocurre al pulsar el botón (POST con CSRF). Así un antivirus que abre los
 * enlaces automáticamente no consume el token.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
?>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-inicio.php'; ?>
<h1 class="titulo-acceso">Verifique su correo electrónico</h1>
<p class="subtitulo-acceso">Pulse el botón para confirmar que este correo le pertenece. Después, una persona autorizada revisará su solicitud.</p>

<form method="post" action="<?= Vista::escapar(Vista::url('/verificar-solicitud')) ?>" class="rejilla-campos" data-formulario="verificar">
    <?= Vista::campoTokenCsrf() ?>
    <div class="acciones-acceso">
        <button type="submit" class="boton-principal">Confirmar mi correo</button>
    </div>
</form>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-fin.php'; ?>
<script src="<?= Vista::escapar(Vista::url('/js/validacion-formularios.js')) ?>" defer></script>

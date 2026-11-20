<?php
/**
 * Configuración del segundo factor: falta el enlace de autorización.
 *
 * La contraseña ya se validó, pero para configurar el autenticador se exige
 * además abrir el enlace de un solo uso enviado al correo de la cuenta
 * (o entregado por la consola del servidor). Quien solo conoce la
 * contraseña no puede vincular su propio teléfono (hallazgo R-02).
 *
 * Variables: mensaje (string|null).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
?>
<div class="pantalla-acceso">
    <?php require RUTA_RAIZ . '/app/views/parciales/panel-institucional.php'; ?>

    <section class="panel-formulario">
        <h1 class="titulo-acceso">Configurar segundo factor</h1>
        <p class="subtitulo-acceso">Su cuenta tiene funciones administrativas. Para vincular su aplicación de autenticación, abra en este navegador el enlace de configuración que se envió a su correo.</p>

        <?php if (!empty($datosVista['mensaje'])): ?>
            <p class="mensaje mensaje-aviso" role="status"><?= Vista::escapar($datosVista['mensaje']) ?></p>
        <?php endif; ?>

        <ul class="lista-pasos">
            <li>El enlace sirve una sola vez y vence en 1 hora.</li>
            <li>Si no lo tiene o ya venció, pida uno nuevo: llegará al correo de su cuenta, no a otro.</li>
            <li>Si usted no intentó iniciar sesión, avise de inmediato al administrador principal.</li>
        </ul>

        <form method="post" action="<?= Vista::escapar(Vista::url('/configurar-segundo-factor/enlace')) ?>" class="rejilla-campos" data-formulario="enlace-segundo-factor">
            <?= Vista::campoTokenCsrf() ?>
            <div class="acciones-acceso">
                <button type="submit" class="boton-principal">Enviarme un enlace nuevo</button>
                <p class="texto-secundario"><a href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Volver al inicio de sesión</a></p>
            </div>
        </form>
    </section>
</div>
<script src="<?= Vista::escapar(Vista::url('/js/validacion-formularios.js')) ?>" defer></script>

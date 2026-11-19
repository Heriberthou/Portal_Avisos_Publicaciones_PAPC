<?php
/**
 * Confirmación neutral tras enviar una solicitud o pedir el reenvío del
 * enlace: el texto es el mismo exista o no el correo (sin enumeración).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
?>
<div class="pantalla-acceso">
    <?php require RUTA_RAIZ . '/app/views/parciales/panel-institucional.php'; ?>

    <section class="panel-formulario">
        <h1 class="titulo-acceso">Solicitud recibida</h1>
        <p class="mensaje mensaje-exito" role="status"><?= Vista::escapar(SolicitudAccesoController::MENSAJE_SOLICITUD_RECIBIDA) ?></p>

        <h2 class="subtitulo-seccion">¿Qué sigue?</h2>
        <ol class="lista-pasos">
            <li>Abra el enlace que le enviamos para confirmar su correo (vence en 24 horas).</li>
            <li>Una persona autorizada revisará que usted pertenece a la comunidad universitaria.</li>
            <li>Si se aprueba, recibirá otro correo para activar su cuenta y crear su contraseña.</li>
        </ol>

        <div class="acciones-acceso acciones-izquierda">
            <p class="texto-secundario enlaces-pie">
                <a href="<?= Vista::escapar(Vista::url('/reenviar-verificacion')) ?>">No me llegó el correo</a>
                <a href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Ir a iniciar sesión</a>
            </p>
        </div>
    </section>
</div>

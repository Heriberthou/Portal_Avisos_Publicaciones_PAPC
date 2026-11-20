<?php
/**
 * Confirmación neutral tras enviar una solicitud o pedir el reenvío del
 * enlace: el texto es el mismo exista o no el correo (sin enumeración).
 *
 * Hallazgo R-07: las instrucciones son condicionales ("si recibe...") y no
 * afirman que el correo se envió. En modo buzón de pruebas se avisa que los
 * mensajes no salen del servidor.
 *
 * Variables: modoBuzonPruebas (bool).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
?>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-inicio.php'; ?>
<h1 class="titulo-acceso">Solicitud recibida</h1>
<p class="mensaje mensaje-exito" role="status"><?= Vista::escapar(SolicitudAccesoController::MENSAJE_SOLICITUD_RECIBIDA) ?></p>

<h2 class="subtitulo-seccion">¿Qué sigue?</h2>
<?php if (!empty($datosVista['modoBuzonPruebas'])): ?>
    <p class="mensaje mensaje-aviso">Portal en modo de demostración: por ahora los mensajes no se envían por correo.</p>
<?php endif; ?>

<ol class="lista-pasos">
    <li>Si recibe un mensaje con un enlace, ábralo para confirmar su correo (vence en 24 horas). Si no llega en unos minutos, puede pedir otro.</li>
    <li>Una persona autorizada revisará que usted pertenece a la comunidad universitaria.</li>
    <li>Si se aprueba, recibirá otro correo para activar su cuenta y crear su contraseña.</li>
</ol>

<div class="acciones-acceso">
    <p class="texto-secundario enlaces-pie">
        <a href="<?= Vista::escapar(Vista::url('/reenviar-verificacion')) ?>">No me llegó el correo</a>
        <a href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Ir a iniciar sesión</a>
    </p>
</div>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-fin.php'; ?>

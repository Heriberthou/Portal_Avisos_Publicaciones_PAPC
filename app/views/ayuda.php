<?php
/**
 * Ayuda (RF-10): explica el recorrido y qué hacer en cada situación sin
 * tener que contactar a los desarrolladores.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
?>
<section class="tarjeta-amplia contenido-informativo">
    <h1>Ayuda</h1>

    <h2>¿Cómo obtengo una cuenta?</h2>
    <ol class="lista-pasos">
        <li>Pulse <strong>Solicitar acceso</strong> y escriba su nombre, su matrícula (o número de personal) y su correo. No se pide contraseña.</li>
        <li>Abra el enlace que le enviamos para <strong>confirmar su correo</strong>. Sin este paso, su solicitud no se revisa.</li>
        <li>Una persona autorizada verifica que usted pertenece a la comunidad universitaria y <strong>aprueba o rechaza</strong> la solicitud.</li>
        <li>Si se aprueba, recibirá un enlace para <strong>activar su cuenta</strong> y crear su propia contraseña. Nadie más la conoce.</li>
        <li>Después inicie sesión con su correo y su contraseña.</li>
    </ol>

    <h2>Situaciones frecuentes</h2>
    <dl class="datos-cuenta">
        <dt>No me llegó el correo</dt>
        <dd>Revise su carpeta de correo no deseado. Puede pedir un enlace nuevo en <a href="<?= Vista::escapar(Vista::url('/reenviar-verificacion')) ?>">Reenviar enlace de verificación</a>.</dd>
        <dt>El enlace dice que no es válido o venció</dt>
        <dd>Los enlaces sirven una sola vez. El de verificación dura 24 horas, el de activación 24 horas y el de recuperación 30 minutos. Solicite uno nuevo.</dd>
        <dt>Mi enlace de activación venció</dt>
        <dd>Use <a href="<?= Vista::escapar(Vista::url('/recuperar-contrasena')) ?>">¿Olvidó su contraseña?</a> con el mismo correo: le enviaremos un enlace de activación nuevo.</dd>
        <dt>Olvidé mi contraseña</dt>
        <dd>Use <a href="<?= Vista::escapar(Vista::url('/recuperar-contrasena')) ?>">Recuperar contraseña</a>. Al cambiarla se cierran todas sus sesiones abiertas.</dd>
        <dt>Soy profesor y no tengo número de personal</dt>
        <dd>Comuníquese con <?= Vista::escapar(CONTACTO_SOPORTE) ?>: su solicitud se revisará con un responsable designado.</dd>
        <dt>Cambié de correo o ya no tengo acceso a él</dt>
        <dd>Comuníquese con <?= Vista::escapar(CONTACTO_SOPORTE) ?>. Por seguridad, la matrícula por sí sola no permite cambiar el correo de una cuenta.</dd>
    </dl>

    <h2>Usuario y administrador no son lo mismo</h2>
    <p>Ser alumno o profesor indica su relación con la universidad, no sus permisos. Toda cuenta aprobada empieza con el rol <strong>Usuario</strong>. Solo el administrador principal puede asignar funciones administrativas, por un procedimiento aparte y auditado.</p>

    <p><a href="<?= Vista::escapar(Vista::url('/')) ?>">Volver al inicio</a></p>
</section>

<?php
/**
 * Aviso de privacidad (BORRADOR del prototipo académico).
 *
 * No es un aviso oficial: el responsable institucional debe revisarlo y
 * completarlo antes de usar datos reales (especificación 1.3, 2.4 y 13.1).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
?>
<section class="tarjeta-amplia contenido-informativo">
    <h1>Aviso de privacidad</h1>
    <p class="mensaje mensaje-aviso">Borrador de un prototipo académico. No sustituye al aviso de privacidad oficial de la institución; debe validarlo el área responsable antes de usar datos reales.</p>

    <h2>Datos que se recaban</h2>
    <ul>
        <li>Nombre(s) y apellidos.</li>
        <li>Tipo de integrante (alumno o profesor) y matrícula o identificador institucional.</li>
        <li>Correo electrónico de contacto.</li>
        <li>Registros técnicos de seguridad: fecha y hora de los accesos y la dirección IP desde la que se hicieron.</li>
    </ul>
    <p>No se solicita CURP, INE, domicilio, fecha de nacimiento ni fotografía.</p>

    <h2>Para qué se usan</h2>
    <ul>
        <li>Revisar su solicitud de acceso y comprobar que pertenece a la comunidad universitaria.</li>
        <li>Enviarle los enlaces de verificación, activación y recuperación.</li>
        <li>Proteger el portal: limitar intentos, investigar incidentes y dejar constancia de las decisiones administrativas.</li>
    </ul>

    <h2>Quién los consulta</h2>
    <p>Solo las personas autorizadas para revisar solicitudes. Las contraseñas se guardan cifradas de forma irreversible (nadie, ni el administrador, puede leerlas).</p>

    <h2>Pendiente de definir por la institución</h2>
    <ul>
        <li>Responsable del tratamiento y medio de contacto.</li>
        <li>Plazos de conservación de solicitudes no verificadas, rechazadas y de bitácoras.</li>
        <li>Procedimiento para ejercer derechos de acceso, rectificación, cancelación u oposición.</li>
    </ul>

    <p><a href="<?= Vista::escapar(Vista::url('/')) ?>">Volver al inicio</a></p>
</section>

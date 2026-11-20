<?php
/**
 * Apertura de las pantallas de acceso (iniciar sesión, solicitar acceso,
 * activar cuenta, recuperar contraseña y verificación en dos pasos).
 *
 * Arma la tarjeta de dos columnas: a la izquierda la identidad de la
 * universidad y a la derecha el contenido de cada pantalla. Toda vista que
 * la incluya debe cerrar con parciales/acceso-fin.php.
 *
 * Variables opcionales: $datosVista['mensajes'] (mensajes flash).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
Vista::usarDiseno(Vista::DISENO_ACCESO);
?>
<div class="pantalla-acceso">
    <div class="tarjeta-acceso">
        <?php require RUTA_RAIZ . '/app/views/parciales/panel-institucional.php'; ?>

        <section class="panel-formulario">
            <?php require RUTA_RAIZ . '/app/views/parciales/mensajes-flash.php'; ?>

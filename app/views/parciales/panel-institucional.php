<?php
/**
 * Panel institucional de las pantallas de acceso: escudo, nombre de la
 * universidad y nombre del sistema. Es decorativo para lectores de pantalla
 * salvo el escudo (texto alternativo) y los nombres.
 */
declare(strict_types=1);
?>
<aside class="panel-institucional">
    <img class="logo-institucional" src="<?= Vista::escapar(Vista::url('/img/logo-umar.png')) ?>"
         alt="Escudo de la Universidad del Mar" width="150" height="150">
    <p class="nombre-universidad">Universidad del Mar</p>
    <p class="nombre-campus">Campus Puerto Escondido</p>
    <p class="nombre-sistema">
        <svg class="icono-sistema" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <rect x="3" y="4" width="15" height="16" rx="2"/>
            <path d="M18 8h3v10a2 2 0 0 1-2 2H5"/>
            <rect x="6" y="7" width="5" height="4" rx="0.5"/>
            <path d="M13 8h2M13 11h2M6 14h9M6 17h9"/>
        </svg>
        <span><?= Vista::escapar(NOMBRE_APLICACION) ?></span>
    </p>
</aside>

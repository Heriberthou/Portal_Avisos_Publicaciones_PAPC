<?php
/**
 * Marca de las pantallas de acceso: escudo, nombre de la universidad y
 * campus, en la parte superior de la tarjeta.
 */
declare(strict_types=1);
?>
<header class="marca-acceso">
    <img class="logo-institucional" src="<?= Vista::escapar(Vista::url('/img/escudo-umar.png')) ?>"
         alt="Escudo de la Universidad del Mar" width="78" height="88">
    <p class="nombre-universidad">Universidad del Mar</p>
    <p class="nombre-campus">Campus Puerto Escondido</p>
</header>

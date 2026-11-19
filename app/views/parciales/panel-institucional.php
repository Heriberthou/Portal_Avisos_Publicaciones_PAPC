<?php
/**
 * Panel institucional (logo y nombre de la UMAR) de las pantallas de acceso.
 */
declare(strict_types=1);
?>
<aside class="panel-institucional">
    <div class="marco-logo">
        <img class="logo-institucional" src="<?= Vista::escapar(Vista::url('/img/logo-umar.png')) ?>"
             alt="Escudo de la Universidad del Mar" width="190" height="190">
    </div>
    <p class="nombre-universidad">Universidad del Mar</p>
    <p class="nombre-campus">Campus Puerto Escondido</p>
    <p class="nombre-sistema"><?= Vista::escapar(NOMBRE_APLICACION) ?></p>
</aside>

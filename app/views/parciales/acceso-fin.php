<?php
/**
 * Cierre de las pantallas de acceso (abiertas en parciales/acceso-inicio.php)
 * y enlaces de apoyo comunes a todas ellas.
 */
declare(strict_types=1);
?>
            <nav class="enlaces-apoyo" aria-label="Enlaces de apoyo">
                <a href="<?= Vista::escapar(Vista::url('/ayuda')) ?>">Ayuda</a>
                <a href="<?= Vista::escapar(Vista::url('/aviso-privacidad')) ?>">Aviso de privacidad</a>
            </nav>
        </section>
    </div>
</div>

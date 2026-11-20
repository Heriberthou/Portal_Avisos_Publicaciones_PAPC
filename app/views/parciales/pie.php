<?php
/**
 * Pie común: cierra las etiquetas abiertas en el encabezado.
 */
declare(strict_types=1);
?>
</main>
<footer class="pie-institucional">
    <p>© <?= date('Y') ?> Universidad del Mar</p>
    <nav aria-label="Ayuda e información legal">
        <a href="<?= Vista::escapar(Vista::url('/ayuda')) ?>">Ayuda</a>
        <a href="<?= Vista::escapar(Vista::url('/aviso-privacidad')) ?>">Aviso de privacidad</a>
    </nav>
</footer>
</body>
</html>

<?php
/**
 * Pantalla A: bienvenida pública. Dos caminos claros: "Iniciar sesión" y
 * "Solicitar acceso". No se elige rol aquí y no se simulan módulos que aún
 * no existen (el portal de avisos se indica "en desarrollo").
 *
 * Variables: usuarioActual (array|null).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$usuarioActual = $datosVista['usuarioActual'] ?? null;
?>
<div class="pantalla-acceso">
    <?php require RUTA_RAIZ . '/app/views/parciales/panel-institucional.php'; ?>

    <section class="panel-formulario">
        <h1 class="titulo-acceso">Bienvenido(a) al PAPC</h1>
        <p class="subtitulo-acceso">Consulte avisos de la comunidad universitaria. Si tiene una cuenta autorizada, inicie sesión; si aún no cuenta con acceso, puede solicitarlo.</p>

        <div class="acciones-acceso acciones-izquierda">
            <?php if ($usuarioActual !== null): ?>
                <a class="boton-principal boton-enlace" href="<?= Vista::escapar(Vista::url('/inicio')) ?>">Ir a mi cuenta</a>
            <?php else: ?>
                <a class="boton-principal boton-enlace" href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Iniciar sesión</a>
                <a class="boton-secundario boton-enlace" href="<?= Vista::escapar(Vista::url('/solicitar-acceso')) ?>">Solicitar acceso</a>
            <?php endif; ?>
        </div>

        <p class="mensaje mensaje-aviso aviso-desarrollo">Portal en desarrollo: la consulta de avisos todavía no está disponible. Esta primera entrega se enfoca en el acceso seguro.</p>

        <p class="texto-secundario enlaces-pie">
            <a href="<?= Vista::escapar(Vista::url('/ayuda')) ?>">Ayuda</a>
            <a href="<?= Vista::escapar(Vista::url('/aviso-privacidad')) ?>">Aviso de privacidad</a>
        </p>
    </section>
</div>

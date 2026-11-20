<?php
/**
 * Encabezado común. Variables: $datosVista['tituloPagina'], $datosVista['mensajes'].
 * Todo valor dinámico se imprime con Vista::escapar().
 *
 * Diseño general: barra superior y mensajes arriba del contenido.
 * Diseño de acceso: sin barra (la tarjeta ya muestra la marca); los mensajes
 * se imprimen dentro de la tarjeta (parciales/acceso-inicio.php).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$disenoPagina = Vista::obtenerDiseno();
?>
<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Vista::escapar($datosVista['tituloPagina'] ?? 'PAPC') ?> · PAPC</title>
    <link rel="stylesheet" href="<?= Vista::escapar(Vista::url('/css/portal.css')) ?>">
</head>
<body class="diseno-<?= Vista::escapar($disenoPagina) ?>">
<?php if ($disenoPagina === Vista::DISENO_GENERAL): ?>
<header class="barra-superior">
    <img class="logo-barra" src="<?= Vista::escapar(Vista::url('/img/logo-umar.png')) ?>" alt="" width="44" height="44">
    <span class="textos-barra">
        <span class="nombre-portal"><?= Vista::escapar(NOMBRE_APLICACION) ?></span>
        <span class="nombre-institucion">Universidad del Mar · Campus Puerto Escondido</span>
    </span>
</header>
<?php endif; ?>
<main class="contenedor-principal">
<?php if ($disenoPagina === Vista::DISENO_GENERAL): ?>
    <?php require RUTA_RAIZ . '/app/views/parciales/mensajes-flash.php'; ?>
<?php endif; ?>

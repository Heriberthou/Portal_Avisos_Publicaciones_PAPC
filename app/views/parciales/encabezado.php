<?php
/**
 * Encabezado común. Variables: $datosVista['tituloPagina'], $datosVista['mensajes'].
 * Todo valor dinámico se imprime con Vista::escapar().
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$mensajesFlash = $datosVista['mensajes'] ?? [];
?>
<!doctype html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Vista::escapar($datosVista['tituloPagina'] ?? 'PAPC') ?> · PAPC</title>
    <link rel="stylesheet" href="<?= Vista::escapar(Vista::url('/css/portal.css')) ?>">
</head>
<body>
<header class="barra-superior">
    <span class="nombre-portal"><?= Vista::escapar(NOMBRE_APLICACION) ?></span>
</header>
<main class="contenedor-principal">
<?php foreach ($mensajesFlash as $mensajeFlash): ?>
    <p class="mensaje mensaje-<?= Vista::escapar($mensajeFlash['tipo'] ?? 'aviso') ?>" role="status">
        <?= Vista::escapar($mensajeFlash['mensaje'] ?? '') ?>
    </p>
<?php endforeach; ?>

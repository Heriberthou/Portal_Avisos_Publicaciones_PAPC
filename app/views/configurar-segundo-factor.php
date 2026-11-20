<?php
/**
 * Configuración inicial del segundo factor (primera vez que un
 * ADMINISTRADOR o PRINCIPAL inicia sesión).
 *
 * Se muestra la clave en texto para escribirla en la aplicación
 * ("Introducir clave de configuración"). No se genera código QR para no
 * depender de bibliotecas ni servicios externos (la CSP solo permite 'self').
 *
 * Variables: secretoFormateado, uriAprovisionamiento, correo, mensajeError.
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$mensajeError = (string) ($datosVista['mensajeError'] ?? '');
?>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-inicio.php'; ?>
<h1 class="titulo-acceso">Activar la verificación en dos pasos</h1>
<p class="subtitulo-acceso">Su cuenta tiene funciones administrativas. Antes de entrar, vincule una aplicación de autenticación en su teléfono (por ejemplo, Google Authenticator, Microsoft Authenticator o FreeOTP).</p>

<ol class="lista-pasos">
    <li>En la aplicación, elija <strong>Agregar cuenta</strong> y luego <strong>Introducir clave de configuración</strong>.</li>
    <li>Nombre de la cuenta: <strong><?= Vista::escapar(EMISOR_SEGUNDO_FACTOR . ' (' . ($datosVista['correo'] ?? '') . ')') ?></strong></li>
    <li>Clave (tipo "basada en tiempo"):
        <code class="clave-secreta"><?= Vista::escapar($datosVista['secretoFormateado'] ?? '') ?></code>
    </li>
    <li>Escriba abajo el código de 6 dígitos que muestra la aplicación.</li>
</ol>
<details class="detalle-tecnico">
    <summary>Otra opción: copiar y pegar la configuración completa</summary>
    <code class="clave-secreta"><?= Vista::escapar($datosVista['uriAprovisionamiento'] ?? '') ?></code>
</details>

<form method="post" action="<?= Vista::escapar(Vista::url('/configurar-segundo-factor')) ?>" class="rejilla-campos" data-formulario="configurar-segundo-factor">
    <?= Vista::campoTokenCsrf() ?>
    <div class="campo campo-ancho">
        <label for="codigo"><?= Vista::icono('escudo') ?>Código de 6 dígitos</label>
        <input id="codigo" name="codigo" type="text" inputmode="numeric" required maxlength="10"
               autocomplete="one-time-code" data-validar="requerido"
               data-mensaje-vacio="Escriba el código que muestra la aplicación."
               aria-describedby="error-codigo" <?= $mensajeError !== '' ? 'aria-invalid="true"' : '' ?>>
        <p class="error-campo" id="error-codigo" aria-live="polite"><?= Vista::escapar($mensajeError) ?></p>
    </div>
    <div class="acciones-acceso">
        <button type="submit" class="boton-principal">Confirmar y entrar</button>
        <p class="texto-secundario"><a href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Cancelar</a></p>
    </div>
</form>
<?php require RUTA_RAIZ . '/app/views/parciales/acceso-fin.php'; ?>
<script src="<?= Vista::escapar(Vista::url('/js/validacion-formularios.js')) ?>" defer></script>

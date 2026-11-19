<?php
/**
 * Pantalla C: solicitar acceso (sustituye al registro abierto).
 *
 * Variables: valoresPrevios (array), errores (array por campo),
 * mensajeError (string|null).
 *
 * No hay contraseña ni rol: la solicitud no crea cuenta. Tampoco se pide
 * CURP, INE, domicilio, fecha de nacimiento ni fotografía (datos mínimos).
 *
 * @var array<string, mixed> $datosVista
 */
declare(strict_types=1);
$valoresPrevios = $datosVista['valoresPrevios'] ?? [];
$errores = $datosVista['errores'] ?? [];
$tipoPrevio = $valoresPrevios['tipo_integrante'] ?? '';

/**
 * Párrafo de error del campo (siempre existe, para anunciar mensajes).
 *
 * @param array<string, string> $errores Errores por campo.
 * @param string $campo Nombre del campo.
 * @return string HTML escapado.
 */
$errorCampo = static function (array $errores, string $campo): string {
    return '<p class="error-campo" id="error-' . Vista::escapar(str_replace('_', '-', $campo)) . '" aria-live="polite">'
        . Vista::escapar($errores[$campo] ?? '') . '</p>';
};

/**
 * @param array<string, string> $errores Errores por campo.
 * @param string $campo Nombre del campo.
 * @return string Atributo aria-invalid o cadena vacía.
 */
$invalido = static fn (array $errores, string $campo): string => isset($errores[$campo]) ? 'aria-invalid="true"' : '';
?>
<div class="pantalla-acceso">
    <?php require RUTA_RAIZ . '/app/views/parciales/panel-institucional.php'; ?>

    <section class="panel-formulario">
        <h1 class="titulo-acceso">Solicitar acceso al PAPC</h1>
        <p class="subtitulo-acceso">Este formulario permite solicitar una cuenta. Su solicitud será revisada antes de autorizar el acceso. En esta etapa no necesita crear una contraseña.</p>

        <?php if (!empty($datosVista['mensajeError'])): ?>
            <p class="mensaje mensaje-error" role="alert"><?= Vista::escapar($datosVista['mensajeError']) ?></p>
        <?php endif; ?>

        <form method="post" action="<?= Vista::escapar(Vista::url('/solicitar-acceso')) ?>" class="rejilla-campos" data-formulario="solicitud">
            <?= Vista::campoTokenCsrf() ?>

            <div class="campo campo-ancho">
                <label for="nombres">Nombre(s)</label>
                <input id="nombres" name="nombres" type="text" required
                       minlength="<?= LONGITUD_MINIMA_PARTE_NOMBRE ?>" maxlength="<?= LONGITUD_MAXIMA_NOMBRES_USUARIO ?>"
                       autocomplete="given-name" data-validar="nombre" data-descripcion="El nombre"
                       aria-describedby="error-nombres" <?= $invalido($errores, 'nombres') ?>
                       value="<?= Vista::escapar($valoresPrevios['nombres'] ?? '') ?>">
                <?= $errorCampo($errores, 'nombres') ?>
            </div>

            <div class="campo">
                <label for="apellido-paterno">Apellido paterno</label>
                <input id="apellido-paterno" name="apellido_paterno" type="text" required
                       minlength="<?= LONGITUD_MINIMA_PARTE_NOMBRE ?>" maxlength="<?= LONGITUD_MAXIMA_APELLIDO_USUARIO ?>"
                       autocomplete="family-name" data-validar="nombre" data-descripcion="El apellido paterno"
                       aria-describedby="error-apellido-paterno" <?= $invalido($errores, 'apellido_paterno') ?>
                       value="<?= Vista::escapar($valoresPrevios['apellido_paterno'] ?? '') ?>">
                <?= $errorCampo($errores, 'apellido_paterno') ?>
            </div>

            <div class="campo">
                <label for="apellido-materno">Apellido materno <span class="texto-secundario">(opcional)</span></label>
                <input id="apellido-materno" name="apellido_materno" type="text"
                       minlength="<?= LONGITUD_MINIMA_PARTE_NOMBRE ?>" maxlength="<?= LONGITUD_MAXIMA_APELLIDO_USUARIO ?>"
                       autocomplete="additional-name" data-validar="nombre" data-descripcion="El apellido materno"
                       aria-describedby="error-apellido-materno" <?= $invalido($errores, 'apellido_materno') ?>
                       value="<?= Vista::escapar($valoresPrevios['apellido_materno'] ?? '') ?>">
                <?= $errorCampo($errores, 'apellido_materno') ?>
            </div>

            <div class="campo campo-ancho">
                <fieldset class="grupo-opciones" data-validar="opcion" data-nombre="tipo_integrante"
                          data-mensaje-vacio="Seleccione si es alumno o profesor."
                          aria-describedby="error-tipo-integrante">
                    <legend>Soy</legend>
                    <label><input type="radio" name="tipo_integrante" value="ALUMNO" required <?= $tipoPrevio === 'ALUMNO' ? 'checked' : '' ?>> Alumno</label>
                    <label><input type="radio" name="tipo_integrante" value="PROFESOR" <?= $tipoPrevio === 'PROFESOR' ? 'checked' : '' ?>> Profesor</label>
                    <small class="texto-secundario">Esta elección no da permisos especiales: solo indica qué identificador se revisará.</small>
                </fieldset>
                <?= $errorCampo($errores, 'tipo_integrante') ?>
            </div>

            <div class="campo campo-ancho">
                <label for="identificador-institucional">Matrícula o identificador institucional</label>
                <input id="identificador-institucional" name="identificador_institucional" type="text" required
                       minlength="<?= LONGITUD_MINIMA_IDENTIFICADOR_INSTITUCIONAL ?>" maxlength="<?= LONGITUD_MAXIMA_IDENTIFICADOR_INSTITUCIONAL ?>"
                       autocomplete="off" spellcheck="false" data-validar="identificador"
                       aria-describedby="ayuda-identificador error-identificador-institucional" <?= $invalido($errores, 'identificador_institucional') ?>
                       value="<?= Vista::escapar($valoresPrevios['identificador_institucional'] ?? '') ?>">
                <small class="texto-secundario" id="ayuda-identificador">
                    Alumno: escriba su matrícula tal como aparece en su registro escolar.
                    Profesor: escriba su identificador de personal; si no cuenta con él, comuníquese con soporte (vea <a href="<?= Vista::escapar(Vista::url('/ayuda')) ?>">Ayuda</a>).
                </small>
                <?= $errorCampo($errores, 'identificador_institucional') ?>
            </div>

            <div class="campo campo-ancho">
                <label for="correo-electronico">Correo electrónico de contacto</label>
                <input id="correo-electronico" name="correo_electronico" type="email" required
                       maxlength="<?= LONGITUD_MAXIMA_CORREO_ELECTRONICO ?>" autocomplete="email"
                       placeholder="usuario@ejemplo.com" data-validar="correo"
                       aria-describedby="ayuda-correo error-correo-electronico" <?= $invalido($errores, 'correo_electronico') ?>
                       value="<?= Vista::escapar($valoresPrevios['correo_electronico'] ?? '') ?>">
                <small class="texto-secundario" id="ayuda-correo">Le enviaremos un enlace para confirmar que usted controla este correo.</small>
                <?= $errorCampo($errores, 'correo_electronico') ?>
            </div>

            <p class="campo-ancho texto-secundario">
                Sus datos se usarán solo para revisar esta solicitud. Consulte el
                <a href="<?= Vista::escapar(Vista::url('/aviso-privacidad')) ?>">aviso de privacidad</a>.
            </p>

            <div class="acciones-acceso">
                <button type="submit" class="boton-principal">Enviar solicitud</button>
                <p class="texto-secundario"><a href="<?= Vista::escapar(Vista::url('/iniciar-sesion')) ?>">Volver al inicio de sesión</a></p>
            </div>
        </form>
    </section>
</div>
<script src="<?= Vista::escapar(Vista::url('/js/validacion-formularios.js')) ?>" defer></script>

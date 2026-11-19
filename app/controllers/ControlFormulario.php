<?php

declare(strict_types=1);

/**
 * Comportamiento común de los controladores con formularios.
 *
 *  - Lista blanca de campos: cualquier campo no previsto rechaza la
 *    solicitud completa (400). Si entre ellos hay campos internos
 *    (rol_usuario, estado_cuenta_usuario, id_usuario_revisor...), además se
 *    audita como intento de escalada (SEG-01/SEG-02).
 *  - Redirección con mensaje flash (patrón POST/Redirect/GET).
 *
 * Requiere que la clase que lo usa tenga las propiedades $gestorSesion y,
 * opcionalmente, $auditoriaModel.
 */
trait ControlFormulario
{
    /**
     * @param array<mixed> $datosFormulario Campos recibidos.
     * @param list<string> $camposPermitidos Lista blanca.
     * @param string $nombreFormulario Para el detalle de auditoría.
     * @param AuditoriaModel|null $auditoriaModel Bitácora (si se audita).
     * @return void
     * @throws ExcepcionHttp 400 si hay campos inesperados.
     */
    private function exigirCamposPermitidos(array $datosFormulario, array $camposPermitidos, string $nombreFormulario, ?AuditoriaModel $auditoriaModel = null): void
    {
        if (ValidadorEntrada::obtenerParametrosInesperados($datosFormulario, $camposPermitidos) === []) {
            return;
        }
        $camposProtegidos = ValidadorEntrada::obtenerCamposProtegidos($datosFormulario, $camposPermitidos);
        if ($camposProtegidos !== [] && $auditoriaModel !== null) {
            $auditoriaModel->registrarEventoAuditoria(
                null,
                AuditoriaModel::ACCION_INTENTO_ESCALADA_FORMULARIO,
                'formulario',
                null,
                ['formulario' => $nombreFormulario, 'camposProtegidos' => implode(',', $camposProtegidos)]
            );
        }
        throw ExcepcionHttp::solicitudInvalida();
    }

    /**
     * @param string $rutaInterna Destino.
     * @param string $tipoMensaje exito, error o aviso.
     * @param string $mensaje Texto.
     * @return Respuesta Redirección 303.
     */
    private function redirigirConMensaje(string $rutaInterna, string $tipoMensaje, string $mensaje): Respuesta
    {
        $this->gestorSesion->guardarMensajeFlash($tipoMensaje, $mensaje);
        return Respuesta::redireccion($rutaInterna);
    }
}

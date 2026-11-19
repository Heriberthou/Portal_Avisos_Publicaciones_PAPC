<?php

declare(strict_types=1);

/**
 * Error controlado que termina la solicitud con un código HTTP.
 *
 * Los middleware y controladores lanzan esta excepción en lugar de llamar
 * a exit(); el punto de entrada (public/index.php) la convierte en una
 * página de error genérica. Esto hace el flujo verificable en pruebas.
 *
 * El mensaje es SIEMPRE apto para mostrarse al usuario: nunca contiene
 * detalles internos (SQL, rutas, trazas).
 */
final class ExcepcionHttp extends RuntimeException
{
    /**
     * @param int $codigoEstado Código HTTP (400, 401, 403, 404, 405).
     * @param string $mensajeUsuario Mensaje genérico, seguro de mostrar.
     */
    public function __construct(
        private readonly int $codigoEstado,
        string $mensajeUsuario
    ) {
        parent::__construct($mensajeUsuario, $codigoEstado);
    }

    /**
     * @return int Código de estado HTTP asociado.
     */
    public function obtenerCodigoEstado(): int
    {
        return $this->codigoEstado;
    }

    /**
     * @return self Solicitud mal formada o con parámetros no permitidos.
     */
    public static function solicitudInvalida(): self
    {
        return new self(400, 'La solicitud no es válida. Vuelve a cargar el formulario e inténtalo de nuevo.');
    }

    /**
     * @return self La operación requiere una sesión iniciada.
     */
    public static function noAutenticado(): self
    {
        return new self(401, 'Debes iniciar sesión para realizar esta operación.');
    }

    /**
     * @return self La sesión existe, pero el rol no tiene el permiso.
     */
    public static function accesoDenegado(): self
    {
        return new self(403, 'No tienes permiso para realizar esta operación.');
    }

    /**
     * @return self Recurso o ruta inexistente.
     */
    public static function noEncontrado(): self
    {
        return new self(404, 'La página solicitada no existe.');
    }

    /**
     * @return self Método HTTP no admitido por la ruta.
     */
    public static function metodoNoPermitido(): self
    {
        return new self(405, 'Método de solicitud no permitido.');
    }
}

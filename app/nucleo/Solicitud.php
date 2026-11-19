<?php

declare(strict_types=1);

/**
 * Lectura centralizada y defensiva de los datos de la solicitud HTTP.
 *
 * Evita que cada clase consulte $_SERVER por su cuenta con criterios
 * distintos. No se confía en encabezados manipulables como
 * X-Forwarded-For: el origen es siempre REMOTE_ADDR.
 */
final class Solicitud
{
    /** Métodos HTTP que la aplicación admite. */
    private const METODOS_ADMITIDOS = ['GET', 'POST'];

    /**
     * Devuelve el método HTTP en mayúsculas, o una cadena vacía si no es
     * uno de los admitidos.
     *
     * @return string 'GET', 'POST' o ''.
     */
    public static function obtenerMetodo(): string
    {
        $metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
        return in_array($metodo, self::METODOS_ADMITIDOS, true) ? $metodo : '';
    }

    /**
     * Obtiene la ruta solicitada sin cadena de consulta y sin el prefijo
     * APP_RUTA_BASE.
     *
     * @return string Ruta que empieza con "/".
     */
    public static function obtenerRuta(): string
    {
        $ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $rutaBase = self::obtenerRutaBase();
        // Solo se recorta si coincide un segmento completo: "/papc" o "/papc/...",
        // nunca "/papcotro".
        if ($rutaBase !== '' && ($ruta === $rutaBase || str_starts_with($ruta, $rutaBase . '/'))) {
            $ruta = substr($ruta, strlen($rutaBase));
        }
        $ruta = '/' . trim($ruta, '/');
        return $ruta;
    }

    /**
     * Prefijo de URL bajo el que se publica la aplicación (APP_RUTA_BASE),
     * normalizado: vacío, o "/segmento" sin barra final (p. ej. "/papc").
     *
     * @return string Ruta base normalizada.
     */
    public static function obtenerRutaBase(): string
    {
        $rutaBase = trim((string) Configuracion::obtener('APP_RUTA_BASE', ''), '/');
        return $rutaBase === '' ? '' : '/' . $rutaBase;
    }

    /**
     * Dirección IP del cliente según la conexión TCP.
     *
     * Si en el futuro hay un proxy inverso de confianza, este es el único
     * lugar que debe adaptarse.
     *
     * @return string IP o "desconocido".
     */
    public static function obtenerOrigen(): string
    {
        $origen = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($origen, FILTER_VALIDATE_IP) !== false ? $origen : 'desconocido';
    }

    /**
     * Agente de usuario recortado a 200 caracteres para la auditoría.
     *
     * @return string Agente de usuario o cadena vacía.
     */
    public static function obtenerAgenteUsuario(): string
    {
        return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
    }

    /**
     * Indica si la conexión actual llegó cifrada por HTTPS.
     *
     * @return bool true si HTTPS está activo.
     */
    public static function esHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }
}

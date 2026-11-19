<?php

declare(strict_types=1);

/**
 * Protección contra falsificación de solicitudes entre sitios (CSRF).
 *
 * Patrón "synchronizer token" (OWASP CSRF Prevention Cheat Sheet): un token
 * aleatorio de 256 bits por sesión, guardado SOLO en el servidor y enviado
 * en un campo oculto de cada formulario POST. SameSite en la cookie es una
 * defensa adicional, no un sustituto.
 */
final class ProtectorCsrf
{
    /** Nombre del campo oculto en los formularios. */
    public const NOMBRE_CAMPO_TOKEN = 'token_csrf';

    /** Clave dentro de $_SESSION. */
    private const CLAVE_SESION = 'tokenCsrf';

    /** Bytes aleatorios del token (32 bytes = 256 bits). */
    private const BYTES_TOKEN = 32;

    /**
     * Devuelve el token de la sesión actual, creándolo si no existe.
     *
     * @return string Token hexadecimal de 64 caracteres.
     * @throws RuntimeException Si no hay una sesión PHP activa.
     */
    public static function generarTokenCsrf(): string
    {
        self::exigirSesionActiva();
        if (!isset($_SESSION[self::CLAVE_SESION]) || !is_string($_SESSION[self::CLAVE_SESION])) {
            $_SESSION[self::CLAVE_SESION] = bin2hex(random_bytes(self::BYTES_TOKEN));
        }
        return $_SESSION[self::CLAVE_SESION];
    }

    /**
     * Reemplaza el token por uno nuevo (tras iniciar o cerrar sesión).
     *
     * @return string Token nuevo.
     */
    public static function renovarTokenCsrf(): string
    {
        self::exigirSesionActiva();
        unset($_SESSION[self::CLAVE_SESION]);
        return self::generarTokenCsrf();
    }

    /**
     * Compara el token recibido con el de la sesión en tiempo constante.
     *
     * @param mixed $tokenRecibido Valor del campo token_csrf (puede venir
     *                             manipulado: arreglo, nulo, etc.).
     * @return bool true solo si es una cadena idéntica al token de sesión.
     */
    public static function validarTokenCsrf(mixed $tokenRecibido): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $tokenSesion = $_SESSION[self::CLAVE_SESION] ?? null;
        if (!is_string($tokenRecibido) || !is_string($tokenSesion) || $tokenSesion === '') {
            return false;
        }
        return hash_equals($tokenSesion, $tokenRecibido);
    }

    /**
     * @return void
     * @throws RuntimeException Si se usa sin sesión iniciada.
     */
    private static function exigirSesionActiva(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('El token CSRF requiere una sesión activa.');
        }
    }
}

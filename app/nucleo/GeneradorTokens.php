<?php

declare(strict_types=1);

/**
 * Tokens de un solo uso para enlaces (verificación, activación,
 * recuperación).
 *
 *  - 32 bytes de random_bytes() (256 bits): imposibles de adivinar.
 *  - Se codifican en base64url (43 caracteres) para viajar en la URL.
 *  - En la base de datos solo se guarda su SHA-256. Con 256 bits de
 *    entropía no hace falta un hash lento (Argon2): no existe diccionario
 *    posible. Si alguien lee la tabla, no obtiene enlaces utilizables.
 */
final class GeneradorTokens
{
    /** Bytes aleatorios por token. */
    private const BYTES_TOKEN = 32;

    /** base64url sin relleno de 32 bytes = 43 caracteres. */
    private const PATRON_TOKEN = '/^[A-Za-z0-9_-]{43}$/';

    /**
     * @return string Token nuevo en base64url.
     */
    public static function generarToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::BYTES_TOKEN)), '+/', '-_'), '=');
    }

    /**
     * @param string $token Token en claro.
     * @return string SHA-256 hexadecimal (64 caracteres) que se guarda en la BD.
     */
    public static function calcularHashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Comprueba la FORMA del token antes de consultar la base de datos.
     *
     * @param mixed $token Valor recibido (puede venir manipulado).
     * @return bool true si tiene el formato exacto.
     */
    public static function esFormatoValido(mixed $token): bool
    {
        return is_string($token) && preg_match(self::PATRON_TOKEN, $token) === 1;
    }
}

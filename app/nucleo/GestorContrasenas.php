<?php

declare(strict_types=1);

/**
 * Encapsula el almacenamiento seguro de contraseñas.
 *
 * Usa exclusivamente la API nativa password_* de PHP (no se implementa
 * criptografía propia). Prefiere Argon2id; si la instalación de PHP no lo
 * incluye, recurre a bcrypt con costo 12.
 *
 * Parámetros Argon2id: 64 MiB de memoria, 4 iteraciones, 1 hilo. Superan el
 * mínimo de OWASP Password Storage Cheat Sheet (19 MiB, t=2, p=1). Se usa
 * un solo hilo porque en Windows PHP compila Argon2 vía libsodium, que no
 * admite paralelismo.
 */
final class GestorContrasenas
{
    /** Opciones de Argon2id. */
    private const OPCIONES_ARGON2ID = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1];

    /** Opciones de bcrypt (respaldo). */
    private const OPCIONES_BCRYPT = ['cost' => 12];

    /** bcrypt ignora todo lo que excede 72 bytes; se rechaza en vez de truncar. */
    private const LIMITE_BYTES_BCRYPT = 72;

    /**
     * Hashes ficticios para igualar el tiempo de respuesta cuando el correo
     * no existe (mitiga enumeración por tiempo). Corresponden a valores
     * aleatorios desechados; no sirven para autenticarse.
     */
    private const HASH_FICTICIO_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$aFJ2WVlVNzR6WnBlcWIueg$8lgSkOm/SNunu+Joq8n5zEyGcZE/QtJbHIja7z6Ouz0';
    private const HASH_FICTICIO_BCRYPT = '$2y$12$1aVr0VZZgQzp3R3d91c0TexQm1qHMZTjpSyUpqMxhvC5Gby9rCYJW';

    /**
     * Indica si el PHP instalado soporta Argon2id.
     *
     * @return bool true si PASSWORD_ARGON2ID está disponible.
     */
    public static function argon2idDisponible(): bool
    {
        return defined('PASSWORD_ARGON2ID');
    }

    /**
     * Genera el hash de una contraseña en texto claro.
     *
     * @param string $contrasena Contraseña ya validada.
     * @return string Hash con algoritmo, sal y parámetros incluidos.
     */
    public static function generarHash(string $contrasena): string
    {
        if (self::argon2idDisponible()) {
            return password_hash($contrasena, PASSWORD_ARGON2ID, self::OPCIONES_ARGON2ID);
        }
        return password_hash($contrasena, PASSWORD_BCRYPT, self::OPCIONES_BCRYPT);
    }

    /**
     * Compara una contraseña con un hash en tiempo constante.
     *
     * @param string $contrasena Contraseña recibida.
     * @param string $hashAlmacenado Hash de la base de datos.
     * @return bool true si coinciden.
     */
    public static function verificar(string $contrasena, string $hashAlmacenado): bool
    {
        return password_verify($contrasena, $hashAlmacenado);
    }

    /**
     * Consume el mismo tiempo que una verificación real sin datos reales.
     * Se invoca cuando el correo no existe para no revelar ese hecho.
     *
     * @param string $contrasena Contraseña recibida.
     * @return void
     */
    public static function simularVerificacion(string $contrasena): void
    {
        $hashFicticio = self::argon2idDisponible() ? self::HASH_FICTICIO_ARGON2ID : self::HASH_FICTICIO_BCRYPT;
        password_verify($contrasena, $hashFicticio);
    }

    /**
     * Indica si un hash debe regenerarse porque cambiaron algoritmo o costos.
     *
     * @param string $hashAlmacenado Hash vigente.
     * @return bool true si conviene re-hashear tras un login exitoso.
     */
    public static function necesitaRehash(string $hashAlmacenado): bool
    {
        if (self::argon2idDisponible()) {
            return password_needs_rehash($hashAlmacenado, PASSWORD_ARGON2ID, self::OPCIONES_ARGON2ID);
        }
        return password_needs_rehash($hashAlmacenado, PASSWORD_BCRYPT, self::OPCIONES_BCRYPT);
    }

    /**
     * Límite máximo en bytes que el algoritmo activo procesa completo.
     *
     * @return int|null 72 con bcrypt; null (sin límite práctico) con Argon2id.
     */
    public static function obtenerLimiteBytes(): ?int
    {
        return self::argon2idDisponible() ? null : self::LIMITE_BYTES_BCRYPT;
    }
}

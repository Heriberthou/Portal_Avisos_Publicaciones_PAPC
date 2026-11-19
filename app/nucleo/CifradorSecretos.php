<?php

declare(strict_types=1);

/**
 * Protección de secretos del segundo factor en la base de datos.
 *
 *  - El secreto TOTP se CIFRA con AES-256-GCM (cifrado autenticado): si
 *    alguien obtiene una copia de la tabla usuario no puede generar códigos.
 *    El contexto (id de la cuenta) va como dato asociado: un secreto copiado
 *    a otra fila no se descifra.
 *  - Los códigos de recuperación se guardan como HMAC-SHA256 (no
 *    reversibles); tienen ~50 bits de entropía, así que sin la clave no se
 *    pueden recalcular por fuerza bruta.
 *
 * Las claves se DERIVAN con HKDF de CLAVE_HUELLAS (.env) con etiquetas
 * distintas, de modo que cada uso tiene su propia clave. Nunca se guardan
 * en la base de datos ni en el repositorio.
 */
final class CifradorSecretos
{
    private const CIFRADO = 'aes-256-gcm';
    private const PREFIJO_VERSION = 'v1:';
    private const BYTES_VECTOR = 12;
    private const BYTES_ETIQUETA = 16;

    /**
     * @param string $claveMaestra CLAVE_HUELLAS del .env.
     * @throws InvalidArgumentException Si la clave es demasiado corta.
     */
    public function __construct(private readonly string $claveMaestra)
    {
        if (strlen($claveMaestra) < 32) {
            throw new InvalidArgumentException('CLAVE_HUELLAS debe tener al menos 32 caracteres.');
        }
    }

    /**
     * @return bool true si la extensión OpenSSL de PHP admite AES-256-GCM.
     */
    public static function cifradoDisponible(): bool
    {
        return function_exists('openssl_encrypt') && in_array(self::CIFRADO, openssl_get_cipher_methods(), true);
    }

    /**
     * @param string $textoClaro Secreto a proteger.
     * @param string $contexto Dato asociado (p. ej. "usuario:15").
     * @return string "v1:" + base64(vector | etiqueta | cifrado).
     * @throws RuntimeException Si OpenSSL no está disponible o falla.
     */
    public function cifrar(string $textoClaro, string $contexto): string
    {
        if (!self::cifradoDisponible()) {
            throw new RuntimeException('La extensión OpenSSL de PHP no está disponible para cifrar el segundo factor.');
        }
        $vector = random_bytes(self::BYTES_VECTOR);
        $etiqueta = '';
        $cifrado = openssl_encrypt($textoClaro, self::CIFRADO, $this->derivarClave('cifrado-secreto-mfa'), OPENSSL_RAW_DATA, $vector, $etiqueta, $contexto, self::BYTES_ETIQUETA);
        if ($cifrado === false) {
            throw new RuntimeException('No fue posible cifrar el secreto.');
        }
        return self::PREFIJO_VERSION . base64_encode($vector . $etiqueta . $cifrado);
    }

    /**
     * @param string $textoCifrado Resultado de cifrar().
     * @param string $contexto El mismo dato asociado usado al cifrar.
     * @return string|null Texto claro, o null si fue alterado o no corresponde.
     */
    public function descifrar(string $textoCifrado, string $contexto): ?string
    {
        if (!self::cifradoDisponible() || !str_starts_with($textoCifrado, self::PREFIJO_VERSION)) {
            return null;
        }
        $datos = base64_decode(substr($textoCifrado, strlen(self::PREFIJO_VERSION)), true);
        if ($datos === false || strlen($datos) <= self::BYTES_VECTOR + self::BYTES_ETIQUETA) {
            return null;
        }
        $vector = substr($datos, 0, self::BYTES_VECTOR);
        $etiqueta = substr($datos, self::BYTES_VECTOR, self::BYTES_ETIQUETA);
        $cifrado = substr($datos, self::BYTES_VECTOR + self::BYTES_ETIQUETA);
        $textoClaro = openssl_decrypt($cifrado, self::CIFRADO, $this->derivarClave('cifrado-secreto-mfa'), OPENSSL_RAW_DATA, $vector, $etiqueta, $contexto);
        return $textoClaro === false ? null : $textoClaro;
    }

    /**
     * HMAC de un código de recuperación normalizado.
     *
     * @param string $codigoNormalizado Código sin guiones, en mayúsculas.
     * @return string 64 caracteres hexadecimales.
     */
    public function calcularHuellaCodigoRecuperacion(string $codigoNormalizado): string
    {
        return hash_hmac('sha256', $codigoNormalizado, $this->derivarClave('codigo-recuperacion-mfa'));
    }

    /**
     * @param string $etiqueta Uso de la clave (separación de dominios).
     * @return string 32 bytes.
     */
    private function derivarClave(string $etiqueta): string
    {
        return hash_hkdf('sha256', $this->claveMaestra, 32, 'papc:' . $etiqueta);
    }
}

<?php

declare(strict_types=1);

/**
 * Contraseñas de un solo uso basadas en tiempo (TOTP, RFC 6238), compatibles
 * con aplicaciones como Google Authenticator, Microsoft Authenticator o
 * FreeOTP. Implementación propia mínima con hash_hmac (sin dependencias):
 * HMAC-SHA1, 6 dígitos, periodo de 30 s.
 *
 * Se acepta el periodo actual y uno antes/después (tolerancia de reloj).
 * Para impedir reutilizar un código interceptado, se guarda el último paso
 * aceptado y solo se aceptan pasos posteriores.
 */
final class SegundoFactorTotp
{
    public const DIGITOS = 6;
    public const PERIODO_SEGUNDOS = 30;
    private const TOLERANCIA_PASOS = 1;
    private const BYTES_SECRETO = 20;
    private const ALFABETO_BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * @return string Secreto nuevo en Base32 (32 caracteres, 160 bits).
     */
    public static function generarSecreto(): string
    {
        return self::codificarBase32(random_bytes(self::BYTES_SECRETO));
    }

    /**
     * Calcula el código de un paso de tiempo.
     *
     * @param string $secretoBinario Secreto en bytes.
     * @param int $paso floor(tiempo / 30).
     * @param int $digitos Número de dígitos.
     * @return string Código con ceros a la izquierda.
     */
    public static function calcularCodigo(string $secretoBinario, int $paso, int $digitos = self::DIGITOS): string
    {
        $contador = pack('J', $paso);
        $hmac = hash_hmac('sha1', $contador, $secretoBinario, true);
        $desplazamiento = ord($hmac[19]) & 0x0F;
        $valor = ((ord($hmac[$desplazamiento]) & 0x7F) << 24)
            | ((ord($hmac[$desplazamiento + 1]) & 0xFF) << 16)
            | ((ord($hmac[$desplazamiento + 2]) & 0xFF) << 8)
            | (ord($hmac[$desplazamiento + 3]) & 0xFF);
        return str_pad((string) ($valor % (10 ** $digitos)), $digitos, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica un código y devuelve el paso aceptado.
     *
     * @param string $secretoBase32 Secreto de la cuenta.
     * @param string $codigo Código escrito por la persona.
     * @param int|null $ultimoPasoAceptado Último paso usado (anti-reutilización).
     * @param int|null $momento Marca de tiempo (inyectable para pruebas).
     * @return int|null Paso aceptado o null si el código no es válido.
     */
    public static function verificarCodigo(string $secretoBase32, string $codigo, ?int $ultimoPasoAceptado, ?int $momento = null): ?int
    {
        $codigo = preg_replace('/\s+/', '', $codigo) ?? '';
        if (preg_match('/^\d{' . self::DIGITOS . '}$/', $codigo) !== 1) {
            return null;
        }
        $secretoBinario = self::decodificarBase32($secretoBase32);
        if ($secretoBinario === '') {
            return null;
        }
        $pasoActual = intdiv($momento ?? time(), self::PERIODO_SEGUNDOS);
        for ($diferencia = -self::TOLERANCIA_PASOS; $diferencia <= self::TOLERANCIA_PASOS; $diferencia++) {
            $paso = $pasoActual + $diferencia;
            if ($ultimoPasoAceptado !== null && $paso <= $ultimoPasoAceptado) {
                continue;
            }
            if (hash_equals(self::calcularCodigo($secretoBinario, $paso), $codigo)) {
                return $paso;
            }
        }
        return null;
    }

    /**
     * URI estándar otpauth:// para configurar la aplicación.
     *
     * @param string $secretoBase32 Secreto.
     * @param string $nombreCuenta Correo de la cuenta.
     * @return string URI de aprovisionamiento.
     */
    public static function construirUriAprovisionamiento(string $secretoBase32, string $nombreCuenta): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode(EMISOR_SEGUNDO_FACTOR),
            rawurlencode($nombreCuenta),
            $secretoBase32,
            rawurlencode(EMISOR_SEGUNDO_FACTOR),
            self::DIGITOS,
            self::PERIODO_SEGUNDOS
        );
    }

    /**
     * Agrupa el secreto en bloques de 4 para copiarlo a mano sin errores.
     *
     * @param string $secretoBase32 Secreto.
     * @return string Secreto en grupos ("ABCD EFGH ...").
     */
    public static function formatearSecreto(string $secretoBase32): string
    {
        return trim(chunk_split($secretoBase32, 4, ' '));
    }

    /**
     * @param string $datos Bytes.
     * @return string Base32 (RFC 4648) sin relleno.
     */
    public static function codificarBase32(string $datos): string
    {
        $bits = '';
        foreach (str_split($datos) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $resultado = '';
        foreach (str_split($bits, 5) as $grupo) {
            $resultado .= self::ALFABETO_BASE32[bindec(str_pad($grupo, 5, '0'))];
        }
        return $resultado;
    }

    /**
     * @param string $textoBase32 Base32 (admite minúsculas, espacios y relleno).
     * @return string Bytes, o cadena vacía si el texto no es Base32 válido.
     */
    public static function decodificarBase32(string $textoBase32): string
    {
        $limpio = rtrim(strtoupper((string) preg_replace('/\s+/', '', $textoBase32)), '=');
        if ($limpio === '' || strspn($limpio, self::ALFABETO_BASE32) !== strlen($limpio)) {
            return '';
        }
        $bits = '';
        foreach (str_split($limpio) as $caracter) {
            $bits .= str_pad(decbin(strpos(self::ALFABETO_BASE32, $caracter)), 5, '0', STR_PAD_LEFT);
        }
        $resultado = '';
        foreach (str_split($bits, 8) as $grupo) {
            if (strlen($grupo) === 8) {
                $resultado .= chr(bindec($grupo));
            }
        }
        return $resultado;
    }
}

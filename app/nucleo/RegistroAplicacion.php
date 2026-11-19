<?php

declare(strict_types=1);

/**
 * Bitácora técnica de errores en storage/logs/app.log (no pública).
 *
 * Complementa a la tabla auditoria: aquí se registran fallos técnicos
 * (excepciones, errores de BD); en auditoria, eventos de seguridad y
 * negocio. Ninguna de las dos guarda contraseñas, tokens ni IDs de sesión.
 */
final class RegistroAplicacion
{
    /** Claves cuyo valor nunca debe escribirse en la bitácora. */
    private const CLAVES_SENSIBLES = ['contrasena', 'token', 'sesion', 'hash', 'clave', 'password', 'cookie'];

    /**
     * Escribe una línea de error con marca de tiempo.
     *
     * Seguridad: elimina saltos de línea y caracteres de control para evitar
     * inyección de registros falsos (log forging) y omite claves sensibles.
     *
     * @param string $mensaje Descripción del error.
     * @param array<string, mixed> $contexto Datos auxiliares no sensibles.
     * @return void
     */
    public static function registrarError(string $mensaje, array $contexto = []): void
    {
        $linea = sprintf(
            "[%s] ERROR %s %s\n",
            date('c'),
            self::limpiarTexto($mensaje),
            self::limpiarTexto(json_encode(self::filtrarContexto($contexto), JSON_UNESCAPED_UNICODE) ?: '{}')
        );
        $rutaBitacora = RUTA_RAIZ . '/storage/logs/app.log';
        // Si el disco falla no debe romperse la respuesta al usuario.
        @file_put_contents($rutaBitacora, $linea, FILE_APPEND | LOCK_EX);
    }

    /**
     * Reemplaza los valores de claves sensibles por "[omitido]".
     *
     * @param array<string, mixed> $contexto Datos originales.
     * @return array<string, mixed> Datos filtrados.
     */
    public static function filtrarContexto(array $contexto): array
    {
        $filtrado = [];
        foreach ($contexto as $clave => $valor) {
            $claveMinusculas = strtolower((string) $clave);
            $esSensible = false;
            foreach (self::CLAVES_SENSIBLES as $claveSensible) {
                if (str_contains($claveMinusculas, $claveSensible)) {
                    $esSensible = true;
                    break;
                }
            }
            $filtrado[$clave] = $esSensible ? '[omitido]' : (is_scalar($valor) || $valor === null ? $valor : '[estructura]');
        }
        return $filtrado;
    }

    /**
     * Sustituye caracteres de control (incluidos \r y \n) por espacios.
     *
     * @param string $texto Texto a limpiar.
     * @return string Texto en una sola línea.
     */
    private static function limpiarTexto(string $texto): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto);
    }
}

<?php

declare(strict_types=1);

/**
 * Rechaza contraseñas comunes, filtradas o fáciles de adivinar aunque
 * cumplan la longitud mínima (NIST SP 800-63B-4: "blocklist").
 *
 * Se compara la contraseña COMPLETA (normalizada), no fragmentos: una frase
 * larga que contenga la palabra "password" sigue siendo válida. Además de la
 * lista, se detectan por patrón:
 *   - secuencias (123456789012345, abcdefghijklmno, 987654321098765),
 *   - repeticiones de un bloque (passwordpassword, abcabcabcabcabc),
 *   - muy pocos caracteres distintos (aaaaaaaaaaaaaaa, 121212121212121).
 *
 * La comprobación se hace solo en el servidor; el navegador nunca recibe
 * la lista.
 */
final class ListaBloqueoContrasenas
{
    /** Archivo de la lista (fuera de public/, protegido por config/.htaccess). */
    private const RUTA_LISTA = '/config/contrasenas-comunes.txt';

    /** Mínimo de caracteres distintos que debe tener una contraseña. */
    private const MINIMO_CARACTERES_DISTINTOS = 4;

    /** Equivalencias para comparar sin acentos ("contraseña" = "contrasena"). */
    private const EQUIVALENCIAS_ACENTOS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ];

    /** @var array<string, true>|null Lista normalizada, cargada una vez por solicitud. */
    private static ?array $listaNormalizada = null;

    /**
     * Indica si la contraseña debe rechazarse por común o predecible.
     *
     * @param string $contrasena Contraseña tal como la escribió el usuario.
     * @return bool true si está en la lista o sigue un patrón trivial.
     */
    public static function esContrasenaDebil(string $contrasena): bool
    {
        $contrasenaNormalizada = self::normalizar($contrasena);

        return isset(self::obtenerLista()[$contrasenaNormalizada])
            || self::tienePocosCaracteresDistintos($contrasenaNormalizada)
            || self::esSecuencia($contrasenaNormalizada)
            || self::esBloqueRepetido($contrasenaNormalizada);
    }

    /**
     * Minúsculas, sin acentos y sin espacios ni signos. Solo se usa para
     * comparar: la contraseña real nunca se modifica.
     *
     * @param string $texto Texto original.
     * @return string Texto normalizado (solo letras y dígitos).
     */
    public static function normalizar(string $texto): string
    {
        $textoMinusculas = strtr(mb_strtolower($texto, 'UTF-8'), self::EQUIVALENCIAS_ACENTOS);
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $textoMinusculas);
    }

    /**
     * @return array<string, true> Entradas normalizadas de la lista.
     */
    private static function obtenerLista(): array
    {
        if (self::$listaNormalizada !== null) {
            return self::$listaNormalizada;
        }
        self::$listaNormalizada = [];
        $lineas = @file(RUTA_RAIZ . self::RUTA_LISTA, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lineas === false) {
            // Sin lista se siguen aplicando las reglas por patrón; se deja constancia.
            RegistroAplicacion::registrarError('No se pudo leer la lista de bloqueo de contraseñas.');
            return self::$listaNormalizada;
        }
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#')) {
                continue;
            }
            self::$listaNormalizada[self::normalizar($linea)] = true;
        }
        return self::$listaNormalizada;
    }

    /**
     * @param string $texto Texto normalizado.
     * @return bool true si usa menos de MINIMO_CARACTERES_DISTINTOS símbolos.
     */
    private static function tienePocosCaracteresDistintos(string $texto): bool
    {
        $caracteres = mb_str_split($texto, 1, 'UTF-8');
        return count(array_unique($caracteres)) < self::MINIMO_CARACTERES_DISTINTOS;
    }

    /**
     * Detecta secuencias ascendentes o descendentes de dígitos (con vuelta
     * 9→0) o de letras consecutivas del alfabeto.
     *
     * @param string $texto Texto normalizado.
     * @return bool true si todo el texto es una sola secuencia.
     */
    private static function esSecuencia(string $texto): bool
    {
        $caracteres = mb_str_split($texto, 1, 'UTF-8');
        if (count($caracteres) < 3) {
            return false;
        }
        foreach ([1, -1] as $direccion) {
            $esSecuencia = true;
            for ($indice = 1, $total = count($caracteres); $indice < $total; $indice++) {
                if (!self::sonConsecutivos($caracteres[$indice - 1], $caracteres[$indice], $direccion)) {
                    $esSecuencia = false;
                    break;
                }
            }
            if ($esSecuencia) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $anterior Carácter anterior.
     * @param string $siguiente Carácter siguiente.
     * @param int $direccion 1 ascendente, -1 descendente.
     * @return bool true si $siguiente sigue a $anterior en esa dirección.
     */
    private static function sonConsecutivos(string $anterior, string $siguiente, int $direccion): bool
    {
        if (ctype_digit($anterior) && ctype_digit($siguiente)) {
            return ((int) $siguiente - (int) $anterior + 10) % 10 === ($direccion + 10) % 10;
        }
        if (preg_match('/^[a-z]$/', $anterior) === 1 && preg_match('/^[a-z]$/', $siguiente) === 1) {
            return ord($siguiente) - ord($anterior) === $direccion;
        }
        return false;
    }

    /**
     * Detecta un bloque repetido de principio a fin ("holaholahola").
     *
     * @param string $texto Texto normalizado.
     * @return bool true si el texto es un bloque repetido 2 o más veces.
     */
    private static function esBloqueRepetido(string $texto): bool
    {
        $caracteres = mb_str_split($texto, 1, 'UTF-8');
        $total = count($caracteres);
        for ($longitudBloque = 1; $longitudBloque <= intdiv($total, 2); $longitudBloque++) {
            if ($total % $longitudBloque !== 0) {
                continue;
            }
            $bloque = implode('', array_slice($caracteres, 0, $longitudBloque));
            if (str_repeat($bloque, intdiv($total, $longitudBloque)) === $texto) {
                return true;
            }
        }
        return false;
    }
}

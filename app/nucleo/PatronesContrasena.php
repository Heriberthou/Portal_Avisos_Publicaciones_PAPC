<?php

declare(strict_types=1);

/**
 * Detección de patrones predecibles en contraseñas (sin listas).
 *
 * La usa ListaBloqueoContrasenas. Todas las funciones son puras y reciben un
 * texto ya normalizado: minúsculas, sin acentos y solo letras y dígitos
 * (ListaBloqueoContrasenas::normalizar). Ninguna modifica la contraseña real.
 *
 * Patrones (cada uno con su caso reproducido en la prueba U07b):
 *   - pocos caracteres distintos ............ aaaaaaaaaaaaaaa, 121212121212121
 *   - texto periódico ....................... abcabcabcabcabc, 123456789123456
 *   - casi todo en tramos predecibles ....... abcdefghijklmn1, 1qaz2wsx3edc4rf, aaaabbbbccccdddd
 *     (tramos de 3 o más: repetición, secuencia o teclas vecinas)
 *   - progresión de números ................. 1234567891011121314, 246810121416182, 2024202520262027
 */
final class PatronesContrasena
{
    /** Mínimo de caracteres distintos. */
    public const MINIMO_CARACTERES_DISTINTOS = 4;

    /** Un tramo predecible cuenta desde esta longitud. */
    private const LONGITUD_MINIMA_TRAMO = 3;

    /** Caracteres que pueden quedar fuera de los tramos predecibles sin dejar de ser débil. */
    private const MAXIMO_CARACTERES_LIBRES = 3;

    /**
     * Filas y columnas del teclado (QWERTY en español). Dos teclas son
     * vecinas si aparecen juntas en alguna de estas cadenas.
     */
    private const RECORRIDOS_TECLADO = [
        '1234567890', 'qwertyuiop', 'asdfghjklñ', 'zxcvbnm',
        '1qaz', '2wsx', '3edc', '4rfv', '5tgb', '6yhn', '7ujm', '8ik', '9ol', '0pñ',
    ];

    /**
     * @param string $texto Texto normalizado.
     * @return bool true si sigue alguno de los patrones predecibles.
     */
    public static function esPredecible(string $texto): bool
    {
        return self::tienePocosCaracteresDistintos($texto)
            || self::esPeriodico($texto)
            || self::estaCubiertoPorTramos($texto)
            || self::esProgresionNumerica($texto);
    }

    /**
     * @param string $texto Texto normalizado.
     * @return bool true si usa menos de MINIMO_CARACTERES_DISTINTOS símbolos.
     */
    public static function tienePocosCaracteresDistintos(string $texto): bool
    {
        return count(array_unique(mb_str_split($texto, 1, 'UTF-8'))) < self::MINIMO_CARACTERES_DISTINTOS;
    }

    /**
     * Texto que repite su propio comienzo ("abcabcab", "123456789123456").
     * Se exige que lo repetido sea al menos un tercio del texto y no menos de
     * 4 caracteres, para no rechazar frases que solo repiten una palabra
     * corta ("casa grande casa").
     *
     * @param string $texto Texto normalizado.
     * @return bool true si es periódico.
     */
    public static function esPeriodico(string $texto): bool
    {
        $caracteres = mb_str_split($texto, 1, 'UTF-8');
        $total = count($caracteres);
        $minimoRepetido = max(4, (int) ceil($total / 3));
        for ($periodo = 1; $total - $periodo >= $minimoRepetido; $periodo++) {
            $esPeriodico = true;
            for ($indice = $periodo; $indice < $total; $indice++) {
                if ($caracteres[$indice] !== $caracteres[$indice - $periodo]) {
                    $esPeriodico = false;
                    break;
                }
            }
            if ($esPeriodico) {
                return true;
            }
        }
        return false;
    }

    /**
     * Divide el texto en tramos donde cada carácter sigue al anterior de forma
     * predecible (igual, consecutivo o tecla vecina, siempre en la misma
     * dirección) y comprueba si casi todo el texto queda dentro de tramos de
     * 3 o más caracteres.
     *
     * @param string $texto Texto normalizado.
     * @return bool true si quedan como mucho MAXIMO_CARACTERES_LIBRES fuera de tramos.
     */
    public static function estaCubiertoPorTramos(string $texto): bool
    {
        $caracteres = mb_str_split($texto, 1, 'UTF-8');
        $total = count($caracteres);
        if ($total === 0) {
            return true;
        }
        $cubierto = array_fill(0, $total, false);
        $inicioTramo = 0;
        $direccionTramo = null;
        for ($indice = 1; $indice <= $total; $indice++) {
            $relacion = $indice < $total ? self::relacionEntre($caracteres[$indice - 1], $caracteres[$indice]) : null;
            if ($relacion !== null && ($relacion === 'igual' || $direccionTramo === null || $relacion === $direccionTramo)) {
                $direccionTramo ??= $relacion === 'igual' ? null : $relacion;
                continue;
            }
            if ($indice - $inicioTramo >= self::LONGITUD_MINIMA_TRAMO) {
                for ($posicion = $inicioTramo; $posicion < $indice; $posicion++) {
                    $cubierto[$posicion] = true;
                }
            }
            // Si solo cambió la dirección ("12321"), el carácter del giro inicia el tramo siguiente.
            $inicioTramo = $relacion !== null ? $indice - 1 : $indice;
            $direccionTramo = $relacion;
        }
        return count(array_filter($cubierto, static fn (bool $valor): bool => !$valor)) <= self::MAXIMO_CARACTERES_LIBRES;
    }

    /**
     * Cadena de solo dígitos que es la concatenación de una progresión
     * aritmética (1 2 3 ... 14, 2 4 6 8 10, 100 200 300, 2024 2025 2026),
     * aunque el último número quede cortado.
     *
     * @param string $texto Texto normalizado.
     * @return bool true si es una progresión de al menos 3 números.
     */
    public static function esProgresionNumerica(string $texto): bool
    {
        $total = strlen($texto);
        if ($total < 6 || preg_match('/^\d+$/', $texto) !== 1) {
            return false;
        }
        for ($longitudPrimero = 1; $longitudPrimero <= 4; $longitudPrimero++) {
            foreach ([$longitudPrimero, $longitudPrimero + 1] as $longitudSegundo) {
                if ($longitudPrimero + $longitudSegundo >= $total || $texto[0] === '0') {
                    continue;
                }
                $primero = (int) substr($texto, 0, $longitudPrimero);
                $paso = (int) substr($texto, $longitudPrimero, $longitudSegundo) - $primero;
                if ($paso === 0) {
                    continue;
                }
                $generado = '';
                $terminos = 0;
                for ($numero = $primero; strlen($generado) < $total && $numero >= 0; $numero += $paso) {
                    $generado .= (string) $numero;
                    $terminos++;
                }
                if ($terminos >= 3 && str_starts_with($generado, $texto)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param string $anterior Carácter.
     * @param string $siguiente Carácter.
     * @return string|null 'igual', 'sube', 'baja', 'teclado+' / 'teclado-', o null si no hay relación.
     */
    private static function relacionEntre(string $anterior, string $siguiente): ?string
    {
        if ($anterior === $siguiente) {
            return 'igual';
        }
        if (ctype_digit($anterior) && ctype_digit($siguiente)) {
            $diferencia = ((int) $siguiente - (int) $anterior + 10) % 10;
            if ($diferencia === 1) {
                return 'sube';
            }
            if ($diferencia === 9) {
                return 'baja';
            }
        }
        if (preg_match('/^[a-z]$/', $anterior) === 1 && preg_match('/^[a-z]$/', $siguiente) === 1) {
            $diferencia = ord($siguiente) - ord($anterior);
            if ($diferencia === 1) {
                return 'sube';
            }
            if ($diferencia === -1) {
                return 'baja';
            }
        }
        foreach (self::RECORRIDOS_TECLADO as $recorrido) {
            $posicionAnterior = mb_strpos($recorrido, $anterior);
            $posicionSiguiente = mb_strpos($recorrido, $siguiente);
            if ($posicionAnterior !== false && $posicionSiguiente !== false) {
                if ($posicionSiguiente - $posicionAnterior === 1) {
                    return 'teclado+';
                }
                if ($posicionSiguiente - $posicionAnterior === -1) {
                    return 'teclado-';
                }
            }
        }
        return null;
    }
}

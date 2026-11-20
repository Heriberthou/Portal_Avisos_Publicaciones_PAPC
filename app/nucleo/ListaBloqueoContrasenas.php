<?php

declare(strict_types=1);

/**
 * Rechaza contraseñas comunes, filtradas o fáciles de adivinar aunque
 * cumplan la longitud mínima (NIST SP 800-63B-4, 3.1.1.2: comparar contra
 * una lista de valores "comúnmente usados, esperados o comprometidos").
 *
 * Cómo se evalúa (solo para comparar; la contraseña real nunca se altera):
 *   1. Se normaliza: forma Unicode compatible (NFKC: "１２３" = "123"), sin
 *      caracteres invisibles, minúsculas, sin acentos y solo letras y dígitos.
 *   2. Contenido mínimo: sin contar espacios, signos ni repeticiones seguidas
 *      ("hola!!!!!!!!!!!", "hola           "), deben quedar al menos
 *      MINIMO_CONTENIDO letras o dígitos.
 *   3. Se revisan variantes de la misma contraseña: tal cual, sin repeticiones
 *      seguidas, con sustituciones típicas (P@ssw0rd = password) y sin los
 *      números del principio o del final (Password2024 = password). Una
 *      variante débil vuelve débil a la contraseña.
 *   4. Cada variante se compara COMPLETA con la lista (no por fragmentos:
 *      una frase que contenga "password" en medio sigue siendo válida) y con
 *      los patrones de PatronesContrasena.
 *
 * Solo en el servidor; el navegador nunca recibe la lista.
 */
final class ListaBloqueoContrasenas
{
    /** Archivo de la lista (fuera de public/, protegido por config/.htaccess). */
    private const RUTA_LISTA = '/config/contrasenas-comunes.txt';

    /**
     * Mínimo de letras o dígitos (sin repeticiones seguidas). Decisión del
     * proyecto, no un requisito de NIST: evita que el relleno con espacios o
     * signos complete la longitud de una palabra corta.
     */
    public const MINIMO_CONTENIDO = 8;

    /** Equivalencias para comparar sin acentos ("contraseña" = "contrasena"). */
    private const EQUIVALENCIAS_ACENTOS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
    ];

    /** Sustituciones habituales de letras por símbolos o dígitos. */
    private const SUSTITUCIONES = [
        '@' => 'a', '4' => 'a', '$' => 's', '5' => 's', '0' => 'o', '1' => 'i', '!' => 'i', '3' => 'e', '7' => 't', '€' => 'e',
    ];

    /** @var array<string, true>|null Lista normalizada, cargada una vez por solicitud. */
    private static ?array $listaNormalizada = null;

    /**
     * Indica si la contraseña debe rechazarse por común o predecible.
     *
     * @param string $contrasena Contraseña tal como la escribió el usuario.
     * @return bool true si está en la lista o sigue un patrón predecible.
     */
    public static function esContrasenaDebil(string $contrasena): bool
    {
        if (self::tieneContenidoInsuficiente($contrasena)) {
            return true;
        }
        foreach (self::obtenerVariantes($contrasena) as $variante) {
            if (isset(self::obtenerLista()[$variante]) || PatronesContrasena::esPredecible($variante)) {
                return true;
            }
        }
        return false;
    }

    /**
     * true si, quitando espacios, signos y repeticiones seguidas, quedan
     * menos de MINIMO_CONTENIDO letras o dígitos.
     *
     * @param string $contrasena Contraseña original.
     * @return bool true si el relleno es lo que completa la longitud.
     */
    public static function tieneContenidoInsuficiente(string $contrasena): bool
    {
        return mb_strlen(self::colapsarRepeticiones(self::normalizar($contrasena)), 'UTF-8') < self::MINIMO_CONTENIDO;
    }

    /**
     * Forma compatible, minúsculas, sin acentos y solo letras y dígitos.
     *
     * @param string $texto Texto original.
     * @return string Texto normalizado.
     */
    public static function normalizar(string $texto): string
    {
        $textoMinusculas = strtr(mb_strtolower(self::formaCompatible($texto), 'UTF-8'), self::EQUIVALENCIAS_ACENTOS);
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $textoMinusculas);
    }

    /**
     * Unicode NFKC (dígitos y letras de ancho completo, ligaduras) y sin
     * caracteres invisibles de formato (espacio de ancho cero, etc.). Si la
     * extensión intl no está disponible, se convierten al menos los
     * caracteres de ancho completo.
     *
     * @param string $texto Texto original.
     * @return string Texto en forma compatible.
     */
    public static function formaCompatible(string $texto): string
    {
        if (class_exists(Normalizer::class)) {
            $normalizado = Normalizer::normalize($texto, Normalizer::FORM_KC);
            $texto = is_string($normalizado) ? $normalizado : $texto;
        } else {
            $texto = (string) preg_replace_callback(
                '/[\x{FF01}-\x{FF5E}]/u',
                static fn (array $coincidencia): string => mb_chr(mb_ord($coincidencia[0], 'UTF-8') - 0xFEE0, 'UTF-8'),
                $texto
            );
        }
        return (string) preg_replace('/\p{Cf}+/u', '', $texto);
    }

    /**
     * Longitud que se ve: código por código, sin contar los caracteres
     * invisibles de formato (NIST cuenta cada punto de código como un
     * carácter; un espacio de ancho cero no aporta nada visible).
     *
     * @param string $contrasena Contraseña original.
     * @return int Caracteres.
     */
    public static function contarCaracteres(string $contrasena): int
    {
        return mb_strlen((string) preg_replace('/\p{Cf}+/u', '', $contrasena), 'UTF-8');
    }

    /**
     * Variantes que se comparan con la lista y los patrones.
     *
     * @param string $contrasena Contraseña original.
     * @return list<string> Variantes normalizadas, sin repetir ni vacías.
     */
    private static function obtenerVariantes(string $contrasena): array
    {
        $minusculas = mb_strtolower(self::formaCompatible($contrasena), 'UTF-8');
        // Núcleo: sin lo que no es letra al principio y al final ("2024P@ssw0rd!!" -> "p@ssw0rd").
        $nucleo = (string) preg_replace('/^[^\p{L}]+|[^\p{L}]+$/u', '', $minusculas);

        $normalizada = self::normalizar($contrasena);
        $variantes = [
            $normalizada,
            self::colapsarRepeticiones($normalizada),
            self::normalizar(strtr($minusculas, self::SUSTITUCIONES)),
            self::normalizar($nucleo),
            self::normalizar(strtr($nucleo, self::SUSTITUCIONES)),
        ];
        $variantes[] = self::colapsarRepeticiones(end($variantes));
        return array_values(array_unique(array_filter($variantes, static fn (string $variante): bool => $variante !== '')));
    }

    /**
     * "paaasssword" -> "pasword": una racha del mismo carácter cuenta como uno.
     *
     * @param string $texto Texto normalizado.
     * @return string Texto sin repeticiones seguidas.
     */
    private static function colapsarRepeticiones(string $texto): string
    {
        return (string) preg_replace('/(.)\1+/u', '$1', $texto);
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
            $entrada = self::normalizar($linea);
            self::$listaNormalizada[$entrada] = true;
            self::$listaNormalizada[self::colapsarRepeticiones($entrada)] = true;
        }
        return self::$listaNormalizada;
    }
}

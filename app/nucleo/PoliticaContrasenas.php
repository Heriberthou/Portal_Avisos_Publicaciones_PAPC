<?php

declare(strict_types=1);

/**
 * Política de contraseñas NUEVAS (activación de cuenta y restablecimiento).
 *
 * Basada en NIST SP 800-63B-4 y OWASP ASVS 5.0 (especificación 1.3, 7.2):
 *  - Al menos LONGITUD_MINIMA_CONTRASENA (15) caracteres y hasta 128.
 *  - Se admiten espacios, Unicode y pegado; NO se exigen mayúsculas,
 *    números ni símbolos, ni cambios periódicos.
 *  - La longitud cuenta cada carácter visible (punto de código Unicode);
 *    los caracteres invisibles de formato no cuentan.
 *  - Sin contar espacios, signos ni repeticiones seguidas deben quedar al
 *    menos ListaBloqueoContrasenas::MINIMO_CONTENIDO letras o dígitos (el
 *    relleno no puede completar la longitud de una palabra corta).
 *  - Se rechazan las contraseñas comunes o predecibles con
 *    ListaBloqueoContrasenas (lista, variantes y patrones). Una cadena
 *    numérica de 15 caracteres NO se rechaza por ser numérica, solo si es
 *    predecible o está en la lista.
 *  - Además se rechazan las que contienen datos de la propia persona
 *    (correo, parte local del correo, nombre, apellidos o identificador),
 *    que NIST pide considerar como "palabras del contexto".
 *  - La contraseña nunca se recorta ni se altera para validarla.
 *
 * Limitación honesta: la lista es local; no sustituye la consulta de un
 * corpus completo de contraseñas filtradas (p. ej. Have I Been Pwned), que
 * no se integra porque enviaría datos a un servicio externo.
 */
final class PoliticaContrasenas
{
    /** Mensaje único: no revela qué regla ni qué lista se aplicó. */
    public const MENSAJE_BLOQUEADA = 'Esta contraseña es muy común o fácil de adivinar. Evite series (1234, abcd, qwerty), repeticiones y palabras comunes con números. Por favor, elija otra.';

    /** Mensaje cuando el relleno (espacios, signos, repeticiones) completa la longitud. */
    public const MENSAJE_POCO_CONTENIDO = 'Use al menos %d letras o números distintos; los espacios, los signos y las repeticiones seguidas no cuentan.';

    /** Mensaje cuando contiene datos de la cuenta. */
    public const MENSAJE_DATOS_PERSONALES = 'La contraseña no debe incluir su nombre, su correo ni su matrícula. Por favor, elija otra.';

    /** Longitud mínima de un dato personal para considerarlo (evita falsos positivos con "Ana"). */
    private const LONGITUD_MINIMA_DATO_PERSONAL = 4;

    /**
     * Valida una contraseña nueva.
     *
     * @param string|null $contrasena Contraseña recibida (sin recortar).
     * @param list<string|null> $datosPersonales Correo, nombres, apellidos e identificador.
     * @return string|null Mensaje de error o null si se acepta.
     */
    public static function validarNuevaContrasena(?string $contrasena, array $datosPersonales = []): ?string
    {
        if ($contrasena === null || $contrasena === '') {
            return 'Escriba una contraseña.';
        }
        $longitud = ListaBloqueoContrasenas::contarCaracteres($contrasena);
        if ($longitud < LONGITUD_MINIMA_CONTRASENA) {
            return sprintf('La contraseña debe tener al menos %d caracteres.', LONGITUD_MINIMA_CONTRASENA);
        }
        if ($longitud > LONGITUD_MAXIMA_CONTRASENA) {
            return sprintf('La contraseña no puede tener más de %d caracteres.', LONGITUD_MAXIMA_CONTRASENA);
        }
        $limiteBytes = GestorContrasenas::obtenerLimiteBytes();
        if ($limiteBytes !== null && strlen($contrasena) > $limiteBytes) {
            return 'La contraseña es demasiado larga. Por favor, use una más corta.';
        }
        if (ListaBloqueoContrasenas::tieneContenidoInsuficiente($contrasena)) {
            return sprintf(self::MENSAJE_POCO_CONTENIDO, ListaBloqueoContrasenas::MINIMO_CONTENIDO);
        }
        if (ListaBloqueoContrasenas::esContrasenaDebil($contrasena)) {
            return self::MENSAJE_BLOQUEADA;
        }
        if (self::contieneDatosPersonales($contrasena, $datosPersonales)) {
            return self::MENSAJE_DATOS_PERSONALES;
        }
        return null;
    }

    /**
     * @param string $contrasena Contraseña.
     * @param list<string|null> $datosPersonales Datos de la cuenta.
     * @return bool true si la contraseña incluye alguno de esos datos.
     */
    public static function contieneDatosPersonales(string $contrasena, array $datosPersonales): bool
    {
        $contrasenaNormalizada = ListaBloqueoContrasenas::normalizar($contrasena);
        foreach (self::extraerPalabrasPersonales($datosPersonales) as $palabraPersonal) {
            if (str_contains($contrasenaNormalizada, $palabraPersonal)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Palabras personales normalizadas: cada dato completo, la parte local
     * del correo y cada fragmento separado por espacios o signos.
     *
     * @param list<string|null> $datosPersonales Correo, nombres, etc.
     * @return list<string> Palabras normalizadas (sin repetir).
     */
    private static function extraerPalabrasPersonales(array $datosPersonales): array
    {
        $palabras = [];
        foreach ($datosPersonales as $dato) {
            if (!is_string($dato) || $dato === '') {
                continue;
            }
            // Del correo solo cuenta la parte local: el dominio (p. ej. "umar")
            // es común a toda la comunidad y no identifica a la persona.
            if (str_contains($dato, '@')) {
                $dato = (string) strstr($dato, '@', true);
            }
            $candidatas = [$dato];
            foreach (preg_split('/[\s.\-_]+/u', $dato) ?: [] as $fragmento) {
                $candidatas[] = $fragmento;
            }
            foreach ($candidatas as $candidata) {
                $normalizada = ListaBloqueoContrasenas::normalizar($candidata);
                if (mb_strlen($normalizada) >= self::LONGITUD_MINIMA_DATO_PERSONAL) {
                    $palabras[$normalizada] = true;
                }
            }
        }
        return array_map('strval', array_keys($palabras));
    }
}

<?php

declare(strict_types=1);

/**
 * Validación de entradas en el servidor (la del navegador es solo ayuda:
 * public/js/validacion-formularios.js repite estas reglas con los mismos
 * textos, pero el servidor vuelve a comprobarlo todo).
 *
 * Principio de lista blanca: cada formulario declara los campos que acepta;
 * cualquier otro se rechaza. Así el cliente no puede "colar" rol_usuario,
 * estado_cuenta_usuario, id_usuario_revisor u otros campos internos.
 *
 * Los mensajes van en trato de usted. Los de formato pueden ser concretos;
 * los de autenticación son neutrales (especificación 1.3, sección 5.10).
 */
final class ValidadorEntrada
{
    /** Formulario "Solicitar acceso" (no lleva contraseña). */
    public const CAMPOS_SOLICITUD = [
        'token_csrf', 'nombres', 'apellido_paterno', 'apellido_materno',
        'tipo_integrante', 'identificador_institucional', 'correo_electronico',
    ];

    /** Formulario de inicio de sesión. */
    public const CAMPOS_LOGIN = ['token_csrf', 'correo_electronico', 'contrasena'];

    /** Formularios que solo piden un correo (recuperar, reenviar verificación). */
    public const CAMPOS_SOLO_CORREO = ['token_csrf', 'correo_electronico'];

    /** Formularios que crean una contraseña nueva (activar, restablecer). */
    public const CAMPOS_NUEVA_CONTRASENA = ['token_csrf', 'contrasena'];

    /** Formularios del segundo factor. */
    public const CAMPOS_SEGUNDO_FACTOR = ['token_csrf', 'codigo'];

    /**
     * Campos internos que el cliente nunca debe enviar en formularios
     * públicos. Su presencia indica manipulación y se audita.
     */
    public const CAMPOS_PROTEGIDOS = [
        'id_usuario', 'rol', 'rol_usuario', 'hash_contrasena_usuario', 'nombre_usuario',
        'estado_verificacion', 'estado_verificacion_usuario', 'estado_cuenta', 'estado_cuenta_usuario',
        'fecha_registro_usuario', 'version_sesion_usuario', 'tipo_integrante_usuario',
        'id_usuario_revisor', 'id_usuario_autorizador', 'id_usuario_vinculado', 'estado_solicitud',
        'solicitud_aprobada', 'fecha_verificacion_correo_solicitud', 'secreto_mfa_usuario', 'contrasena',
    ];

    /** Nombre: letras (con acentos), marcas, espacios, punto, apóstrofo y guion. */
    private const PATRON_NOMBRE = "/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\-]*$/u";

    /** Matrícula o número de empleado: letras, dígitos y guiones (sin espacios). */
    private const PATRON_IDENTIFICADOR = '/^[A-Z0-9][A-Z0-9\-]*$/';

    /**
     * Lista los parámetros recibidos que no pertenecen al formulario.
     *
     * @param array<mixed> $datosRecibidos Normalmente $_POST.
     * @param list<string> $camposPermitidos Lista blanca del formulario.
     * @return list<string> Nombres de campos inesperados.
     */
    public static function obtenerParametrosInesperados(array $datosRecibidos, array $camposPermitidos): array
    {
        return array_values(array_diff(array_map('strval', array_keys($datosRecibidos)), $camposPermitidos));
    }

    /**
     * Lista los campos internos protegidos presentes en la solicitud.
     *
     * @param array<mixed> $datosRecibidos Datos del formulario.
     * @param list<string> $camposPermitidos Campos legítimos del formulario
     *        (no cuentan como manipulación aunque tengan nombre sensible).
     * @return list<string> Campos protegidos encontrados.
     */
    public static function obtenerCamposProtegidos(array $datosRecibidos, array $camposPermitidos = []): array
    {
        $camposRecibidos = array_diff(array_map('strval', array_keys($datosRecibidos)), $camposPermitidos);
        return array_values(array_intersect($camposRecibidos, self::CAMPOS_PROTEGIDOS));
    }

    /**
     * Normaliza un correo: sin espacios exteriores y en minúsculas.
     *
     * @param string $correoElectronico Correo recibido.
     * @return string Correo normalizado.
     */
    public static function normalizarCorreo(string $correoElectronico): string
    {
        return mb_strtolower(trim($correoElectronico), 'UTF-8');
    }

    /**
     * Valida el formulario "Solicitar acceso".
     *
     * No pide contraseña ni rol: la solicitud no crea cuenta. El tipo de
     * integrante solo admite ALUMNO o PROFESOR y nunca determina privilegios.
     *
     * @param array<mixed> $datosRecibidos Datos del formulario.
     * @return array{valido: bool, datos: array<string, string|null>, errores: array<string, string>}
     */
    public static function validarDatosSolicitud(array $datosRecibidos): array
    {
        $errores = [];
        $nombres = self::normalizarParteNombre(self::obtenerTexto($datosRecibidos, 'nombres'));
        $apellidoPaterno = self::normalizarParteNombre(self::obtenerTexto($datosRecibidos, 'apellido_paterno'));
        $apellidoMaterno = self::normalizarParteNombre(self::obtenerTexto($datosRecibidos, 'apellido_materno'));
        $tipoIntegrante = self::obtenerTexto($datosRecibidos, 'tipo_integrante');
        $identificador = self::normalizarIdentificador(self::obtenerTexto($datosRecibidos, 'identificador_institucional'));
        $correoElectronico = self::obtenerTexto($datosRecibidos, 'correo_electronico');

        // El apellido materno es opcional, pero si se envía como arreglo o
        // con UTF-8 inválido se rechaza en lugar de ignorarse.
        $maternoEnviadoInvalido = array_key_exists('apellido_materno', $datosRecibidos)
            && self::obtenerTexto($datosRecibidos, 'apellido_materno') === null;

        $erroresNombre = array_filter([
            'nombres'          => self::validarParteNombre($nombres, 'El nombre', LONGITUD_MAXIMA_NOMBRES_USUARIO, true),
            'apellido_paterno' => self::validarParteNombre($apellidoPaterno, 'El apellido paterno', LONGITUD_MAXIMA_APELLIDO_USUARIO, true),
            'apellido_materno' => $maternoEnviadoInvalido
                ? 'El apellido materno no es válido.'
                : self::validarParteNombre($apellidoMaterno, 'El apellido materno', LONGITUD_MAXIMA_APELLIDO_USUARIO, false),
        ]);
        $errores += $erroresNombre;
        if ($erroresNombre === []) {
            $nombreCompleto = implode(' ', array_filter([$nombres, $apellidoPaterno, $apellidoMaterno]));
            if (mb_strlen($nombreCompleto) > LONGITUD_MAXIMA_NOMBRE_USUARIO) {
                $errores['nombres'] = sprintf('El nombre completo no puede tener más de %d caracteres.', LONGITUD_MAXIMA_NOMBRE_USUARIO);
            }
        }

        if ($tipoIntegrante === null || !in_array($tipoIntegrante, UsuarioModel::TIPOS_INTEGRANTE, true)) {
            $errores['tipo_integrante'] = 'Seleccione si es alumno o profesor.';
        }

        $errorIdentificador = self::validarIdentificador($identificador);
        if ($errorIdentificador !== null) {
            $errores['identificador_institucional'] = $errorIdentificador;
        }

        $correoNormalizado = $correoElectronico === null ? null : self::normalizarCorreo($correoElectronico);
        $errorCorreo = self::validarCorreo($correoNormalizado);
        if ($errorCorreo !== null) {
            $errores['correo_electronico'] = $errorCorreo;
        }

        return [
            'valido'  => $errores === [],
            'datos'   => $errores === [] ? [
                'nombres'          => (string) $nombres,
                'apellidoPaterno'  => (string) $apellidoPaterno,
                'apellidoMaterno'  => $apellidoMaterno,
                'tipoIntegrante'   => (string) $tipoIntegrante,
                'identificador'    => (string) $identificador,
                'correo'           => (string) $correoNormalizado,
            ] : [],
            'errores' => $errores,
        ];
    }

    /**
     * Valida los datos del formulario de inicio de sesión.
     *
     * Solo comprueba forma (presencia, formato y longitud); no revela si el
     * correo existe. Los errores son por campo.
     *
     * @param array<mixed> $datosRecibidos Datos del formulario.
     * @return array{valido: bool, datos: array<string, string>, errores: array<string, string>, correoPrevio: string}
     */
    public static function validarDatosLogin(array $datosRecibidos): array
    {
        $correoElectronico = self::obtenerTexto($datosRecibidos, 'correo_electronico');
        $contrasena = self::obtenerTexto($datosRecibidos, 'contrasena', false);
        $errores = [];

        if ($correoElectronico === null || $correoElectronico === '') {
            $errores['correo_electronico'] = 'Escriba su correo electrónico.';
        } elseif (mb_strlen($correoElectronico) > LONGITUD_MAXIMA_CORREO_ELECTRONICO) {
            $errores['correo_electronico'] = sprintf('El correo no puede tener más de %d caracteres.', LONGITUD_MAXIMA_CORREO_ELECTRONICO);
        } elseif (filter_var(self::normalizarCorreo($correoElectronico), FILTER_VALIDATE_EMAIL) === false) {
            $errores['correo_electronico'] = 'Escriba un correo válido, por ejemplo: nombre@ejemplo.com';
        }
        if ($contrasena === null || $contrasena === '') {
            $errores['contrasena'] = 'Escriba su contraseña.';
        } elseif (mb_strlen($contrasena) > LONGITUD_MAXIMA_CONTRASENA) {
            $errores['contrasena'] = sprintf('La contraseña no puede tener más de %d caracteres.', LONGITUD_MAXIMA_CONTRASENA);
        }

        $valido = $errores === [];
        return [
            'valido'  => $valido,
            'datos'   => $valido ? [
                'correo_electronico' => self::normalizarCorreo((string) $correoElectronico),
                'contrasena'         => (string) $contrasena,
            ] : [],
            'errores' => $errores,
            // Solo para volver a mostrarlo en el formulario (nunca la contraseña).
            'correoPrevio' => ($correoElectronico !== null && mb_strlen($correoElectronico) <= LONGITUD_MAXIMA_CORREO_ELECTRONICO)
                ? $correoElectronico : '',
        ];
    }

    /**
     * Valida formularios que solo piden el correo.
     *
     * @param array<mixed> $datosRecibidos Datos del formulario.
     * @return array{valido: bool, correo: string, error: string|null, correoPrevio: string}
     */
    public static function validarSoloCorreo(array $datosRecibidos): array
    {
        $correoElectronico = self::obtenerTexto($datosRecibidos, 'correo_electronico');
        $correoNormalizado = $correoElectronico === null ? null : self::normalizarCorreo($correoElectronico);
        $error = self::validarCorreo($correoNormalizado);
        return [
            'valido'       => $error === null,
            'correo'       => (string) $correoNormalizado,
            'error'        => $error,
            'correoPrevio' => ($correoElectronico !== null && mb_strlen($correoElectronico) <= LONGITUD_MAXIMA_CORREO_ELECTRONICO)
                ? $correoElectronico : '',
        ];
    }

    /**
     * Texto libre acotado (método de verificación, motivo de rechazo).
     *
     * @param array<mixed> $datosRecibidos Datos del formulario.
     * @param string $campo Nombre del campo.
     * @param int $longitudMinima Mínimo de caracteres.
     * @param int $longitudMaxima Máximo de caracteres.
     * @return string|null Texto normalizado o null si no cumple.
     */
    public static function obtenerTextoAcotado(array $datosRecibidos, string $campo, int $longitudMinima, int $longitudMaxima): ?string
    {
        $texto = self::obtenerTexto($datosRecibidos, $campo);
        if ($texto === null) {
            return null;
        }
        // Sin caracteres de control (evita registros falsos en bitácoras y correos).
        $texto = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto);
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        $longitud = mb_strlen($texto);
        return $longitud >= $longitudMinima && $longitud <= $longitudMaxima ? $texto : null;
    }

    /**
     * Convierte un valor en entero positivo, si es posible.
     *
     * @param mixed $valor Valor recibido (p. ej. id_usuario de un formulario).
     * @return int|null Entero > 0, o null si no es válido.
     */
    public static function obtenerEnteroPositivo(mixed $valor): ?int
    {
        if (!is_string($valor) && !is_int($valor)) {
            return null;
        }
        $entero = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $entero === false ? null : $entero;
    }

    /**
     * Extrae un campo de texto rechazando arreglos y UTF-8 inválido.
     *
     * @param array<mixed> $datos Datos del formulario.
     * @param string $campo Nombre del campo.
     * @param bool $recortar Si se eliminan espacios exteriores (no se hace
     *                       con contraseñas: los espacios son válidos).
     * @return string|null Texto o null si falta o es inválido.
     */
    public static function obtenerTexto(array $datos, string $campo, bool $recortar = true): ?string
    {
        $valor = $datos[$campo] ?? null;
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return null;
        }
        return $recortar ? trim($valor) : $valor;
    }

    /**
     * Matrícula o número de empleado: sin espacios y en mayúsculas.
     *
     * @param string|null $identificador Texto recibido.
     * @return string|null Identificador normalizado o null si quedó vacío.
     */
    public static function normalizarIdentificador(?string $identificador): ?string
    {
        if ($identificador === null) {
            return null;
        }
        $normalizado = mb_strtoupper((string) preg_replace('/\s+/u', '', $identificador), 'UTF-8');
        return $normalizado === '' ? null : $normalizado;
    }

    /**
     * @param string|null $identificador Identificador normalizado.
     * @return string|null Mensaje de error o null si es válido.
     */
    private static function validarIdentificador(?string $identificador): ?string
    {
        if ($identificador === null) {
            return 'Escriba su matrícula o número de personal.';
        }
        $longitud = mb_strlen($identificador);
        if ($longitud < LONGITUD_MINIMA_IDENTIFICADOR_INSTITUCIONAL || $longitud > LONGITUD_MAXIMA_IDENTIFICADOR_INSTITUCIONAL
            || preg_match(self::PATRON_IDENTIFICADOR, $identificador) !== 1) {
            return sprintf(
                'La matrícula o número de personal debe tener entre %d y %d letras, números o guiones.',
                LONGITUD_MINIMA_IDENTIFICADOR_INSTITUCIONAL,
                LONGITUD_MAXIMA_IDENTIFICADOR_INSTITUCIONAL
            );
        }
        return null;
    }

    /**
     * Valida una parte del nombre (nombres, apellido paterno o materno).
     *
     * @param string|null $parteNombre Valor ya normalizado (null si vacío).
     * @param string $descripcion Sujeto para el mensaje (p. ej. "El apellido paterno").
     * @param int $longitudMaxima Máximo de caracteres de la columna.
     * @param bool $esObligatoria Si puede omitirse (apellido materno).
     * @return string|null Mensaje de error o null si es válida.
     */
    private static function validarParteNombre(?string $parteNombre, string $descripcion, int $longitudMaxima, bool $esObligatoria): ?string
    {
        if ($parteNombre === null) {
            return $esObligatoria ? "{$descripcion} es obligatorio." : null;
        }
        $longitud = mb_strlen($parteNombre);
        if ($longitud < LONGITUD_MINIMA_PARTE_NOMBRE || $longitud > $longitudMaxima) {
            return sprintf('%s debe tener entre %d y %d caracteres.', $descripcion, LONGITUD_MINIMA_PARTE_NOMBRE, $longitudMaxima);
        }
        if (preg_match(self::PATRON_NOMBRE, $parteNombre) !== 1) {
            return "{$descripcion} solo puede llevar letras, espacios, puntos, apóstrofos y guiones.";
        }
        return null;
    }

    /**
     * @param string|null $correoElectronico Correo ya normalizado.
     * @return string|null Mensaje de error o null si es válido.
     */
    private static function validarCorreo(?string $correoElectronico): ?string
    {
        if ($correoElectronico === null || $correoElectronico === '') {
            return 'Escriba su correo electrónico.';
        }
        if (mb_strlen($correoElectronico) > LONGITUD_MAXIMA_CORREO_ELECTRONICO) {
            return sprintf('El correo no puede tener más de %d caracteres.', LONGITUD_MAXIMA_CORREO_ELECTRONICO);
        }
        if (filter_var($correoElectronico, FILTER_VALIDATE_EMAIL) === false) {
            return 'Escriba un correo válido, por ejemplo: nombre@ejemplo.com';
        }
        return null;
    }

    /**
     * Reduce espacios internos repetidos a uno solo y convierte el texto
     * vacío en null (así un apellido materno en blanco se guarda como NULL).
     *
     * @param string|null $texto Texto ya recortado por obtenerTexto().
     * @return string|null Texto normalizado o null si quedó vacío.
     */
    private static function normalizarParteNombre(?string $texto): ?string
    {
        if ($texto === null) {
            return null;
        }
        $textoNormalizado = (string) preg_replace('/\s+/u', ' ', $texto);
        return $textoNormalizado === '' ? null : $textoNormalizado;
    }
}

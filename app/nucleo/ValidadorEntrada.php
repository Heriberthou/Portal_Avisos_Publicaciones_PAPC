<?php

declare(strict_types=1);

/**
 * Validación de entradas en el servidor (la del navegador es solo ayuda).
 *
 * Principio de lista blanca: cada formulario declara los campos que acepta;
 * cualquier otro se rechaza. Así el cliente no puede "colar" rol_usuario,
 * estado_verificacion_usuario, fechas u otros campos internos.
 */
final class ValidadorEntrada
{
    /** Campos aceptados por el formulario de registro. */
    public const CAMPOS_REGISTRO = [
        'token_csrf', 'nombres_usuario', 'apellido_paterno_usuario', 'apellido_materno_usuario',
        'correo_electronico', 'contrasena', 'confirmacion_contrasena', 'tipo_integrante',
    ];

    /** Campos aceptados por el formulario de inicio de sesión. */
    public const CAMPOS_LOGIN = ['token_csrf', 'correo_electronico', 'contrasena'];

    /**
     * Campos internos que el cliente nunca debe enviar en formularios
     * públicos. Su presencia indica manipulación y se audita.
     */
    public const CAMPOS_PROTEGIDOS = [
        'id_usuario', 'rol', 'rol_usuario', 'hash_contrasena_usuario', 'nombre_usuario',
        'estado_verificacion', 'estado_verificacion_usuario',
        'fecha_registro_usuario', 'version_sesion_usuario', 'tipo_integrante_usuario',
    ];

    /** Nombre: letras (con acentos), marcas, espacios, punto, apóstrofo y guion. */
    private const PATRON_NOMBRE = "/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\-]*$/u";

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
     * @return list<string> Campos protegidos encontrados.
     */
    public static function obtenerCamposProtegidos(array $datosRecibidos): array
    {
        return array_values(array_intersect(array_map('strval', array_keys($datosRecibidos)), self::CAMPOS_PROTEGIDOS));
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
     * Valida los datos del registro público.
     *
     * No valida el token CSRF (lo hace el enrutador) ni decide el rol: el
     * rol jamás es un dato de entrada. El nombre llega en partes (migración
     * 003): nombres y apellido paterno obligatorios, apellido materno
     * opcional; el nombre completo lo calcula MariaDB.
     *
     * @param array<mixed> $datosRecibidos Datos del formulario.
     * @return array{valido: bool, datos: array<string, string|null>, errores: array<string, string>}
     */
    public static function validarDatosRegistro(array $datosRecibidos): array
    {
        $errores = [];
        $nombresUsuario = self::normalizarParteNombre(self::obtenerTexto($datosRecibidos, 'nombres_usuario'));
        $apellidoPaterno = self::normalizarParteNombre(self::obtenerTexto($datosRecibidos, 'apellido_paterno_usuario'));
        $apellidoMaterno = self::normalizarParteNombre(self::obtenerTexto($datosRecibidos, 'apellido_materno_usuario'));
        $correoElectronico = self::obtenerTexto($datosRecibidos, 'correo_electronico');
        $contrasena = self::obtenerTexto($datosRecibidos, 'contrasena', false);
        $confirmacionContrasena = self::obtenerTexto($datosRecibidos, 'confirmacion_contrasena', false);
        $tipoIntegrante = self::obtenerTexto($datosRecibidos, 'tipo_integrante');

        // El apellido materno es opcional, pero si se envía como arreglo o
        // con UTF-8 inválido se rechaza en lugar de ignorarse.
        $maternoEnviadoInvalido = array_key_exists('apellido_materno_usuario', $datosRecibidos)
            && self::obtenerTexto($datosRecibidos, 'apellido_materno_usuario') === null;

        $erroresNombre = array_filter([
            'nombres_usuario'          => self::validarParteNombre($nombresUsuario, 'El nombre', LONGITUD_MAXIMA_NOMBRES_USUARIO, true),
            'apellido_paterno_usuario' => self::validarParteNombre($apellidoPaterno, 'El apellido paterno', LONGITUD_MAXIMA_APELLIDO_USUARIO, true),
            'apellido_materno_usuario' => $maternoEnviadoInvalido
                ? 'El apellido materno no es válido.'
                : self::validarParteNombre($apellidoMaterno, 'El apellido materno', LONGITUD_MAXIMA_APELLIDO_USUARIO, false),
        ]);
        $errores += $erroresNombre;

        if ($erroresNombre === []) {
            $nombreCompleto = implode(' ', array_filter([$nombresUsuario, $apellidoPaterno, $apellidoMaterno]));
            if (mb_strlen($nombreCompleto) > LONGITUD_MAXIMA_NOMBRE_USUARIO) {
                $errores['nombres_usuario'] = sprintf('El nombre completo no puede exceder %d caracteres.', LONGITUD_MAXIMA_NOMBRE_USUARIO);
            }
        }

        $correoNormalizado = $correoElectronico === null ? null : self::normalizarCorreo($correoElectronico);
        $errorCorreo = self::validarCorreo($correoNormalizado);
        if ($errorCorreo !== null) {
            $errores['correo_electronico'] = $errorCorreo;
        }

        $errorContrasena = self::validarContrasena($contrasena);
        if ($errorContrasena !== null) {
            $errores['contrasena'] = $errorContrasena;
        } elseif ($confirmacionContrasena === null || !hash_equals($contrasena, $confirmacionContrasena)) {
            $errores['confirmacion_contrasena'] = 'Las contraseñas no coinciden.';
        }

        if ($tipoIntegrante === null || !in_array($tipoIntegrante, UsuarioModel::TIPOS_INTEGRANTE, true)) {
            $errores['tipo_integrante'] = 'Selecciona si te registras como alumno o profesor.';
        }

        return [
            'valido'  => $errores === [],
            'datos'   => $errores === [] ? [
                'nombres_usuario'          => (string) $nombresUsuario,
                'apellido_paterno_usuario' => (string) $apellidoPaterno,
                'apellido_materno_usuario' => $apellidoMaterno,
                'correo_electronico'       => (string) $correoNormalizado,
                'contrasena'         => (string) $contrasena,
                'tipo_integrante'    => (string) $tipoIntegrante,
            ] : [],
            'errores' => $errores,
        ];
    }

    /**
     * Valida los datos del formulario de inicio de sesión.
     *
     * Solo comprueba forma (presencia, tipo, longitud); no revela si el
     * correo existe.
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
            $errores['correo_electronico'] = 'Escribe tu correo electrónico.';
        } elseif (mb_strlen($correoElectronico) > LONGITUD_MAXIMA_CORREO_ELECTRONICO) {
            $errores['correo_electronico'] = sprintf('El correo no puede exceder %d caracteres.', LONGITUD_MAXIMA_CORREO_ELECTRONICO);
        }
        if ($contrasena === null || $contrasena === '') {
            $errores['contrasena'] = 'Escribe tu contraseña.';
        } elseif (mb_strlen($contrasena) > LONGITUD_MAXIMA_CONTRASENA) {
            $errores['contrasena'] = sprintf('La contraseña no puede exceder %d caracteres.', LONGITUD_MAXIMA_CONTRASENA);
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
            return "{$descripcion} solo puede contener letras, espacios, puntos, apóstrofos y guiones.";
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
            return 'El correo electrónico es obligatorio.';
        }
        if (mb_strlen($correoElectronico) > LONGITUD_MAXIMA_CORREO_ELECTRONICO) {
            return sprintf('El correo no puede exceder %d caracteres.', LONGITUD_MAXIMA_CORREO_ELECTRONICO);
        }
        if (filter_var($correoElectronico, FILTER_VALIDATE_EMAIL) === false) {
            return 'El correo electrónico no tiene un formato válido.';
        }
        return null;
    }

    /**
     * Política de contraseñas: longitud y lista de bloqueo (sin reglas de
     * composición arbitrarias, como recomiendan NIST 800-63B y OWASP ASVS 5.0).
     *
     * @param string|null $contrasena Contraseña recibida.
     * @return string|null Mensaje de error o null si es válida.
     */
    private static function validarContrasena(?string $contrasena): ?string
    {
        if ($contrasena === null || $contrasena === '') {
            return 'La contraseña es obligatoria.';
        }
        $longitud = mb_strlen($contrasena);
        if ($longitud < LONGITUD_MINIMA_CONTRASENA || $longitud > LONGITUD_MAXIMA_CONTRASENA) {
            return sprintf(
                'La contraseña debe tener entre %d y %d caracteres. Puedes usar una frase con espacios.',
                LONGITUD_MINIMA_CONTRASENA,
                LONGITUD_MAXIMA_CONTRASENA
            );
        }
        if (ListaBloqueoContrasenas::esContrasenaDebil($contrasena)) {
            return 'Esta contraseña es demasiado común o fácil de adivinar. Elige otra; puedes usar una frase larga.';
        }
        $limiteBytes = GestorContrasenas::obtenerLimiteBytes();
        if ($limiteBytes !== null && strlen($contrasena) > $limiteBytes) {
            return "La contraseña excede {$limiteBytes} bytes, el máximo que admite el algoritmo configurado.";
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

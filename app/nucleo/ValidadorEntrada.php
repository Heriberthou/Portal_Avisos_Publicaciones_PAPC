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
        'token_csrf', 'nombre_usuario', 'correo_electronico',
        'contrasena', 'confirmacion_contrasena', 'tipo_integrante',
    ];

    /** Campos aceptados por el formulario de inicio de sesión. */
    public const CAMPOS_LOGIN = ['token_csrf', 'correo_electronico', 'contrasena'];

    /**
     * Campos internos que el cliente nunca debe enviar en formularios
     * públicos. Su presencia indica manipulación y se audita.
     */
    public const CAMPOS_PROTEGIDOS = [
        'id_usuario', 'rol', 'rol_usuario', 'hash_contrasena_usuario',
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
     * rol jamás es un dato de entrada.
     *
     * @param array<mixed> $datosRecibidos Datos del formulario.
     * @return array{valido: bool, datos: array<string, string>, errores: array<string, string>}
     */
    public static function validarDatosRegistro(array $datosRecibidos): array
    {
        $errores = [];
        $nombreUsuario = self::obtenerTexto($datosRecibidos, 'nombre_usuario');
        $correoElectronico = self::obtenerTexto($datosRecibidos, 'correo_electronico');
        $contrasena = self::obtenerTexto($datosRecibidos, 'contrasena', false);
        $confirmacionContrasena = self::obtenerTexto($datosRecibidos, 'confirmacion_contrasena', false);
        $tipoIntegrante = self::obtenerTexto($datosRecibidos, 'tipo_integrante');

        $errorNombre = self::validarNombre($nombreUsuario);
        if ($errorNombre !== null) {
            $errores['nombre_usuario'] = $errorNombre;
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
            $errores['confirmacion_contrasena'] = 'La confirmación no coincide con la contraseña.';
        }

        if ($tipoIntegrante === null || !in_array($tipoIntegrante, UsuarioModel::TIPOS_INTEGRANTE, true)) {
            $errores['tipo_integrante'] = 'Selecciona si te registras como alumno o profesor.';
        }

        return [
            'valido'  => $errores === [],
            'datos'   => $errores === [] ? [
                'nombre_usuario'     => self::normalizarEspacios((string) $nombreUsuario),
                'correo_electronico' => (string) $correoNormalizado,
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
     * @return array{valido: bool, datos: array<string, string>}
     */
    public static function validarDatosLogin(array $datosRecibidos): array
    {
        $correoElectronico = self::obtenerTexto($datosRecibidos, 'correo_electronico');
        $contrasena = self::obtenerTexto($datosRecibidos, 'contrasena', false);

        $valido = $correoElectronico !== null
            && $contrasena !== null
            && $contrasena !== ''
            && mb_strlen($correoElectronico) <= LONGITUD_MAXIMA_CORREO_ELECTRONICO
            && mb_strlen($contrasena) <= LONGITUD_MAXIMA_CONTRASENA;

        return [
            'valido' => $valido,
            'datos'  => $valido ? [
                'correo_electronico' => self::normalizarCorreo($correoElectronico),
                'contrasena'         => $contrasena,
            ] : [],
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
     * @param string|null $nombreUsuario Nombre recibido.
     * @return string|null Mensaje de error o null si es válido.
     */
    private static function validarNombre(?string $nombreUsuario): ?string
    {
        if ($nombreUsuario === null || $nombreUsuario === '') {
            return 'El nombre es obligatorio.';
        }
        $longitud = mb_strlen($nombreUsuario);
        if ($longitud < LONGITUD_MINIMA_NOMBRE_USUARIO || $longitud > LONGITUD_MAXIMA_NOMBRE_USUARIO) {
            return sprintf('El nombre debe tener entre %d y %d caracteres.', LONGITUD_MINIMA_NOMBRE_USUARIO, LONGITUD_MAXIMA_NOMBRE_USUARIO);
        }
        if (preg_match(self::PATRON_NOMBRE, $nombreUsuario) !== 1) {
            return 'El nombre solo puede contener letras, espacios, puntos, apóstrofos y guiones.';
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
     * Política de contraseñas: solo longitud (sin reglas de composición
     * arbitrarias, como recomiendan NIST 800-63B y OWASP ASVS 5.0).
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
        $limiteBytes = GestorContrasenas::obtenerLimiteBytes();
        if ($limiteBytes !== null && strlen($contrasena) > $limiteBytes) {
            return "La contraseña excede {$limiteBytes} bytes, el máximo que admite el algoritmo configurado.";
        }
        return null;
    }

    /**
     * Reduce espacios internos repetidos a uno solo.
     *
     * @param string $texto Texto original.
     * @return string Texto normalizado.
     */
    private static function normalizarEspacios(string $texto): string
    {
        return (string) preg_replace('/\s+/u', ' ', $texto);
    }
}

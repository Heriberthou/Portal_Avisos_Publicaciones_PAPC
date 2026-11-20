<?php

declare(strict_types=1);

/**
 * Notificaciones por correo del circuito de acceso.
 *
 * Modos (CORREO_MODO del .env):
 *  - buzon_pruebas: NO envía nada. Guarda cada mensaje como archivo de texto
 *    en storage/correos/ (carpeta privada, fuera de public/ y excluida del
 *    repositorio). Sirve para demostrar el flujo sin declarar envíos reales
 *    (especificación 2.4). Se leen con herramientas/ver-buzon-pruebas.php.
 *  - mail: usa mail() de PHP. Requiere un servidor SMTP configurado en
 *    php.ini; si falla, el método devuelve false y la interfaz lo informa.
 *  - cualquier otro valor: no se envía y se devuelve false.
 *
 * Seguridad:
 *  - Los enlaces se arman con APP_URL_PUBLICA y NUNCA con el encabezado
 *    Host de la petición (evita enlaces envenenados hacia otro servidor).
 *  - Destinatario y asunto se validan contra saltos de línea (inyección de
 *    encabezados de correo).
 *  - Los mensajes no incluyen contraseñas; solo enlaces de un solo uso.
 */
final class ServicioCorreo
{
    public const MODO_BUZON_PRUEBAS = 'buzon_pruebas';
    public const MODO_MAIL = 'mail';

    /**
     * @param string $modo Modo de envío.
     * @param string|null $urlPublica APP_URL_PUBLICA (sin barra final).
     * @param string $remitente Dirección del remitente.
     * @param string $directorioBuzon Carpeta del buzón de pruebas.
     */
    public function __construct(
        private readonly string $modo,
        private readonly ?string $urlPublica,
        private readonly string $remitente,
        private readonly string $directorioBuzon
    ) {
    }

    /**
     * @return self Servicio configurado desde el .env.
     */
    public static function desdeConfiguracion(): self
    {
        // CORREO_DIRECTORIO_BUZON solo lo usan las pruebas automáticas (carpeta temporal).
        $directorioBuzon = (string) Configuracion::obtener('CORREO_DIRECTORIO_BUZON', '');
        return new self(
            (string) Configuracion::obtener('CORREO_MODO', self::MODO_BUZON_PRUEBAS),
            Configuracion::obtener('APP_URL_PUBLICA'),
            (string) Configuracion::obtener('CORREO_REMITENTE', 'no-responder@papc.invalid'),
            $directorioBuzon !== '' ? $directorioBuzon : RUTA_RAIZ . '/storage/correos'
        );
    }

    /**
     * @param string $correo Destinatario.
     * @param string $nombre Nombre del solicitante.
     * @param string $token Token de verificación en claro.
     * @return bool true si el mensaje quedó entregado al canal configurado.
     */
    public function enviarVerificacionSolicitud(string $correo, string $nombre, string $token): bool
    {
        $enlace = $this->construirEnlace('/verificar-solicitud', $token);
        if ($enlace === null) {
            return false;
        }
        return $this->enviar($correo, 'Verifique su correo para continuar su solicitud', <<<TEXTO
            Hola, {$nombre}:

            Recibimos una solicitud de acceso al Portal de Avisos y Publicaciones de la Comunidad (PAPC) con este correo.
            Para continuar, abra el siguiente enlace y confirme su correo:

            {$enlace}

            El enlace funciona una sola vez y vence en 24 horas. Verificar su correo no aprueba la solicitud:
            después la revisará una persona autorizada.

            Si usted no hizo esta solicitud, ignore este mensaje.
            TEXTO);
    }

    /**
     * @param string $correo Destinatario.
     * @param string $nombre Nombre de la persona.
     * @param string $token Token de activación en claro.
     * @return bool true si se entregó al canal configurado.
     */
    public function enviarInvitacionActivacion(string $correo, string $nombre, string $token): bool
    {
        $enlace = $this->construirEnlace('/activar-cuenta', $token);
        if ($enlace === null) {
            return false;
        }
        return $this->enviar($correo, 'Su acceso al PAPC fue autorizado: active su cuenta', <<<TEXTO
            Hola, {$nombre}:

            Su solicitud fue aprobada. Para activar su cuenta, abra el siguiente enlace y cree su propia contraseña:

            {$enlace}

            El enlace funciona una sola vez y vence en 24 horas. Nadie del PAPC le pedirá su contraseña.
            Si el enlace venció, solicite uno nuevo en "¿Olvidó su contraseña?" con este mismo correo.
            TEXTO);
    }

    /**
     * @param string $correo Destinatario.
     * @param string $token Token de recuperación en claro.
     * @return bool true si se entregó al canal configurado.
     */
    public function enviarRecuperacion(string $correo, string $token): bool
    {
        $enlace = $this->construirEnlace('/restablecer-contrasena', $token);
        if ($enlace === null) {
            return false;
        }
        return $this->enviar($correo, 'Instrucciones para restablecer su contraseña del PAPC', <<<TEXTO
            Hola:

            Recibimos una solicitud para restablecer la contraseña de su cuenta del PAPC.
            Abra el siguiente enlace para crear una contraseña nueva:

            {$enlace}

            El enlace funciona una sola vez y vence en 30 minutos. Al cambiarla se cerrarán sus sesiones abiertas.
            Si usted no lo solicitó, ignore este mensaje: su contraseña actual seguirá funcionando.
            TEXTO);
    }

    /**
     * Enlace que autoriza configurar el segundo factor (hallazgo R-02).
     *
     * @param string $correo Destinatario (correo de la cuenta).
     * @param string $nombre Nombre de la persona.
     * @param string $token Token de enrolamiento en claro.
     * @return bool true si se entregó al canal configurado.
     */
    public function enviarEnlaceEnrolamiento(string $correo, string $nombre, string $token): bool
    {
        $enlace = $this->construirEnlace('/configurar-segundo-factor', $token);
        if ($enlace === null) {
            return false;
        }
        return $this->enviar($correo, 'Configure su segundo factor del PAPC', <<<TEXTO
            Hola, {$nombre}:

            Su cuenta del PAPC tiene funciones administrativas. Para usarlas debe configurar un segundo factor
            (una aplicación de autenticación en su teléfono). Abra este enlace en el navegador donde iniciará sesión:

            {$enlace}

            El enlace funciona una sola vez y vence en 1 hora. Si usted no esperaba este mensaje, no lo abra y
            avise de inmediato al administrador principal: alguien podría conocer su contraseña.
            TEXTO);
    }

    /**
     * @return bool true si los mensajes NO se envían por correo y solo quedan
     *              en el buzón de pruebas del servidor (hallazgo R-07).
     */
    public function esModoBuzonPruebas(): bool
    {
        return $this->modo === self::MODO_BUZON_PRUEBAS;
    }

    /**
     * Respuesta a una solicitud repetida o con correo ya registrado. Se envía
     * al buzón (no se muestra en pantalla) para no revelar si existe cuenta.
     *
     * @param string $correo Destinatario.
     * @param string $situacion CUENTA_EXISTENTE, EN_REVISION o RECHAZADA_PREVIA.
     * @return bool true si se entregó al canal configurado.
     */
    public function enviarAvisoSolicitudRepetida(string $correo, string $situacion): bool
    {
        $contacto = CONTACTO_SOPORTE;
        $cuerpo = match ($situacion) {
            'CUENTA_EXISTENTE' => "Recibimos una solicitud de acceso con este correo, pero ya está asociado a una cuenta del PAPC.\n"
                . "Si no recuerda su contraseña, use \"¿Olvidó su contraseña?\" en la pantalla de inicio de sesión.",
            'EN_REVISION' => "Recibimos otra solicitud con este correo. Su solicitud anterior ya está en revisión; no necesita enviarla de nuevo.",
            default => "Recibimos una solicitud con este correo. Una solicitud anterior asociada a este correo no fue autorizada.\n"
                . "Si necesita aclararlo, comuníquese con {$contacto}.",
        };
        return $this->enviar($correo, 'Sobre su solicitud de acceso al PAPC', "Hola:\n\n{$cuerpo}\n\nSi usted no hizo esta solicitud, ignore este mensaje.");
    }

    /**
     * @param string $correo Destinatario (correo ya verificado).
     * @return bool true si se entregó al canal configurado.
     */
    public function enviarRechazoSolicitud(string $correo): bool
    {
        $contacto = CONTACTO_SOPORTE;
        return $this->enviar($correo, 'Resultado de su solicitud de acceso al PAPC', <<<TEXTO
            Hola:

            Su solicitud no fue autorizada. Si necesita aclararlo, comuníquese con {$contacto}.
            TEXTO);
    }

    /**
     * Construye un enlace absoluto con el token en la cadena de consulta.
     *
     * @param string $rutaInterna Ruta pública (p. ej. /activar-cuenta).
     * @param string $token Token en claro.
     * @return string|null Enlace, o null si APP_URL_PUBLICA falta o no es válida.
     */
    public function construirEnlace(string $rutaInterna, string $token): ?string
    {
        $urlPublica = rtrim((string) $this->urlPublica, '/');
        $esquema = strtolower((string) parse_url($urlPublica, PHP_URL_SCHEME));
        if ($urlPublica === '' || !in_array($esquema, ['http', 'https'], true)
            || filter_var($urlPublica, FILTER_VALIDATE_URL) === false
            || parse_url($urlPublica, PHP_URL_QUERY) !== null || parse_url($urlPublica, PHP_URL_FRAGMENT) !== null) {
            RegistroAplicacion::registrarError('APP_URL_PUBLICA no está configurada o no es válida; no se envió el correo.');
            return null;
        }
        return $urlPublica . $rutaInterna . '?token=' . rawurlencode($token);
    }

    /**
     * @param string $destinatario Correo validado.
     * @param string $asunto Asunto.
     * @param string $cuerpo Texto plano.
     * @return bool true si se entregó al canal configurado.
     */
    private function enviar(string $destinatario, string $asunto, string $cuerpo): bool
    {
        if (filter_var($destinatario, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $destinatario . $asunto . $this->remitente) === 1) {
            return false;
        }
        $cuerpo = (string) preg_replace('/^[ \t]+/m', '', $cuerpo);

        if ($this->modo === self::MODO_BUZON_PRUEBAS) {
            return $this->guardarEnBuzon($destinatario, $asunto, $cuerpo);
        }
        if ($this->modo === self::MODO_MAIL) {
            $encabezados = [
                'From'         => $this->remitente,
                'Content-Type' => 'text/plain; charset=UTF-8',
                'MIME-Version' => '1.0',
            ];
            $enviado = @mail($destinatario, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, $encabezados);
            if (!$enviado) {
                RegistroAplicacion::registrarError('mail() no pudo entregar el mensaje (revisa SMTP en php.ini).');
            }
            return $enviado;
        }
        RegistroAplicacion::registrarError('CORREO_MODO no reconocido; no se envió el correo.', ['modo' => $this->modo]);
        return false;
    }

    /**
     * @param string $destinatario Correo.
     * @param string $asunto Asunto.
     * @param string $cuerpo Texto.
     * @return bool true si el archivo se escribió.
     */
    private function guardarEnBuzon(string $destinatario, string $asunto, string $cuerpo): bool
    {
        if (!is_dir($this->directorioBuzon) || !is_writable($this->directorioBuzon)) {
            RegistroAplicacion::registrarError('El buzón de pruebas storage/correos no existe o no tiene permiso de escritura.');
            return false;
        }
        // Microsegundos en el nombre: los archivos se ordenan cronológicamente aunque lleguen en el mismo segundo.
        $nombreArchivo = sprintf('%s-%s.txt', (new DateTimeImmutable())->format('Ymd-His-u'), bin2hex(random_bytes(4)));
        $contenido = "Para: {$destinatario}\nAsunto: {$asunto}\nFecha: " . date('c') . "\n"
            . "Modo: BUZÓN DE PRUEBAS (este mensaje NO se envió por correo)\n\n{$cuerpo}\n";
        return @file_put_contents($this->directorioBuzon . '/' . $nombreArchivo, $contenido, LOCK_EX) !== false;
    }
}

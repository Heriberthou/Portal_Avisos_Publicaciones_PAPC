<?php

declare(strict_types=1);

/**
 * Cliente SMTP mínimo para enviar los correos del portal (sin dependencias).
 *
 * Sirve para Gmail (smtp.gmail.com, puerto 587, STARTTLS) con una
 * "contraseña de aplicación", y para cualquier proveedor SMTP equivalente.
 *
 * Seguridad:
 *  - La conexión se cifra (STARTTLS en 587 o TLS directo en 465) y se
 *    verifica el certificado del servidor. Sin cifrado solo se permite hacia
 *    la propia máquina (localhost), que es lo que usan las pruebas.
 *  - La contraseña nunca se escribe en registros ni en mensajes de error.
 *  - Los saltos de línea en remitente y destinatario se rechazan
 *    (inyección de comandos SMTP).
 */
final class ClienteSmtp
{
    public const SEGURIDAD_STARTTLS = 'tls';
    public const SEGURIDAD_SSL = 'ssl';
    public const SEGURIDAD_NINGUNA = 'ninguna';

    /** @var resource|null Conexión abierta. */
    private $conexion = null;

    /**
     * @param string $servidor Nombre del servidor SMTP (p. ej. smtp.gmail.com).
     * @param int $puerto 587 (STARTTLS) o 465 (SSL).
     * @param string $seguridad Una de las constantes SEGURIDAD_*.
     * @param string $usuario Cuenta SMTP (vacío = sin autenticación).
     * @param string $contrasena Contraseña de aplicación.
     * @param string|null $archivoCertificados Archivo de autoridades (CA) para verificar el certificado, si PHP no trae uno.
     * @param int $segundosEspera Tiempo máximo por operación.
     * @throws InvalidArgumentException Si la combinación no es segura.
     */
    public function __construct(
        private readonly string $servidor,
        private readonly int $puerto,
        private readonly string $seguridad,
        private readonly string $usuario,
        #[\SensitiveParameter] private readonly string $contrasena,
        private readonly ?string $archivoCertificados = null,
        private readonly int $segundosEspera = 15
    ) {
        if (!in_array($seguridad, [self::SEGURIDAD_STARTTLS, self::SEGURIDAD_SSL, self::SEGURIDAD_NINGUNA], true)) {
            throw new InvalidArgumentException('CORREO_SMTP_SEGURIDAD debe ser tls, ssl o ninguna.');
        }
        if ($seguridad === self::SEGURIDAD_NINGUNA && !in_array($servidor, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new InvalidArgumentException('Sin cifrado solo se permite un servidor SMTP local: la contraseña viajaría en claro.');
        }
    }

    /**
     * Entrega un mensaje ya armado (encabezados y cuerpo) a un destinatario.
     *
     * @param string $remitente Dirección del sobre (MAIL FROM).
     * @param string $destinatario Dirección del sobre (RCPT TO).
     * @param string $mensaje Mensaje completo en formato RFC 5322, con saltos \r\n.
     * @return void
     * @throws RuntimeException Si el servidor rechaza alguna etapa (el texto no incluye la contraseña).
     */
    public function enviar(string $remitente, string $destinatario, string $mensaje): void
    {
        if (preg_match('/[\r\n<>]/', $remitente . $destinatario) === 1) {
            throw new RuntimeException('Remitente o destinatario con caracteres no permitidos.');
        }
        try {
            $this->conectar();
            $this->esperarRespuesta(220, 'saludo');
            $this->saludar();
            if ($this->seguridad === self::SEGURIDAD_STARTTLS) {
                $this->comando('STARTTLS', 220, 'STARTTLS');
                $this->activarCifrado();
                $this->saludar();
            }
            if ($this->usuario !== '') {
                $this->comando('AUTH LOGIN', 334, 'AUTH');
                $this->comando(base64_encode($this->usuario), 334, 'usuario');
                $this->comando(base64_encode($this->contrasena), 235, 'contraseña');
            }
            $this->comando('MAIL FROM:<' . $remitente . '>', 250, 'MAIL FROM');
            $this->comando('RCPT TO:<' . $destinatario . '>', [250, 251], 'RCPT TO');
            $this->comando('DATA', 354, 'DATA');
            // Una línea que empieza con "." se duplica (RFC 5321, 4.5.2).
            $cuerpo = (string) preg_replace('/^\./m', '..', $mensaje);
            $this->comando($cuerpo . "\r\n.", 250, 'fin del mensaje');
            $this->escribir('QUIT');
        } finally {
            $this->cerrar();
        }
    }

    /**
     * @return void
     * @throws RuntimeException Si no se puede conectar.
     */
    private function conectar(): void
    {
        $esquema = $this->seguridad === self::SEGURIDAD_SSL ? 'ssl' : 'tcp';
        $contexto = stream_context_create(['ssl' => $this->opcionesTls()]);
        $conexion = @stream_socket_client(
            "{$esquema}://{$this->servidor}:{$this->puerto}",
            $codigoError,
            $mensajeError,
            $this->segundosEspera,
            STREAM_CLIENT_CONNECT,
            $contexto
        );
        if ($conexion === false) {
            throw new RuntimeException("No se pudo conectar con {$this->servidor}:{$this->puerto} ({$mensajeError}).");
        }
        stream_set_timeout($conexion, $this->segundosEspera);
        $this->conexion = $conexion;
    }

    /**
     * @return array<string, mixed> Opciones TLS: siempre se verifica el certificado.
     */
    private function opcionesTls(): array
    {
        $opciones = [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'peer_name'        => $this->servidor,
        ];
        if ($this->archivoCertificados !== null && $this->archivoCertificados !== '') {
            $opciones['cafile'] = $this->archivoCertificados;
        }
        return $opciones;
    }

    /**
     * @return void
     * @throws RuntimeException Si el certificado no es válido o falla el cifrado.
     */
    private function activarCifrado(): void
    {
        foreach ($this->opcionesTls() as $opcion => $valor) {
            stream_context_set_option($this->conexion, 'ssl', $opcion, $valor);
        }
        $metodos = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
        if (@stream_socket_enable_crypto($this->conexion, true, $metodos) !== true) {
            throw new RuntimeException('No se pudo cifrar la conexión (revise CORREO_SMTP_CERTIFICADOS si el error es de certificado).');
        }
    }

    /**
     * EHLO con el nombre de esta máquina.
     *
     * @return void
     */
    private function saludar(): void
    {
        $nombreEquipo = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) gethostname());
        $this->comando('EHLO ' . ($nombreEquipo !== '' ? $nombreEquipo : 'localhost'), 250, 'EHLO');
    }

    /**
     * @param string $linea Comando sin salto final.
     * @param int|list<int> $codigosEsperados Código(s) de respuesta aceptados.
     * @param string $etapa Nombre de la etapa para el mensaje de error.
     * @return void
     * @throws RuntimeException Si la respuesta no es la esperada.
     */
    private function comando(string $linea, int|array $codigosEsperados, string $etapa): void
    {
        $this->escribir($linea);
        $this->esperarRespuesta($codigosEsperados, $etapa);
    }

    /**
     * @param string $linea Texto a enviar.
     * @return void
     * @throws RuntimeException Si la conexión se cerró.
     */
    private function escribir(string $linea): void
    {
        if (@fwrite($this->conexion, $linea . "\r\n") === false) {
            throw new RuntimeException('La conexión SMTP se cerró inesperadamente.');
        }
    }

    /**
     * Lee una respuesta (posiblemente de varias líneas "250-...") y valida su código.
     *
     * @param int|list<int> $codigosEsperados Código(s) aceptados.
     * @param string $etapa Nombre de la etapa.
     * @return void
     * @throws RuntimeException Con el código y el texto del servidor.
     */
    private function esperarRespuesta(int|array $codigosEsperados, string $etapa): void
    {
        $respuesta = '';
        do {
            $linea = fgets($this->conexion, 1024);
            if ($linea === false) {
                throw new RuntimeException("El servidor SMTP no respondió en la etapa {$etapa}.");
            }
            $respuesta .= $linea;
        } while (strlen($linea) >= 4 && $linea[3] === '-');

        $codigo = (int) substr($respuesta, 0, 3);
        if (!in_array($codigo, (array) $codigosEsperados, true)) {
            $texto = trim((string) preg_replace('/\s+/', ' ', $respuesta));
            throw new RuntimeException("El servidor SMTP rechazó la etapa {$etapa}: {$texto}");
        }
    }

    /**
     * @return void
     */
    private function cerrar(): void
    {
        if (is_resource($this->conexion)) {
            @fclose($this->conexion);
        }
        $this->conexion = null;
    }
}

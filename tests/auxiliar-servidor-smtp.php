<?php

/**
 * Auxiliar de la prueba U16 (solo consola): servidor SMTP falso y local.
 *
 * Atiende UNA conexión en 127.0.0.1, acepta AUTH LOGIN solo con la
 * contraseña indicada y guarda en un archivo JSON lo que recibió (usuario,
 * contraseña, sobre y mensaje) para que la prueba lo revise. No envía nada
 * a ningún lado.
 *
 * Uso: php tests/auxiliar-servidor-smtp.php <puerto> <contraseñaEsperada> <archivoSalida>
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

[$puerto, $contrasenaEsperada, $archivoSalida] = [(int) ($argv[1] ?? 0), (string) ($argv[2] ?? ''), (string) ($argv[3] ?? '')];
$servidor = stream_socket_server("tcp://127.0.0.1:{$puerto}", $codigoError, $mensajeError);
if ($servidor === false) {
    fwrite(STDERR, "No se pudo escuchar: {$mensajeError}\n");
    exit(1);
}
echo "listo\n";

$cliente = stream_socket_accept($servidor, 20);
if ($cliente === false) {
    exit(1);
}
$recibido = ['usuario' => null, 'contrasena' => null, 'remitente' => null, 'destinatario' => null, 'mensaje' => null, 'comandos' => []];
$responder = static fn (string $linea) => fwrite($cliente, $linea . "\r\n");
$leer = static fn (): string => rtrim((string) fgets($cliente, 8192), "\r\n");

$responder('220 smtp.prueba.local ESMTP');
$etapa = '';
while (!feof($cliente)) {
    $linea = $leer();
    if ($etapa === 'usuario') {
        $recibido['usuario'] = base64_decode($linea);
        $etapa = 'contrasena';
        $responder('334 UGFzc3dvcmQ6');
        continue;
    }
    if ($etapa === 'contrasena') {
        $recibido['contrasena'] = base64_decode($linea);
        $etapa = '';
        if ($recibido['contrasena'] !== $contrasenaEsperada) {
            $responder('535 5.7.8 Username and Password not accepted');
            break;
        }
        $responder('235 2.7.0 Accepted');
        continue;
    }
    $recibido['comandos'][] = strtok($linea, ' :');
    $comando = strtoupper((string) strtok($linea, ' :'));
    if ($comando === 'EHLO') {
        $responder('250-smtp.prueba.local');
        $responder('250 AUTH LOGIN');
    } elseif ($comando === 'AUTH') {
        $etapa = 'usuario';
        $responder('334 VXNlcm5hbWU6');
    } elseif ($comando === 'MAIL') {
        $recibido['remitente'] = $linea;
        $responder('250 OK');
    } elseif ($comando === 'RCPT') {
        $recibido['destinatario'] = $linea;
        $responder('250 OK');
    } elseif ($comando === 'DATA') {
        $responder('354 Termine con <CRLF>.<CRLF>');
        $datos = '';
        while (($lineaDatos = fgets($cliente, 8192)) !== false && rtrim($lineaDatos, "\r\n") !== '.') {
            $datos .= $lineaDatos;
        }
        $recibido['mensaje'] = $datos;
        $responder('250 2.0.0 OK');
    } elseif ($comando === 'QUIT') {
        $responder('221 Adiós');
        break;
    } else {
        $responder('502 Comando no implementado');
    }
}
fclose($cliente);
file_put_contents($archivoSalida, json_encode($recibido, JSON_UNESCAPED_UNICODE));

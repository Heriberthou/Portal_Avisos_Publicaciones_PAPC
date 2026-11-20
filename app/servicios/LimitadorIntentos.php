<?php

declare(strict_types=1);

/**
 * Limitación de intentos por identificador (correo o cuenta) y por origen.
 *
 * Algoritmo (ventana deslizante con reserva, hallazgo R-06):
 *   1. Se calcula una huella HMAC-SHA256 del identificador y otra de la IP,
 *      antepuestas del ámbito (inicio de sesión, segundo factor...).
 *   2. Cada petición INSERTA su intento antes de comprobar la credencial
 *      (reserva) y luego cuenta los intentos de la ventana incluyéndose.
 *   3. Si se excede MAX del identificador o del origen, retira su reserva y
 *      se rechaza SIN comprobar nada.
 *   4. Si la comprobación falla, la reserva queda como intento fallido.
 *      Si tiene éxito, se retira SOLO esa reserva (liberarReserva). Nunca se
 *      borran los intentos de otras peticiones: algunas pueden estar todavía
 *      en curso y sus fallos no volverían a registrarse.
 *   5. Los intentos dejan de contar cuando salen de VENTANA_INTENTOS_SEGUNDOS:
 *      el bloqueo nunca es permanente, así un atacante no puede bloquear una
 *      cuenta legítima indefinidamente.
 *
 * Contar por cuenta frena ataques dirigidos a un correo desde muchas IP;
 * contar por origen frena el "password spraying" desde una IP contra
 * muchos correos. Se cuenta igual exista o no el correo.
 */
final class LimitadorIntentos
{
    /** Ámbito del inicio de sesión: sin prefijo (compatible con los registros previos). */
    public const AMBITO_LOGIN = '';

    /**
     * @param IntentoAccesoModel $intentoAccesoModel Persistencia de intentos.
     * @param string $claveHuellas Secreto HMAC (CLAVE_HUELLAS del .env).
     * @throws InvalidArgumentException Si la clave es demasiado corta.
     */
    public function __construct(
        private readonly IntentoAccesoModel $intentoAccesoModel,
        private readonly string $claveHuellas
    ) {
        if (strlen($claveHuellas) < 32) {
            throw new InvalidArgumentException('CLAVE_HUELLAS debe tener al menos 32 caracteres.');
        }
    }

    /**
     * Calcula una huella irreversible sin almacenar el dato original.
     *
     * Se usa HMAC (y no un hash simple) porque correos e IP tienen pocas
     * combinaciones posibles: sin la clave secreta no se pueden recalcular
     * por fuerza bruta a partir de la tabla.
     *
     * @param string $valor Correo normalizado o IP.
     * @return string 64 caracteres hexadecimales.
     */
    public function calcularHuella(string $valor): string
    {
        return hash_hmac('sha256', $valor, $this->claveHuellas);
    }

    /**
     * Reserva atómica de un intento (hallazgo R-06).
     *
     * Primero INSERTA el intento y después cuenta, incluyéndolo. Así, si
     * llegan varias peticiones simultáneas, cada una ve las reservas de las
     * demás que ya se registraron: nunca se comprueban más credenciales que
     * el máximo configurado (en el peor caso se rechazan de más, nunca de
     * menos). Si se excede el límite, la reserva se retira y no se comprueba
     * nada.
     *
     * @param string $ambito AMBITO_LOGIN o un nombre corto ("segundo-factor"...).
     * @param string $identificador Correo normalizado o id de la cuenta.
     * @param string $origen IP del cliente.
     * @param int $maximoIdentificador Intentos permitidos por identificador.
     * @param int $maximoOrigen Intentos permitidos por origen.
     * @return int|null Id de la reserva, o null si el límite está alcanzado.
     */
    public function reservarIntento(string $ambito, string $identificador, string $origen, int $maximoIdentificador, int $maximoOrigen): ?int
    {
        $huellaIdentificador = $this->calcularHuellaAmbito($ambito, $identificador);
        $huellaOrigen = $this->calcularHuellaAmbito($ambito, $origen);
        $this->intentoAccesoModel->purgarIntentosAntiguos(RETENCION_INTENTOS_SEGUNDOS);
        $idReserva = $this->intentoAccesoModel->reservarIntento($huellaIdentificador, $huellaOrigen);

        $dentroDelLimite = $this->intentoAccesoModel->contarIntentosPorCorreo($huellaIdentificador, VENTANA_INTENTOS_SEGUNDOS) <= $maximoIdentificador
            && $this->intentoAccesoModel->contarIntentosPorOrigen($huellaOrigen, VENTANA_INTENTOS_SEGUNDOS) <= $maximoOrigen;
        if (!$dentroDelLimite) {
            $this->intentoAccesoModel->eliminarIntento($idReserva);
            return null;
        }
        return $idReserva;
    }

    /**
     * Retira la reserva de una petición que tuvo éxito (no cuenta como fallo).
     * Solo afecta a esa reserva; los demás intentos se conservan.
     *
     * @param int $idReserva Resultado de reservarIntento().
     * @return void
     */
    public function liberarReserva(int $idReserva): void
    {
        $this->intentoAccesoModel->eliminarIntento($idReserva);
    }

    /**
     * @param string $ambito Ámbito ('' = inicio de sesión).
     * @param string $valor Identificador o IP.
     * @return string Huella HMAC.
     */
    private function calcularHuellaAmbito(string $ambito, string $valor): string
    {
        return $this->calcularHuella($ambito === self::AMBITO_LOGIN ? $valor : $ambito . ':' . $valor);
    }
}

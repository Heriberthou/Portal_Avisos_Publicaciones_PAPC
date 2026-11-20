<?php

declare(strict_types=1);

/**
 * Limitación de intentos de inicio de sesión por cuenta y por origen.
 *
 * Algoritmo (ventana deslizante):
 *   1. Se calcula una huella HMAC-SHA256 del correo y otra de la IP.
 *   2. Se cuentan los fallos de cada huella en los últimos
 *      VENTANA_INTENTOS_SEGUNDOS.
 *   3. Si la cuenta supera MAX_INTENTOS_LOGIN, o el origen supera
 *      MAX_INTENTOS_LOGIN_ORIGEN, se rechaza el intento SIN verificar la
 *      contraseña.
 *   4. El bloqueo se levanta solo cuando los fallos antiguos salen de la
 *      ventana: nunca es permanente, así un atacante no puede bloquear una
 *      cuenta legítima indefinidamente.
 *
 * Contar por cuenta frena ataques dirigidos a un correo desde muchas IP;
 * contar por origen frena el "password spraying" desde una IP contra
 * muchos correos. Se cuenta igual exista o no el correo.
 */
final class LimitadorIntentos
{
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

    /** Ámbito del inicio de sesión: sin prefijo (compatible con los registros previos). */
    public const AMBITO_LOGIN = '';

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
     * Uso: si la comprobación falla, la reserva se queda como intento
     * fallido; si tiene éxito, se libera con liberarReserva() o
     * reiniciarIntentos...().
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
     * Retira una reserva cuyo intento tuvo éxito (no debe contar como fallo).
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

    /**
     * Indica si se permite un nuevo intento.
     *
     * @param string $correoNormalizado Correo del formulario.
     * @param string $origen IP del cliente.
     * @return bool true si está permitido; false si está temporalmente limitado.
     */
    public function verificarLimiteIntentos(string $correoNormalizado, string $origen): bool
    {
        $intentosCuenta = $this->intentoAccesoModel->contarIntentosPorCorreo(
            $this->calcularHuella($correoNormalizado),
            VENTANA_INTENTOS_SEGUNDOS
        );
        if ($intentosCuenta >= MAX_INTENTOS_LOGIN) {
            return false;
        }
        $intentosOrigen = $this->intentoAccesoModel->contarIntentosPorOrigen(
            $this->calcularHuella($origen),
            VENTANA_INTENTOS_SEGUNDOS
        );
        return $intentosOrigen < MAX_INTENTOS_LOGIN_ORIGEN;
    }

    /**
     * Registra un fallo y aprovecha para purgar intentos antiguos.
     *
     * @param string $correoNormalizado Correo del formulario.
     * @param string $origen IP del cliente.
     * @return void
     */
    public function registrarIntentoFallido(string $correoNormalizado, string $origen): void
    {
        $this->intentoAccesoModel->registrarIntentoFallido(
            $this->calcularHuella($correoNormalizado),
            $this->calcularHuella($origen)
        );
        $this->intentoAccesoModel->purgarIntentosAntiguos(RETENCION_INTENTOS_SEGUNDOS);
    }

    /**
     * Límite genérico por ÁMBITO (solicitud, recuperación, segundo factor).
     *
     * El ámbito se antepone al valor antes de calcular la huella, de modo que
     * los contadores de cada formulario son independientes entre sí y del
     * inicio de sesión, aunque compartan la tabla intento_acceso.
     *
     * @param string $ambito Nombre corto del formulario (p. ej. "solicitud").
     * @param string $identificador Correo normalizado o id de la cuenta.
     * @param string $origen IP del cliente.
     * @param int $maximoIdentificador Envíos permitidos por identificador.
     * @param int $maximoOrigen Envíos permitidos por origen.
     * @return bool true si se permite.
     */
    public function verificarLimiteAmbito(string $ambito, string $identificador, string $origen, int $maximoIdentificador, int $maximoOrigen): bool
    {
        $intentosIdentificador = $this->intentoAccesoModel->contarIntentosPorCorreo(
            $this->calcularHuella($ambito . ':' . $identificador),
            VENTANA_INTENTOS_SEGUNDOS
        );
        if ($intentosIdentificador >= $maximoIdentificador) {
            return false;
        }
        $intentosOrigen = $this->intentoAccesoModel->contarIntentosPorOrigen(
            $this->calcularHuella($ambito . ':' . $origen),
            VENTANA_INTENTOS_SEGUNDOS
        );
        return $intentosOrigen < $maximoOrigen;
    }

    /**
     * Registra un envío o fallo dentro de un ámbito.
     *
     * @param string $ambito Nombre corto del formulario.
     * @param string $identificador Correo normalizado o id de la cuenta.
     * @param string $origen IP del cliente.
     * @return void
     */
    public function registrarIntentoAmbito(string $ambito, string $identificador, string $origen): void
    {
        $this->intentoAccesoModel->registrarIntentoFallido(
            $this->calcularHuella($ambito . ':' . $identificador),
            $this->calcularHuella($ambito . ':' . $origen)
        );
        $this->intentoAccesoModel->purgarIntentosAntiguos(RETENCION_INTENTOS_SEGUNDOS);
    }

    /**
     * @param string $ambito Nombre corto del formulario.
     * @param string $identificador Correo normalizado o id de la cuenta.
     * @return void
     */
    public function reiniciarIntentosAmbito(string $ambito, string $identificador): void
    {
        $this->intentoAccesoModel->eliminarIntentosPorCorreo($this->calcularHuella($ambito . ':' . $identificador));
    }

    /**
     * Reinicia el contador de la cuenta tras un login correcto.
     *
     * @param string $correoNormalizado Correo autenticado.
     * @return void
     */
    public function reiniciarIntentosCuenta(string $correoNormalizado): void
    {
        $this->intentoAccesoModel->eliminarIntentosPorCorreo($this->calcularHuella($correoNormalizado));
    }
}

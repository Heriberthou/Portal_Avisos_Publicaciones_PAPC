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

<?php

declare(strict_types=1);

/**
 * Inicio y cierre de sesión (un solo formulario para todos los roles).
 *
 * El servidor determina tipo de integrante, estado de cuenta y rol desde la
 * base de datos; la persona nunca elige su nivel de privilegios.
 *
 *  - Solo las cuentas ACTIVA pueden iniciar sesión. Una cuenta INVITADA,
 *    SUSPENDIDA o BAJA recibe exactamente la misma respuesta que una
 *    contraseña incorrecta (SEG-06: sin enumeración de cuentas ni estados).
 *  - Los roles ADMINISTRADOR y ADMINISTRADOR_PRINCIPAL NO obtienen sesión
 *    con la contraseña: quedan en "segundo factor pendiente" hasta
 *    completar el TOTP (SEG-08). Lo resuelve ServicioSegundoFactor.
 */
final class ServicioAutenticacion
{
    public const RESULTADO_EXITO = 'EXITO';
    public const RESULTADO_REQUIERE_SEGUNDO_FACTOR = 'REQUIERE_SEGUNDO_FACTOR';
    public const RESULTADO_REQUIERE_CONFIGURAR_SEGUNDO_FACTOR = 'REQUIERE_CONFIGURAR_SEGUNDO_FACTOR';
    public const RESULTADO_CREDENCIALES_INVALIDAS = 'CREDENCIALES_INVALIDAS';
    public const RESULTADO_LIMITE_ALCANZADO = 'LIMITE_ALCANZADO';

    /**
     * @param UsuarioModel $usuarioModel Acceso a cuentas.
     * @param AuditoriaModel $auditoriaModel Registro de eventos.
     * @param LimitadorIntentos $limitadorIntentos Control de fuerza bruta.
     * @param GestorSesion $gestorSesion Creación y destrucción de sesiones.
     */
    public function __construct(
        private readonly UsuarioModel $usuarioModel,
        private readonly AuditoriaModel $auditoriaModel,
        private readonly LimitadorIntentos $limitadorIntentos,
        private readonly GestorSesion $gestorSesion
    ) {
    }

    /**
     * Comprueba correo, contraseña y estado de la cuenta.
     *
     * Si el correo no existe o la cuenta aún no tiene contraseña (INVITADA),
     * se ejecuta una verificación con un hash ficticio para que el tiempo de
     * respuesta no delate esos casos.
     *
     * @param string $correoNormalizado Correo normalizado.
     * @param string $contrasena Contraseña recibida.
     * @return array<string, mixed>|null Usuario ACTIVO (sin hash) o null.
     */
    public function verificarCredenciales(string $correoNormalizado, string $contrasena): ?array
    {
        $usuario = $this->usuarioModel->obtenerUsuarioPorCorreo($correoNormalizado);
        $hashAlmacenado = $usuario['hash_contrasena_usuario'] ?? null;
        if (!is_string($hashAlmacenado) || $hashAlmacenado === '') {
            GestorContrasenas::simularVerificacion($contrasena);
            return null;
        }
        if (!GestorContrasenas::verificar($contrasena, $hashAlmacenado)) {
            return null;
        }
        if (!UsuarioModel::puedeIniciarSesion($usuario)) {
            // Contraseña correcta pero cuenta no habilitada (o sin validación
            // institucional registrada, R-03): respuesta pública idéntica.
            return null;
        }

        if (GestorContrasenas::necesitaRehash($hashAlmacenado)) {
            $this->usuarioModel->actualizarHashContrasena((int) $usuario['id_usuario'], GestorContrasenas::generarHash($contrasena), false);
            $this->auditoriaModel->registrarEventoAuditoria(
                (int) $usuario['id_usuario'],
                AuditoriaModel::ACCION_REHASH_CONTRASENA,
                'usuario',
                (int) $usuario['id_usuario']
            );
        }

        unset($usuario['hash_contrasena_usuario']);
        return $usuario;
    }

    /**
     * Flujo de inicio de sesión.
     *
     * Orden: límite de intentos -> credenciales y estado -> (segundo factor
     * para administradores) -> sesión nueva -> auditoría.
     *
     * @param string $correoNormalizado Correo normalizado.
     * @param string $contrasena Contraseña recibida.
     * @param string $origen IP del cliente.
     * @return string Una de las constantes RESULTADO_*.
     */
    public function iniciarSesion(string $correoNormalizado, string $contrasena, string $origen): string
    {
        $huellaCorreo = $this->limitadorIntentos->calcularHuella($correoNormalizado);

        // El intento se reserva ANTES de comprobar la contraseña (R-06).
        $reserva = $this->limitadorIntentos->reservarIntento(LimitadorIntentos::AMBITO_LOGIN, $correoNormalizado, $origen, MAX_INTENTOS_LOGIN, MAX_INTENTOS_LOGIN_ORIGEN);
        if ($reserva === null) {
            $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_LOGIN_BLOQUEADO, 'usuario', null, ['huellaCorreo' => $huellaCorreo]);
            return self::RESULTADO_LIMITE_ALCANZADO;
        }

        $usuario = $this->verificarCredenciales($correoNormalizado, $contrasena);
        if ($usuario === null) {
            // La reserva queda registrada como intento fallido.
            $this->auditoriaModel->registrarEventoAuditoria(null, AuditoriaModel::ACCION_LOGIN_FALLIDO, 'usuario', null, ['huellaCorreo' => $huellaCorreo]);
            return self::RESULTADO_CREDENCIALES_INVALIDAS;
        }

        $this->limitadorIntentos->reiniciarIntentosCuenta($correoNormalizado);
        $idUsuario = (int) $usuario['id_usuario'];

        if (in_array($usuario['rol_usuario'], UsuarioModel::ROLES_CON_SEGUNDO_FACTOR, true)) {
            $this->gestorSesion->iniciarSegundoFactorPendiente($usuario);
            $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_SEGUNDO_FACTOR_REQUERIDO, 'usuario', $idUsuario);
            return (int) $usuario['tiene_segundo_factor_usuario'] === 1
                ? self::RESULTADO_REQUIERE_SEGUNDO_FACTOR
                : self::RESULTADO_REQUIERE_CONFIGURAR_SEGUNDO_FACTOR;
        }

        $this->gestorSesion->crearSesion($usuario);
        $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_LOGIN_EXITOSO, 'usuario', $idUsuario);
        return self::RESULTADO_EXITO;
    }

    /**
     * Cierra la sesión: audita, destruye datos y cookie, y abre una sesión
     * anónima limpia con un token CSRF nuevo.
     *
     * @param array<string, mixed> $usuarioActual Usuario autenticado.
     * @return void
     */
    public function cerrarSesion(array $usuarioActual): void
    {
        $idUsuario = (int) $usuarioActual['id_usuario'];
        $this->auditoriaModel->registrarEventoAuditoria($idUsuario, AuditoriaModel::ACCION_CIERRE_SESION, 'usuario', $idUsuario);
        $this->gestorSesion->destruirSesion();
        GestorSesion::iniciarSesionPhp();
        session_regenerate_id(true);
        ProtectorCsrf::renovarTokenCsrf();
    }
}

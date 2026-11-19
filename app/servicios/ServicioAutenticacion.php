<?php

declare(strict_types=1);

/**
 * Reglas de negocio de registro, inicio y cierre de sesión.
 *
 * Separa la lógica de seguridad del controlador (que solo traduce HTTP a
 * llamadas de este servicio), de modo que pueda probarse sin navegador.
 */
final class ServicioAutenticacion
{
    public const RESULTADO_EXITO = 'EXITO';
    public const RESULTADO_CREDENCIALES_INVALIDAS = 'CREDENCIALES_INVALIDAS';
    public const RESULTADO_LIMITE_ALCANZADO = 'LIMITE_ALCANZADO';

    /** Código de error de MariaDB para clave duplicada (UNIQUE). */
    private const ERROR_MARIADB_DUPLICADO = 1062;

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
     * Registra una cuenta pública con rol USUARIO.
     *
     * - ALUMNO  -> estado de verificación NO_REQUERIDA (clasificación declarada).
     * - PROFESOR -> estado PENDIENTE hasta que un administrador lo resuelva.
     *   Ninguno de los dos recibe permisos adicionales.
     *
     * @param array{nombres_usuario: string, apellido_paterno_usuario: string, apellido_materno_usuario: string|null,
     *              correo_electronico: string, contrasena: string, tipo_integrante: string} $datosValidados
     *        Salida de ValidadorEntrada::validarDatosRegistro().
     * @return array{exito: bool, idUsuario: int|null}
     * @throws PDOException Ante errores de BD distintos a un duplicado.
     */
    public function registrarUsuario(array $datosValidados): array
    {
        $correoElectronico = $datosValidados['correo_electronico'];
        if ($this->usuarioModel->existeCorreo($correoElectronico)) {
            $this->auditarRegistroRechazado();
            return ['exito' => false, 'idUsuario' => null];
        }

        $tipoIntegrante = $datosValidados['tipo_integrante'];
        $estadoVerificacion = $tipoIntegrante === UsuarioModel::TIPO_PROFESOR
            ? UsuarioModel::VERIFICACION_PENDIENTE
            : UsuarioModel::VERIFICACION_NO_REQUERIDA;

        try {
            $idUsuario = $this->usuarioModel->crearUsuario(
                $datosValidados['nombres_usuario'],
                $datosValidados['apellido_paterno_usuario'],
                $datosValidados['apellido_materno_usuario'],
                $correoElectronico,
                GestorContrasenas::generarHash($datosValidados['contrasena']),
                $tipoIntegrante,
                $estadoVerificacion
            );
        } catch (PDOException $excepcion) {
            // Dos registros simultáneos con el mismo correo: lo resuelve UNIQUE.
            if ((int) ($excepcion->errorInfo[1] ?? 0) === self::ERROR_MARIADB_DUPLICADO) {
                $this->auditarRegistroRechazado();
                return ['exito' => false, 'idUsuario' => null];
            }
            throw $excepcion;
        }

        $this->auditoriaModel->registrarEventoAuditoria(
            $idUsuario,
            AuditoriaModel::ACCION_REGISTRO_USUARIO,
            'usuario',
            $idUsuario,
            ['tipoIntegrante' => $tipoIntegrante, 'estadoVerificacion' => $estadoVerificacion, 'rol' => UsuarioModel::ROL_USUARIO]
        );

        return ['exito' => true, 'idUsuario' => $idUsuario];
    }

    /**
     * Comprueba correo y contraseña.
     *
     * Si el correo no existe se ejecuta igualmente una verificación con un
     * hash ficticio para que el tiempo de respuesta no delate la existencia
     * de la cuenta.
     *
     * @param string $correoNormalizado Correo normalizado.
     * @param string $contrasena Contraseña recibida.
     * @return array<string, mixed>|null Usuario (sin hash) o null.
     */
    public function verificarCredenciales(string $correoNormalizado, string $contrasena): ?array
    {
        $usuario = $this->usuarioModel->obtenerUsuarioPorCorreo($correoNormalizado);
        if ($usuario === null) {
            GestorContrasenas::simularVerificacion($contrasena);
            return null;
        }

        $hashAlmacenado = (string) $usuario['hash_contrasena_usuario'];
        if (!GestorContrasenas::verificar($contrasena, $hashAlmacenado)) {
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
     * Flujo completo de inicio de sesión.
     *
     * Orden: límite de intentos -> credenciales -> sesión nueva -> auditoría.
     * Los resultados de "correo inexistente" y "contraseña incorrecta" son
     * idénticos para quien llama (no hay enumeración de cuentas).
     *
     * @param string $correoNormalizado Correo normalizado.
     * @param string $contrasena Contraseña recibida.
     * @param string $origen IP del cliente.
     * @return string Una de las constantes RESULTADO_*.
     */
    public function iniciarSesion(string $correoNormalizado, string $contrasena, string $origen): string
    {
        $huellaCorreo = $this->limitadorIntentos->calcularHuella($correoNormalizado);

        if (!$this->limitadorIntentos->verificarLimiteIntentos($correoNormalizado, $origen)) {
            $this->auditoriaModel->registrarEventoAuditoria(
                null,
                AuditoriaModel::ACCION_LOGIN_BLOQUEADO,
                'usuario',
                null,
                ['huellaCorreo' => $huellaCorreo]
            );
            return self::RESULTADO_LIMITE_ALCANZADO;
        }

        $usuario = $this->verificarCredenciales($correoNormalizado, $contrasena);
        if ($usuario === null) {
            $this->limitadorIntentos->registrarIntentoFallido($correoNormalizado, $origen);
            $this->auditoriaModel->registrarEventoAuditoria(
                null,
                AuditoriaModel::ACCION_LOGIN_FALLIDO,
                'usuario',
                null,
                ['huellaCorreo' => $huellaCorreo]
            );
            return self::RESULTADO_CREDENCIALES_INVALIDAS;
        }

        $this->limitadorIntentos->reiniciarIntentosCuenta($correoNormalizado);
        $this->gestorSesion->crearSesion($usuario);
        $this->auditoriaModel->registrarEventoAuditoria(
            (int) $usuario['id_usuario'],
            AuditoriaModel::ACCION_LOGIN_EXITOSO,
            'usuario',
            (int) $usuario['id_usuario']
        );
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

    /**
     * Audita un registro rechazado por correo existente sin guardar el correo.
     *
     * @return void
     */
    private function auditarRegistroRechazado(): void
    {
        $this->auditoriaModel->registrarEventoAuditoria(
            null,
            AuditoriaModel::ACCION_REGISTRO_RECHAZADO,
            'usuario',
            null,
            ['motivo' => 'CORREO_DUPLICADO']
        );
    }
}

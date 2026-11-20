<?php

declare(strict_types=1);

/**
 * Ensambla las dependencias y atiende una solicitud HTTP completa.
 *
 * Flujo: Navegador -> public/index.php -> Aplicacion -> Enrutador
 *        -> AuthMiddleware -> RoleMiddleware -> CSRF -> Controlador
 *        -> Servicio -> Modelo -> PDO -> MariaDB.
 *
 * Es también el ÚNICO lugar que convierte excepciones en respuestas: los
 * errores internos se registran en storage/logs y el usuario solo ve un
 * mensaje genérico.
 */
final class Aplicacion
{
    /**
     * Crea todos los objetos del módulo compartiendo una sola conexión.
     *
     * @param PDO $conexion Conexión de obtenerConexionBaseDatos().
     * @param ServicioCorreo|null $servicioCorreo Inyectable en pruebas; por
     *        omisión se configura desde el .env.
     * @return array<string, object> Modelos, servicios, middleware y controladores.
     */
    public static function construirDependencias(PDO $conexion, ?ServicioCorreo $servicioCorreo = null): array
    {
        $claveHuellas = Configuracion::obtenerObligatorio('CLAVE_HUELLAS');
        $servicioCorreo ??= ServicioCorreo::desdeConfiguracion();

        $usuarioModel = new UsuarioModel($conexion);
        $auditoriaModel = new AuditoriaModel($conexion);
        $solicitudAccesoModel = new SolicitudAccesoModel($conexion);
        $tokenSolicitudModel = new TokenSolicitudModel($conexion);
        $tokenCuentaModel = new TokenCuentaModel($conexion);
        $codigoRecuperacionModel = new CodigoRecuperacionMfaModel($conexion);

        $gestorSesion = new GestorSesion($usuarioModel);
        $roleMiddleware = new RoleMiddleware(require RUTA_RAIZ . '/config/permisos.php', $auditoriaModel);
        $limitadorIntentos = new LimitadorIntentos(new IntentoAccesoModel($conexion), $claveHuellas);
        $cifradorSecretos = new CifradorSecretos($claveHuellas);

        $servicioAutenticacion = new ServicioAutenticacion($usuarioModel, $auditoriaModel, $limitadorIntentos, $gestorSesion);
        $servicioSegundoFactor = new ServicioSegundoFactor($conexion, $usuarioModel, $codigoRecuperacionModel, $tokenCuentaModel, $auditoriaModel, $limitadorIntentos, $gestorSesion, $cifradorSecretos, $servicioCorreo);
        $servicioSolicitudAcceso = new ServicioSolicitudAcceso($conexion, $solicitudAccesoModel, $tokenSolicitudModel, $usuarioModel, $tokenCuentaModel, $auditoriaModel, $limitadorIntentos, $servicioCorreo, $roleMiddleware);
        $servicioCredenciales = new ServicioCredenciales($conexion, $usuarioModel, $tokenCuentaModel, $auditoriaModel, $limitadorIntentos, $servicioCorreo);
        $servicioAdministracion = new ServicioAdministracionUsuarios($conexion, $usuarioModel, $auditoriaModel, $roleMiddleware, $tokenCuentaModel, $codigoRecuperacionModel, $limitadorIntentos, $servicioSegundoFactor, $servicioCorreo);

        return [
            'usuarioModel'            => $usuarioModel,
            'auditoriaModel'          => $auditoriaModel,
            'solicitudAccesoModel'    => $solicitudAccesoModel,
            'tokenCuentaModel'        => $tokenCuentaModel,
            'gestorSesion'            => $gestorSesion,
            'authMiddleware'          => new AuthMiddleware($gestorSesion, $auditoriaModel),
            'roleMiddleware'          => $roleMiddleware,
            'servicioCorreo'          => $servicioCorreo,
            'servicioAutenticacion'   => $servicioAutenticacion,
            'servicioSegundoFactor'   => $servicioSegundoFactor,
            'servicioSolicitudAcceso' => $servicioSolicitudAcceso,
            'servicioCredenciales'    => $servicioCredenciales,
            'servicioAdministracion'  => $servicioAdministracion,
            'portalController'        => new PortalController($gestorSesion),
            'authController'          => new AuthController($servicioAutenticacion, $gestorSesion, $auditoriaModel, $roleMiddleware),
            'segundoFactorController' => new SegundoFactorController($servicioSegundoFactor, $gestorSesion),
            'solicitudAccesoController' => new SolicitudAccesoController($servicioSolicitudAcceso, $gestorSesion, $auditoriaModel, $servicioCorreo),
            'activacionController'    => new ActivacionController($servicioCredenciales, $gestorSesion),
            'recuperacionController'  => new RecuperacionController($servicioCredenciales, $gestorSesion),
            'administracionSolicitudesController' => new AdministracionSolicitudesController($servicioSolicitudAcceso, $solicitudAccesoModel, $usuarioModel, $gestorSesion),
            'adminUsuarioController'  => new AdminUsuarioController($servicioAdministracion, $usuarioModel, $auditoriaModel, $gestorSesion, $roleMiddleware),
        ];
    }

    /**
     * Atiende la solicitud actual y envía la respuesta.
     *
     * @return void
     */
    public static function ejecutar(): void
    {
        $gestorSesion = null;
        try {
            $dependencias = self::construirDependencias(obtenerConexionBaseDatos());
            $gestorSesion = $dependencias['gestorSesion'];
            GestorSesion::iniciarSesionPhp();

            $enrutador = new Enrutador(
                $dependencias['authMiddleware'],
                $dependencias['roleMiddleware'],
                $dependencias['auditoriaModel']
            );
            $registrarRutas = require RUTA_RAIZ . '/config/rutas.php';
            $registrarRutas($enrutador, $dependencias);

            $metodo = Solicitud::obtenerMetodo();
            $datosFormulario = $metodo === 'POST' ? $_POST : $_GET;
            $respuesta = $enrutador->despachar($metodo, Solicitud::obtenerRuta(), $datosFormulario);
        } catch (ExcepcionHttp $excepcion) {
            $respuesta = self::responderErrorControlado($excepcion, $gestorSesion);
        } catch (Throwable $excepcion) {
            RegistroAplicacion::registrarError('Excepción no controlada', [
                'tipo'    => get_class($excepcion),
                'mensaje' => $excepcion->getMessage(),
                'archivo' => $excepcion->getFile() . ':' . $excepcion->getLine(),
                'ruta'    => Solicitud::obtenerRuta(),
            ]);
            $respuesta = Respuesta::vista('error', [
                'tituloPagina'  => 'Error',
                'codigoEstado'  => 500,
                'mensajeError'  => 'Ocurrió un error interno. Inténtalo más tarde.',
            ], 500);
        }

        $respuesta->enviar();
    }

    /**
     * Convierte un error HTTP previsto en una respuesta.
     *
     * Un 401 en una solicitud GET se convierte en redirección al login
     * (comportamiento esperado en navegación); en POST se responde 401.
     *
     * @param ExcepcionHttp $excepcion Error controlado.
     * @param GestorSesion|null $gestorSesion Para guardar el aviso flash.
     * @return Respuesta Respuesta de error.
     */
    private static function responderErrorControlado(ExcepcionHttp $excepcion, ?GestorSesion $gestorSesion): Respuesta
    {
        $codigoEstado = $excepcion->obtenerCodigoEstado();
        if ($codigoEstado === 401 && Solicitud::obtenerMetodo() === 'GET' && $gestorSesion !== null) {
            $gestorSesion->guardarMensajeFlash('aviso', $excepcion->getMessage());
            return Respuesta::redireccion('/iniciar-sesion');
        }
        if ($codigoEstado === 405) {
            header('Allow: GET, POST');
        }
        return Respuesta::vista('error', [
            'tituloPagina' => 'Error ' . $codigoEstado,
            'codigoEstado' => $codigoEstado,
            'mensajeError' => $excepcion->getMessage(),
        ], $codigoEstado);
    }
}

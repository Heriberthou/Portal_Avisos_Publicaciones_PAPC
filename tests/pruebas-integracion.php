<?php

/**
 * Pruebas de integración: servicios, middleware, enrutador y modelos contra
 * una base MariaDB de pruebas, conectando con la cuenta de MÍNIMOS
 * privilegios (igual que la aplicación real).
 */

declare(strict_types=1);

return static function (MarcoPruebas $marco, BaseDatosPruebas $baseDatos): void {
    $dependencias = Aplicacion::construirDependencias(obtenerConexionBaseDatos());
    /** @var ServicioAutenticacion $servicioAutenticacion */
    $servicioAutenticacion = $dependencias['servicioAutenticacion'];
    /** @var ServicioAdministracionUsuarios $servicioAdministracion */
    $servicioAdministracion = $dependencias['servicioAdministracion'];
    /** @var GestorSesion $gestorSesion */
    $gestorSesion = $dependencias['gestorSesion'];
    /** @var RoleMiddleware $roleMiddleware */
    $roleMiddleware = $dependencias['roleMiddleware'];
    /** @var UsuarioModel $usuarioModel */
    $usuarioModel = $dependencias['usuarioModel'];

    $contrasenaComun = 'frase de paso de pruebas numero uno';

    $reiniciarSesion = static function (): void {
        GestorSesion::iniciarSesionPhp();
        $_SESSION = [];
    };

    $registrar = static function (string $nombre, string $correo, string $tipo) use ($servicioAutenticacion, $contrasenaComun): array {
        return $servicioAutenticacion->registrarUsuario([
            'nombre_usuario'     => $nombre,
            'correo_electronico' => $correo,
            'contrasena'         => $contrasenaComun,
            'tipo_integrante'    => $tipo,
        ]);
    };

    $iniciarComo = static function (string $correo) use ($servicioAutenticacion, $reiniciarSesion, $contrasenaComun): string {
        $reiniciarSesion();
        return $servicioAutenticacion->iniciarSesion($correo, $contrasenaComun, '192.0.2.250');
    };

    $construirEnrutador = static function () use ($dependencias): Enrutador {
        $enrutador = new Enrutador($dependencias['authMiddleware'], $dependencias['roleMiddleware'], $dependencias['auditoriaModel']);
        (require RUTA_RAIZ . '/config/rutas.php')($enrutador, $dependencias);
        return $enrutador;
    };

    $marco->probar('P01', 'Registro correcto de un alumno', static function () use ($registrar, $baseDatos): void {
        $resultado = $registrar('Ana Alumna', 'ana.alumna@ejemplo.mx', 'ALUMNO');
        MarcoPruebas::afirmar($resultado['exito'], 'El registro debió completarse');
        $fila = $baseDatos->obtenerFilaUsuario('ana.alumna@ejemplo.mx');
        MarcoPruebas::afirmarIgual('USUARIO', $fila['rol_usuario'], 'Rol asignado por el servidor');
        MarcoPruebas::afirmarIgual('ALUMNO', $fila['tipo_integrante_usuario'], 'Tipo de integrante');
        MarcoPruebas::afirmarIgual('NO_REQUERIDA', $fila['estado_verificacion_usuario'], 'Estado de verificación');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contarEventos('REGISTRO_USUARIO'), 'Evento de auditoría');
    });

    $marco->probar('P02', 'Registro de profesor: PENDIENTE y sin permisos adicionales', static function () use ($registrar, $baseDatos, $roleMiddleware): void {
        MarcoPruebas::afirmar($registrar('Pedro Profesor', 'pedro.profesor@ejemplo.mx', 'PROFESOR')['exito'], 'Registro');
        $fila = $baseDatos->obtenerFilaUsuario('pedro.profesor@ejemplo.mx');
        MarcoPruebas::afirmarIgual('USUARIO', $fila['rol_usuario'], 'Un profesor recibe rol USUARIO');
        MarcoPruebas::afirmarIgual('PENDIENTE', $fila['estado_verificacion_usuario'], 'Verificación pendiente');
        foreach (['administrar_cuentas', 'verificar_clasificacion', 'gestionar_aviso_oficial', 'asignar_rol_administrativo'] as $permiso) {
            MarcoPruebas::afirmar(!$roleMiddleware->tienePermiso($fila['rol_usuario'], $permiso), "Profesor sin {$permiso}");
        }
    });

    $marco->probar('P03', 'Rechazo de correos duplicados (incluso con mayúsculas)', static function () use ($registrar, $baseDatos): void {
        MarcoPruebas::afirmar(!$registrar('Otra Ana', 'ana.alumna@ejemplo.mx', 'ALUMNO')['exito'], 'Duplicado exacto');
        $correoMayusculas = ValidadorEntrada::normalizarCorreo('ANA.Alumna@Ejemplo.MX');
        MarcoPruebas::afirmar(!$registrar('Otra Ana', $correoMayusculas, 'PROFESOR')['exito'], 'Duplicado con mayúsculas');
        $conteo = (int) $baseDatos->obtenerConexionAdministrativa()
            ->query("SELECT COUNT(*) FROM usuario WHERE correo_electronico_usuario = 'ana.alumna@ejemplo.mx'")->fetchColumn();
        MarcoPruebas::afirmarIgual(1, $conteo, 'Una sola fila');
        MarcoPruebas::afirmarIgual(2, $baseDatos->contarEventos('REGISTRO_RECHAZADO'), 'Rechazos auditados');
    });

    $marco->probar('P05b', 'Contraseña almacenada como hash Argon2id en la BD', static function () use ($baseDatos, $contrasenaComun): void {
        $hash = (string) $baseDatos->obtenerFilaUsuario('ana.alumna@ejemplo.mx')['hash_contrasena_usuario'];
        MarcoPruebas::afirmar(str_starts_with($hash, '$argon2id$'), 'Hash Argon2id');
        MarcoPruebas::afirmar(!str_contains($hash, $contrasenaComun), 'Sin texto claro');
        $detalles = implode(' ', $baseDatos->obtenerConexionAdministrativa()->query('SELECT detalle_auditoria FROM auditoria')->fetchAll(PDO::FETCH_COLUMN));
        MarcoPruebas::afirmar(!str_contains($detalles, $contrasenaComun) && !str_contains($detalles, '$argon2id$'), 'La auditoría no contiene contraseñas ni hashes');
    });

    $marco->probar('P06', 'Inicio de sesión válido regenera el identificador de sesión', static function () use ($servicioAutenticacion, $reiniciarSesion, $contrasenaComun, $baseDatos): void {
        $reiniciarSesion();
        $idSesionAnonima = session_id();
        $resultado = $servicioAutenticacion->iniciarSesion('ana.alumna@ejemplo.mx', $contrasenaComun, '192.0.2.1');
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_EXITO, $resultado, 'Resultado');
        MarcoPruebas::afirmar(session_id() !== $idSesionAnonima, 'El ID de sesión cambió tras autenticar (anti-fijación)');
        MarcoPruebas::afirmarIgual((int) $baseDatos->obtenerFilaUsuario('ana.alumna@ejemplo.mx')['id_usuario'], $_SESSION['idUsuario'], 'Sesión ligada a la cuenta');
        MarcoPruebas::afirmar(!isset($_SESSION['rol']) && !isset($_SESSION['rolUsuario']), 'La sesión no almacena el rol');
    });

    $marco->probar('P07', 'Credenciales incorrectas: respuesta idéntica exista o no el correo', static function () use ($servicioAutenticacion, $reiniciarSesion): void {
        $reiniciarSesion();
        $resultadoContrasenaMala = $servicioAutenticacion->iniciarSesion('ana.alumna@ejemplo.mx', 'contraseña equivocada larga', '192.0.2.2');
        $resultadoCorreoInexistente = $servicioAutenticacion->iniciarSesion('nadie@ejemplo.mx', 'contraseña equivocada larga', '192.0.2.2');
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_CREDENCIALES_INVALIDAS, $resultadoContrasenaMala, 'Contraseña incorrecta');
        MarcoPruebas::afirmarIgual($resultadoContrasenaMala, $resultadoCorreoInexistente, 'Mismo resultado para correo inexistente');
        MarcoPruebas::afirmar(!isset($_SESSION['idUsuario']), 'No se creó sesión');
    });

    $marco->probar('P08a', 'Cerrar sesión elimina la sesión del servidor', static function () use ($servicioAutenticacion, $iniciarComo, $usuarioModel): void {
        $iniciarComo('ana.alumna@ejemplo.mx');
        $idSesionAutenticada = session_id();
        $archivoSesion = session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . $idSesionAutenticada;
        session_write_close();
        MarcoPruebas::afirmar(is_file($archivoSesion), 'Existía el archivo de sesión');
        GestorSesion::iniciarSesionPhp();
        $servicioAutenticacion->cerrarSesion($usuarioModel->obtenerUsuarioPorCorreo('ana.alumna@ejemplo.mx'));
        clearstatcache();
        MarcoPruebas::afirmar(!is_file($archivoSesion), 'El archivo de la sesión anterior fue destruido');
        MarcoPruebas::afirmar(session_id() !== $idSesionAutenticada, 'Nuevo ID anónimo');
        MarcoPruebas::afirmar(!isset($_SESSION['idUsuario']), 'Sin usuario en la sesión nueva');
    });

    $marco->probar('P20', 'Expiración por inactividad y por duración máxima', static function () use ($iniciarComo, $gestorSesion): void {
        $iniciarComo('ana.alumna@ejemplo.mx');
        $_SESSION['ultimaActividad'] = time() - TIEMPO_INACTIVIDAD_SESION_SEGUNDOS - 1;
        MarcoPruebas::afirmarIgual(null, $gestorSesion->validarSesion(), 'Sesión inactiva rechazada');
        MarcoPruebas::afirmarIgual(GestorSesion::MOTIVO_INACTIVIDAD, $gestorSesion->obtenerMotivoUltimaExpiracion(), 'Motivo');

        $iniciarComo('ana.alumna@ejemplo.mx');
        $_SESSION['fechaCreacion'] = time() - DURACION_MAXIMA_SESION_SEGUNDOS - 1;
        MarcoPruebas::afirmarIgual(null, $gestorSesion->validarSesion(), 'Sesión demasiado antigua rechazada');
        MarcoPruebas::afirmarIgual(GestorSesion::MOTIVO_DURACION_MAXIMA, $gestorSesion->obtenerMotivoUltimaExpiracion(), 'Motivo');

        $iniciarComo('ana.alumna@ejemplo.mx');
        MarcoPruebas::afirmar($gestorSesion->validarSesion() !== null, 'Sesión vigente aceptada');
    });

    $marco->probar('P14b', 'Inyección SQL: los datos se tratan como datos', static function () use ($servicioAutenticacion, $registrar, $baseDatos, $reiniciarSesion, $usuarioModel): void {
        $totalAntes = (int) $baseDatos->obtenerConexionAdministrativa()->query('SELECT COUNT(*) FROM usuario')->fetchColumn();
        $reiniciarSesion();
        foreach (["' OR '1'='1' -- ", "ana.alumna@ejemplo.mx' -- ", "x'; DROP TABLE usuario; -- "] as $correoMalicioso) {
            MarcoPruebas::afirmarIgual(null, $usuarioModel->obtenerUsuarioPorCorreo($correoMalicioso), 'Sin coincidencias para ' . $correoMalicioso);
            MarcoPruebas::afirmarIgual(
                ServicioAutenticacion::RESULTADO_CREDENCIALES_INVALIDAS,
                $servicioAutenticacion->iniciarSesion($correoMalicioso, "' OR '1'='1", '192.0.2.3'),
                'Login rechazado'
            );
        }
        MarcoPruebas::afirmar($registrar("Seán O'Brien-D'Arcy", 'obrien@ejemplo.mx', 'ALUMNO')['exito'], 'Apóstrofos válidos');
        MarcoPruebas::afirmarIgual("Seán O'Brien-D'Arcy", $baseDatos->obtenerFilaUsuario('obrien@ejemplo.mx')['nombre_usuario'], 'Guardado literal');
        $totalDespues = (int) $baseDatos->obtenerConexionAdministrativa()->query('SELECT COUNT(*) FROM usuario')->fetchColumn();
        MarcoPruebas::afirmarIgual($totalAntes + 1, $totalDespues, 'La tabla sigue intacta');
    });

    $marco->probar('P15', 'Limitación de intentos por cuenta y por origen, sin bloqueo permanente', static function () use ($servicioAutenticacion, $reiniciarSesion, $contrasenaComun, $baseDatos): void {
        $baseDatos->limpiarIntentos();
        $reiniciarSesion();
        for ($intento = 1; $intento <= MAX_INTENTOS_LOGIN; $intento++) {
            $servicioAutenticacion->iniciarSesion('pedro.profesor@ejemplo.mx', 'contraseña equivocada larga', '198.51.100.' . $intento);
        }
        MarcoPruebas::afirmarIgual(
            ServicioAutenticacion::RESULTADO_LIMITE_ALCANZADO,
            $servicioAutenticacion->iniciarSesion('pedro.profesor@ejemplo.mx', $contrasenaComun, '198.51.100.99'),
            'Cuenta limitada aunque la contraseña sea correcta y cambie la IP'
        );
        MarcoPruebas::afirmar(!isset($_SESSION['idUsuario']), 'Sin sesión mientras está limitada');

        for ($intento = 1; $intento <= MAX_INTENTOS_LOGIN; $intento++) {
            $servicioAutenticacion->iniciarSesion('no.existe@ejemplo.mx', 'contraseña equivocada larga', '198.51.100.' . $intento);
        }
        MarcoPruebas::afirmarIgual(
            ServicioAutenticacion::RESULTADO_LIMITE_ALCANZADO,
            $servicioAutenticacion->iniciarSesion('no.existe@ejemplo.mx', 'x', '198.51.100.99'),
            'Correo inexistente se comporta igual (sin enumeración)'
        );

        $baseDatos->obtenerConexionAdministrativa()->exec(
            'UPDATE intento_acceso SET fecha_intento_acceso = NOW() - INTERVAL ' . (VENTANA_INTENTOS_SEGUNDOS + 60) . ' SECOND'
        );
        MarcoPruebas::afirmarIgual(
            ServicioAutenticacion::RESULTADO_EXITO,
            $servicioAutenticacion->iniciarSesion('pedro.profesor@ejemplo.mx', $contrasenaComun, '198.51.100.99'),
            'Al salir de la ventana la cuenta legítima vuelve a entrar'
        );

        $baseDatos->limpiarIntentos();
        $reiniciarSesion();
        for ($intento = 1; $intento <= MAX_INTENTOS_LOGIN_ORIGEN; $intento++) {
            $servicioAutenticacion->iniciarSesion("rociado{$intento}@ejemplo.mx", 'contraseña equivocada larga', '203.0.113.7');
        }
        MarcoPruebas::afirmarIgual(
            ServicioAutenticacion::RESULTADO_LIMITE_ALCANZADO,
            $servicioAutenticacion->iniciarSesion('ana.alumna@ejemplo.mx', $contrasenaComun, '203.0.113.7'),
            'Origen limitado tras rociado de contraseñas'
        );
        MarcoPruebas::afirmarIgual(
            ServicioAutenticacion::RESULTADO_EXITO,
            $servicioAutenticacion->iniciarSesion('ana.alumna@ejemplo.mx', $contrasenaComun, '203.0.113.8'),
            'Otro origen no se ve afectado'
        );
        MarcoPruebas::afirmar($baseDatos->contarEventos('LOGIN_BLOQUEADO') >= 3, 'Bloqueos auditados');
        $huellas = $baseDatos->obtenerConexionAdministrativa()->query('SELECT huella_correo_intento_acceso, huella_origen_intento_acceso FROM intento_acceso LIMIT 1')->fetch();
        MarcoPruebas::afirmar(preg_match('/^[a-f0-9]{64}$/', $huellas['huella_origen_intento_acceso']) === 1, 'IP guardada solo como huella HMAC');
        $baseDatos->limpiarIntentos();
    });

    $marco->probar('P19', 'Inicialización controlada del administrador principal (una sola vez)', static function () use ($registrar, $servicioAdministracion, $baseDatos): void {
        MarcoPruebas::afirmar($registrar('Paula Principal', 'paula.principal@ejemplo.mx', 'PROFESOR')['exito'], 'Registro previo');
        $resultado = $servicioAdministracion->inicializarAdministradorPrincipal('paula.principal@ejemplo.mx');
        MarcoPruebas::afirmar($resultado['exito'], $resultado['mensaje']);
        MarcoPruebas::afirmarIgual('ADMINISTRADOR_PRINCIPAL', $baseDatos->obtenerFilaUsuario('paula.principal@ejemplo.mx')['rol_usuario'], 'Rol');
        $segundoIntento = $servicioAdministracion->inicializarAdministradorPrincipal('ana.alumna@ejemplo.mx');
        MarcoPruebas::afirmar(!$segundoIntento['exito'], 'Segunda inicialización rechazada');
        MarcoPruebas::afirmarIgual('USUARIO', $baseDatos->obtenerFilaUsuario('ana.alumna@ejemplo.mx')['rol_usuario'], 'Sin cambios');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contarEventos('INICIALIZACION_ADMINISTRADOR_PRINCIPAL'), 'Auditado');
    });

    $marco->probar('P12a', 'Un ADMINISTRADOR común no puede cambiar roles (servicio)', static function () use ($registrar, $baseDatos, $servicioAdministracion, $usuarioModel, $contrasenaComun): void {
        $registrar('Arturo Admin', 'arturo.admin@ejemplo.mx', 'ALUMNO');
        $baseDatos->fijarRol('arturo.admin@ejemplo.mx', 'ADMINISTRADOR');
        $administrador = $usuarioModel->obtenerUsuarioPorCorreo('arturo.admin@ejemplo.mx');
        $idAna = (int) $baseDatos->obtenerFilaUsuario('ana.alumna@ejemplo.mx')['id_usuario'];
        $eventosAntes = $baseDatos->contarEventos('INTENTO_CAMBIO_PRIVILEGIO_DENEGADO');
        foreach (['ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'] as $rolSolicitado) {
            $resultado = $servicioAdministracion->cambiarRolUsuario($administrador, $idAna, $rolSolicitado, $contrasenaComun);
            MarcoPruebas::afirmar(!$resultado['exito'], "Debe rechazar elevar a {$rolSolicitado}");
        }
        $resultadoPropio = $servicioAdministracion->cambiarRolUsuario($administrador, (int) $administrador['id_usuario'], 'ADMINISTRADOR_PRINCIPAL', $contrasenaComun);
        MarcoPruebas::afirmar(!$resultadoPropio['exito'], 'No puede autoelevarse');
        MarcoPruebas::afirmarIgual('USUARIO', $baseDatos->obtenerFilaUsuario('ana.alumna@ejemplo.mx')['rol_usuario'], 'Rol de Ana intacto');
        MarcoPruebas::afirmarIgual('ADMINISTRADOR', $baseDatos->obtenerFilaUsuario('arturo.admin@ejemplo.mx')['rol_usuario'], 'Rol propio intacto');
        MarcoPruebas::afirmarIgual($eventosAntes + 3, $baseDatos->contarEventos('INTENTO_CAMBIO_PRIVILEGIO_DENEGADO'), 'Tres intentos auditados');
    });

    $marco->probar('P18', 'El principal cambia roles solo con re-autenticación y nunca el propio', static function () use ($baseDatos, $servicioAdministracion, $usuarioModel, $contrasenaComun): void {
        $principal = $usuarioModel->obtenerUsuarioPorCorreo('paula.principal@ejemplo.mx');
        $filaPedro = $baseDatos->obtenerFilaUsuario('pedro.profesor@ejemplo.mx');
        $idPedro = (int) $filaPedro['id_usuario'];

        MarcoPruebas::afirmar(!$servicioAdministracion->cambiarRolUsuario($principal, $idPedro, 'ADMINISTRADOR', 'contraseña equivocada larga')['exito'], 'Re-autenticación fallida');
        MarcoPruebas::afirmar(!$servicioAdministracion->cambiarRolUsuario($principal, (int) $principal['id_usuario'], 'USUARIO', $contrasenaComun)['exito'], 'Cambio del propio rol');
        MarcoPruebas::afirmar(!$servicioAdministracion->cambiarRolUsuario($principal, $idPedro, 'ROOT', $contrasenaComun)['exito'], 'Rol inexistente');

        $resultado = $servicioAdministracion->cambiarRolUsuario($principal, $idPedro, 'ADMINISTRADOR', $contrasenaComun);
        MarcoPruebas::afirmar($resultado['exito'], $resultado['mensaje']);
        $filaPedroDespues = $baseDatos->obtenerFilaUsuario('pedro.profesor@ejemplo.mx');
        MarcoPruebas::afirmarIgual('ADMINISTRADOR', $filaPedroDespues['rol_usuario'], 'Rol nuevo');
        MarcoPruebas::afirmarIgual((int) $filaPedro['version_sesion_usuario'] + 1, (int) $filaPedroDespues['version_sesion_usuario'], 'Versión de sesión incrementada');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contarEventos('CAMBIO_ROL'), 'Cambio auditado');
    });

    $marco->probar('P17a', 'Revocar privilegios invalida la sesión abierta del afectado', static function () use ($iniciarComo, $gestorSesion, $servicioAdministracion, $usuarioModel, $contrasenaComun): void {
        MarcoPruebas::afirmarIgual(ServicioAutenticacion::RESULTADO_EXITO, $iniciarComo('pedro.profesor@ejemplo.mx'), 'Pedro inicia sesión como ADMINISTRADOR');
        $usuarioEnSesion = $gestorSesion->validarSesion();
        MarcoPruebas::afirmarIgual('ADMINISTRADOR', $usuarioEnSesion['rol_usuario'], 'Rol vigente leído de la BD');
        $sesionPedro = $_SESSION;

        $principal = $usuarioModel->obtenerUsuarioPorCorreo('paula.principal@ejemplo.mx');
        MarcoPruebas::afirmar($servicioAdministracion->cambiarRolUsuario($principal, (int) $usuarioEnSesion['id_usuario'], 'USUARIO', $contrasenaComun)['exito'], 'Revocación');

        $_SESSION = $sesionPedro;
        MarcoPruebas::afirmarIgual(null, $gestorSesion->validarSesion(), 'La sesión anterior ya no es válida');
        MarcoPruebas::afirmarIgual(GestorSesion::MOTIVO_VERSION_CAMBIADA, $gestorSesion->obtenerMotivoUltimaExpiracion(), 'Motivo');
    });

    $marco->probar('P23', 'Verificación de profesor: solo administradores, sin cambiar el rol', static function () use ($registrar, $servicioAdministracion, $usuarioModel, $baseDatos): void {
        $registrar('Fernanda Profesora', 'fernanda@ejemplo.mx', 'PROFESOR');
        $idFernanda = (int) $baseDatos->obtenerFilaUsuario('fernanda@ejemplo.mx')['id_usuario'];
        $alumna = $usuarioModel->obtenerUsuarioPorCorreo('ana.alumna@ejemplo.mx');
        $administrador = $usuarioModel->obtenerUsuarioPorCorreo('arturo.admin@ejemplo.mx');

        MarcoPruebas::afirmar(!$servicioAdministracion->resolverVerificacion($alumna, $idFernanda, 'VERIFICADA')['exito'], 'USUARIO no puede verificar');
        MarcoPruebas::afirmar(!$servicioAdministracion->resolverVerificacion($administrador, (int) $alumna['id_usuario'], 'VERIFICADA')['exito'], 'Un alumno no tiene verificación pendiente');
        MarcoPruebas::afirmar(!$servicioAdministracion->resolverVerificacion($administrador, $idFernanda, 'PENDIENTE')['exito'], 'Decisión inválida');
        MarcoPruebas::afirmar($servicioAdministracion->resolverVerificacion($administrador, $idFernanda, 'VERIFICADA')['exito'], 'Administrador verifica');
        $filaFernanda = $baseDatos->obtenerFilaUsuario('fernanda@ejemplo.mx');
        MarcoPruebas::afirmarIgual('VERIFICADA', $filaFernanda['estado_verificacion_usuario'], 'Estado');
        MarcoPruebas::afirmarIgual('USUARIO', $filaFernanda['rol_usuario'], 'La verificación no concede rol');
        MarcoPruebas::afirmar(!$servicioAdministracion->resolverVerificacion($administrador, $idFernanda, 'RECHAZADA')['exito'], 'No se puede resolver dos veces');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contarEventos('CAMBIO_VERIFICACION'), 'Auditado');
    });

    $marco->probar('P09a', 'Enrutador: rutas protegidas sin sesión responden 401', static function () use ($construirEnrutador, $reiniciarSesion): void {
        $reiniciarSesion();
        $enrutador = $construirEnrutador();
        MarcoPruebas::afirmarExcepcionHttp(401, static fn () => $enrutador->despachar('GET', '/inicio', []), 'GET /inicio');
        MarcoPruebas::afirmarExcepcionHttp(401, static fn () => $enrutador->despachar('GET', '/admin/usuarios', []), 'GET /admin/usuarios');
        MarcoPruebas::afirmarExcepcionHttp(401, static fn () => $enrutador->despachar('POST', '/admin/usuarios/rol', ['id_usuario' => '1', 'rol_nuevo' => 'ADMINISTRADOR']), 'POST rol');
        MarcoPruebas::afirmarExcepcionHttp(404, static fn () => $enrutador->despachar('GET', '/no-existe', []), 'Ruta inexistente');
        MarcoPruebas::afirmarExcepcionHttp(405, static fn () => $enrutador->despachar('GET', '/cerrar-sesion', []), 'Método no permitido');
    });

    $marco->probar('P11a', 'Enrutador: un USUARIO no accede a operaciones administrativas', static function () use ($construirEnrutador, $iniciarComo, $baseDatos): void {
        $iniciarComo('ana.alumna@ejemplo.mx');
        $enrutador = $construirEnrutador();
        $token = ProtectorCsrf::generarTokenCsrf();
        $idFernanda = (string) $baseDatos->obtenerFilaUsuario('fernanda@ejemplo.mx')['id_usuario'];
        MarcoPruebas::afirmarExcepcionHttp(403, static fn () => $enrutador->despachar('GET', '/admin/usuarios', []), 'Listado de cuentas');
        MarcoPruebas::afirmarExcepcionHttp(403, static fn () => $enrutador->despachar('POST', '/admin/usuarios/verificacion', ['token_csrf' => $token, 'id_usuario' => $idFernanda, 'decision' => 'RECHAZADA']), 'Verificación');
        MarcoPruebas::afirmarIgual('VERIFICADA', $baseDatos->obtenerFilaUsuario('fernanda@ejemplo.mx')['estado_verificacion_usuario'], 'Sin cambios');
    });

    $marco->probar('P10a', 'Enrutador: registro con rol_usuario manipulado se rechaza y audita', static function () use ($construirEnrutador, $reiniciarSesion, $baseDatos): void {
        $reiniciarSesion();
        $enrutador = $construirEnrutador();
        $datosManipulados = [
            'token_csrf'              => ProtectorCsrf::generarTokenCsrf(),
            'nombre_usuario'          => 'Mallory',
            'correo_electronico'      => 'mallory@ejemplo.mx',
            'contrasena'              => 'frase de paso de pruebas numero uno',
            'confirmacion_contrasena' => 'frase de paso de pruebas numero uno',
            'tipo_integrante'         => 'PROFESOR',
            'rol_usuario'             => 'ADMINISTRADOR_PRINCIPAL',
        ];
        MarcoPruebas::afirmarExcepcionHttp(400, static fn () => $enrutador->despachar('POST', '/registro', $datosManipulados), 'Rechazo 400');
        MarcoPruebas::afirmarIgual(null, $baseDatos->obtenerFilaUsuario('mallory@ejemplo.mx'), 'No se creó la cuenta');
        MarcoPruebas::afirmarIgual(1, $baseDatos->contarEventos('INTENTO_ESCALADA_REGISTRO'), 'Intento auditado');
    });

    $marco->probar('P22', 'Mínimo privilegio: la cuenta de la aplicación no altera la auditoría ni la estructura', static function (): void {
        $conexionAplicacion = obtenerConexionBaseDatos();
        foreach ([
            'DELETE FROM auditoria',
            'UPDATE auditoria SET accion_auditoria = \'X\'',
            'DELETE FROM usuario',
            'DROP TABLE intento_acceso',
            'ALTER TABLE usuario ADD COLUMN x INT',
            'SELECT * FROM aviso',
        ] as $sentenciaProhibida) {
            $fueRechazada = false;
            try {
                $conexionAplicacion->exec($sentenciaProhibida);
            } catch (PDOException) {
                $fueRechazada = true;
            }
            MarcoPruebas::afirmar($fueRechazada, "MariaDB debió rechazar: {$sentenciaProhibida}");
        }
    });

    $reiniciarSesion();
};

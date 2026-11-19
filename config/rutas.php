<?php

/**
 * Tabla de rutas (NOM-14: minúsculas/kebab-case), especificación 1.3.
 *
 * Cada ruta declara su nivel de acceso y, si aplica, el permiso RBAC de
 * config/permisos.php. Una ruta no registrada aquí responde 404; un método
 * no registrado, 405. Toda ruta POST exige token CSRF (Enrutador).
 *
 * Las rutas con token (verificar, activar, restablecer) son públicas: la
 * autorización la da el token de un solo uso, no la sesión. Su token se
 * pasa a la sesión y se redirige a la URL limpia.
 *
 * Los módulos futuros (avisos, comentarios, favoritos, propuestas) deben
 * registrar aquí sus rutas con su permiso correspondiente.
 */

declare(strict_types=1);

/**
 * @param Enrutador $enrutador Enrutador a configurar.
 * @param array<string, mixed> $dependencias Resultado de Aplicacion::construirDependencias().
 * @return void
 */
return static function (Enrutador $enrutador, array $dependencias): void {
    $portal = $dependencias['portalController'];
    $auth = $dependencias['authController'];
    $segundoFactor = $dependencias['segundoFactorController'];
    $solicitud = $dependencias['solicitudAccesoController'];
    $activacion = $dependencias['activacionController'];
    $recuperacion = $dependencias['recuperacionController'];
    $adminSolicitudes = $dependencias['administracionSolicitudesController'];
    $adminUsuarios = $dependencias['adminUsuarioController'];

    // Pantalla A: bienvenida pública, ayuda y aviso de privacidad.
    $enrutador->agregarRuta('GET', '/', [$portal, 'mostrarBienvenida'], Enrutador::ACCESO_PUBLICO);
    $enrutador->agregarRuta('GET', '/ayuda', [$portal, 'mostrarAyuda'], Enrutador::ACCESO_PUBLICO);
    $enrutador->agregarRuta('GET', '/aviso-privacidad', [$portal, 'mostrarAvisoPrivacidad'], Enrutador::ACCESO_PUBLICO);

    // Pantalla B: inicio y cierre de sesión.
    $enrutador->agregarRuta('GET', '/iniciar-sesion', [$auth, 'mostrarFormularioLogin'], Enrutador::ACCESO_SOLO_INVITADO, 'iniciar_sesion');
    $enrutador->agregarRuta('POST', '/iniciar-sesion', [$auth, 'procesarLogin'], Enrutador::ACCESO_SOLO_INVITADO, 'iniciar_sesion');
    $enrutador->agregarRuta('POST', '/cerrar-sesion', [$auth, 'cerrarSesion'], Enrutador::ACCESO_AUTENTICADO, 'cerrar_sesion');
    $enrutador->agregarRuta('GET', '/inicio', [$auth, 'mostrarInicio'], Enrutador::ACCESO_AUTENTICADO);

    // Pantalla H: segundo factor (antes de que exista sesión autenticada).
    $enrutador->agregarRuta('GET', '/verificar-segundo-factor', [$segundoFactor, 'mostrarVerificacion'], Enrutador::ACCESO_SOLO_INVITADO);
    $enrutador->agregarRuta('POST', '/verificar-segundo-factor', [$segundoFactor, 'procesarVerificacion'], Enrutador::ACCESO_SOLO_INVITADO);
    $enrutador->agregarRuta('GET', '/configurar-segundo-factor', [$segundoFactor, 'mostrarConfiguracion'], Enrutador::ACCESO_SOLO_INVITADO);
    $enrutador->agregarRuta('POST', '/configurar-segundo-factor', [$segundoFactor, 'procesarConfiguracion'], Enrutador::ACCESO_SOLO_INVITADO);
    $enrutador->agregarRuta('GET', '/codigos-recuperacion', [$segundoFactor, 'mostrarCodigosRecuperacion'], Enrutador::ACCESO_AUTENTICADO);

    // Pantallas C y D: solicitar acceso y verificar el correo.
    $enrutador->agregarRuta('GET', '/registro', [$solicitud, 'redirigirRegistroAnterior'], Enrutador::ACCESO_PUBLICO);
    $enrutador->agregarRuta('GET', '/solicitar-acceso', [$solicitud, 'mostrarFormulario'], Enrutador::ACCESO_SOLO_INVITADO, 'solicitar_acceso');
    $enrutador->agregarRuta('POST', '/solicitar-acceso', [$solicitud, 'procesarSolicitud'], Enrutador::ACCESO_SOLO_INVITADO, 'solicitar_acceso');
    $enrutador->agregarRuta('GET', '/solicitud-recibida', [$solicitud, 'mostrarSolicitudRecibida'], Enrutador::ACCESO_PUBLICO);
    $enrutador->agregarRuta('GET', '/verificar-solicitud', [$solicitud, 'mostrarVerificacion'], Enrutador::ACCESO_PUBLICO);
    $enrutador->agregarRuta('POST', '/verificar-solicitud', [$solicitud, 'procesarVerificacion'], Enrutador::ACCESO_PUBLICO);
    $enrutador->agregarRuta('GET', '/reenviar-verificacion', [$solicitud, 'mostrarReenvio'], Enrutador::ACCESO_SOLO_INVITADO, 'solicitar_acceso');
    $enrutador->agregarRuta('POST', '/reenviar-verificacion', [$solicitud, 'procesarReenvio'], Enrutador::ACCESO_SOLO_INVITADO, 'solicitar_acceso');

    // Pantalla F: activar cuenta INVITADA.
    $enrutador->agregarRuta('GET', '/activar-cuenta', [$activacion, 'mostrarActivacion'], Enrutador::ACCESO_PUBLICO);
    $enrutador->agregarRuta('POST', '/activar-cuenta', [$activacion, 'procesarActivacion'], Enrutador::ACCESO_PUBLICO);

    // Pantalla G: recuperar y restablecer contraseña.
    $enrutador->agregarRuta('GET', '/recuperar-contrasena', [$recuperacion, 'mostrarRecuperacion'], Enrutador::ACCESO_SOLO_INVITADO);
    $enrutador->agregarRuta('POST', '/recuperar-contrasena', [$recuperacion, 'procesarRecuperacion'], Enrutador::ACCESO_SOLO_INVITADO);
    $enrutador->agregarRuta('GET', '/restablecer-contrasena', [$recuperacion, 'mostrarRestablecimiento'], Enrutador::ACCESO_PUBLICO);
    $enrutador->agregarRuta('POST', '/restablecer-contrasena', [$recuperacion, 'procesarRestablecimiento'], Enrutador::ACCESO_PUBLICO);

    // Pantalla E: bandeja de solicitudes (ADMINISTRADOR y PRINCIPAL).
    $enrutador->agregarRuta('GET', '/admin/solicitudes', [$adminSolicitudes, 'listarSolicitudes'], Enrutador::ACCESO_AUTENTICADO, 'revisar_solicitudes');
    $enrutador->agregarRuta('GET', '/admin/solicitudes/detalle', [$adminSolicitudes, 'mostrarDetalle'], Enrutador::ACCESO_AUTENTICADO, 'revisar_solicitudes');
    $enrutador->agregarRuta('POST', '/admin/solicitudes/aprobar', [$adminSolicitudes, 'aprobarSolicitud'], Enrutador::ACCESO_AUTENTICADO, 'resolver_solicitud');
    $enrutador->agregarRuta('POST', '/admin/solicitudes/rechazar', [$adminSolicitudes, 'rechazarSolicitud'], Enrutador::ACCESO_AUTENTICADO, 'resolver_solicitud');
    $enrutador->agregarRuta('POST', '/admin/solicitudes/reenviar-invitacion', [$adminSolicitudes, 'reenviarInvitacion'], Enrutador::ACCESO_AUTENTICADO, 'resolver_solicitud');

    // Cuentas y bitácora (ADMINISTRADOR y PRINCIPAL).
    $enrutador->agregarRuta('GET', '/admin/usuarios', [$adminUsuarios, 'listarUsuarios'], Enrutador::ACCESO_AUTENTICADO, 'administrar_cuentas');
    $enrutador->agregarRuta('POST', '/admin/usuarios/estado', [$adminUsuarios, 'cambiarEstado'], Enrutador::ACCESO_AUTENTICADO, 'gestionar_estado_cuenta');
    $enrutador->agregarRuta('POST', '/admin/usuarios/verificacion', [$adminUsuarios, 'resolverVerificacion'], Enrutador::ACCESO_AUTENTICADO, 'verificar_clasificacion');
    $enrutador->agregarRuta('GET', '/admin/bitacora', [$adminUsuarios, 'mostrarBitacora'], Enrutador::ACCESO_AUTENTICADO, 'consultar_bitacora');

    // Sección exclusiva del ADMINISTRADOR_PRINCIPAL.
    $enrutador->agregarRuta('GET', '/admin/roles', [$adminUsuarios, 'listarRoles'], Enrutador::ACCESO_AUTENTICADO, 'asignar_rol_administrativo');
    $enrutador->agregarRuta('POST', '/admin/roles/cambiar', [$adminUsuarios, 'cambiarRol'], Enrutador::ACCESO_AUTENTICADO, 'asignar_rol_administrativo');
    $enrutador->agregarRuta('POST', '/admin/roles/restablecer-segundo-factor', [$adminUsuarios, 'restablecerSegundoFactor'], Enrutador::ACCESO_AUTENTICADO, 'asignar_rol_administrativo');
};

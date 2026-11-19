<?php

/**
 * Tabla de rutas del módulo de autenticación (NOM-14: minúsculas/kebab-case).
 *
 * Cada ruta declara su nivel de acceso y, si aplica, el permiso RBAC de
 * config/permisos.php. Una ruta no registrada aquí responde 404; un método
 * no registrado, 405. Toda ruta POST exige token CSRF (Enrutador).
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
    $authController = $dependencias['authController'];
    $adminUsuarioController = $dependencias['adminUsuarioController'];

    $enrutador->agregarRuta('GET', '/', static fn (): Respuesta => Respuesta::redireccion('/inicio'), Enrutador::ACCESO_PUBLICO);

    $enrutador->agregarRuta('GET', '/iniciar-sesion', [$authController, 'mostrarFormularioLogin'], Enrutador::ACCESO_SOLO_INVITADO, 'iniciar_sesion');
    $enrutador->agregarRuta('POST', '/iniciar-sesion', [$authController, 'procesarLogin'], Enrutador::ACCESO_SOLO_INVITADO, 'iniciar_sesion');
    $enrutador->agregarRuta('GET', '/registro', [$authController, 'mostrarFormularioRegistro'], Enrutador::ACCESO_SOLO_INVITADO, 'registrarse');
    $enrutador->agregarRuta('POST', '/registro', [$authController, 'procesarRegistro'], Enrutador::ACCESO_SOLO_INVITADO, 'registrarse');

    $enrutador->agregarRuta('POST', '/cerrar-sesion', [$authController, 'cerrarSesion'], Enrutador::ACCESO_AUTENTICADO, 'cerrar_sesion');
    $enrutador->agregarRuta('GET', '/inicio', [$authController, 'mostrarInicio'], Enrutador::ACCESO_AUTENTICADO);

    $enrutador->agregarRuta('GET', '/admin/usuarios', [$adminUsuarioController, 'listarUsuarios'], Enrutador::ACCESO_AUTENTICADO, 'administrar_cuentas');
    $enrutador->agregarRuta('POST', '/admin/usuarios/verificacion', [$adminUsuarioController, 'resolverVerificacion'], Enrutador::ACCESO_AUTENTICADO, 'verificar_clasificacion');
    $enrutador->agregarRuta('POST', '/admin/usuarios/rol', [$adminUsuarioController, 'cambiarRol'], Enrutador::ACCESO_AUTENTICADO, 'asignar_rol_administrativo');
};

<?php

/**
 * Matriz RBAC de PAPC (Fase I, Actividad 5).
 *
 * Cada permiso enumera EXPLÍCITAMENTE los roles autorizados. Lo que no
 * aparece aquí está denegado (denegación por defecto). "INVITADO" es un
 * pseudo-rol para solicitudes sin sesión; no existe en la base de datos.
 *
 * Nota: "Editar propuesta PENDIENTE" no se lista porque en la Fase I
 * ningún rol lo tiene (la propuesta enviada es inmutable).
 *
 * Los permisos de módulos aún no implementados (avisos, comentarios,
 * favoritos, propuestas) se declaran para que esos módulos los consuman
 * mediante RoleMiddleware::verificarPermiso().
 *
 * @return array<string, list<string>>
 */

declare(strict_types=1);

return [
    // Públicos
    'leer_publicaciones'           => ['INVITADO', 'USUARIO', 'ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'buscar_publicaciones'         => ['INVITADO', 'USUARIO', 'ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'registrarse'                  => ['INVITADO'],
    'iniciar_sesion'               => ['INVITADO'],

    // Usuarios registrados (ALUMNO o PROFESOR por igual)
    'cerrar_sesion'                => ['USUARIO', 'ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'administrar_perfil_propio'    => ['USUARIO', 'ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'gestionar_comentario_propio'  => ['USUARIO', 'ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'gestionar_favorito_propio'    => ['USUARIO', 'ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'enviar_propuesta'             => ['USUARIO', 'ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'consultar_propuesta_propia'   => ['USUARIO', 'ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],

    // Administrativos
    'gestionar_aviso_oficial'      => ['ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'resolver_propuesta'           => ['ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'moderar_comentario'           => ['ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'administrar_cuentas'          => ['ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'verificar_clasificacion'      => ['ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],
    'consultar_bitacora'           => ['ADMINISTRADOR', 'ADMINISTRADOR_PRINCIPAL'],

    // Exclusivo del administrador principal
    'asignar_rol_administrativo'   => ['ADMINISTRADOR_PRINCIPAL'],
];

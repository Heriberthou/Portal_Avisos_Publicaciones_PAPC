<?php

/**
 * Parámetros de seguridad de la aplicación PAPC.
 *
 * Son constantes (NOM-13) y no secretos: los secretos viven en el archivo
 * .env, que se lee con la clase Configuracion.
 */

declare(strict_types=1);

/** Nombre visible de la aplicación. */
const NOMBRE_APLICACION = 'Portal de Avisos y Publicaciones de la Comunidad';

/* --------------------------- Contraseñas --------------------------- */

/**
 * Longitud mínima de contraseña. NIST SP 800-63B rev. 4 exige 15 caracteres
 * cuando la contraseña es el único factor. Los administradores usan además
 * un segundo factor, pero se conserva la misma política para todas las
 * cuentas (especificación 1.3, sección 7.2). No hay reglas de composición.
 */
const LONGITUD_MINIMA_CONTRASENA = 15;

/** Longitud máxima en caracteres (permite frases de paso largas). */
const LONGITUD_MAXIMA_CONTRASENA = 128;

/* ----------------------- Limitación de intentos -------------------- */

/** Intentos fallidos permitidos por cuenta dentro de la ventana. */
const MAX_INTENTOS_LOGIN = 5;

/** Intentos fallidos permitidos por origen (IP) dentro de la ventana. */
const MAX_INTENTOS_LOGIN_ORIGEN = 20;

/** Ventana deslizante de conteo de intentos, en segundos (15 minutos). */
const VENTANA_INTENTOS_SEGUNDOS = 900;

/** Antigüedad a partir de la cual se purgan intentos, en segundos (1 día). */
const RETENCION_INTENTOS_SEGUNDOS = 86400;

/**
 * Formularios públicos que envían correos (solicitar acceso, reenviar
 * verificación, recuperar contraseña): envíos permitidos por correo y por
 * origen dentro de VENTANA_INTENTOS_SEGUNDOS. El límite por origen es
 * holgado porque un salón completo puede compartir la misma IP del campus.
 */
const MAX_ENVIOS_FORMULARIO_CORREO = 3;
const MAX_ENVIOS_FORMULARIO_ORIGEN = 30;

/** Códigos de segundo factor fallidos permitidos por cuenta en la ventana. */
const MAX_INTENTOS_SEGUNDO_FACTOR = 5;

/* ------------------- Enlaces de un solo uso (tokens) --------------- */
/* Políticas propuestas en la especificación 1.3; son revisables. */

/** Vigencia del enlace para verificar el correo de una solicitud (24 h). */
const VIGENCIA_TOKEN_SOLICITUD_SEGUNDOS = 86400;

/** Vigencia del enlace de activación de una cuenta INVITADA (24 h). */
const VIGENCIA_TOKEN_ACTIVACION_SEGUNDOS = 86400;

/** Vigencia del enlace de recuperación de contraseña (30 min). */
const VIGENCIA_TOKEN_RECUPERACION_SEGUNDOS = 1800;

/* -------------------------- Segundo factor ------------------------- */

/** Tiempo para completar el segundo factor tras la contraseña (5 min). */
const VIGENCIA_SEGUNDO_FACTOR_PENDIENTE_SEGUNDOS = 300;

/** Códigos de recuperación generados al configurar el segundo factor. */
const CANTIDAD_CODIGOS_RECUPERACION_MFA = 10;

/** Emisor que muestran las aplicaciones de autenticación. */
const EMISOR_SEGUNDO_FACTOR = 'PAPC';

/* ------------------------------ Sesiones --------------------------- */

/** Cierre automático por inactividad, en segundos (30 minutos). */
const TIEMPO_INACTIVIDAD_SESION_SEGUNDOS = 1800;

/** Mínimo permitido al reducir la inactividad con SESION_INACTIVIDAD_SEGUNDOS (demostraciones). */
const TIEMPO_INACTIVIDAD_MINIMO_SEGUNDOS = 60;

/** Duración máxima absoluta de una sesión, en segundos (8 horas). */
const DURACION_MAXIMA_SESION_SEGUNDOS = 28800;

/** Cada cuánto se renueva el identificador de sesión, en segundos (15 minutos). */
const INTERVALO_REGENERACION_SESION_SEGUNDOS = 900;

/* ------------------------------ Entradas --------------------------- */

/** Longitudes según las columnas reales de la tabla usuario (migración 003). */
const LONGITUD_MINIMA_PARTE_NOMBRE = 2;
const LONGITUD_MAXIMA_NOMBRES_USUARIO = 60;
const LONGITUD_MAXIMA_APELLIDO_USUARIO = 50;

/** Máximo del nombre completo calculado (nombre_usuario VARCHAR(100) y chk_usuario_nombre_usuario). */
const LONGITUD_MAXIMA_NOMBRE_USUARIO = 100;
const LONGITUD_MAXIMA_CORREO_ELECTRONICO = 150;

/** Matrícula o número de empleado: 4 a 20 letras, dígitos o guiones. */
const LONGITUD_MINIMA_IDENTIFICADOR_INSTITUCIONAL = 4;
const LONGITUD_MAXIMA_IDENTIFICADOR_INSTITUCIONAL = 20;

/** Método de verificación de pertenencia que registra el administrador. */
const LONGITUD_MINIMA_METODO_VERIFICACION = 5;
const LONGITUD_MAXIMA_METODO_VERIFICACION = 120;

/** Motivo de rechazo de una solicitud (solo lo ve el personal autorizado). */
const LONGITUD_MINIMA_MOTIVO_RECHAZO = 10;
const LONGITUD_MAXIMA_MOTIVO_RECHAZO = 500;

/* ------------------------------ Soporte ---------------------------- */

/**
 * Medio de contacto que se muestra en Ayuda y en los mensajes de rechazo.
 * Es un texto de ejemplo del prototipo: sustitúyelo por el canal real que
 * designe el responsable institucional.
 */
const CONTACTO_SOPORTE = 'el responsable del PAPC en su campus';

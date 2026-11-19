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
 * Longitud mínima de contraseña. NIST SP 800-63B rev. 4 recomienda 15
 * caracteres cuando la contraseña es el único factor de autenticación
 * (PAPC aún no tiene MFA). No se imponen reglas de composición arbitrarias.
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

/* ------------------------------ Sesiones --------------------------- */

/** Cierre automático por inactividad, en segundos (30 minutos). */
const TIEMPO_INACTIVIDAD_SESION_SEGUNDOS = 1800;

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

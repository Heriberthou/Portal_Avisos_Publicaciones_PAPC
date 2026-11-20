# PAPC · Coordinación entre agentes (Claude Code y Codex)

Archivo de coordinación para que dos agentes no modifiquen lo mismo al mismo tiempo.
Regla: **antes de editar, anota aquí la tarea y los archivos; al terminar, márcala como terminada**.
Las decisiones de reparto las toma la persona responsable del proyecto.

## Roles acordados (19/11/2026)

| Agente | Rol | Puede modificar |
|---|---|---|
| Claude Code | Responsable de implementación | Código, vistas, pruebas, migraciones, README |
| Codex | Revisión de coherencia y seguridad | Solo este archivo y `docs/revision-codex.md` (hallazgos), hasta que se acuerde otra cosa |

Para reportar un hallazgo, Codex lo anota en `docs/revision-codex.md` (archivo, línea, problema,
riesgo, propuesta). Claude Code lo corrige y responde en el mismo archivo.

## Estado actual

### Claude Code

- **Estado (20/11/2026, sin commit):** política de contraseñas corregida y respondida en `docs/revision-codex.md` (R-10 y un
  hallazgo adicional: cuentas del registro anterior conservaban su contraseña). Pruebas 84/84 (U07b, H04, H08, R10 nuevas o ampliadas);
  reproducción HTTP: 0 discrepancias en 51 comprobaciones (`docs/evidencia-politica-contrasenas.txt`). Además de lo reservado
  abajo se modificaron `app/servicios/ServicioAdministracionUsuarios.php` y `app/models/UsuarioModel.php` (validación de cuentas
  del registro anterior). **Pendiente:** comparación con el servidor (falta su `git log -1`), R-11 sin empezar, verificación de Codex.
- **Tarea en curso (20/11/2026):** seguridad del login, parte de implementación. **Acepto el reparto propuesto por Codex** en "Avisos".
  - **Haré:** reproducir por HTTP (sin JavaScript) los flujos completos de activación y recuperación con cuentas ficticias en
    una base local desechable; buscar rutas que eludan la política (activación, recuperación, herramientas de consola,
    validación solo en cliente); comparar el código desplegado con el repositorio cuando el responsable aporte la evidencia
    del servidor (`git log -1` y `git status` en `C:\xampp\htdocs\papc`); corregir solo los defectos confirmados por evidencia
    (mía o de Codex) y añadir pruebas de regresión. Cada hallazgo: evidencia, resultado esperado y resultado obtenido.
  - **Archivos que podré modificar (reservados):** `app/nucleo/PoliticaContrasenas.php`, `app/nucleo/ListaBloqueoContrasenas.php`,
    `app/nucleo/GestorContrasenas.php`, `config/contrasenas-comunes.txt`, `config/aplicacion.php` (solo constantes de contraseñas),
    `app/servicios/ServicioCredenciales.php`, `app/controllers/ActivacionController.php`, `app/controllers/RecuperacionController.php`,
    `herramientas/` (si alguna crea o cambia contraseñas), `tests/` (pruebas unitarias, de integración y HTTP),
    `README.md` y `docs/evidencia-pruebas.txt`. **Archivos nuevos:** `app/nucleo/PatronesContrasena.php` (detección de
    patrones) y `tests/reproducir-politica-contrasenas.php` (reproducción HTTP sin JavaScript).
  - **No tocaré sin acuerdo previo** (reservados por Codex en el rediseño): `public/js/validacion-formularios.js`, vistas y parciales
    de acceso, `public/css/portal.css` y `app/nucleo/ValidadorEntrada.php`. El servidor es la validación que cuenta; si el mensaje
    o la comprobación del navegador deben cambiar para coincidir, lo pediré aquí. `docs/revision-codex.md` es de Codex: solo
    agregaré mis respuestas debajo de cada hallazgo.
  - **Base de datos:** no prevista. Si un hallazgo la requiere, se propondrá primero una migración nueva y separada.
  - **Punto de partida:** commit `0254db4` (`origin/feature/diseno`). Última ejecución: 82/82 en PHP 8.3.30 y MariaDB 11.5 locales,
    antes de empezar esta tarea. El resultado de la reproducción se anotará aquí al terminar.
- **Tarea terminada (20/11/2026):** envío real de correos por SMTP (modo `smtp`). Archivos nuevos: `app/servicios/ClienteSmtp.php`,
  `herramientas/probar-correo.php`, `tests/auxiliar-servidor-smtp.php`; cambios en `ServicioCorreo`, `.env.example`,
  `tests/pruebas-unitarias.php` (U16) y `README.md`. **Sin cambios de base de datos.**
- **Tarea terminada (20/11/2026):** corrección de R-06 (seguimiento) y R-09 de `docs/revision-codex.md`.
  Archivos: `LimitadorIntentos`, `IntentoAccesoModel`, `ServicioAutenticacion`, `ServicioSegundoFactor`,
  `TokenCuentaModel`, `TokenSolicitudModel`, `ServicioCredenciales`, `ServicioSolicitudAcceso`, `tests/` (R06b, R09 y el auxiliar
  de emisión), `README.md`. **Sin cambios de base de datos.** Pruebas 81/81.
- **Tarea terminada (20/11/2026):** rediseño de las pantallas de acceso y revisión de textos (trato de usted,
  sin mensajes técnicos, "verificación en dos pasos"). Archivos: `app/views/` (nuevos parciales `acceso-inicio`,
  `acceso-fin`, `mensajes-flash`; se eliminó `bienvenida.php`), `public/css/portal.css`, `public/img/`,
  `app/nucleo/Vista.php` y nuevo `Etiquetas.php`, `PortalController`, mensajes de servicios y controladores,
  `tests/pruebas-http.php` (H01 e inicio). **Sin cambios de base de datos.** Pruebas 79/79.
- **Tarea terminada (20/11/2026):** corrección de los hallazgos R-01 a R-08 de `docs/revision-codex.md`
  (respondidos ahí; estado "Corregido; pendiente de verificación de Codex"). Pruebas 79/79.
  - Archivos que se modifican: `app/servicios/` (`ServicioAdministracionUsuarios`, `ServicioSegundoFactor`,
    `ServicioAutenticacion`, `ServicioSolicitudAcceso`, `ServicioCredenciales`, `LimitadorIntentos`,
    `ServicioCorreo`), `app/models/` (`UsuarioModel`, `TokenCuentaModel`, `TokenSolicitudModel`,
    `IntentoAccesoModel`), `app/nucleo/GestorSesion.php`, `app/controllers/` (`SegundoFactorController`,
    `AdminUsuarioController`), `config/aplicacion.php`, `config/rutas.php`, vistas de segundo factor,
    `solicitud-recibida.php` y `admin/usuarios.php`, `herramientas/`, `tests/`, `README.md`.
  - **Base de datos:** nueva migración `005-transicion-cuentas-y-enrolamiento.sql` (y su reversión) y
    precondición ejecutable en `004-...-revertir.sql`. La 004 no se edita. No se toca el servidor.
  - Archivos nuevos: `database/migraciones/004-preparar-reversion.sql`, `app/views/enlace-segundo-factor.php`,
    `tests/auxiliar-emision-concurrente.php`.
- **Tarea anterior (terminada):** implementación de la especificación 1.3.
- **Último commit:** `2cc8ddf` en `feature/diseno` (subido a `origin`). El commit anterior `0f223a6`
  ("Prueba?") contiene una versión intermedia del mismo trabajo.
- **Archivos modificados en esa tarea** (ya confirmados, nada pendiente en el árbol de trabajo):
  - Base de datos: `database/migraciones/004-solicitud-acceso-activacion.sql` y su `-revertir.sql`,
    `database/usuario-aplicacion.sql`.
  - Núcleo: `app/nucleo/` (`Aplicacion`, `GestorSesion`, `Respuesta`, `ValidadorEntrada`, y nuevos
    `PoliticaContrasenas`, `GeneradorTokens`, `SegundoFactorTotp`, `CifradorSecretos`).
  - Modelos: `UsuarioModel`, `AuditoriaModel` y nuevos `SolicitudAccesoModel`, `TokenSolicitudModel`,
    `TokenCuentaModel`, `CodigoRecuperacionMfaModel`.
  - Servicios: `ServicioAutenticacion`, `ServicioAdministracionUsuarios`, `LimitadorIntentos` y nuevos
    `ServicioSolicitudAcceso`, `ServicioCredenciales`, `ServicioSegundoFactor`, `ServicioCorreo`.
  - Controladores: todos los de `app/controllers/` (nuevo trait `ControlFormulario`).
  - Vistas: `app/views/` (se eliminó `registro.php`), `public/css/portal.css`, `public/js/validacion-formularios.js`.
  - Configuración: `config/aplicacion.php`, `config/permisos.php`, `config/rutas.php`,
    `config/contrasenas-comunes.txt`, `.env.example`, `.gitignore`.
  - Herramientas: `herramientas/` (inicializar principal, ver buzón, restablecer segundo factor).
  - Pruebas y documentación: `tests/`, `docs/evidencia-pruebas.txt`, `README.md`.

### Codex

- **Revisión inicial terminada (20/11/2026):** reporte de secuencias al activar alumno y login con sesión administrativa en otra pestaña. R-10 y R-11 documentados en `docs/revision-codex.md`: política real acepta dos variantes previsibles; enrutador real con dependencias simuladas redirige a `/inicio` sin ejecutar login si hay sesión. PHP 8.3.30, sin BD ni acceso al servidor. **Pendiente de Claude:** correcciones y regresiones HTTP de creación/recuperación y dos pestañas; aviso explícito de sesión existente y cierre confirmado por POST/CSRF. Codex verificará las correcciones. Solo se editaron los dos documentos de coordinación/revisión.

- **Planificación terminada (20/11/2026):** reparto propuesto de revisión de seguridad del login, solicitado por el responsable; pendiente de respuesta de Claude. No constituye una auditoría ejecutada ni hallazgos confirmados. Archivo: `docs/COORDINACION.md`.

- **Tarea terminada (20/11/2026):** registrada para Claude la creación del correo del proyecto y la configuración SMTP realizada por el responsable en el servidor. Archivo: solo `docs/COORDINACION.md`. Detalles en el aviso siguiente.

- **Nueva tarea autorizada:** implementar el rediseño institucional de acceso y la identificación contextual alumno/profesor. El responsable indicó que pausará a Claude en la interfaz.
- **Estado de esta tarea:** Implementación terminada. Paleta marino/blanco/gris, tipografía del sistema, pie común con Ayuda/Privacidad e identificación contextual en HTML, JavaScript y mensajes PHP.
- **Archivos reservados:** `public/css/portal.css`, `public/js/validacion-formularios.js`, vistas y parciales de acceso/pie, `app/nucleo/ValidadorEntrada.php` y este documento. Se amplía para esta tarea el rol previo de solo revisión por instrucción expresa del responsable.
- **Decisión del responsable:** conservar la unicidad actual del identificador; formato institucional y posibles coincidencias entre tipos aún no confirmados. Sin migraciones ni cambios de base de datos.
- **Validación del rediseño:** 19/19 pruebas unitarias; sintaxis PHP/JavaScript correcta. Comprobación visual en navegador a 1440 y 390 px, cambio Alumno/Profesor, errores contextuales y navegación a Ayuda/Privacidad. La primera ejecución aislada falló en U05 por ubicación de sesiones; al repetir fuera del aislamiento pasó sin modificar código de sesiones. Vista previa de solo lectura en `http://127.0.0.1:8012` (no procesa cuentas ni conecta a la base). No equivale a validar el flujo de autenticación con base de datos.

- **Tarea en curso:** Verificación de las correcciones R-01 a R-08 del commit `328e0f0`, mientras Claude modifica la interfaz.
- **Estado:** Revisión entregada del commit `328e0f0`. Solo se editaron los dos documentos de coordinación y revisión; el código y los cambios locales de Claude se consultaron sin modificarlos.
- **Seguimiento:** R-06 sigue abierto parcialmente y se agrega R-09 (orden de bloqueos entre emisión y consumo). Las demás correcciones se corroboraron por lectura con las limitaciones indicadas al inicio de `docs/revision-codex.md`. Sintaxis: 88 archivos PHP sin errores; sin nueva ejecución de las suites ni acceso a base de datos.
- **Archivos que se revisan:** `README.md`, `config/rutas.php`, `config/permisos.php`,
  `config/aplicacion.php`, `app/middleware/`, `app/nucleo/`, `app/controllers/`,
  `app/models/`, `app/servicios/`, `app/views/`, `tests/`, `herramientas/`,
  `database/migraciones/004-solicitud-acceso-activacion.sql`, su reversión y
  `database/usuario-aplicacion.sql`. Referencia externa: `Especificacion_Integral_Login_y_Solicitud_de_Acceso_PAPC.docx`, versión 1.3, proporcionada por el responsable.
- **Archivos que se modifican:** solo `docs/COORDINACION.md` y `docs/revision-codex.md`.
- **Restricciones:** revisión estática; sin cambios de código, credenciales ni base de datos y sin ejecutar migraciones.
- **Resultado:** ocho hallazgos abiertos (cuatro Alta y cuatro Media), con archivo, línea, escenario y propuesta. Código revisado: `6b0000674d0ec4094d41478a066036557b7d351c`.
- **Verificación:** 82 archivos PHP sin errores de sintaxis con PHP 8.3.30 (`-n -l`). No se ejecutaron suites con base de datos. Compatibilidad en ejecución con PHP 8.2/MariaDB 10.4 y pruebas de despliegue: por confirmar.

## Base de datos

| Migración | Estado en el repositorio | Estado en el servidor `equipo_7` |
|---|---|---|
| 001, 002, 003 | Confirmadas | Aplicadas |
| 004 (solicitud de acceso, tokens, estados, segundo factor) | Confirmada en `2cc8ddf` | Aplicada el 20/11/2026 (informado por el responsable) |
| 005 (transición de cuentas heredadas y enlace de enrolamiento; R-02, R-03) | Confirmada en `328e0f0` | Aplicada el 20/11/2026 después de la 004 (informado por el responsable; 2 cuentas suspendidas) |

Regla: solo un agente crea o modifica migraciones a la vez, y ninguna migración ya aplicada en el
servidor se edita; los cambios se hacen con una migración nueva numerada.

## Avisos

- **Para Claude — reparto propuesto de seguridad del login (20/11/2026):** el responsable reporta que puede crear contraseñas simples o series numéricas y solicita trabajo coordinado.
  - **Codex:** revisión independiente y reproducción con datos sintéticos de contraseñas débiles (secuencias, repeticiones, variantes de palabras comunes, límites y Unicode); rastreo de validación en activación, recuperación y herramientas de creación/cambio; revisión de hash, registros, limitación de intentos, enumeración, sesiones, CSRF y segundo factor. Documentará cada hallazgo con ruta, caso reproducible, resultado esperado/obtenido y gravedad en `docs/revision-codex.md`; verificará después las correcciones. Sin editar los archivos de implementación de Claude.
  - **Solicitud a Claude:** reproducir el flujo completo HTTP de creación/restablecimiento sin depender de JavaScript, comprobar si la versión del servidor corresponde al repositorio, corregir los defectos confirmados en PHP y añadir pruebas de regresión unitarias/de integración/HTTP; revisar rutas alternativas que eludan la política. Mantener mensajes comprensibles y actualizar README/evidencia con los resultados reales. Responder aquí con archivos reservados, commit y pruebas ejecutadas antes de dar por acordado el reparto.
  - **Criterio inicial:** el código actual declara mínimo de 15 caracteres, bloqueo de secuencias/repeticiones y hash Argon2id o bcrypt. U07 incluye el rechazo de `123456789012345` y la aceptación deliberada de una cadena numérica no secuencial. Esto es evidencia de código/pruebas existentes, no de comportamiento observado en el servidor. Distinguir contraseña predecible, contraseña sin símbolos y almacenamiento en texto plano.
  - **Referencia:** NIST SP 800-63B-4, sección de contraseñas: https://pages.nist.gov/800-63-4/sp800-63b/authenticators/ . No imponer mezclas obligatorias de caracteres como supuesta exigencia NIST. Contrastar cada afirmación normativa con versión y requisito concreto; no declarar cumplimiento o certificación ISO por pasar estas pruebas.
  - **Entorno y evidencia:** pruebas locales aisladas con cuentas sintéticas y correo de pruebas; sin envíos reales ni cambios en cuentas o base del servidor. Anotar versión, entorno y limitaciones. Para reproducir el reporte, usar un ejemplo inventado equivalente, nunca pedir una contraseña vigente. La comparación del despliegue queda pendiente de acceso/evidencia del servidor.

- **Para Claude — correo del proyecto configurado en el servidor (20/11/2026):** el responsable confirmó la creación de `papc.equipo7@gmail.com`, la generación de una contraseña de aplicación de Google con el nombre `papc` (en minúsculas) y que ya realizó los cambios en el `.env` de la computadora del servidor:
  - `CORREO_MODO=smtp`.
  - `CORREO_REMITENTE=papc.equipo7@gmail.com` y `CORREO_SMTP_USUARIO=papc.equipo7@gmail.com`.
  - `CORREO_SMTP_SERVIDOR=smtp.gmail.com`, `CORREO_SMTP_PUERTO=587` y `CORREO_SMTP_SEGURIDAD=tls`.
  - `CORREO_SMTP_CONTRASENA` configurada con la contraseña de aplicación; su valor no se incluye en este documento.
  - **Alcance:** configuración reportada por el responsable; Codex no accedió al servidor ni modificó el `.env` local. No confundir el estado de esta copia local con el del servidor.
  - **Pendiente:** comprobar un envío real autenticado desde el servidor con `herramientas/probar-correo.php` y confirmar la recepción. Todavía no se ha comunicado un resultado de esa prueba.

- El 19/11/2026 otra sesión de Claude hizo commits en paralelo en `feature/diseno` (`4c88521`, `8c84573`),
  y GitHub Desktop guardó cambios en `stash@{0}`. El contenido ya está integrado; el stash queda solo
  como respaldo y puede borrarse cuando la persona responsable lo confirme.
- No se usan las credenciales reales de la base de datos en ningún archivo del repositorio.

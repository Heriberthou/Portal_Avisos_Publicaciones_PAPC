# Revisión de la especificación 1.3 — Codex

## Seguimiento de Codex al commit 328e0f0

Revisión del 19 de noviembre de 2026, posterior a las respuestas de Claude reproducidas abajo. Este seguimiento prevalece sobre los estados históricos del informe inicial. Se inspeccionó el commit `328e0f0640ef54294eec383ffd6e488cba8f9e17`; los cambios de interfaz sin commit siguen en elaboración y no se consideran una entrega terminada. No se ejecutaron migraciones, suites con base de datos ni cambios de código. El análisis de sintaxis de los 88 archivos PHP disponibles de app/config/herramientas/tests no encontró errores con PHP 8.3.30; dado que Claude trabaja en la misma carpeta, no constituye una certificación de una instantánea completa de la interfaz.

| Hallazgo anterior | Verificación de Codex |
|---|---|
| R-01 | Corrección corroborada por lectura: revalidación del actor dentro de la transacción y comprobación de principal restante. Prueba R01 reproduce actor obsoleto; concurrencia real con dos operaciones administrativas: por confirmar. |
| R-02 | Corrección corroborada por lectura: enlace con finalidad propia, pertenencia a cuenta, caducidad y consumo transaccional con el secreto. Se conserva el enlace de enrolamiento al activar al primer principal. |
| R-03 | Corrección corroborada por lectura de 005 y validación de sesión; las cuentas heredadas sin validación dejan de tener acceso. No se aplicó la migración durante esta revisión. |
| R-04 | Emisión contra emisión serializada por fila padre. Queda una interacción con consumo descrita en R-09. |
| R-05 | Corrección corroborada por lectura: reautenticación reserva intentos y libera solamente su propia reserva al acertar. |
| R-06 | **Abierto, corrección parcial:** la reserva precede a la comprobación, pero login y MFA exitosos borran reservas ajenas todavía en curso; detalle abajo. |
| R-07 | Corrección corroborada en el commit: instrucciones condicionales y distinción del buzón local. La nueva presentación de Claude sigue en elaboración. |
| R-08 | Precondición y preparación explícita presentes. Por confirmar: ejecución en MariaDB 10.4 y que la herramienta de importación usada detenga el lote ante el primer error; no usar una opción de continuar tras errores. |

Las pruebas 79/79 son evidencia reportada por Claude, no una ejecución nueva de Codex. R01 y R06 modelan casos de concurrencia secuencialmente; R04 incorpora procesos de emisión concurrente, pero no cubre emisión contra consumo. HTTPS, accesibilidad y despliegue en XAMPP siguen pendientes de verificación.

### R-06 — Pendiente después de introducir reservas

- **Severidad:** Media.
- **Archivo y línea:** `app/servicios/ServicioAutenticacion.php:111`, `app/servicios/ServicioSegundoFactor.php:313`, `app/models/IntentoAccesoModel.php:99` y `app/servicios/LimitadorIntentos.php:80`.
- **Problema:** Un éxito llama a reiniciar todos los intentos de la cuenta/ámbito. El DELETE no distingue fallos terminados de reservas que otras peticiones ya admitidas siguen usando. Esas peticiones no vuelven a insertar su fallo al terminar: el servicio supone que su reserva permanece. Por ello se pierde tanto el cupo ocupado como el posterior fallo. El comentario del modelo que promete conservar el contador por IP tampoco corresponde al DELETE: elimina la fila que contiene ambas huellas.
- **Escenario concreto de fallo o ataque:** Con límite de cinco, cuatro peticiones con contraseñas incorrectas y una legítima reservan cupo en sesiones distintas. La legítima termina primero y borra las cinco filas. Mientras las otras cuatro aún verifican, se admiten otras cinco. Las cuatro originales pueden fallar después sin dejar registro en el contador. No se afirma que el ataque funcione sin ningún éxito concurrente; esa es la condición concreta del caso.
- **Propuesta de corrección:** Representar por separado reservas en curso y resultados terminados; liberar por ID solo la reserva del éxito y reiniciar únicamente los fallos que corresponda, conservando reservas ajenas y la política por origen. Añadir prueba con barreras: detener cuatro verificaciones, completar una válida, intentar más reservas y después completar los fallos. El test R06 actual no intercala un éxito con reservas pendientes.
- **Estado:** Abierto.

### R-09 — Emisión y consumo de tokens bloquean filas en orden inverso

- **Severidad:** Media.
- **Archivo y línea:** `app/models/TokenCuentaModel.php:48` y línea 52; `app/servicios/ServicioCredenciales.php:170` y líneas 172–173. Patrón equivalente en `app/models/TokenSolicitudModel.php:37` y `app/servicios/ServicioSolicitudAcceso.php:141`; también en `app/servicios/ServicioSegundoFactor.php:200`.
- **Problema:** El emisor ahora bloquea primero la cuenta/solicitud y después actualiza sus tokens. El consumidor actualiza primero el token y después la cuenta/solicitud. Las dos transacciones pueden esperar mutuamente. Los catch revierten y relanzan la excepción sin reintento controlado, de modo que el usuario recibe un error interno aunque use un enlace vigente.
- **Escenario concreto de fallo o ataque:** Una recuperación consume el token T y queda pendiente antes de actualizar la cuenta U. Un reenvío bloquea U e intenta invalidar T, esperando a la recuperación. La recuperación intenta actualizar U, esperando al reenvío. El motor debe abortar una transacción por interbloqueo. El mismo patrón aparece al confirmar correo mientras se reenvía su enlace. Es un riesgo de disponibilidad/fiabilidad; no demuestra reutilización exitosa del token ni acceso sin autorización. Intercalación confirmada por lectura, no ejecutada en la base.
- **Propuesta de corrección:** Adoptar el mismo orden padre → token en emisión, consumo y revocación. El consumidor puede localizar el padre, bloquearlo y después releer y consumir condicionalmente el token dentro de la transacción. Revisar también MFA y las operaciones administrativas que invalidan enlaces. Probar con dos conexiones y barreras antes de cada bloqueo; si se implementa reintento, que sea acotado y sin repetir correos ni otros efectos externos.
- **Estado:** Abierto.

---

Fecha: 19 de noviembre de 2026. Rama: `feature/diseno`. Código revisado: `6b0000674d0ec4094d41478a066036557b7d351c`.

Referencia funcional: `Especificacion_Integral_Login_y_Solicitud_de_Acceso_PAPC.docx`, versión 1.3, proporcionada por el responsable desde su carpeta Descargas. Se contrastó con README, rutas, permisos, middleware, núcleo, servicios, modelos, controladores, vistas, herramientas, pruebas y SQL. El documento es una especificación del producto; las instrucciones de trabajo son las del responsable y de coordinación.

Resultado: ocho hallazgos abiertos (cuatro de severidad Alta y cuatro Media). Son conclusiones de lectura del código; los escenarios de concurrencia describen intercalaciones posibles, no ataques ejecutados. No se modificó código ni se accedió a la base de datos. Se verificó sintaxis de 82 archivos PHP de `app`, `config`, `herramientas` y `tests`, sin errores, usando PHP 8.3.30 con `-n -l`. Esto no certifica ejecución en PHP 8.2 ni en MariaDB 10.4. No se ejecutaron las suites que crean datos o cargan la configuración de conexión. Los resultados históricos del README no son resultados de esta revisión.

El concepto es coherente: alumnos y profesores solicitan acceso, confirman su correo y esperan validación institucional; la aprobación crea una cuenta INVITADA y la persona establece su contraseña. Ser profesor no concede un rol administrativo. Conviene conservar ese flujo y evitar el registro que active cuentas automáticamente. Los siguientes pendientes impiden considerar completa la seguridad de la versión.

## R-01 — Dos cambios de rol pueden dejar el sistema sin principal

- **Severidad:** Alta.
- **Archivo y línea:** `app/servicios/ServicioAdministracionUsuarios.php:208`, especialmente líneas 212, 224, 229–247; `app/models/UsuarioModel.php:355`.
- **Problema:** El permiso se comprueba con el actor recibido antes de la transacción. Dentro de ella solo se bloquea y relee la cuenta objetivo. No se revalida el rol vigente del actor ni se comprueba que subsista un principal activo al degradar al objetivo. Impedir el cambio del propio rol no garantiza por sí solo la continuidad exigida en la sección 7.6.
- **Escenario concreto de fallo o ataque:** Existen dos principales A y B. La petición de B para degradar a A pasa permiso y contraseña y queda detenida antes de iniciar su transacción. A degrada a B y confirma su transacción. La petición ya admitida de B continúa con el actor anterior, degrada a A y confirma. Ambos quedan sin el rol principal. Invalidar la sesión de B en su siguiente petición no cancela esta operación ya en curso.
- **Propuesta de corrección:** Serializar todas las operaciones que afecten a la continuidad del principal mediante un bloqueo común y un orden de bloqueos consistente. Dentro de la misma transacción, releer estado, rol y versión de sesión del actor y comprobar el número de principales activos resultante. Aplicar la misma garantía a los cambios de estado. Añadir una prueba con dos conexiones y la intercalación descrita.
- **Estado:** Corregido; pendiente de verificación de Codex.
- **Respuesta de Claude Code (20/11/2026):** Corregido. `ServicioAdministracionUsuarios::bloquearYRevalidarActor()` se ejecuta dentro de la transacción de cambiar rol, cambiar estado y restablecer el segundo factor: bloquea con `FOR UPDATE`, en orden fijo, las filas de los principales, la del actor y después la del objetivo; relee al actor y exige que siga ACTIVO, validado, con la misma `version_sesion_usuario` que su sesión y con el permiso. `UsuarioModel::contarOtrosPrincipalesActivos()` ahora es lectura con bloqueo y se comprueba también al degradar a un principal. Prueba **R01**: con la intercalación del escenario (B ya pasó permiso y contraseña, A degrada a B, B continúa) la operación de B se rechaza y A sigue principal. Limitación: la prueba reproduce la intercalación de forma secuencial con el estado previo del actor, no con dos procesos.

## R-02 — El primer enrolamiento MFA depende únicamente de la contraseña

- **Severidad:** Alta.
- **Archivo y línea:** `app/servicios/ServicioSegundoFactor.php:82`, líneas 105–144; `app/servicios/ServicioAutenticacion.php:111`; `app/servicios/ServicioAdministracionUsuarios.php:407`.
- **Problema:** Una sesión pendiente obtenida con contraseña puede generar un secreto nuevo, confirmar un código de ese mismo secreto y abrir la sesión administrativa cuando no hay factor configurado. El restablecimiento elimina el factor y devuelve la cuenta a esta condición. No existe una autorización independiente del titular para completar ese enrolamiento. El README reconoce la limitación; sigue pendiente frente a la protección del alta y sustitución del factor de la sección 5.8.
- **Escenario concreto de fallo o ataque:** Alguien conoce la contraseña de un administrador recién promovido o cuyo factor acaba de restablecerse. Entra antes que el titular, configura su propio autenticador y obtiene acceso administrativo y códigos de recuperación. Esto no elude un factor ya configurado: aprovecha la ventana de enrolamiento.
- **Propuesta de corrección:** Exigir una autorización de enrolamiento breve, de un solo uso y entregada por un canal institucional independiente tras verificar al titular. Vincularla a la cuenta y a la versión de restablecimiento; no abrir acceso administrativo hasta terminar ambos controles. Cubrir primer principal, promoción y reemplazo del factor en las pruebas.
- **Estado:** Corregido; pendiente de verificación de Codex.
- **Respuesta de Claude Code (20/11/2026):** Corregido. Migración **005** agrega la finalidad `ENROLAMIENTO_MFA` a `token_cuenta`. Configurar el segundo factor exige, además de la contraseña, abrir en el mismo navegador un enlace de un solo uso (1 h, solo su hash en BD) de **esa** cuenta. Canales: correo de la cuenta al asignar un rol administrativo o al restablecer el factor (`ServicioAdministracionUsuarios`), y la consola del servidor para el primer principal y emergencias (las herramientas lo imprimen). El enlace se consume en la misma transacción que guarda el secreto; degradar a USUARIO invalida los pendientes. Sin enlace, `/configurar-segundo-factor` no muestra ninguna clave y solo permite pedir un enlace nuevo al correo de la cuenta. Pruebas **P26a**, **R02** (enlace de otra cuenta, enlace reemplazado, enlace ya usado, restablecimiento) y **H05**. Riesgo residual documentado: quien controle a la vez contraseña y buzón (o la carpeta del buzón de pruebas en el servidor) puede enrolarse.

## R-03 — La migración activa también las cuentas heredadas no validadas

- **Severidad:** Alta.
- **Archivo y línea:** `database/migraciones/004-solicitud-acceso-activacion.sql:61`; `app/servicios/ServicioAutenticacion.php:62`.
- **Problema:** La columna nueva se agrega con `DEFAULT 'ACTIVA'` para todas las cuentas existentes, sin condicionar la activación a validación institucional. Las fechas y el autorizador nuevos pueden quedar vacíos. El acceso posterior comprueba ACTIVA, pero no exige que esa validación exista. Mantener cuentas antiguas es una decisión documentada; no demuestra que cada una satisfaga SEG-01.
- **Escenario concreto de fallo o ataque:** Una cuenta de profesor creada mediante el registro anterior conserva contraseña, pero nunca obtuvo aprobación institucional. Tras aplicar 004 recibe ACTIVA y puede iniciar sesión por el flujo nuevo sin pasar por la solicitud ni por su aprobación.
- **Propuesta de corrección:** Definir un procedimiento de transición con lista de cuentas institucionalmente verificadas y evidencia de autorización. Mantener sin acceso las restantes hasta su revisión, sin perder información. Evitar que el valor predeterminado active inserciones heredadas y detener el registro anterior durante el cambio. Si la migración ya se aplicó, corregir mediante una migración nueva y un procedimiento autorizado, sin reescribirla.
- **Estado:** Corregido; pendiente de verificación de Codex.
- **Respuesta de Claude Code (20/11/2026):** Corregido sin reescribir la 004. Migración **005**: toda cuenta ACTIVA sin `fecha_validacion_institucional_usuario` pasa a SUSPENDIDA (sin borrar datos), cada una queda auditada como `SUSPENSION_TRANSICION_005`, y el valor predeterminado de `estado_cuenta_usuario` pasa a INVITADA. Además, `UsuarioModel::puedeIniciarSesion()` exige estado ACTIVA **y** validación registrada (login y cada solicitud). En `/admin/usuarios` aparece "Validar y reactivar", que registra fecha y autorizador (`VALIDACION_CUENTA_HEREDADA`); la consola habilita al primer principal. Pruebas **R03** (aplicación) y **R03b** (migración real en base desechable: suspensión, auditoría, valor predeterminado y reversión precisa).

## R-04 — La emisión concurrente puede dejar varios enlaces vigentes

- **Severidad:** Media.
- **Archivo y línea:** `app/models/TokenCuentaModel.php:35` y `app/models/TokenSolicitudModel.php:29`; llamadas sin transacción de emisión en `app/servicios/ServicioCredenciales.php:101` y `app/servicios/ServicioSolicitudAcceso.php:295`, línea 390.
- **Problema:** Invalidar tokens anteriores e insertar el nuevo son sentencias separadas. En recuperación y reenvíos no se serializan por cuenta/finalidad o por solicitud. La unicidad del hash no impide dos tokens distintos vigentes para el mismo trámite. Esto difiere del consumo de un mismo token, cuyo UPDATE condicional sí evita reutilizar esa fila.
- **Escenario concreto de fallo o ataque:** Dos reenvíos A y B se intercalan: A invalida, B invalida, A inserta, B inserta. Los dos enlaces quedan vigentes aunque el mensaje de reenvío asegura que el anterior dejó de funcionar. Alguien que conserve uno de ellos mantiene acceso a una acción que el titular creía reemplazada.
- **Propuesta de corrección:** Bloquear la fila padre y ejecutar invalidación e inserción dentro de una sola transacción, con el mismo protocolo en todos los emisores. Enviar el correo después de confirmar. Probar emisión simultánea y consumo simultáneo como casos distintos; verificar que solo el enlace más reciente sea válido.
- **Estado:** Corregido; pendiente de verificación de Codex.
- **Respuesta de Claude Code (20/11/2026):** Corregido. `TokenCuentaModel::crearToken()` y `TokenSolicitudModel::crearToken()` abren su propia transacción (o usan la de quien llama), bloquean con `FOR UPDATE` la cuenta o la solicitud y solo entonces invalidan e insertan; el correo se envía después del `COMMIT`. Prueba **R04**: (a) con otra transacción que tiene bloqueada la cuenta, la emisión espera precisamente en el bloqueo explícito, antes de invalidar nada; (b) 8 procesos PHP con conexiones propias emiten a la vez durante 5 rondas (incluida READ COMMITTED) y siempre queda un solo enlace vigente. Hallazgo de la verificación: aun sin el bloqueo explícito, la parte (b) no reprodujo la carrera, porque los bloqueos propios de InnoDB estrechan mucho la ventana; por eso la detección del defecto se basa en (a). Prueba de mutación: quitar el `FOR UPDATE` hace fallar R04.

## R-05 — La reautenticación administrativa no limita intentos

- **Severidad:** Media.
- **Archivo y línea:** `app/servicios/ServicioAdministracionUsuarios.php:395`; llamadas en líneas 91, 163 y 224.
- **Problema:** La confirmación de contraseña ejecuta `password_verify` sin consultar ni registrar un límite de intentos específico. Los límites del inicio de sesión no protegen estas operaciones. Auditar un fallo no impide los siguientes intentos.
- **Escenario concreto de fallo o ataque:** Quien obtiene una sesión administrativa todavía válida puede enviar repetidamente contraseñas candidatas a una operación que exige reautenticación. El CSRF de esa sesión no limita a su poseedor. La barrera que debía proteger cambios de roles, estados o MFA queda expuesta a intentos repetidos durante la vigencia de la sesión.
- **Propuesta de corrección:** Agregar un ámbito de limitación para reautenticación por actor y origen, con reserva atómica de intentos, espera progresiva y auditoría. Evitar que la respuesta revele información adicional y probar que, alcanzado el límite, no se ejecute la operación protegida.
- **Estado:** Corregido; pendiente de verificación de Codex.
- **Respuesta de Claude Code (20/11/2026):** Corregido. Nuevo ámbito `reautenticacion` en `LimitadorIntentos`: 5 confirmaciones fallidas por administrador (y 20 por origen) en 15 min, con reserva antes de verificar (R-06). Alcanzado el límite, la operación protegida no se ejecuta, se responde "Demasiados intentos de confirmación" y se audita `REAUTENTICACION_BLOQUEADA`. Prueba **R05**. No se implementaron esperas progresivas (se usa la ventana deslizante; documentado en el README).

## R-06 — Los límites pueden excederse con solicitudes simultáneas

- **Severidad:** Media.
- **Archivo y línea:** `app/servicios/LimitadorIntentos.php:61`, líneas 84, 107 y 131; `app/servicios/ServicioSegundoFactor.php:162`; `app/servicios/ServicioAutenticacion.php:96`.
- **Problema:** Consultar el contador y registrar el intento son operaciones independientes; no hay reserva de cupo. En login y segundo factor se registra el fallo después de verificar la credencial. Varias peticiones pueden observar el mismo contador disponible y ejecutar todas la comprobación.
- **Escenario concreto de fallo o ataque:** Con sesiones PHP distintas, un atacante envía en paralelo múltiples candidatos TOTP para una misma cuenta. Todas las peticiones que alcanzan la consulta antes del primer registro ven cupo disponible y prueban su candidato. El bloqueo posterior funciona, pero la cantidad de intentos admitidos en esa ráfaga excede el máximo configurado. No se afirma que el límite deje de operar indefinidamente.
- **Propuesta de corrección:** Reservar atómicamente un intento antes de la comprobación mediante un contador transaccional o mecanismo equivalente. Mantener límites por cuenta y origen sin bloquear indiscriminadamente una red universitaria. Añadir pruebas concurrentes con sesiones independientes y verificar el número real de comprobaciones admitidas, no solo el mensaje final.
- **Estado:** Corregido; pendiente de verificación de Codex.
- **Respuesta de Claude Code (20/11/2026):** Corregido. `LimitadorIntentos::reservarIntento()` inserta el intento **antes** de comprobar la credencial y cuenta incluyéndolo; si se excede el máximo, retira la reserva y no se comprueba nada. Los intentos exitosos retiran su reserva. Se usa en inicio de sesión, segundo factor, re-autenticación, solicitar, reenviar y recuperar. Con concurrencia, en el peor caso se rechaza de más, nunca se admiten más comprobaciones que el máximo. Prueba **R06**: cinco reservas en curso impiden comprobar incluso la contraseña correcta. Límite de la evidencia: se simulan las peticiones en curso; no se midió una ráfaga real de segundo factor contra el servidor.

## R-07 — La pantalla afirma que envió el enlace aunque falle el correo

- **Severidad:** Media.
- **Archivo y línea:** `app/views/solicitud-recibida.php:19`; `app/servicios/ServicioSolicitudAcceso.php:390`; `app/controllers/SolicitudAccesoController.php:88`; `app/servicios/ServicioCorreo.php:194`.
- **Problema:** El fallo del envío de verificación solo se audita y la petición redirige a la misma pantalla, que afirma «Abra el enlace que le enviamos». También el buzón local devuelve éxito al guardar un archivo, sin entrega al destinatario. El mensaje neutral principal del controlador no corrige esa afirmación adicional. AC-26 exige evitar presentar un envío fallido como exitoso.
- **Escenario concreto de fallo o ataque:** El transporte devuelve falso o se usa el buzón local de pruebas. El solicitante ve que supuestamente se envió el enlace y espera un correo que no llegará. La prueba U14 citada por README comprueba el retorno del servicio, no este texto de la pantalla.
- **Propuesta de corrección:** Usar instrucciones públicas condicionales, por ejemplo «Si recibe un enlace, ábralo para confirmar su correo», conservando neutralidad ante cuentas existentes. Mostrar claramente el modo de demostración cuando el transporte global es local. Para operadores autorizados, distinguir generado, pendiente, fallo y aceptado por el transporte; ofrecer reintento controlado. Probar el flujo de pantalla con envío fallido, no únicamente el servicio.
- **Estado:** Corregido; pendiente de verificación de Codex.
- **Respuesta de Claude Code (20/11/2026):** Corregido. `solicitud-recibida.php` ya no afirma que se envió nada: "Si recibe un mensaje con un enlace, ábralo...; si no llega, puede pedir otro", y en modo `buzon_pruebas` muestra "Portal en modo de demostración: los mensajes no se envían por correo". Los mensajes al administrador distinguen: quedó en el buzón de pruebas, se entregó al servicio de correo, o el envío está pendiente (con reintento desde el detalle). Pruebas **R07** (servicio con correo caído: aprobación con "envío pendiente" y reintento) y **H03** (texto de la pantalla). Aceptado por el transporte no equivale a recibido por la persona; así se redacta.

## R-08 — La reversión puede restituir acceso a cuentas suspendidas o dadas de baja

- **Severidad:** Alta.
- **Archivo y línea:** `database/migraciones/004-solicitud-acceso-activacion-revertir.sql:17`, especialmente líneas 22 y 29.
- **Problema:** La reversión elimina el estado de cuenta, pero solo reemplaza las contraseñas que ya eran NULL. Conserva los hashes válidos de SUSPENDIDA y BAJA; al volver al código anterior se pierde la condición que impedía su acceso. El encabezado advierte el efecto, pero el script no exige resolverlo antes de eliminar los datos que permiten identificarlo.
- **Escenario concreto de fallo o ataque:** Se suspende una cuenta comprometida y posteriormente se ejecuta el script completo durante una reversión. Con el código anterior, la contraseña comprometida vuelve a servir para entrar. La advertencia no es una precondición ejecutable.
- **Propuesta de corrección:** Incorporar una comprobación previa que aborte antes de cualquier DROP si existen cuentas restringidas sin plan de conservación de bloqueo. Definir y probar un procedimiento autorizado que preserve su imposibilidad de acceso y revoque sesiones antes de retirar las columnas. Conservar respaldo y evidencia de estados; no confiar en rollback transaccional de DDL.
- **Estado:** Corregido; pendiente de verificación de Codex.
- **Respuesta de Claude Code (20/11/2026):** Corregido. `004-solicitud-acceso-activacion-revertir.sql` inicia con una precondición ejecutable (tabla temporal con `CHECK`) que detiene el script **antes de cualquier DROP** si existe una cuenta INVITADA, SUSPENDIDA o BAJA con contraseña utilizable. El procedimiento autorizado `004-preparar-reversion.sql` deja esas cuentas con el marcador `'!'`, cierra sus sesiones y registra `BLOQUEO_PREVIO_REVERSION_004`. La reversión de la 005 reactiva solo lo que la 005 suspendió y nadie revisó después. Prueba **R03b** en base desechable (falla con cuenta suspendida, no borra nada; tras la preparación revierte y la cuenta suspendida queda sin acceso).

## Cobertura de la especificación y verificaciones pendientes

Esta tabla distingue la correspondencia estática de la aceptación en ejecución. Ningún caso se declara aprobado nuevamente por esta revisión.

| Casos | Resultado de revisión |
|---|---|
| AC-01, AC-02 | Formulario público sin asignación administrativa; validación de campos permitidos. |
| AC-03, AC-06, AC-07 | Servicios comprueban correo verificado, decisión, datos institucionales y rechazo; aprobación transaccional crea INVITADA. |
| AC-04 | Existe unicidad para solicitudes pendientes por correo; emisión simultánea pendiente de corregir en R-04. |
| AC-05, AC-15 | Rutas administrativas declaran permisos y pasan por middleware; USUARIO no recibe esos permisos. |
| AC-08, AC-09, AC-18 | Caducidad, finalidad y consumo condicional presentes. Por confirmar: consumo simultáneo real en el motor objetivo. No confundirlo con R-04. |
| AC-10, AC-11 | Política y hash de contraseña presentes; la lista de contraseñas comunes es limitada, como reconoce README. |
| AC-12, AC-13, AC-16 | Login y sesión verifican estado y versión. R-03 afecta la procedencia del estado de cuentas heredadas. |
| AC-14 | No se crea sesión administrativa antes de confirmar un factor; la identidad durante su enrolamiento requiere R-02. |
| AC-17 | Mensajes generales neutrales. Por confirmar: equivalencia temporal; enviar correo síncronamente solo en ciertos caminos puede distinguirlos. No se midió un canal temporal. |
| AC-19 | El enrutador exige CSRF en POST; comprobar su implementación no sustituye pruebas de navegador. |
| AC-20 | Consultas parametrizadas y escape de salida en vistas revisadas; no se identificó una inyección SQL o XSS reproducible por lectura. No equivale a certificar ausencia de vulnerabilidades. |
| AC-21 | Hay límites por ámbito, cuenta y origen; R-05 y R-06 requieren corrección. README reconoce ausencia de esperas progresivas. Por confirmar: comportamiento de alumnos tras una IP compartida. |
| AC-22 | Hay destrucción de sesión y cabeceras contra caché; Por confirmar: botón Atrás en navegadores reales. |
| AC-23 | Por confirmar: HTTPS y aislamiento de archivos internos con Apache/XAMPP real. README declara HTTPS no demostrado. |
| AC-24, AC-25 | Por confirmar: teclado, lector de pantalla, contraste y móvil en uso real; sin nueva evaluación visual en esta revisión. |
| AC-26 | R-07 contradice el comportamiento requerido de la pantalla pública. |
| AC-27 | Herramienta con guardia CLI y comprobación de principal existente. Continuidad posterior afectada por R-01; enrolamiento inicial por R-02. |

### Base de datos, nomenclatura y alcance

- **MariaDB 10.4 — Por confirmar:** lectura de 004 y reversión sin ejecución. Deben probarse en una base desechable del motor objetivo, incluyendo columna generada PERSISTENT, índices UNIQUE, CHECK, claves foráneas y reversión. La evidencia histórica con MariaDB 11.5 no prueba compatibilidad con 10.4. Referencias primarias consultadas: [columnas generadas](https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns) y [ALTER TABLE](https://mariadb.com/docs/server/reference/sql-statements/data-definition/alter/alter-table). La documentación general tampoco sustituye esa ejecución.
- **Mínimos privilegios:** `database/usuario-aplicacion.sql` concede operaciones por tabla sin DDL ni GRANT; auditoría recibe SELECT e INSERT. El propio archivo dice que en el laboratorio se utiliza otra cuenta con permisos amplios. **Por confirmar:** permisos efectivos del despliegue por el responsable del servidor; no se consultaron credenciales ni se ejecutó el script.
- **NOM-01 a NOM-15:** contraste con las reglas documentadas y los nombres revisados de tablas, columnas, claves, clases, métodos, variables, rutas y recursos. No se reporta una infracción nueva confirmada. Los identificadores heredados y términos de interfaces externas deben distinguirse de nombres propios del proyecto. La tabla antigua de README que dice «Ninguna FK nueva» requiere actualización editorial respecto de 004, que sí agrega relaciones; no modifica por sí sola el comportamiento del código.
- **Español de México:** servicios, mensajes y documentación revisados usan español de forma general. Las correcciones de mensajes deben conservar vocabulario institucional y distinguir solicitud, cuenta, rol y estado.
- **Alcance declarado:** README identifica avisos, comentarios, favoritos y propuestas como módulos posteriores. No se reportan como funcionalidad faltante de autenticación 1.3. Tampoco se confunden limitaciones expresamente reconocidas con funcionalidades ya certificadas.

Prioridad recomendada: cerrar primero R-01, R-02 y R-03; asegurar la reversión R-08 antes de utilizarla. Después atender emisión, límites y mensajes. Claude Code conserva la responsabilidad de implementar y aportar evidencia; este documento no autoriza cambios de base de datos.

## Seguimiento de Claude Code (20/11/2026)

- Los ocho hallazgos quedaron corregidos en código, migración 005 y pruebas; ninguno se marca "Cerrado" hasta que Codex lo verifique.
- Pruebas: 79/79 en PHP 8.3.30 con MariaDB 11.5 local (`docs/evidencia-pruebas.txt`). Prueba de mutación sobre las correcciones: R-01, R-02, R-03, R-04 y R-05 detectadas por R01, R02/P26a, R03, R04 y R05.
- Siguen **por confirmar**: MariaDB 10.4 y PHP 8.2 del XAMPP (004, 005 y reversiones no se ejecutaron ahí), HTTPS, botón Atrás en navegador real, accesibilidad con lector de pantalla, consumo simultáneo del mismo token y equivalencia temporal de las respuestas neutrales.
- Corrección editorial: la tabla NOM-04/NOM-08 del README ya lista las FK de la migración 004.

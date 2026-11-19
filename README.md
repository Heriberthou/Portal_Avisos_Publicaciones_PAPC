# PAPC · Módulo de autenticación y control de acceso

Portal de Avisos y Publicaciones de la Comunidad (PAPC). Este repositorio contiene **solo** el módulo de
registro, inicio/cierre de sesión, gestión de sesiones, RBAC y auditoría. Los módulos de avisos,
comentarios, favoritos y propuestas **no** están implementados; únicamente se dejaron preparados los
permisos (`config/permisos.php`) y el mecanismo (`RoleMiddleware::verificarPermiso()`) que usarán.

Stack: PHP 8.2+ · MariaDB 10.4+ · Apache (XAMPP) · HTML sin JavaScript · sin frameworks ni Composer.

---

## Etapa 1. Análisis del proyecto

Fuentes: `Fase_I_Diseno_Arquitectura_PAPC_Nomenclatura_v1_2.pdf` y `esquema_papc_nomenclatura_v1_2.sql`.

### Requisitos aplicables tomados de la Fase I
- Perfiles: invitado (sin sesión), `USUARIO`, `ADMINISTRADOR`, `ADMINISTRADOR_PRINCIPAL`.
- Matriz RBAC (Actividad 5) con denegación por defecto; solo el principal asigna/revoca roles administrativos.
- `public/` como único *document root*; `config/`, `database/`, `storage/`, `tests/`, `.env`, `.git` sin acceso HTTP.
- Tabla `usuario` (6 columnas exactas) y `auditoria` para eventos; `password_hash()`/`password_verify()`; CSRF en POST.
- Reglas NOM-01 a NOM-15.

### Incompatibilidades y datos faltantes detectados
| # | Hallazgo | Decisión |
|---|----------|----------|
| 1 | El SQL hace `USE equipo_7;`, pero NOM-01 y la consigna usan `papc_db`. | `database/schema.sql` es copia literal del original con **solo** esa línea comentada; la base se elige al importar (`papc_db`). |
| 2 | El PDF dice que el SQL incluye `CREATE DATABASE`; el archivo no lo incluye. | Se documenta el `CREATE DATABASE` en la instalación (Etapa 5). |
| 3 | No existe forma de distinguir ALUMNO/PROFESOR ni de verificar la clasificación. | Migración 001 (columnas nuevas, propuestas). |
| 4 | No hay dónde persistir intentos fallidos de login (limitarlos en la sesión es evadible). | Migración 002 (tabla `intento_acceso`). |
| 5 | No hay mecanismo para invalidar sesiones abiertas tras revocar un rol o cambiar la contraseña. | Columna `version_sesion_usuario` (migración 001). |
| 6 | `auditoria` sí admite los datos necesarios (`accion`, `entidad`, `id_registro`, `detalle` TEXT). | Se usa sin cambios; el detalle se guarda como JSON. |
| 7 | La Fase I no define cómo se crea el primer `ADMINISTRADOR_PRINCIPAL`. | Herramienta de consola de un solo uso (ver RBAC). |

### Cambios mínimos
Ninguna tabla ni columna original se renombró o eliminó. Todo lo nuevo está en migraciones separadas y reversibles.

---

## Etapa 2. Arquitectura

Flujo: **Navegador → `public/index.php` → `Aplicacion` → `Enrutador` → `AuthMiddleware` → `RoleMiddleware` → CSRF → Controlador → Servicio → Modelo → PDO → MariaDB.**

```
papc/
├── app/
│   ├── controllers/
│   │   ├── AuthController.php           [Fase I]  HTTP de registro, login, logout, /inicio
│   │   └── AdminUsuarioController.php   [nuevo]   HTTP de listado, verificación y cambio de rol
│   ├── models/
│   │   ├── UsuarioModel.php             [Fase I]  Consultas preparadas sobre usuario
│   │   ├── AuditoriaModel.php           [nuevo]   INSERT en auditoria (bitácora de solo anexado)
│   │   └── IntentoAccesoModel.php       [nuevo]   Consultas sobre intento_acceso
│   ├── middleware/
│   │   ├── AuthMiddleware.php           [Fase I]  ¿Quién eres? (sesión válida o 401)
│   │   └── RoleMiddleware.php           [Fase I]  ¿Puedes hacerlo? (RBAC, 403 + auditoría)
│   ├── servicios/                       [nuevo]   Reglas de negocio, probables sin HTTP
│   │   ├── ServicioAutenticacion.php    registrarUsuario, verificarCredenciales, iniciarSesion, cerrarSesion
│   │   ├── ServicioAdministracionUsuarios.php  cambio de rol, verificación, inicialización del principal
│   │   └── LimitadorIntentos.php        verificarLimiteIntentos (cuenta + origen)
│   ├── nucleo/                          [nuevo]   Infraestructura transversal
│   │   ├── arranque.php                 Autocargador, errores ocultos al usuario, .env
│   │   ├── Aplicacion.php               Ensambla dependencias y convierte excepciones en respuestas
│   │   ├── Enrutador.php                Rutas + orden de controles de seguridad
│   │   ├── GestorSesion.php             crearSesion, validarSesion, expirarSesion
│   │   ├── ProtectorCsrf.php            generarTokenCsrf, validarTokenCsrf
│   │   ├── GestorContrasenas.php        Argon2id / bcrypt, rehash, verificación ficticia
│   │   ├── ValidadorEntrada.php         validarDatosRegistro, listas blancas de campos
│   │   ├── Respuesta.php                Vista o redirección interna + encabezados de seguridad
│   │   ├── Vista.php                    Renderizado y escapar() (XSS)
│   │   ├── Solicitud.php                Método, ruta, IP (sin confiar en X-Forwarded-For)
│   │   ├── Configuracion.php            Lectura de .env / variables de entorno
│   │   ├── ExcepcionHttp.php            Errores 400/401/403/404/405 con mensajes genéricos
│   │   └── RegistroAplicacion.php       storage/logs/app.log sin secretos
│   └── views/                           [Fase I]  login.php, registro.php, inicio.php, error.php,
│                                                  admin/usuarios.php, parciales/
├── config/
│   ├── database.php                     [Fase I]  ÚNICA conexión PDO
│   ├── aplicacion.php                   [nuevo]   Constantes (MAX_INTENTOS_LOGIN, tiempos de sesión…)
│   ├── permisos.php                     [nuevo]   Matriz RBAC
│   └── rutas.php                        [nuevo]   Tabla de rutas con su permiso
├── database/
│   ├── schema.sql                       [Fase I]  Esquema 1.2 original
│   ├── usuario-aplicacion.sql           [nuevo]   Cuenta MariaDB de mínimos privilegios
│   └── migraciones/                     [nuevo]   001 y 002 con su reversión
├── herramientas/
│   └── inicializar-administrador-principal.php  [nuevo] Solo consola
├── storage/
│   ├── logs/                            [Fase I]  app.log, php-errores.log (no públicos)
│   └── sesiones/                        [nuevo]   Archivos de sesión propios (no compartidos)
├── public/
│   ├── index.php                        [Fase I]  Único PHP público; solo delega en Aplicacion
│   ├── .htaccess                        Reescritura a index.php, sin listados
│   └── css/portal.css
├── tests/                               [Fase I]  Marco propio + 3 suites
├── docs/
│   ├── evidencia-pruebas.txt            Salida real de la última ejecución
│   └── xampp/papc.conf                  Alias /papc → public/ para XAMPP compartido
├── .htaccess                            Red de seguridad: deniega todo si falta el Alias
├── .env.example                         Sin secretos
└── README.md
```

¿Por qué `servicios/` y `nucleo/`? Para no mezclar responsabilidades: el controlador solo traduce HTTP, el
servicio decide reglas (y se prueba sin navegador), el modelo solo habla SQL. No hay controladores,
modelos ni conexiones duplicados.

---

## Etapa 3. Base de datos

Orden de instalación: `schema.sql` → `001-clasificacion-usuario.sql` → `002-intento-acceso.sql` → `usuario-aplicacion.sql`.

### Migración 001 — clasificación institucional y versión de sesión
| Columna | Tipo | Por qué |
|---|---|---|
| `tipo_integrante_usuario` | `ENUM('ALUMNO','PROFESOR') NULL` | Clasificación institucional, **distinta del rol de seguridad**. `NULL` solo para cuentas previas a la migración. |
| `estado_verificacion_usuario` | `ENUM('NO_REQUERIDA','PENDIENTE','VERIFICADA','RECHAZADA')` | El navegador no prueba identidad: un profesor queda `PENDIENTE` hasta que un administrador lo resuelva. |
| `version_sesion_usuario` | `INT UNSIGNED DEFAULT 1` | Se incrementa al cambiar rol o contraseña; cada solicitud la compara con la de la sesión. |

Índice `idx_usuario_estado_verificacion_usuario`. Filas existentes: tipo `NULL`, estado `NO_REQUERIDA`, versión 1.

### Migración 002 — `intento_acceso`
Guarda **huellas HMAC-SHA256** del correo y de la IP (nunca el dato en claro), con índices compuestos
`(huella, fecha)`. Sin FK a `usuario` a propósito: se cuentan igual los intentos contra correos inexistentes,
lo que evita enumerar cuentas. Se purgan registros con más de un día.

### Respaldo y reversión
```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root -p papc_db > respaldo_papc_db_AAAAMMDD.sql
```
```powershell
Get-Content database\migraciones\002-intento-acceso-revertir.sql | C:\xampp\mysql\bin\mysql.exe -u root -p papc_db
```
```powershell
Get-Content database\migraciones\001-clasificacion-usuario-revertir.sql | C:\xampp\mysql\bin\mysql.exe -u root -p papc_db
```
Revertir 001 **borra** la clasificación de todas las cuentas; el módulo deja de funcionar hasta volver a migrar.
Aplicación y reversión de ambas migraciones fueron verificadas en MariaDB 11.5.2 (ver Etapa 6).

### Mínimos privilegios (`usuario-aplicacion.sql`)
`papc_app` tiene `SELECT, INSERT, UPDATE` en `usuario`; **solo `SELECT, INSERT`** en `auditoria` (no puede
borrar ni alterar la bitácora); `SELECT, INSERT, DELETE` en `intento_acceso`; nada más (sin `DROP`, `ALTER`,
`GRANT`, ni acceso a otras tablas todavía).

---

## Etapa 4. Implementación: cómo se relacionan los componentes

| Ruta (NOM-14) | Acceso | Permiso RBAC | Acción |
|---|---|---|---|
| `GET /` | público | — | Redirige a `/inicio` |
| `GET/POST /iniciar-sesion` | solo invitado | `iniciar_sesion` | `AuthController::mostrarFormularioLogin / procesarLogin` |
| `GET/POST /registro` | solo invitado | `registrarse` | `AuthController::mostrarFormularioRegistro / procesarRegistro` |
| `POST /cerrar-sesion` | autenticado | `cerrar_sesion` | `AuthController::cerrarSesion` |
| `GET /inicio` | autenticado | — | `AuthController::mostrarInicio` |
| `GET /admin/usuarios` | autenticado | `administrar_cuentas` | `AdminUsuarioController::listarUsuarios` |
| `POST /admin/usuarios/verificacion` | autenticado | `verificar_clasificacion` | `AdminUsuarioController::resolverVerificacion` |
| `POST /admin/usuarios/rol` | autenticado | `asignar_rol_administrativo` | `AdminUsuarioController::cambiarRol` |

En cada solicitud el `Enrutador` aplica, en orden: ruta/método (404/405) → `AuthMiddleware` → `RoleMiddleware`
→ token CSRF (todo POST) → controlador. Los controladores devuelven un objeto `Respuesta`; nunca imprimen ni
llaman a `exit()`, por eso son verificables en pruebas.

**Login** (`ServicioAutenticacion::iniciarSesion`): `verificarLimiteIntentos` → `verificarCredenciales`
(si el correo no existe se ejecuta `password_verify` contra un hash ficticio para igualar tiempos) →
`crearSesion` (regenera ID, limpia la sesión anónima, renueva CSRF) → auditoría `LOGIN_EXITOSO`.

**Validación de sesión** (`GestorSesion::validarSesion`, en cada solicitud): inactividad (30 min) →
duración máxima (8 h) → relee la cuenta de MariaDB → compara `version_sesion_usuario` → regenera el ID cada 15 min.
**El rol nunca se guarda en la sesión**; se lee de la BD en cada solicitud.

---

## Etapa 5. Instalación en el servidor XAMPP (`http://IP-DEL-SERVIDOR/papc/`)

Escenario: un solo XAMPP compartido por varios equipos; cada proyecto vive en `htdocs` y se abre como
`http://192.168.20.132/<proyecto>/`. Colocar el código en `htdocs` no es lo ideal, pero se resuelve de forma
segura con un **`Alias`**: la URL `/papc` entra **solo** a `public/`; el resto del proyecto queda denegado
aunque esté dentro de `htdocs`.

```
C:\xampp\htdocs\papc\           ← carpeta del proyecto (denegada por Apache)
├── public\                     ← lo único que se sirve como http://IP/papc/
├── app\  config\  database\  storage\  tests\  herramientas\   (privados)
└── .env                        ← se crea en el servidor; nunca se copia por chat o correo
```

1. **Copiar el proyecto** a `C:\xampp\htdocs\papc` (descomprime el zip de modo que exista
   `C:\xampp\htdocs\papc\public\index.php`).

2. **Configurar Apache** (una sola vez):
   - Copia `docs\xampp\papc.conf` a `C:\xampp\apache\conf\extra\papc.conf`.
   - Al **final** de `C:\xampp\apache\conf\httpd.conf` agrega la línea `Include conf/extra/papc.conf`.
   - En el mismo `httpd.conf` confirma que la línea `LoadModule rewrite_module modules/mod_rewrite.so` **no** empiece con `#`.
   - Reinicia Apache desde el panel de XAMPP. Si no arranca, revisa `C:\xampp\apache\logs\error.log`.

   El `papc.conf` contiene:
   ```apache
   Alias /papc "C:/xampp/htdocs/papc/public"
   <Directory "C:/xampp/htdocs/papc">
       Require all denied
       AllowOverride None
       Options None
   </Directory>
   <Directory "C:/xampp/htdocs/papc/public">
       Options SymLinksIfOwnerMatch
       AllowOverride All
       Require all granted
   </Directory>
   ```
   `Options SymLinksIfOwnerMatch` es obligatorio: sin él Apache prohíbe `mod_rewrite` en `.htaccess` y
   **todas** las páginas responden 403 (fallo encontrado y corregido al probar en Apache real).

3. **Comprobar Argon2id** en el PHP de XAMPP (si devuelve `false`, se usa bcrypt costo 12 y la contraseña se limita a 72 bytes):
   ```powershell
   C:\xampp\php\php.exe -r "var_dump(defined('PASSWORD_ARGON2ID'));"
   ```

4. **Crear la base** (desde `C:\xampp\htdocs\papc`). Si root de XAMPP no tiene contraseña, presiona Enter cuando la pida:
   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u root -p --default-character-set=utf8mb4 -e "CREATE DATABASE papc_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE papc_db; source database/schema.sql; source database/migraciones/001-clasificacion-usuario.sql; source database/migraciones/002-intento-acceso.sql;"
   ```

5. **Cuenta MariaDB de la aplicación** (mínimos privilegios). Genera una contraseña:
   ```powershell
   C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(24));"
   ```
   Copia `database\usuario-aplicacion.sql` a `C:\xampp\tmp\papc-usuario.sql`, reemplaza en la copia
   `CAMBIAR_POR_CONTRASENA_LARGA_Y_ALEATORIA` por la contraseña generada, ejecútala y **borra la copia**:
   ```powershell
   C:\xampp\mysql\bin\mysql.exe -u root -p -e "source C:/xampp/tmp/papc-usuario.sql"
   ```

6. **Crear el `.env`** en `C:\xampp\htdocs\papc` a partir de `.env.example`:
   `APP_RUTA_BASE=/papc` (ya viene así), `DB_CONTRASENA=` la del paso 5 y una `CLAVE_HUELLAS` nueva:
   ```powershell
   C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"
   ```

7. **Permitir el acceso desde la red**. En el servidor, en una PowerShell **como administrador**:
   ```powershell
   New-NetFirewallRule -DisplayName "Apache XAMPP (HTTP 80)" -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow -Profile Private,Domain
   ```

8. **Verificar desde otra computadora** de la red. `http://192.168.20.132/papc/` debe mostrar el inicio de
   sesión, y estas rutas deben responder **403 o 404** (nunca 200):
   ```powershell
   foreach ($r in '/papc/.env','/papc/.git/config','/papc/config/database.php','/papc/database/schema.sql','/papc/storage/logs/','/papc/tests/ejecutar-pruebas.php','/PAPC/.env','/papc/css/') { curl.exe -s -o NUL -w "$r -> %{http_code}`n" "http://192.168.20.132$r" }
   ```
   Si alguna da 200, **detén el servidor** y revisa los pasos 2 y 6 antes de continuar.

9. **Primer administrador principal** (no hay cuentas ni contraseñas predeterminadas): regístrate en
   `http://192.168.20.132/papc/registro` con tu propia contraseña y, en la consola del servidor:
   ```powershell
   C:\xampp\php\php.exe C:\xampp\htdocs\papc\herramientas\inicializar-administrador-principal.php tu.correo@dominio.mx
   ```
   Solo funciona si aún no hay ningún principal; queda auditado como `INICIALIZACION_ADMINISTRADOR_PRINCIPAL`.
   Después, todo cambio de rol se hace en `/papc/admin/usuarios` con re-autenticación.

### Red de seguridad si alguien olvida el `Alias`
Si el proyecto se copia a `htdocs` sin el paso 2, el `.htaccess` de la raíz deniega todo (`.env`, `.git`,
`config/`…) y solo `public/` responde, en `http://IP/papc/public/` (con `APP_RUTA_BASE=/papc/public`).
Esto también se verificó en Apache real, pero **no** sustituye al paso 2.

### Alternativa: VirtualHost propio
Si algún día el proyecto tiene su propio dominio, basta un VirtualHost con `DocumentRoot` hacia
`papc/public`, las mismas secciones `<Directory>` del `papc.conf` y `APP_RUTA_BASE=` vacío.

### Consideraciones del servidor compartido
- **Cookie aislada**: la cookie de sesión lleva `Path=/papc/`, así que el navegador no la envía a los
  proyectos de los otros equipos.
- **Sesiones propias**: se guardan en `storage/sesiones/` y no en `C:\xampp\tmp`, donde el recolector de
  basura de otra aplicación podría borrarlas antes de tiempo.
- **Límite que no puede resolverse por código**: todos los proyectos se ejecutan dentro del mismo proceso de
  Apache, así que el PHP de otro equipo **podría leer** tu `.env` o tus sesiones en disco. Por eso la cuenta
  `papc_app` tiene mínimos privilegios y sus contraseñas no deben reutilizarse en ningún otro lugar. Para
  aislar de verdad haría falta un servidor (o máquina virtual) por proyecto.

### Desarrollo local vs. producción
| Aspecto | Desarrollo (`APP_ENTORNO=desarrollo`) | Producción (`APP_ENTORNO=produccion`) |
|---|---|---|
| Transporte | HTTP. **No equivale a HTTPS**: en la red del laboratorio la contraseña y la cookie viajan sin cifrar. | HTTPS obligatorio (puerto 443; XAMPP trae un certificado autofirmado que el navegador marcará como no confiable). |
| Cookie | `papc_sesion`, `Path=/papc/`, `HttpOnly`, `SameSite=Lax`, sin `Secure` | `__Secure-papc_sesion` (o `__Host-papc_sesion` si se publica en `/`), `Secure`, `HttpOnly`, `SameSite=Lax` |
| HSTS | No | `Strict-Transport-Security` de 1 año |
| Errores | Ocultos al usuario; en `storage/logs/` | Igual; además `display_errors=Off` en `php.ini` |

En producción la cookie `Secure` no se envía por HTTP, de modo que el login **no funciona sin HTTPS**: es intencional.

---

## Etapa 6. Pruebas

### Cómo ejecutarlas
```powershell
C:\xampp\php\php.exe tests\ejecutar-pruebas.php unitarias
```
```powershell
$env:PAPC_PRUEBAS_DB_USUARIO='root'; $env:PAPC_PRUEBAS_DB_CONTRASENA=''; C:\xampp\php\php.exe tests\ejecutar-pruebas.php todas
```
Las suites de integración y HTTP **crean y borran** la base `papc_db_pruebas` y una cuenta temporal
`papc_app_pruebas@127.0.0.1` (se elimina al terminar). Se niegan a operar sobre cualquier base que no termine
en `_pruebas`. La suite HTTP levanta `php -S` en el puerto 8097 con DocumentRoot `public/`.

### Entorno de la ejecución real
Las pruebas se ejecutaron el **19/11/2026** con **PHP 8.3.30 (Laragon) y MariaDB 11.5.2** en una instancia
temporal aislada (localhost:3399), **no en XAMPP**. Resultado: **43 de 43 pasan** (`docs/evidencia-pruebas.txt`).

**Verificación en Apache real** (Apache 2.4.66 + PHP 8.3 como módulo, igual que XAMPP), con un `htdocs`
configurado como el de XAMPP (`AllowOverride All`, `Options Indexes`), el `docs/xampp/papc.conf` sin cambios
salvo la ruta, y la base instalada siguiendo la Etapa 5:

| ID | Comprobación | Resultado |
|---|---|---|
| A01 | `/papc` → `/papc/` → login; enlaces y CSS con prefijo `/papc` | Pasa |
| A02 | Registro, login, cookie `path=/papc/; HttpOnly; SameSite=Lax`, 403 en admin para USUARIO, logout y cookie vieja inválida | Pasa |
| A03 | 24 rutas privadas con Alias (incl. `/PAPC/.env`, `/papc/%2e%2e/.env`, `/papc./.env`, `.git`, `storage/sesiones`) → 403/404 sin contenido | Pasa |
| A04 | Sin Alias: `.htaccess` de la raíz deniega 9 rutas privadas; `public/` sigue funcionando | Pasa |

Este script de humo se ejecutó desde fuera del repositorio; no se ha probado aún desde **otra computadora**
por la red, ni en el XAMPP del servidor (paso 8 de la Etapa 5).

### Correspondencia con las 17 pruebas solicitadas
| # | Prueba solicitada | IDs | Entrada principal | Resultado esperado | Observado |
|---|---|---|---|---|---|
| 1 | Registro de alumno | P01, H01 | Formulario válido tipo ALUMNO | Rol USUARIO, `NO_REQUERIDA`, auditado | Pasa |
| 2 | Registro de profesor sin permisos extra | P02, H01 | Tipo PROFESOR | Rol USUARIO, `PENDIENTE`, sin permisos administrativos | Pasa |
| 3 | Correos duplicados | P03, H01 | Mismo correo, también en mayúsculas | Rechazo (409), una sola fila | Pasa |
| 4 | Campos inválidos | P04 | 13 casos (vacíos, longitudes, arreglos, UTF-8 inválido, tipo no permitido) | Error por campo | Pasa |
| 5 | Almacenamiento seguro | P05a, P05b | Contraseña de 30 caracteres | `$argon2id$m=65536,t=4,p=1`, salado, sin texto claro en BD ni auditoría | Pasa |
| 6 | Login válido | P06, P06b | Credenciales correctas | 303 a `/inicio`, ID de sesión regenerado, cookie HttpOnly/SameSite | Pasa |
| 7 | Credenciales incorrectas | P07, P07b | Contraseña mala / correo inexistente | Mismo código (401) y mismo mensaje | Pasa |
| 8 | Cierre de sesión e invalidación | P08a, P08b | Reutilizar la cookie anterior al logout | Archivo de sesión destruido; la cookie vieja redirige al login | Pasa |
| 9 | Rutas protegidas sin autenticación | P09a, P09b | GET/POST sin cookie | GET → 303 al login; POST → 401; 404/405 | Pasa |
| 10 | Elevar rol con formulario manipulado | P10a, P10b | `rol_usuario=ADMINISTRADOR_PRINCIPAL` en `/registro` | 400, sin cuenta, `INTENTO_ESCALADA_REGISTRO` | Pasa |
| 11 | Operaciones administrativas por USUARIO | P11a, P11b | USUARIO en `/admin/*` | 403 sin cambios | Pasa |
| 12 | ADMINISTRADOR cambia roles | P12a, P12b | Elevar a otro y autoelevarse | 403, rol intacto, `INTENTO_CAMBIO_PRIVILEGIO_DENEGADO` | Pasa |
| 13 | Tokens CSRF | P13a, P13b | Sin token, token de otra sesión, arreglo, mayúsculas | 400 auditado; logout forzado rechazado | Pasa |
| 14 | Inyección SQL y XSS | P14a, P14b, P14c | `' OR '1'='1' -- `, `"><script>…` | Login rechazado; salida escapada; CSP; sin errores internos | Pasa |
| 15 | Intentos repetidos | P15, P15b | 5 fallos por cuenta; 20 por origen | Limitado (429) aun con contraseña correcta; se libera al salir de la ventana | Pasa |
| 16 | Archivos privados por HTTP | P16, H02 | 15 rutas privadas, `..` codificado, `/css/` | 404 sin contenido; sin listado | Pasa (servidor embebido) |
| 17 | Revocación de privilegios | P17a, P17b | Principal revoca ADMINISTRADOR con sesión abierta | La sesión se invalida; nueva sesión → 403 | Pasa |

Pruebas adicionales: P18 (re-autenticación y prohibición de cambiar el propio rol), P19 (inicialización única
del principal), P20 (expiración por inactividad y duración máxima), P22 (mínimo privilegio en MariaDB),
P23 (verificación de profesor sin cambio de rol), U01–U05 (validación, parámetros inesperados, redirección
abierta, matriz RBAC, publicación en /papc).

### ¿Las pruebas detectan fallos? (prueba de mutación ejecutada)
En una copia desechable se introdujo un defecto a la vez:

| Defecto introducido | Pruebas que fallaron |
|---|---|
| `tienePermiso()` siempre devuelve `true` | 9 (U04, P02, P11a/b, P12a/b, P17b, P18, P23) |
| Sin comparar `version_sesion_usuario` | P17a, P17b |
| Sin validar CSRF | P13b |
| `escapar()` sin `htmlspecialchars` | P14a, P14c |
| Sin `session_regenerate_id()` al autenticar | P06, P06b |

### Fallos encontrados durante el desarrollo
- Una mutación no se aplicó por un problema de escape del comando `sed`; se detectó y repitió aplicándola con PHP.
- La cuenta de pruebas se creaba con host `'%'`; se restringió a `127.0.0.1` y se elimina al terminar.
- **En Apache real todas las páginas respondían 403** con el `Alias`: `Options None` en la carpeta del proyecto
  desactivaba `FollowSymLinks` también en `public/` y Apache prohibía `mod_rewrite`. Se corrigió con
  `Options SymLinksIfOwnerMatch` en `public/`. El servidor embebido de PHP no podía detectar este fallo.

### Pruebas diseñadas pero NO ejecutadas
- **XAMPP del servidor y acceso desde otra computadora**: ejecuta el paso 8 de la Etapa 5.
- **HTTPS y cookie `__Host-` con `Secure`**: requiere certificado; no se probó.
- **Prueba de tiempos** (igualdad estadística de tiempos de respuesta con correo existente/inexistente): implementada la mitigación, no medida.

---

## Etapa 7. Verificación final

### Reglas NOM-01 a NOM-15 (ejemplos reales del código)
Comprobación automática ejecutada: ninguna variable PHP con guion bajo o mayúscula inicial, ningún método
fuera de camelCase, ninguna clase CSS fuera de kebab-case.

| Regla | Ejemplo real | Archivo |
|---|---|---|
| NOM-01 BD minúsculas snake_case | `papc_db`, `papc_db_pruebas` | `.env.example`, `tests/BaseDatosPruebas.php` |
| NOM-02 Tablas en singular | `usuario`, `auditoria`, `intento_acceso` | `database/migraciones/002-intento-acceso.sql` |
| NOM-03 PK `id_<entidad>` | `id_intento_acceso` | migración 002 |
| NOM-04 FK descriptiva | Ninguna FK nueva (decisión justificada); existentes intactas: `id_usuario_actor` | `schema.sql` |
| NOM-05 Columna atributo + entidad | `tipo_integrante_usuario`, `huella_origen_intento_acceso` | migraciones 001 y 002 |
| NOM-06 `uq_<tabla>_<campo>` | `uq_usuario_correo_electronico_usuario` (usada por el registro) | `schema.sql` |
| NOM-07 `idx_<tabla>_<campo>` | `idx_usuario_estado_verificacion_usuario`, `idx_intento_acceso_fecha_intento_acceso` | migraciones |
| NOM-08 `fk_<origen>_<relación>` | Sin FK nuevas; `fk_auditoria_usuario_actor` intacta | `schema.sql` |
| NOM-09 Estados en MAYÚSCULAS | `PENDIENTE`, `VERIFICADA`, `NO_REQUERIDA`, `ALUMNO`, `PROFESOR` | `UsuarioModel.php` |
| NOM-10 Clases PascalCase | `AuthController`, `UsuarioModel`, `RoleMiddleware`, `GestorSesion` | `app/` |
| NOM-11 Métodos camelCase | `registrarUsuario()`, `verificarCredenciales()`, `verificarLimiteIntentos()` | servicios |
| NOM-12 Variables camelCase | `$idUsuarioActor`, `$correoNormalizado`, `$rolUsuario` | `ServicioAdministracionUsuarios.php` |
| NOM-13 Constantes MAYÚSCULAS | `MAX_INTENTOS_LOGIN`, `TIEMPO_INACTIVIDAD_SESION_SEGUNDOS` | `config/aplicacion.php` |
| NOM-14 Rutas/archivos kebab-case | `/iniciar-sesion`, `/cerrar-sesion`, `inicializar-administrador-principal.php` | `config/rutas.php` |
| NOM-15 CSS kebab-case | `tarjeta-formulario`, `boton-principal`, `mensaje-error` | `public/css/portal.css` |

Nota: los nombres de campos HTML (`correo_electronico`, `token_csrf`) usan snake_case para coincidir con las
columnas; NOM-12 aplica a variables PHP/JS, que sí cumplen. No hay JavaScript en el módulo.

### Cumplimiento de la matriz RBAC
| Permiso | Invitado | Usuario | Admin | Principal | Prueba |
|---|---|---|---|---|---|
| Registrarse / iniciar sesión | Sí | — | — | — | U04, P06b |
| Cerrar sesión | No | Sí | Sí | Sí | P08b, P09b |
| Administrar cuentas (listar) | No | No | Sí | Sí | P11b, P12b |
| Verificar clasificación de profesor | No | No | Sí | Sí | P23, P11b |
| Asignar/revocar rol administrativo | No | No | No | Sí | P12a/b, P18, P17b |

Los permisos de avisos, comentarios, favoritos y propuestas están declarados en `config/permisos.php` según la
Fase I, pero **sus rutas no existen** y la **comprobación de propiedad** de cada recurso deberá implementarse
en esos módulos.

### Controles OWASP aplicados
| Área | Control | Dónde |
|---|---|---|
| A05 Inyección (SQL) | PDO con `ATTR_EMULATE_PREPARES=false`, parámetros vinculados, `sql_mode` estricto | `config/database.php`, modelos |
| A04 Fallas criptográficas (Password Storage) | Argon2id (64 MiB, t=4, p=1), `password_needs_rehash`, respaldo bcrypt sin truncar | `GestorContrasenas.php` |
| A07 Fallas de autenticación | Mínimo 15 caracteres sin reglas de composición, máx. 128; mensaje genérico; tiempo igualado; límite por cuenta y origen sin bloqueo permanente | `ValidadorEntrada`, `ServicioAutenticacion`, `LimitadorIntentos` |
| A07 · Session Management | Modo estricto, solo cookies, sin SID en URL, regeneración al autenticar y cada 15 min, inactividad 30 min, máximo 8 h, destrucción en servidor al salir, invalidación por versión | `GestorSesion.php` |
| CSRF | Token de 256 bits por sesión, `hash_equals`, en todos los POST incluidos login, registro y logout | `ProtectorCsrf`, `Enrutador` |
| A05 Inyección (XSS) | `htmlspecialchars(ENT_QUOTES\|ENT_SUBSTITUTE)` en toda salida; CSP sin scripts en línea | `Vista.php`, `Respuesta.php` |
| A01 Control de acceso | Denegación por defecto, rol leído de BD en cada solicitud, listas blancas de campos, re-autenticación para cambios de rol, sin autoasignación | `RoleMiddleware`, servicios |
| Redirección abierta | Solo rutas internas validadas | `Respuesta::esRutaInternaSegura` |
| A09 Fallas de registro y alertas | Auditoría en BD de 15 tipos de evento sin secretos; bitácora técnica sin saltos de línea inyectables | `AuditoriaModel`, `RegistroAplicacion` |
| A02 Configuración incorrecta · A10 Manejo de condiciones excepcionales | `display_errors=0`, errores genéricos, `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `no-store`, sin `X-Powered-By` | `arranque.php`, `Respuesta.php` |
| Mínimo privilegio | Cuenta MariaDB sin DROP/ALTER; auditoría de solo anexado | `usuario-aplicacion.sql` (probado en P22) |

Esto **no** constituye certificación de cumplimiento de OWASP ASVS 5.0: es una lista de controles
implementados y probados en el alcance indicado.

### Limitaciones y controles pendientes
1. **Verificación institucional de profesores**: hoy es una revisión manual de un administrador. Falta un mecanismo
   institucional (p. ej. lista oficial de la universidad o validación por correo + documento). Mientras tanto,
   `VERIFICADA` **no concede ningún beneficio** en el sistema.
2. **Confirmación de correo** y **recuperación de contraseña**: no implementadas (requieren servidor de correo).
3. **Cambio de contraseña por el usuario**: no hay pantalla; `UsuarioModel::actualizarHashContrasena(..., true)`
   ya invalida sesiones y está listo para usarse.
4. **Lista de contraseñas comprometidas** (ASVS pide rechazar las más comunes): pendiente.
5. **MFA**: no implementado; por eso la longitud mínima es 15.
6. **Enumeración en el registro**: el mensaje de duplicado es genérico, pero un registro fallido sigue insinuando
   que el correo existe. La solución completa requiere correo de confirmación. El registro tampoco tiene límite de frecuencia.
7. **Suspensión de cuentas** por administradores: no implementada.
8. **Concurrencia**: `session_regenerate_id(true)` periódico puede cerrar solicitudes simultáneas de una misma pestaña en casos raros.
9. **Proxy inverso**: el origen se toma de `REMOTE_ADDR`; si se usa un proxy, ajustar `Solicitud::obtenerOrigen()`.
10. Pendiente de ejecutar en el XAMPP del servidor: Etapa 5 paso 8 y las pruebas BD-01 a BD-06 de la Fase I sobre `papc_db`.
11. **XAMPP compartido**: el código de otros proyectos del mismo Apache puede leer los archivos de PAPC
    (`.env`, sesiones). Solo se mitiga con mínimos privilegios y contraseñas no reutilizadas; aislarlo requiere otro servidor.
12. **HTTP en la red del laboratorio**: sin HTTPS, contraseñas y cookies viajan sin cifrar entre las computadoras.

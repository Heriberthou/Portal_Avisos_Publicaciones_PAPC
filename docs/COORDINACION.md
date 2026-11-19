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

- **Tarea en curso:** ninguna. Se terminó la implementación de la especificación 1.3
  (solicitud de acceso → verificación de correo → aprobación → cuenta INVITADA → activación →
  login con segundo factor para administradores; recuperación, estados de cuenta, roles y bitácora).
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

- **Tarea en curso:** (pendiente de anotar)
- **Archivos:** (pendiente de anotar)

## Base de datos

| Migración | Estado en el repositorio | Estado en el servidor `equipo_7` |
|---|---|---|
| 001, 002, 003 | Confirmadas | Aplicadas |
| 004 (solicitud de acceso, tokens, estados, segundo factor) | Confirmada en `2cc8ddf` | **No aplicada** (debe aplicarse antes del `git pull`) |

Regla: solo un agente crea o modifica migraciones a la vez, y ninguna migración ya aplicada en el
servidor se edita; los cambios se hacen con una migración nueva numerada.

## Avisos

- El 19/11/2026 otra sesión de Claude hizo commits en paralelo en `feature/diseno` (`4c88521`, `8c84573`),
  y GitHub Desktop guardó cambios en `stash@{0}`. El contenido ya está integrado; el stash queda solo
  como respaldo y puede borrarse cuando la persona responsable lo confirme.
- No se usan las credenciales reales de la base de datos en ningún archivo del repositorio.

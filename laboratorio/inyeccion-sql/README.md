# Laboratorio E8 · Consulta vulnerable vs. consulta preparada

Evidencia para **E8-P07** ("Comparación técnica"). Solo se ejecuta por consola,
con datos ficticios en una tabla **temporal**; no toca la tabla `usuario`.

```
php laboratorio/inyeccion-sql/demostracion.php            # SQLite en memoria
php laboratorio/inyeccion-sql/demostracion.php --mariadb  # tabla temporal en la base del .env
```

`laboratorio/` está fuera de `public/` y tiene `Require all denied`: no se puede abrir por HTTP.

## Código comparado

**❌ Vulnerable (`iniciarSesionVulnerable`)**: la entrada se pega dentro del SQL.
```php
$sql = "SELECT ... FROM laboratorio_usuario_e8 WHERE correo_laboratorio = '" . $correo . "'";
$filas = $conexion->query($sql)->fetchAll();
```

**✅ Corregida (`iniciarSesionSegura`)**: estructura fija y parámetro enlazado.
```php
$sentencia = $conexion->prepare('SELECT ... WHERE correo_laboratorio = :correo LIMIT 1');
$sentencia->bindValue(':correo', $correo, PDO::PARAM_STR);
$sentencia->execute();
```

La aplicación real usa el mismo patrón en `app/models/UsuarioModel.php`
(`obtenerUsuarioPorCorreo()`), con `PDO::ATTR_EMULATE_PREPARES = false`
(`config/database.php`), y verifica la contraseña con `password_verify()`.

## Resultado de la demostración

| Caso | Entrada (correo) | ❌ Vulnerable | ✅ Preparada |
|---|---|---|---|
| E8-P01 | `ana@laboratorio.test` + clave correcta | Acceso | Acceso |
| E8-P01b | `ana@laboratorio.test` + clave incorrecta | Rechazado | Rechazado |
| E8-P02 | `o'brien@laboratorio.test` (comilla legítima) | **Error SQL visible** (revela la consulta) | Acceso correcto: la comilla es un dato |
| E8-P03 | `' OR '1'='1' -- ` | **Consulta alterada**: devuelve las 3 cuentas | 0 filas, rechazado |
| E8-P03b | `nadie' UNION SELECT 1, 'admin@…', '<hash del atacante>' -- ` | **Acceso como administrador** sin conocer su contraseña | 0 filas, rechazado |
| E8-P04 | `no.existe@laboratorio.test` | Rechazado | Rechazado |

Conclusión: el caso UNION demuestra que **hashear contraseñas no basta** si la
consulta se arma por concatenación: el atacante sustituye la fila y su propio
hash pasa `password_verify()`. Con la consulta preparada, la entrada completa se
compara como texto y nunca cambia la estructura del SQL.

## Respuestas breves (preguntas de coevaluación E8)

1. **SQL Injection**: insertar instrucciones SQL en un dato de entrada para cambiar la consulta.
2. **Cómo separan los parámetros**: el SQL se envía y compila primero con `:correo`; el valor viaja después, por separado, y el motor lo trata solo como dato.
3. **Por qué validar caracteres no basta**: hay entradas legítimas con `'` (O'Brien) y siempre existe otra forma de codificar el ataque; la defensa correcta es separar código y datos.
4. **Concatenar vs. preparar**: concatenar mezcla datos con código; preparar fija el código y enlaza los datos.
5. **Cómo se demuestra**: la prueba U10 y esta demostración ejecutan las mismas entradas maliciosas contra ambas versiones; solo la vulnerable cambia el número de filas o concede acceso.

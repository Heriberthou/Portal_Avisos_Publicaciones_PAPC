<?php

declare(strict_types=1);

/**
 * Lee la configuración privada desde variables de entorno y el archivo .env.
 *
 * Orden de prioridad: variable de entorno real del proceso > valor del .env.
 * Así, un servidor de producción puede inyectar secretos sin escribirlos en
 * disco. No se usa putenv() para no exponer secretos a procesos hijos.
 */
final class Configuracion
{
    /** @var array<string, string> Valores leídos del archivo .env. */
    private static array $valores = [];

    /**
     * Carga un archivo con líneas CLAVE=valor. Ignora comentarios (#) y
     * líneas vacías. Si el archivo no existe no hace nada (las variables
     * pueden venir del entorno).
     *
     * @param string $rutaArchivo Ruta absoluta al archivo .env.
     * @return void
     */
    public static function cargarArchivoEntorno(string $rutaArchivo): void
    {
        if (!is_file($rutaArchivo) || !is_readable($rutaArchivo)) {
            return;
        }

        $lineas = file($rutaArchivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lineas as $linea) {
            $linea = trim($linea);
            if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = explode('=', $linea, 2);
            self::$valores[trim($clave)] = trim(trim($valor), "\"'");
        }
    }

    /**
     * Fija valores en memoria (utilizado por las pruebas automatizadas).
     *
     * @param array<string, string> $valoresNuevos Pares clave => valor.
     * @return void
     */
    public static function definirValores(array $valoresNuevos): void
    {
        foreach ($valoresNuevos as $clave => $valor) {
            self::$valores[$clave] = $valor;
        }
    }

    /**
     * Obtiene un valor de configuración.
     *
     * @param string $clave Nombre de la variable (p. ej. DB_SERVIDOR).
     * @param string|null $valorPredeterminado Valor si no está definida.
     * @return string|null Valor encontrado o el predeterminado.
     */
    public static function obtener(string $clave, ?string $valorPredeterminado = null): ?string
    {
        $valorEntorno = getenv($clave);
        if ($valorEntorno !== false && $valorEntorno !== '') {
            return $valorEntorno;
        }
        return self::$valores[$clave] ?? $valorPredeterminado;
    }

    /**
     * Obtiene un valor que DEBE existir; si falta, la aplicación falla
     * cerrada en lugar de continuar con una configuración insegura.
     *
     * @param string $clave Nombre de la variable.
     * @return string Valor no vacío.
     * @throws RuntimeException Si la variable no está definida.
     */
    public static function obtenerObligatorio(string $clave): string
    {
        $valor = self::obtener($clave);
        if ($valor === null || $valor === '') {
            throw new RuntimeException("Falta la variable de configuración obligatoria {$clave}.");
        }
        return $valor;
    }

    /**
     * Indica si la aplicación corre en modo producción.
     *
     * @return bool true si APP_ENTORNO=produccion.
     */
    public static function esProduccion(): bool
    {
        return self::obtener('APP_ENTORNO', 'desarrollo') === 'produccion';
    }
}

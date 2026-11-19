<?php

declare(strict_types=1);

/**
 * Renderizado de plantillas de app/views con escape de salida.
 *
 * Las plantillas viven fuera de public/, por lo que no pueden solicitarse
 * directamente por HTTP. Toda variable mostrada debe pasar por
 * Vista::escapar() (prevención de XSS).
 */
final class Vista
{
    /** Formato permitido para nombres de vista (evita recorrido de rutas). */
    private const PATRON_NOMBRE_VISTA = '#^[a-z0-9\-]+(/[a-z0-9\-]+)*$#';

    /**
     * Renderiza una vista dentro del diseño común (encabezado y pie).
     *
     * @param string $nombreVista Ruta relativa a app/views, sin ".php".
     * @param array<string, mixed> $datosVista Variables disponibles en la vista.
     * @return string HTML generado.
     * @throws RuntimeException Si el nombre de vista no es válido.
     */
    public static function renderizar(string $nombreVista, array $datosVista = []): string
    {
        $contenidoPrincipal = self::incluirPlantilla($nombreVista, $datosVista);
        return self::incluirPlantilla('parciales/encabezado', $datosVista)
            . $contenidoPrincipal
            . self::incluirPlantilla('parciales/pie', $datosVista);
    }

    /**
     * Escapa texto para contexto HTML (contenido y atributos entre comillas).
     *
     * ENT_QUOTES escapa comillas simples y dobles; ENT_SUBSTITUTE reemplaza
     * secuencias UTF-8 inválidas en lugar de devolver una cadena vacía.
     *
     * @param mixed $valor Valor a mostrar.
     * @return string Texto seguro para insertarse en HTML.
     */
    public static function escapar(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Construye una URL interna anteponiendo APP_RUTA_BASE.
     *
     * @param string $rutaInterna Ruta que empieza con "/".
     * @return string URL relativa al sitio.
     */
    public static function url(string $rutaInterna): string
    {
        $rutaBase = rtrim((string) Configuracion::obtener('APP_RUTA_BASE', ''), '/');
        return $rutaBase . $rutaInterna;
    }

    /**
     * Genera el campo oculto con el token CSRF para un formulario POST.
     *
     * @return string HTML del input oculto.
     */
    public static function campoTokenCsrf(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            ProtectorCsrf::NOMBRE_CAMPO_TOKEN,
            self::escapar(ProtectorCsrf::generarTokenCsrf())
        );
    }

    /**
     * Ejecuta una plantilla en un ámbito aislado y captura su salida.
     *
     * @param string $nombreVista Nombre validado de la plantilla.
     * @param array<string, mixed> $datosVista Datos de la plantilla.
     * @return string HTML producido.
     * @throws RuntimeException Si el nombre no es válido o no existe.
     */
    private static function incluirPlantilla(string $nombreVista, array $datosVista): string
    {
        if (preg_match(self::PATRON_NOMBRE_VISTA, $nombreVista) !== 1) {
            throw new RuntimeException('Nombre de vista no válido.');
        }
        $rutaPlantilla = RUTA_RAIZ . '/app/views/' . $nombreVista . '.php';
        if (!is_file($rutaPlantilla)) {
            throw new RuntimeException('La vista solicitada no existe.');
        }

        $ejecutarPlantilla = static function (string $rutaPlantilla, array $datosVista): void {
            // Las vistas leen sus datos desde $datosVista (sin extract(), para
            // no sobrescribir variables por accidente).
            require $rutaPlantilla;
        };

        ob_start();
        $ejecutarPlantilla($rutaPlantilla, $datosVista);
        return (string) ob_get_clean();
    }
}

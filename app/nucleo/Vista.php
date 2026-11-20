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

    /** Diseño de las páginas internas: barra superior y tarjeta. */
    public const DISENO_GENERAL = 'general';

    /** Diseño de las pantallas de acceso: fondo de playa y tarjeta de vidrio. */
    public const DISENO_ACCESO = 'acceso';

    /**
     * Íconos de línea (24×24) usados junto a etiquetas y botones. Son
     * decorativos: el texto de la etiqueta sigue siendo el que se lee.
     */
    private const ICONOS = [
        'correo'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'candado'    => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'persona'    => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'credencial' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2"/><path d="M6 16a3 3 0 0 1 6 0M14 10h4M14 14h4"/>',
        'escudo'     => '<path d="M12 3 5 6v5c0 4.5 3 8.5 7 10 4-1.5 7-5.5 7-10V6z"/><path d="m9 12 2 2 4-4"/>',
        'entrar'     => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 16l4-4-4-4M14 12H4"/>',
        'enviar'     => '<path d="M21 3 10 14"/><path d="M21 3 14 21l-4-7-7-4z"/>',
    ];

    /** Diseño elegido por la vista que se está renderizando. */
    private static string $disenoActual = self::DISENO_GENERAL;

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
        // La vista se ejecuta primero: así puede elegir su diseño
        // (Vista::usarDiseno) antes de que se genere el encabezado.
        self::$disenoActual = self::DISENO_GENERAL;
        $contenidoPrincipal = self::incluirPlantilla($nombreVista, $datosVista);
        return self::incluirPlantilla('parciales/encabezado', $datosVista)
            . $contenidoPrincipal
            . self::incluirPlantilla('parciales/pie', $datosVista);
    }

    /**
     * Elige el diseño de la página. Lo llaman las vistas (por ejemplo,
     * parciales/acceso-inicio.php) antes de generar su contenido.
     *
     * @param string $diseno Vista::DISENO_GENERAL o Vista::DISENO_ACCESO.
     * @return void
     * @throws InvalidArgumentException Si el diseño no existe.
     */
    public static function usarDiseno(string $diseno): void
    {
        if (!in_array($diseno, [self::DISENO_GENERAL, self::DISENO_ACCESO], true)) {
            throw new InvalidArgumentException('Diseño de página no válido.');
        }
        self::$disenoActual = $diseno;
    }

    /**
     * @return string Diseño de la página que se está renderizando.
     */
    public static function obtenerDiseno(): string
    {
        return self::$disenoActual;
    }

    /**
     * Devuelve un ícono SVG en línea, oculto para lectores de pantalla.
     *
     * @param string $nombre Clave de Vista::ICONOS.
     * @return string HTML del ícono (cadena vacía si no existe).
     */
    public static function icono(string $nombre): string
    {
        if (!isset(self::ICONOS[$nombre])) {
            return '';
        }
        return '<svg class="icono" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            . self::ICONOS[$nombre] . '</svg>';
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
        return Solicitud::obtenerRutaBase() . $rutaInterna;
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

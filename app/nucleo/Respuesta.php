<?php

declare(strict_types=1);

/**
 * Resultado que un controlador devuelve: una vista o una redirección.
 *
 * Los controladores no imprimen ni envían encabezados directamente; esto
 * permite probarlos y concentra aquí los encabezados de seguridad y la
 * prevención de redirecciones abiertas.
 */
final class Respuesta
{
    /**
     * @param int $codigoEstado Código HTTP.
     * @param string|null $nombreVista Vista a renderizar (si no es redirección).
     * @param array<string, mixed> $datosVista Variables para la vista.
     * @param string|null $rutaRedireccion Ruta interna destino.
     */
    private function __construct(
        public readonly int $codigoEstado,
        public readonly ?string $nombreVista,
        public readonly array $datosVista,
        public readonly ?string $rutaRedireccion
    ) {
    }

    /**
     * Crea una respuesta que renderiza una vista.
     *
     * @param string $nombreVista Nombre relativo a app/views (sin .php).
     * @param array<string, mixed> $datosVista Datos para la plantilla.
     * @param int $codigoEstado Código HTTP.
     * @return self
     */
    public static function vista(string $nombreVista, array $datosVista = [], int $codigoEstado = 200): self
    {
        return new self($codigoEstado, $nombreVista, $datosVista, null);
    }

    /**
     * Crea una redirección 303 hacia una ruta INTERNA.
     *
     * Prevención de redirección abierta: si la ruta no es interna y segura,
     * se redirige a la página de inicio en su lugar.
     *
     * @param string $rutaInterna Ruta que debe empezar con "/".
     * @return self
     */
    public static function redireccion(string $rutaInterna): self
    {
        $rutaSegura = self::esRutaInternaSegura($rutaInterna) ? $rutaInterna : '/';
        return new self(303, null, [], $rutaSegura);
    }

    /**
     * Determina si una ruta apunta exclusivamente a este sitio.
     *
     * Rechaza URLs absolutas (https://...), relativas al protocolo (//sitio),
     * barras invertidas (\\sitio, que algunos navegadores tratan como "/"),
     * caracteres de control y esquemas como javascript:.
     *
     * @param string $ruta Ruta candidata.
     * @return bool true si es interna y segura.
     */
    public static function esRutaInternaSegura(string $ruta): bool
    {
        return preg_match('#^/(?![/\\\\])[A-Za-z0-9\-._~/]*$#', $ruta) === 1;
    }

    /**
     * Envía encabezados y cuerpo al navegador.
     *
     * @return void
     */
    public function enviar(): void
    {
        self::aplicarEncabezadosSeguridad();
        http_response_code($this->codigoEstado);

        if ($this->rutaRedireccion !== null) {
            header('Location: ' . Vista::url($this->rutaRedireccion));
            return;
        }
        echo Vista::renderizar((string) $this->nombreVista, $this->datosVista);
    }

    /**
     * Encabezados HTTP de endurecimiento para todas las respuestas.
     *
     *  - CSP restrictiva: solo recursos propios, sin scripts en línea, sin
     *    marcos (mitiga XSS y clickjacking).
     *  - nosniff, Referrer-Policy y no-store para páginas con datos de sesión.
     *  - HSTS únicamente en producción con HTTPS.
     *
     * @return void
     */
    public static function aplicarEncabezadosSeguridad(): void
    {
        if (headers_sent()) {
            return;
        }
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=UTF-8');
        header_remove('X-Powered-By');
        if (Configuracion::esProduccion() && Solicitud::esHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}

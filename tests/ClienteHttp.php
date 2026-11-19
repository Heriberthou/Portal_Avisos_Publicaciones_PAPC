<?php

declare(strict_types=1);

/**
 * Cliente HTTP de pruebas que se comporta como un navegador sencillo:
 * conserva cookies, NO sigue redirecciones (para poder inspeccionarlas) y
 * permite reutilizar a propósito una cookie antigua (prueba de logout).
 */
final class ClienteHttp
{
    /** @var array<string, string> Cookies vigentes (nombre => valor). */
    private array $cookies = [];

    /**
     * @param string $urlBase Ej. http://127.0.0.1:8097
     */
    public function __construct(private readonly string $urlBase)
    {
    }

    /**
     * @param string $ruta Ruta interna.
     * @return array{codigo: int, encabezados: string, cuerpo: string}
     */
    public function obtener(string $ruta): array
    {
        return $this->ejecutar('GET', $ruta, null);
    }

    /**
     * @param string $ruta Ruta interna.
     * @param array<string, string|array<string>> $campos Campos del formulario.
     * @return array{codigo: int, encabezados: string, cuerpo: string}
     */
    public function enviar(string $ruta, array $campos): array
    {
        return $this->ejecutar('POST', $ruta, http_build_query($campos));
    }

    /**
     * Obtiene el token CSRF de un formulario HTML.
     *
     * @param string $html Cuerpo de la respuesta.
     * @return string Token o cadena vacía.
     */
    public static function extraerTokenCsrf(string $html): string
    {
        return preg_match('/name="token_csrf" value="([a-f0-9]{64})"/', $html, $coincidencias) === 1 ? $coincidencias[1] : '';
    }

    /**
     * Carga un formulario y devuelve su token CSRF.
     *
     * @param string $ruta Ruta del formulario.
     * @return string Token.
     */
    public function obtenerTokenDe(string $ruta): string
    {
        return self::extraerTokenCsrf($this->obtener($ruta)['cuerpo']);
    }

    /**
     * @return array<string, string> Copia de las cookies actuales.
     */
    public function exportarCookies(): array
    {
        return $this->cookies;
    }

    /**
     * Sustituye las cookies (simula a un atacante que guardó una cookie vieja).
     *
     * @param array<string, string> $cookies Cookies a usar.
     * @return void
     */
    public function importarCookies(array $cookies): void
    {
        $this->cookies = $cookies;
    }

    /**
     * @param string $metodo GET o POST.
     * @param string $ruta Ruta interna.
     * @param string|null $cuerpoSolicitud Cuerpo urlencoded.
     * @return array{codigo: int, encabezados: string, cuerpo: string}
     */
    private function ejecutar(string $metodo, string $ruta, ?string $cuerpoSolicitud): array
    {
        $manejador = curl_init($this->urlBase . $ruta);
        $opciones = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $metodo,
        ];
        if ($this->cookies !== []) {
            $pares = [];
            foreach ($this->cookies as $nombre => $valor) {
                $pares[] = $nombre . '=' . $valor;
            }
            $opciones[CURLOPT_COOKIE] = implode('; ', $pares);
        }
        if ($cuerpoSolicitud !== null) {
            $opciones[CURLOPT_POSTFIELDS] = $cuerpoSolicitud;
        }
        curl_setopt_array($manejador, $opciones);
        $respuestaCompleta = (string) curl_exec($manejador);
        $codigo = (int) curl_getinfo($manejador, CURLINFO_RESPONSE_CODE);
        $tamanoEncabezados = (int) curl_getinfo($manejador, CURLINFO_HEADER_SIZE);
        curl_close($manejador);

        $encabezados = substr($respuestaCompleta, 0, $tamanoEncabezados);
        $this->actualizarCookies($encabezados);

        return ['codigo' => $codigo, 'encabezados' => $encabezados, 'cuerpo' => substr($respuestaCompleta, $tamanoEncabezados)];
    }

    /**
     * Aplica los Set-Cookie recibidos (incluidas eliminaciones).
     *
     * @param string $encabezados Encabezados crudos.
     * @return void
     */
    private function actualizarCookies(string $encabezados): void
    {
        preg_match_all('/^Set-Cookie:\s*([^=;\s]+)=([^;\r\n]*)(.*)$/mi', $encabezados, $coincidencias, PREG_SET_ORDER);
        foreach ($coincidencias as $coincidencia) {
            [, $nombre, $valor, $atributos] = $coincidencia;
            $estaExpirada = $valor === '' || $valor === 'deleted' || stripos($atributos, 'Max-Age=0') !== false;
            if ($estaExpirada) {
                unset($this->cookies[$nombre]);
            } else {
                $this->cookies[$nombre] = $valor;
            }
        }
    }
}

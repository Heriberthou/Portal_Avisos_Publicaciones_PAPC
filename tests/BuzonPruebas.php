<?php

declare(strict_types=1);

/**
 * Lee el buzón de pruebas que escribe ServicioCorreo (modo buzon_pruebas)
 * en una carpeta TEMPORAL exclusiva de las pruebas. Permite obtener los
 * enlaces de verificación, activación y recuperación igual que lo haría la
 * persona al abrir su correo.
 */
final class BuzonPruebas
{
    /**
     * @param string $directorio Carpeta temporal del buzón.
     */
    public function __construct(public readonly string $directorio)
    {
        if (!is_dir($directorio)) {
            mkdir($directorio, 0700, true);
        }
    }

    /**
     * Borra todos los mensajes.
     *
     * @return void
     */
    public function vaciar(): void
    {
        foreach (glob($this->directorio . '/*.txt') ?: [] as $archivo) {
            unlink($archivo);
        }
    }

    /**
     * @param string $correo Destinatario.
     * @return list<string> Mensajes de ese destinatario, del más antiguo al más reciente.
     */
    public function mensajesPara(string $correo): array
    {
        $archivos = glob($this->directorio . '/*.txt') ?: [];
        // El nombre empieza con fecha y microsegundos: el orden alfabético es cronológico.
        sort($archivos);
        $mensajes = [];
        foreach ($archivos as $archivo) {
            $contenido = (string) file_get_contents($archivo);
            if (str_starts_with($contenido, 'Para: ' . $correo . "\n")) {
                $mensajes[] = $contenido;
            }
        }
        return $mensajes;
    }

    /**
     * Token del enlace más reciente hacia una ruta (p. ej. /activar-cuenta).
     *
     * @param string $correo Destinatario.
     * @param string $ruta Ruta del enlace.
     * @return string|null Token o null si no hay mensaje con ese enlace.
     */
    public function ultimoToken(string $correo, string $ruta): ?string
    {
        $token = null;
        foreach ($this->mensajesPara($correo) as $mensaje) {
            if (preg_match('#' . preg_quote($ruta, '#') . '\?token=([A-Za-z0-9_-]{43})#', $mensaje, $coincidencia) === 1) {
                $token = $coincidencia[1];
            }
        }
        return $token;
    }
}

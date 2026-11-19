<?php

declare(strict_types=1);

/**
 * Páginas públicas informativas (pantalla A de la especificación 1.3):
 * bienvenida, ayuda y aviso de privacidad. No simulan módulos que aún no
 * existen (avisos, comentarios): indican "Portal en desarrollo".
 */
final class PortalController
{
    /**
     * @param GestorSesion $gestorSesion Mensajes flash.
     */
    public function __construct(private readonly GestorSesion $gestorSesion)
    {
    }

    /**
     * GET /
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Bienvenida pública.
     */
    public function mostrarBienvenida(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return Respuesta::vista('bienvenida', [
            'tituloPagina'  => 'Bienvenida',
            'mensajes'      => $this->gestorSesion->extraerMensajesFlash(),
            'usuarioActual' => $usuarioActual,
        ]);
    }

    /**
     * GET /ayuda
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Página de ayuda.
     */
    public function mostrarAyuda(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return Respuesta::vista('ayuda', ['tituloPagina' => 'Ayuda', 'usuarioActual' => $usuarioActual]);
    }

    /**
     * GET /aviso-privacidad
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Aviso de privacidad (borrador del prototipo).
     */
    public function mostrarAvisoPrivacidad(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return Respuesta::vista('aviso-privacidad', ['tituloPagina' => 'Aviso de privacidad', 'usuarioActual' => $usuarioActual]);
    }
}

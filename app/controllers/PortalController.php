<?php

declare(strict_types=1);

/**
 * Páginas públicas informativas: ayuda y aviso de privacidad.
 *
 * La dirección principal (/) no tiene pantalla propia: lleva al inicio de
 * sesión, o al inicio de la cuenta si ya hay una sesión abierta. Lo
 * importante de esta etapa es el acceso, no una portada.
 */
final class PortalController
{
    /**
     * GET /
     *
     * @param array<string, mixed>|null $usuarioActual Usuario o null.
     * @param array<mixed> $datosFormulario No se usan.
     * @return Respuesta Redirección a /iniciar-sesion o a /inicio.
     */
    public function irAlInicio(?array $usuarioActual, array $datosFormulario): Respuesta
    {
        return Respuesta::redireccion($usuarioActual === null ? '/iniciar-sesion' : '/inicio');
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

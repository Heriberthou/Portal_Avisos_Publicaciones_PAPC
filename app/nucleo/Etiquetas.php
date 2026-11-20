<?php

declare(strict_types=1);

/**
 * Textos legibles para los valores internos que se muestran en pantalla.
 *
 * La base de datos guarda claves en mayúsculas (ADMINISTRADOR_PRINCIPAL,
 * SUSPENDIDA...). Las vistas no deben mostrarlas tal cual: se traducen
 * aquí a palabras comunes. Los formularios siguen enviando la clave interna.
 */
final class Etiquetas
{
    private const ROLES = [
        UsuarioModel::ROL_USUARIO                 => 'Usuario',
        UsuarioModel::ROL_ADMINISTRADOR           => 'Administrador',
        UsuarioModel::ROL_ADMINISTRADOR_PRINCIPAL => 'Administrador principal',
    ];

    private const ESTADOS_CUENTA = [
        UsuarioModel::ESTADO_INVITADA   => 'Por activar',
        UsuarioModel::ESTADO_ACTIVA     => 'Activa',
        UsuarioModel::ESTADO_SUSPENDIDA => 'Suspendida',
        UsuarioModel::ESTADO_BAJA       => 'Dada de baja',
    ];

    private const TIPOS_INTEGRANTE = [
        UsuarioModel::TIPO_ALUMNO   => 'Alumno',
        UsuarioModel::TIPO_PROFESOR => 'Profesor',
    ];

    private const ESTADOS_SOLICITUD = [
        SolicitudAccesoModel::ESTADO_PENDIENTE => 'Pendiente',
        SolicitudAccesoModel::ESTADO_APROBADA  => 'Aprobada',
        SolicitudAccesoModel::ESTADO_RECHAZADA => 'Rechazada',
    ];

    /**
     * @param string $rol Clave interna del rol.
     * @return string Nombre del rol para mostrar.
     */
    public static function rol(string $rol): string
    {
        return self::ROLES[$rol] ?? $rol;
    }

    /**
     * @param string $estado Clave interna del estado de la cuenta.
     * @return string Estado para mostrar.
     */
    public static function estadoCuenta(string $estado): string
    {
        return self::ESTADOS_CUENTA[$estado] ?? $estado;
    }

    /**
     * @param string|null $tipo Clave interna del tipo de integrante o null.
     * @return string Tipo para mostrar.
     */
    public static function tipoIntegrante(?string $tipo): string
    {
        return $tipo === null ? 'Sin clasificar' : (self::TIPOS_INTEGRANTE[$tipo] ?? $tipo);
    }

    /**
     * @param string $estado Clave interna del estado de la solicitud.
     * @return string Estado para mostrar.
     */
    public static function estadoSolicitud(string $estado): string
    {
        return self::ESTADOS_SOLICITUD[$estado] ?? $estado;
    }
}

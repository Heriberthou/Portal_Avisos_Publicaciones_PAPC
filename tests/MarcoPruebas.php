<?php

declare(strict_types=1);

/**
 * Marco mínimo de pruebas sin dependencias externas.
 *
 * Cada prueba es una función; cualquier afirmación fallida lanza
 * FalloPrueba. Al final se imprime un resumen con lo REALMENTE ejecutado:
 * una prueba solo aparece como "PASA" si su código terminó sin fallos.
 */
final class MarcoPruebas
{
    /** @var list<array{id: string, descripcion: string, estado: string, detalle: string}> */
    private array $resultados = [];

    /**
     * Ejecuta una prueba y guarda su resultado.
     *
     * @param string $idPrueba Identificador (p. ej. P01).
     * @param string $descripcion Qué se comprueba.
     * @param callable $cuerpoPrueba Código de la prueba.
     * @return void
     */
    public function probar(string $idPrueba, string $descripcion, callable $cuerpoPrueba): void
    {
        try {
            $cuerpoPrueba();
            $this->resultados[] = ['id' => $idPrueba, 'descripcion' => $descripcion, 'estado' => 'PASA', 'detalle' => ''];
        } catch (Throwable $excepcion) {
            $detalle = $excepcion instanceof FalloPrueba
                ? $excepcion->getMessage()
                : get_class($excepcion) . ': ' . $excepcion->getMessage() . ' @ ' . basename($excepcion->getFile()) . ':' . $excepcion->getLine();
            $this->resultados[] = ['id' => $idPrueba, 'descripcion' => $descripcion, 'estado' => 'FALLA', 'detalle' => $detalle];
        }
    }

    /**
     * @param bool $condicion Debe ser verdadera.
     * @param string $mensaje Explicación si falla.
     * @return void
     * @throws FalloPrueba Si la condición es falsa.
     */
    public static function afirmar(bool $condicion, string $mensaje): void
    {
        if (!$condicion) {
            throw new FalloPrueba($mensaje);
        }
    }

    /**
     * @param mixed $esperado Valor esperado.
     * @param mixed $obtenido Valor obtenido.
     * @param string $mensaje Contexto.
     * @return void
     * @throws FalloPrueba Si no son idénticos.
     */
    public static function afirmarIgual(mixed $esperado, mixed $obtenido, string $mensaje): void
    {
        if ($esperado !== $obtenido) {
            throw new FalloPrueba($mensaje . ' | esperado: ' . var_export($esperado, true) . ' | obtenido: ' . var_export($obtenido, true));
        }
    }

    /**
     * Verifica que una función lance ExcepcionHttp con un código dado.
     *
     * @param int $codigoEsperado Código HTTP esperado.
     * @param callable $accion Código que debe fallar.
     * @param string $mensaje Contexto.
     * @return void
     * @throws FalloPrueba Si no lanza o el código difiere.
     */
    public static function afirmarExcepcionHttp(int $codigoEsperado, callable $accion, string $mensaje): void
    {
        try {
            $accion();
        } catch (ExcepcionHttp $excepcion) {
            self::afirmarIgual($codigoEsperado, $excepcion->obtenerCodigoEstado(), $mensaje);
            return;
        }
        throw new FalloPrueba($mensaje . ' | no se lanzó ExcepcionHttp');
    }

    /**
     * Imprime el resumen y devuelve el código de salida.
     *
     * @return int 0 si todas pasaron; 1 si alguna falló.
     */
    public function imprimirResumen(): int
    {
        $totalFallidas = 0;
        foreach ($this->resultados as $resultado) {
            if ($resultado['estado'] === 'FALLA') {
                $totalFallidas++;
            }
            printf("[%s] %-7s %s\n", $resultado['estado'], $resultado['id'], $resultado['descripcion']);
            if ($resultado['detalle'] !== '') {
                printf("         -> %s\n", $resultado['detalle']);
            }
        }
        printf("\nTotal: %d | Pasan: %d | Fallan: %d\n", count($this->resultados), count($this->resultados) - $totalFallidas, $totalFallidas);
        return $totalFallidas === 0 ? 0 : 1;
    }
}

/**
 * Afirmación de prueba no cumplida.
 */
final class FalloPrueba extends RuntimeException
{
}

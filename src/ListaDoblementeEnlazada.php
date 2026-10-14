<?php
require_once __DIR__.'/Nodo.php';
class ListaDoblementeEnlazada {
    public ?Nodo $primero = null;
    public ?Nodo $ultimo = null;
    public int $cantidad = 0;
    public function insertar(array $registro): void {
        $nuevo = new Nodo($registro);
        $nuevo->anterior = $this->ultimo;
        if ($this->ultimo !== null) $this->ultimo->siguiente = $nuevo;
        else $this->primero = $nuevo;
        $this->ultimo = $nuevo;
        $this->cantidad++;
    }
    public function recorrer(): iterable {
        for ($n = $this->primero; $n !== null; $n = $n->siguiente) yield $n->registro;
    }
    public function burbuja(string $campo, bool $numerico = false): void {
        $limite = null;
        do {
            $cambio = false;
            $n = $this->primero;
            if ($n === null) return;
            while ($n->siguiente !== $limite && $n->siguiente !== null) {
                $otro = $n->siguiente;
                $comparacion = $numerico
                    ? ($n->registro[$campo] <=> $otro->registro[$campo])
                    : strcasecmp($n->registro[$campo], $otro->registro[$campo]);
                if ($comparacion > 0) {
                    $temporal = $n->registro;
                    $n->registro = $otro->registro;
                    $otro->registro = $temporal;
                    $cambio = true;
                }
                $n = $otro;
            }
            $limite = $n;
        } while ($cambio);
    }
}

<?php
class Nodo {
    public array $registro;
    public ?Nodo $anterior = null;
    public ?Nodo $siguiente = null;
    public function __construct(array $registro) { $this->registro = $registro; }
}

<?php

namespace SchoolAid\Assistant\Services\Entities;

/**
 * Un registro que el agente leyó o que una escritura confirmada tocó o creó, antes de cargarlo: su tipo
 * (`config('assistant.entities')`), su id y, en un cliente, su tipo de cliente (`modelKey` de get-customer-detail).
 * `date` solo en un horario: la clase que se consultó. `created`: lo creó la escritura (la ruta era `result.*`).
 */
final readonly class AssistantEntityRef
{
    public function __construct(
        public string $type,
        public int $id,
        public ?string $modelKey = null,
        public ?string $date = null,
        public bool $created = false,
    ) {
    }

    /**
     * La identidad del registro para no repetirlo: tipo e id, y en un cliente también su tipo de cliente (la familia 5
     * y el estudiante 5 son dos registros).
     */
    public function identity(): string
    {
        return "{$this->type}:{$this->modelKey}:{$this->id}";
    }
}

<?php

namespace SchoolAid\Assistant\Services\Anthropic;

use SchoolAid\Assistant\Enums\AssistantKeySourceEnum;
use SchoolAid\Assistant\Models\AssistantApiKey;

/**
 * La llave con la que sale una llamada y de dónde vino. `row` es la fila guardada (null si es `ANTHROPIC_API_KEY`):
 * con ella se marca `invalid` cuando Anthropic la rechaza.
 */
final readonly class ResolvedApiKey
{
    public function __construct(
        public string $key,
        public AssistantKeySourceEnum $source,
        public ?AssistantApiKey $row,
    ) {
    }
}

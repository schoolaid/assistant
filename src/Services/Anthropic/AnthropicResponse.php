<?php

namespace SchoolAid\Assistant\Services\Anthropic;

use SchoolAid\Assistant\Enums\AssistantKeySourceEnum;

/**
 * Respuesta de la Messages API con el consumo ya separado por tarifa: entrada, salida, lectura de
 * caché y escritura de caché. `cacheWriteTokens` es toda la escritura; `cacheWrite1hTokens`, la
 * parte de ella que se escribió con duración de 1 hora (`usage.cache_creation`), que se cobra más
 * cara que la de 5 minutos. `keySource` dice con qué llave se pidió: con la propia del market, el
 * costo es suyo ({@see \SchoolAid\Assistant\Services\Usage\AssistantBudget::record()}).
 */
final readonly class AnthropicResponse
{
    /**
     * @param array<int, array<string, mixed>> $content
     */
    public function __construct(
        public array $content,
        public string $stopReason,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadTokens,
        public int $cacheWriteTokens,
        public int $cacheWrite1hTokens = 0,
        public AssistantKeySourceEnum $keySource = AssistantKeySourceEnum::Said,
    ) {
    }

    public function withKeySource(AssistantKeySourceEnum $keySource): self
    {
        return new self(
            $this->content, $this->stopReason, $this->model, $this->inputTokens, $this->outputTokens,
            $this->cacheReadTokens, $this->cacheWriteTokens, $this->cacheWrite1hTokens, $keySource,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $usage = (array) ($body['usage'] ?? []);

        return new self(
            content: array_values((array) ($body['content'] ?? [])),
            stopReason: (string) ($body['stop_reason'] ?? ''),
            model: (string) ($body['model'] ?? ''),
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cacheReadTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
            cacheWriteTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            cacheWrite1hTokens: (int) ($usage['cache_creation']['ephemeral_1h_input_tokens'] ?? 0),
        );
    }

    /**
     * @return array<int, array{id: string, name: string, input: array<string, mixed>}>
     */
    public function toolUses(): array
    {
        $uses = [];

        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                $uses[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'input' => (array) ($block['input'] ?? []),
                ];
            }
        }

        return $uses;
    }

    public function text(): string
    {
        $texts = [];

        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $texts[] = (string) ($block['text'] ?? '');
            }
        }

        return implode("\n", $texts);
    }
}

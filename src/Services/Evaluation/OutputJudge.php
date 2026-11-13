<?php

namespace SchoolAid\Assistant\Services\Evaluation;

use SchoolAid\Assistant\Services\Anthropic\AnthropicMessagesClient;
use SchoolAid\Assistant\Services\Usage\AssistantBudget;
use JsonException;

/**
 * El juez opcional del set de salida (`assistant:eval --set=output --judge`): una llamada al modelo del asistente que
 * califica de 1 a 5 una respuesta con una rúbrica fija, con salida estructurada. Cuesta dinero y no bloquea: informa.
 * No recibe los resultados de las tools, así que no juzga si las cifras son ciertas; eso lo hace {@see OutputChecks}.
 */
class OutputJudge
{
    public const CRITERIA = [
        'answers' => 'Responde lo que se pidió.',
        'direct' => 'Va directo: la respuesta primero y el detalle después, sin rodeos.',
        'format' => 'El formato ayuda a leerla (tablas para listas, montos con moneda, sin ruido).',
        'concise' => 'No rellena: nada de repetir el pedido, disculpas ni ofrecimientos de sobra.',
    ];

    private const SYSTEM = <<<'TEXT'
Evalúas respuestas del asistente del panel de MarketAid (un sistema de gestión para negocios) a preguntas del staff.
Califica cada criterio de 1 (muy mal) a 5 (excelente) y deja un comentario de una o dos frases con lo que más pesaría
mejorar. No juzgues si las cifras son ciertas: no tienes los datos.
TEXT;

    private const MAX_TOKENS = 4000;

    public function __construct(
        private readonly AnthropicMessagesClient $client,
        private readonly AssistantBudget $budget,
    ) {
    }

    /**
     * La nota (o null si el modelo no devolvió una nota válida) y lo que costó la llamada.
     *
     * @return array{judge: array{scores: array<string, int>, comment: string}|null, costUsd: float}
     */
    public function judge(string $request, string $answer): array
    {
        $criteria = implode("\n", array_map(fn (string $key, string $text): string => "- {$key}: {$text}", array_keys(self::CRITERIA), self::CRITERIA));

        $response = $this->client->create([
            'model' => (string) config('assistant.model'),
            'max_tokens' => self::MAX_TOKENS,
            'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
            'system' => self::SYSTEM."\n\nCriterios:\n".$criteria,
            'messages' => [[
                'role' => 'user',
                'content' => "<pedido>\n{$request}\n</pedido>\n\n<respuesta>\n{$answer}\n</respuesta>",
            ]],
        ]);

        return ['judge' => $this->parse($response->text()), 'costUsd' => $this->budget->costOf($response)];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $scores = [];

        foreach (self::CRITERIA as $key => $text) {
            $scores[$key] = ['type' => 'integer', 'description' => "{$text} De 1 a 5."];
        }

        return [
            'type' => 'object',
            'properties' => [
                'scores' => ['type' => 'object', 'properties' => $scores, 'required' => array_keys(self::CRITERIA), 'additionalProperties' => false],
                'comment' => ['type' => 'string'],
            ],
            'required' => ['scores', 'comment'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{scores: array<string, int>, comment: string}|null
     */
    private function parse(string $text): ?array
    {
        try {
            $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || ! is_array($data['scores'] ?? null)) {
            return null;
        }

        $scores = [];

        foreach (array_keys(self::CRITERIA) as $key) {
            $score = $data['scores'][$key] ?? null;

            if (! is_int($score) || $score < 1 || $score > 5) {
                return null;
            }

            $scores[$key] = $score;
        }

        return ['scores' => $scores, 'comment' => trim((string) ($data['comment'] ?? ''))];
    }
}

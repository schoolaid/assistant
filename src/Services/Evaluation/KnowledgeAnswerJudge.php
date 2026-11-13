<?php

namespace SchoolAid\Assistant\Services\Evaluation;

use SchoolAid\Assistant\Support\AssistantNaming;
use SchoolAid\Assistant\Help\HelpPages;
use SchoolAid\Assistant\Services\Anthropic\AnthropicMessagesClient;
use SchoolAid\Assistant\Services\Usage\AssistantBudget;
use Illuminate\Support\Str;
use JsonException;

/**
 * El juez del set de conocimiento (`assistant:eval --set=knowledge --judge`): una llamada al modelo del asistente que
 * compara la respuesta con la guía, con salida estructurada. Recibe la pregunta, la referencia (las secciones
 * etiquetadas en `help-eval.json`; vacía si la guía no la cubre), lo que el asistente leyó (los resultados de
 * `search-knowledge`, con sus extractos y guías en video, y las páginas que abrió con `get-help-page`) y su respuesta.
 * Lo leído va aparte de la referencia porque las etiquetas no son todas las páginas que responden: un paso que sale de
 * otra página que sí leyó, o el enlace de un video que trajo la búsqueda, no es un invento. La primera corrida
 * (2026-11-12) no le pasaba los resultados de la búsqueda y marcó «inventa» en 33 de 40 respuestas: el asistente
 * responde casi siempre con los extractos, sin abrir la página.
 *
 * `invented` mira solo lo que la respuesta afirma del panel. El juez no ve las tools de datos ni el contexto del turno
 * (fecha, sucursal, tipos de cliente), así que el prompt le dice que ofrecer una consulta o mencionar ese contexto no es
 * inventar: en la segunda corrida la mitad de los «inventa» eran eso.
 *
 * Cuesta dinero. Es un modelo calificando a otro: sirve para comparar corridas y encontrar qué revisar a mano, no como
 * verdad sobre una respuesta suelta.
 *
 * @phpstan-import-type KnowledgeLabel from KnowledgeEvalSet
 */
class KnowledgeAnswerJudge
{
    private const SYSTEM = <<<'TEXT'
Evalúas respuestas del asistente del panel de MarketAid (un sistema de gestión para negocios) a preguntas del staff sobre
cómo funciona el panel. La única fuente válida es la guía del panel. Recibes la pregunta, la referencia (las secciones de
la guía que la responden; vacía si la guía no la cubre), lo que el asistente leyó de la guía (resultados de su búsqueda,
con extractos y guías en video, y páginas que abrió) y su respuesta.

`verdict`:
- correct: responde la pregunta y coincide con la referencia.
- partial: va en la dirección de la referencia, pero le falta lo esencial o mezcla un dato que la contradice.
- wrong: contradice la referencia o responde otra cosa.
- abstained: dice que la guía no lo cubre o que no encontró la respuesta, o solo pide un dato sin responder.
Si la referencia está vacía, la guía no responde la pregunta: usa abstained si la respuesta lo dice con claridad (puede
agregar lo relacionado que sí leyó) y wrong si responde como si la guía lo cubriera.

`invented`: true si la respuesta afirma, sobre cómo funciona el panel, un paso, una regla, el nombre de una pantalla o un
dato que no está ni en la referencia ni en lo que leyó. No cuenta como inventar:
- que ofrezca revisar o hacer algo con los datos del comercio (buscar a un cliente, ver una orden, correr un reporte,
  registrar un pago): el asistente tiene herramientas para eso, aunque aquí no las veas;
- que mencione la fecha, la sucursal, quién escribe o los tipos de cliente del comercio: los recibe en su contexto;
- que diga qué no puede hacer él mismo (por ejemplo, nada con tarjetas) o que lo mande a hacer en el panel.

`comment`: una o dos frases con lo que falló o faltó; vacío si nada.
TEXT;

    private const MAX_TOKENS = 4000;

    private const MAX_REFERENCE_CHARS = 12000;

    private const MAX_READ_CHARS = 40000;

    public function __construct(
        private readonly AnthropicMessagesClient $client,
        private readonly AssistantBudget $budget,
        private readonly HelpPages $pages,
    ) {
    }

    /**
     * El veredicto (o null si el modelo no devolvió uno válido) y lo que costó la llamada.
     *
     * @param  list<KnowledgeLabel>  $labels  las secciones que responden; vacío si la guía no cubre la pregunta
     * @param  list<string>  $read  lo que devolvió cada `search-knowledge` y cada `get-help-page` del turno
     * @return array{verdict: array{verdict: string, invented: bool, comment: string}|null, costUsd: float}
     */
    public function judge(string $question, array $labels, array $read, string $answer): array
    {
        $response = $this->client->create([
            'model' => (string) AssistantNaming::config('model'),
            'max_tokens' => self::MAX_TOKENS,
            'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
            'system' => self::SYSTEM,
            'messages' => [[
                'role' => 'user',
                'content' => "<pregunta>\n{$question}\n</pregunta>\n\n<referencia>\n{$this->reference($labels)}\n</referencia>\n\n<leido>\n"
                    .Str::limit(implode("\n\n---\n\n", $read), self::MAX_READ_CHARS, '')
                    ."\n</leido>\n\n<respuesta>\n{$answer}\n</respuesta>",
            ]],
        ]);

        return ['verdict' => $this->parse($response->text()), 'costUsd' => $this->budget->costOf($response)];
    }

    /**
     * El texto de las secciones etiquetadas; si un encabezado ya no existe, la página entera.
     *
     * @param  list<KnowledgeLabel>  $labels
     */
    private function reference(array $labels): string
    {
        $parts = [];

        foreach ($labels as $label) {
            $section = $label['section'] ?? null;
            $content = $this->pages->content($label['page'], $section) ?? $this->pages->content($label['page']);

            if ($content !== null) {
                $parts[] = '['.$label['page'].($section === null ? '' : " › {$section}")."]\n".trim($content);
            }
        }

        return Str::limit(implode("\n\n---\n\n", $parts), self::MAX_REFERENCE_CHARS, '');
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'verdict' => ['type' => 'string', 'enum' => KnowledgeAnswerChecks::VERDICTS],
                'invented' => ['type' => 'boolean'],
                'comment' => ['type' => 'string'],
            ],
            'required' => ['verdict', 'invented', 'comment'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{verdict: string, invented: bool, comment: string}|null
     */
    private function parse(string $text): ?array
    {
        try {
            $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || ! in_array($data['verdict'] ?? null, KnowledgeAnswerChecks::VERDICTS, true) || ! is_bool($data['invented'] ?? null)) {
            return null;
        }

        return ['verdict' => $data['verdict'], 'invented' => $data['invented'], 'comment' => trim((string) ($data['comment'] ?? ''))];
    }
}

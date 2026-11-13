<?php

namespace SchoolAid\Assistant\Support;

use SchoolAid\Assistant\Enums\AssistantMessageKindEnum;
use SchoolAid\Assistant\Models\AssistantTurn;
use SchoolAid\Assistant\Services\Agent\ExportDocumentTool;
use SchoolAid\Assistant\Services\Agent\ExportFileTool;
use SchoolAid\Assistant\Services\Agent\OfferChoicesTool;
use SchoolAid\Assistant\Services\Agent\ShowChartTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;

/**
 * Los pasos ya hechos de un turno, para que el panel los muestre como una línea gris cada uno mientras hace poll (spec,
 * «Flujo de un turno», punto 7; R-P2-10): cada `tool_use` de las respuestas del turno, en orden, con su estado según el
 * `tool_result` que le corresponde. Sin columna propia: sale de los mensajes guardados. Los `server_tool_use` (la
 * búsqueda de tools) no son pasos.
 *
 * La etiqueta es por tool, en palabras del staff (`assistant.steps.<tool>`), o «Consulté <tool>» si falta.
 */
final class AssistantTurnSteps
{
    public const DONE = 'done';

    public const ERROR = 'error';

    public const PENDING = 'pending';

    /**
     * Tools que no se muestran: `report-unanswered-question` registra el hueco para SAID sin mencionárselo a la persona
     * (spec, «Lo que no puede»), y `offer-choices` ya se ve como la pregunta con botones (el mensaje `choices`), igual que `show-chart` como la
     * gráfica (el mensaje `chart`).
     *
     * @var list<string>
     */
    public const HIDDEN_TOOLS = ['report-unanswered-question', OfferChoicesTool::NAME, ShowChartTool::NAME, ExportFileTool::NAME, ExportDocumentTool::NAME];

    /**
     * @return list<array{tool: string, label: string, status: string}>
     */
    public function for(AssistantTurn $turn): array
    {
        $toolUses = [];
        $results = [];

        $messages = $turn->messages()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $responses) => $responses->where('role', 'assistant')->where('kind', AssistantMessageKindEnum::Text->value))
                ->orWhere(fn (Builder $toolResults) => $toolResults->where('role', 'user')->where('kind', AssistantMessageKindEnum::Internal->value)))
            ->orderBy('id')
            ->get(['id', 'role', 'kind', 'content']);

        foreach ($messages as $message) {
            foreach ((array) $message->content as $block) {
                if (! is_array($block)) {
                    continue;
                }

                if ($message->role === 'assistant' && ($block['type'] ?? null) === 'tool_use') {
                    $toolUses[] = ['id' => (string) ($block['id'] ?? ''), 'tool' => (string) ($block['name'] ?? '')];
                }

                if ($message->role === 'user' && ($block['type'] ?? null) === 'tool_result') {
                    $results[(string) ($block['tool_use_id'] ?? '')] = (bool) ($block['is_error'] ?? false);
                }
            }
        }

        $steps = [];

        foreach ($toolUses as $toolUse) {
            if ($toolUse['tool'] === '' || in_array($toolUse['tool'], self::HIDDEN_TOOLS, true)) {
                continue;
            }

            $steps[] = [
                'tool' => $toolUse['tool'],
                'label' => $this->label($toolUse['tool']),
                'status' => match (true) {
                    ! array_key_exists($toolUse['id'], $results) => self::PENDING,
                    $results[$toolUse['id']] => self::ERROR,
                    default => self::DONE,
                },
            ];
        }

        return $steps;
    }

    private function label(string $tool): string
    {
        $key = AssistantNaming::langKey("steps.{$tool}");

        return Lang::has($key) ? (string) __($key) : (string) AssistantNaming::trans('step_fallback', ['tool' => $tool]);
    }
}

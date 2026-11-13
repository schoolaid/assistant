<?php

namespace SchoolAid\Assistant\Services\Evaluation;

use Illuminate\Support\Str;

/**
 * Chequeos del set de conocimiento (`assistant:eval --set=knowledge`), sin llamar la API.
 *
 * - **Abrió una página que responde** (pregunta con respuesta): alguna llamada a `get-help-page` fue a una de las
 *   páginas etiquetadas. Es lo único que se sabe sin juez; con el veredicto del juez deja de ser falla y queda como
 *   dato ({@see TaskEvalResult::withGuideVerdict()}): las etiquetas no son todas las páginas que responden.
 * - **Vista previa** (falla siempre): una pregunta de cómo funciona el panel no pide ninguna escritura.
 * - **Veredicto del juez** ({@see self::verdictFailures()}): una pregunta con respuesta pasa solo con `correct`; una
 *   sin respuesta en la guía, solo con `abstained`; en las dos, inventar es falla.
 *
 * @phpstan-import-type KnowledgeLabel from KnowledgeEvalSet
 */
final class KnowledgeAnswerChecks
{
    public const PAGE_TOOL = 'get-help-page';

    public const SEARCH_TOOL = 'search-knowledge';

    /**
     * Las tools con las que el asistente lee la guía: lo que devuelven es lo que el juez recibe como «leído». La búsqueda
     * cuenta: el asistente responde a menudo con sus extractos (y da el enlace de sus guías en video) sin abrir la página.
     */
    public const READING_TOOLS = [self::SEARCH_TOOL, self::PAGE_TOOL];

    public const NOT_OPENED = 'no abrió ninguna página que responde';

    public const VERDICTS = ['correct', 'partial', 'wrong', 'abstained'];

    /**
     * @param  array{answerable: bool, labels: list<KnowledgeLabel>}  $knowledge
     * @param  list<array{name: string, input: array<string, mixed>}>  $toolUses  los `tool_use` del turno, en orden
     * @param  list<string>  $previews  escrituras que llegaron a vista previa
     * @return array{opened: list<string>, openedLabeled: bool, failures: list<string>}
     */
    public static function check(array $knowledge, array $toolUses, array $previews): array
    {
        $opened = [];

        foreach ($toolUses as $toolUse) {
            $page = $toolUse['name'] === self::PAGE_TOOL ? Str::before(trim((string) ($toolUse['input']['page'] ?? '')), '#') : '';

            if ($page !== '' && ! in_array($page, $opened, true)) {
                $opened[] = $page;
            }
        }

        $openedLabeled = array_intersect($opened, array_column($knowledge['labels'], 'page')) !== [];
        $failures = [];

        if ($previews !== []) {
            $failures[] = 'pidió una vista previa en una pregunta de la guía';
        }

        if ($knowledge['answerable'] && ! $openedLabeled) {
            $failures[] = self::NOT_OPENED.($opened === [] ? '' : ' (abrió: '.implode(', ', $opened).')');
        }

        return ['opened' => $opened, 'openedLabeled' => $openedLabeled, 'failures' => $failures];
    }

    /**
     * @param  array{verdict: string, invented: bool, comment: string}  $verdict
     * @return list<string>
     */
    public static function verdictFailures(bool $answerable, array $verdict): array
    {
        $failures = [];

        if ($answerable) {
            $reason = match ($verdict['verdict']) {
                'correct' => null,
                'partial' => 'juez: respuesta a medias',
                'abstained' => 'juez: no respondió aunque la guía lo cubre',
                default => 'juez: respuesta equivocada',
            };
        } else {
            $reason = $verdict['verdict'] === 'abstained' ? null : 'juez: respondió aunque la guía no lo cubre';
        }

        if ($reason !== null) {
            $failures[] = $reason;
        }

        if ($verdict['invented']) {
            $failures[] = 'juez: afirma algo que no está en la guía';
        }

        return $failures;
    }
}

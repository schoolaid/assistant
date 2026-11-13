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
 * - **Enlazó la pantalla** (pregunta con respuesta, cuando las páginas etiquetadas o las que abrió tienen pantallas
 *   que la persona ve, {@see \App\Mcp\Support\PanelScreens}): la respuesta trae el enlace a una de ellas.
 * - **Rutas inventadas** (falla siempre): un enlace relativo del panel que no es una pantalla de la persona ni
 *   `/manual/…`.
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

    public const NO_SCREEN_LINK = 'no enlazó la pantalla';

    public const UNKNOWN_SCREEN_LINK = 'enlazó una ruta del panel que no es una pantalla';

    /**
     * Destino de un enlace markdown relativo a la raíz (`[Órdenes](/resources/order)`).
     */
    private const PANEL_LINK = '/\]\((\/[^)\s]*)\)/u';

    /**
     * @param  array{answerable: bool, labels: list<KnowledgeLabel>}  $knowledge
     * @param  list<array{name: string, input: array<string, mixed>}>  $toolUses  los `tool_use` del turno, en orden
     * @param  list<string>  $previews  escrituras que llegaron a vista previa
     * @param  string  $text  lo que la persona leyó del asistente
     * @param  array{byPage: array<string, list<string>>, visible: list<string>}|null  $screens  las URLs de las
     *                                                                                        pantallas de cada página y todas las que la persona ve; null no revisa enlaces
     * @return array{opened: list<string>, openedLabeled: bool, screens: list<string>, linkedScreen: bool|null, failures: list<string>}
     */
    public static function check(array $knowledge, array $toolUses, array $previews, string $text = '', ?array $screens = null): array
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

        $expected = [];
        $linkedScreen = null;

        if ($screens !== null) {
            $links = self::panelLinks($text);

            foreach (array_unique([...array_column($knowledge['labels'], 'page'), ...$opened]) as $page) {
                $expected = [...$expected, ...($screens['byPage'][$page] ?? [])];
            }

            $expected = array_values(array_unique($expected));

            if ($knowledge['answerable'] && $expected !== []) {
                $linkedScreen = array_intersect($links, $expected) !== [];

                if (! $linkedScreen) {
                    $failures[] = self::NO_SCREEN_LINK.' ('.implode(', ', $expected).')';
                }
            }

            $unknown = array_values(array_diff($links, $screens['visible']));

            if ($unknown !== []) {
                $failures[] = self::UNKNOWN_SCREEN_LINK.': '.implode(', ', $unknown);
            }
        }

        return ['opened' => $opened, 'openedLabeled' => $openedLabeled, 'screens' => $expected, 'linkedScreen' => $linkedScreen, 'failures' => $failures];
    }

    /**
     * Los enlaces relativos del panel en el texto, sin la barra final y sin los del Manual (`/manual/…`, que valida el
     * catálogo de videos).
     *
     * @return list<string>
     */
    public static function panelLinks(string $text): array
    {
        preg_match_all(self::PANEL_LINK, $text, $matches);
        $links = [];

        foreach ($matches[1] as $link) {
            $link = rtrim(Str::before($link, '#'), '/') ?: '/';

            if (! str_starts_with($link, '/manual/') && $link !== '/manual' && ! in_array($link, $links, true)) {
                $links[] = $link;
            }
        }

        return $links;
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

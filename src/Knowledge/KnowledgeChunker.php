<?php

namespace SchoolAid\Assistant\Knowledge;

use SchoolAid\Assistant\Help\HelpBank;
use SchoolAid\Assistant\Help\HelpPages;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Parte las guías admin ({@see HelpPages}) y el banco de preguntas ({@see HelpBank}) en
 * fragmentos para indexar: uno por sección `##`/`###` (más la intro de cada página) y uno
 * por entrada del banco. Suma uno por guía en video del Manual ({@see VideoGuideCatalog}).
 */
class KnowledgeChunker
{
    private const MAX_TEXT_LENGTH = 6000;

    private const HEADING = '/^(#{2,3})\s+(.+?)\s*#*\s*$/u';

    public const VIDEO_PAGE_TITLE = 'Manual en video';

    public const VIDEO_ID_PREFIX = 'video-';

    public function __construct(
        private readonly HelpPages $pages,
        private readonly HelpBank $bank,
        private readonly ?VideoGuideCatalog $videos = null,
    ) {
    }

    /**
     * @return Collection<int, KnowledgeChunk>
     */
    public function chunks(): Collection
    {
        return $this->pageChunks()->concat($this->videoChunks())->values();
    }

    /**
     * Lo que sale del repo: la guía admin y el banco de preguntas. No depende de nada externo.
     *
     * @return Collection<int, KnowledgeChunk>
     */
    public function pageChunks(): Collection
    {
        return $this->guideChunks()->concat($this->faqChunks())->values();
    }

    /**
     * Un fragmento por guía en video: resumen, preguntas tipo y pasos. `source` es `video`
     * (`guide` ya nombra a las páginas de la guía admin) y no tiene página que abrir con
     * get-help-page: se enlaza a `/manual/<guide>` del panel. Sus ids empiezan con
     * {@see self::VIDEO_ID_PREFIX}.
     *
     * @return Collection<int, KnowledgeChunk>
     *
     * @throws \RuntimeException si el catálogo de guías no se puede leer ({@see VideoGuideCatalog})
     */
    public function videoChunks(): Collection
    {
        return collect($this->videos?->guides() ?? [])->map(function (array $guide): KnowledgeChunk {
            $steps = collect($guide['steps'])
                ->values()
                ->map(fn (array $step, int $index): string => ($index + 1).'. '.trim($step['title'].': '.$step['text'], ': '));

            $text = collect([$guide['summary'], implode("\n", $guide['intents']), $steps->implode("\n")])
                ->filter()
                ->implode("\n\n");

            return (new KnowledgeChunk(
                id: self::VIDEO_ID_PREFIX.$guide['id'],
                source: 'video',
                module: $guide['module'],
                moduleTitle: $guide['moduleTitle'],
                page: '',
                pageTitle: self::VIDEO_PAGE_TITLE,
                heading: $guide['title'],
                anchor: null,
                text: Str::limit($text, self::MAX_TEXT_LENGTH, ''),
                hash: '',
                extra: [
                    'guide' => $guide['id'],
                    'menu' => $guide['menu'],
                    'steps' => collect($guide['steps'])
                        ->filter(fn (array $step): bool => $step['id'] !== null)
                        ->map(fn (array $step): array => ['id' => $step['id'], 'title' => $step['title']])
                        ->values()
                        ->all(),
                    'duration' => $guide['durationSeconds'],
                ],
            ))->sealed();
        });
    }

    /**
     * Un encabezado sin texto propio (un `##` seguido de sus `###`) no deja fragmento: sin texto
     * no responde nada, sale con el extracto vacío y su contenido ya está en los fragmentos de sus
     * `###`. Sí cuenta para la numeración de un encabezado repetido, así los ids y las anclas de
     * los demás no se mueven.
     *
     * @return Collection<int, KnowledgeChunk>
     */
    private function guideChunks(): Collection
    {
        $chunks = collect();

        foreach ($this->pages->modules() as $module) {
            foreach ($this->pages->pages($module['module']) as $page) {
                $occurrences = [];

                foreach ($this->sections($page) as $section) {
                    $text = Str::limit(trim(implode("\n", $section['lines'])), self::MAX_TEXT_LENGTH, '');

                    if ($text === '' && $section['anchor'] === null) {
                        continue;
                    }

                    $key = (string) $section['anchor'];
                    $occurrence = $occurrences[$key] = ($occurrences[$key] ?? -1) + 1;

                    if ($text === '') {
                        continue;
                    }

                    $anchor = $occurrence === 0 ? $section['anchor'] : $section['parentAnchor'];

                    $chunks->push(new KnowledgeChunk(
                        id: sha1($page['page'].'#'.$key.'#'.$occurrence),
                        source: 'guide',
                        module: $module['module'],
                        moduleTitle: $module['title'],
                        page: $page['page'],
                        pageTitle: $page['title'],
                        heading: $section['heading'] ?? $page['title'],
                        anchor: $anchor === '' ? null : $anchor,
                        text: $text,
                        hash: '',
                    )->sealed());
                }
            }
        }

        return $chunks;
    }

    /**
     * Secciones de la página en orden. `parentAnchor` es el ancla del `##` que contiene un `###`,
     * si ese `##` es el primero con su ancla en la página: `get-help-page` abre siempre el primer
     * encabezado con un ancla dada, así que un `###` repetido («Dónde está» bajo dos `##`) se abre
     * por su `##`.
     *
     * @param  array{page: string, title: string}  $page
     * @return array<int, array{heading: ?string, anchor: ?string, parentAnchor: ?string, lines: array<int, string>}>
     */
    private function sections(array $page): array
    {
        $body = (string) $this->pages->content($page['page']);
        $sections = [];
        $current = ['heading' => null, 'anchor' => null, 'parentAnchor' => null, 'lines' => []];
        $inFence = false;
        $seen = [];
        $parentAnchor = null;

        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $inFence = ! $inFence;
            }

            if (! $inFence && preg_match(self::HEADING, $line, $match)) {
                $sections[] = $current;
                $anchor = Str::slug($match[2]);
                $isFirst = ! isset($seen[$anchor]);
                $seen[$anchor] = true;

                if (strlen($match[1]) === 2) {
                    $parentAnchor = $isFirst && $anchor !== '' ? $anchor : null;
                    $current = ['heading' => $match[2], 'anchor' => $anchor, 'parentAnchor' => null, 'lines' => []];
                } else {
                    $current = ['heading' => $match[2], 'anchor' => $anchor, 'parentAnchor' => $parentAnchor, 'lines' => []];
                }

                continue;
            }

            if (! $inFence && $current['heading'] === null && $current['lines'] === [] && preg_match('/^#\s/', $line)) {
                continue;
            }

            $current['lines'][] = $line;
        }

        $sections[] = $current;

        return $sections;
    }

    /**
     * @return Collection<int, KnowledgeChunk>
     */
    private function faqChunks(): Collection
    {
        $titles = $this->pages->modules()->pluck('title', 'module');

        return collect($this->bank->items())->map(function (array $item) use ($titles): KnowledgeChunk {
            $source = Str::after($item['source'], HelpPages::ROOT.'/');
            $page = Str::before($source, '#');
            $text = $item['question']."\n\n".$item['answer'];

            return new KnowledgeChunk(
                id: 'faq-'.$item['id'],
                source: 'faq',
                module: $item['module'],
                moduleTitle: (string) ($titles[$item['module']] ?? $item['module']),
                page: $page,
                pageTitle: (string) ($this->pages->find($page)['title'] ?? ''),
                heading: $item['question'],
                anchor: str_contains($source, '#') ? Str::after($source, '#') : null,
                text: Str::limit($text, self::MAX_TEXT_LENGTH, ''),
                hash: '',
            )->sealed();
        });
    }
}

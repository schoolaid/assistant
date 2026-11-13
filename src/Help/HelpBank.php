<?php

namespace SchoolAid\Assistant\Help;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Banco de preguntas y respuestas de soporte para el staff del panel
 * (`docs/modules/_qa-bank/`), generado por `scripts/build-qa-bank.py` desde las páginas
 * admin de cada módulo. Viaja en la imagen: `.dockerignore` deja pasar `docs/modules`.
 *
 * La búsqueda es por palabras, sin índice: el banco es chico (≈ 100 entradas) y se lee del
 * disco en cada llamada. Cada palabra se reduce a una raíz corta (sin acentos, sin la `s`
 * final, máximo 5 letras) para que «cancelo», «cancelar» y «cancelación» coincidan.
 */
class HelpBank
{
    public const PATH = 'docs/modules/_qa-bank/admin-preguntas-y-respuestas.json';

    private const STEM_LENGTH = 5;

    private const QUESTION_WEIGHT = 3;

    private const ANSWER_WEIGHT = 1;

    private const STOPWORDS = [
        'que', 'qué', 'como', 'cómo', 'cual', 'cuál', 'cuando', 'cuándo', 'donde', 'dónde', 'por',
        'para', 'con', 'sin', 'los', 'las', 'del', 'una', 'uno', 'unos', 'unas', 'este', 'esta',
        'esto', 'ese', 'esa', 'eso', 'hay', 'hace', 'hago', 'puedo', 'puede', 'pasa', 'mas', 'más',
        'muy', 'son', 'sus', 'mis', 'tus', 'les', 'nos', 'ya', 'todo', 'toda', 'todos', 'todas',
    ];

    /**
     * @var array{meta: array<string, mixed>, items: array<int, array<string, mixed>>}|null
     */
    private ?array $bank = null;

    public function __construct(private readonly HelpPages $pages)
    {
    }

    /**
     * Módulos que cubre el banco (`meta.scope`).
     *
     * @return array<int, string>
     */
    public function modules(): array
    {
        return array_values($this->load()['meta']['scope'] ?? []);
    }

    /**
     * Todas las entradas del banco, tal como vienen del JSON.
     *
     * @return array<int, array{id: string, module: string, question: string, answer: string, source: string, see_also: array<int, string>}>
     */
    public function items(): array
    {
        return array_values(array_map(fn (array $item): array => [
            'id' => (string) $item['id'],
            'module' => (string) $item['module'],
            'question' => (string) $item['question'],
            'answer' => (string) $item['answer'],
            'source' => (string) $item['source'],
            'see_also' => array_values($item['see_also'] ?? []),
        ], $this->load()['items']));
    }

    /**
     * Entradas ordenadas de mayor a menor coincidencia con la consulta (a igual puntaje, primero
     * la pregunta más corta: es la más específica); las que no comparten ninguna raíz con ella
     * quedan fuera.
     *
     * @return Collection<int, array{id: string, module: string, question: string, answer: string, source: string, seeAlso: array<int, string>}>
     */
    public function search(string $query, ?string $module = null, int $limit = 5): Collection
    {
        $queryStems = self::stems($query);

        if ($queryStems === []) {
            return collect();
        }

        return collect($this->load()['items'])
            ->when($module !== null, fn (Collection $items): Collection => $items->where('module', $module))
            ->map(function (array $item, int $position) use ($queryStems): array {
                $questionStems = self::stems((string) $item['question']);
                $answerStems = self::stems((string) $item['answer']);

                $score = count(array_intersect($queryStems, $questionStems)) * self::QUESTION_WEIGHT
                    + count(array_intersect($queryStems, $answerStems)) * self::ANSWER_WEIGHT;

                return ['item' => $item, 'score' => $score, 'questionLength' => count($questionStems), 'position' => $position];
            })
            ->filter(fn (array $match): bool => $match['score'] > 0)
            ->sortBy([['score', 'desc'], ['questionLength', 'asc'], ['position', 'asc']])
            ->take($limit)
            ->map(fn (array $match): array => [
                'id' => (string) $match['item']['id'],
                'module' => (string) $match['item']['module'],
                'question' => (string) $match['item']['question'],
                'answer' => (string) $match['item']['answer'],
                'source' => (string) $match['item']['source'],
                'seeAlso' => $this->catalogLinks($match['item']['see_also'] ?? []),
            ])
            ->values();
    }

    /**
     * Solo los enlaces a páginas del catálogo de guías del administrador ({@see HelpPages}). Las
     * páginas `dev/` documentan fallos sin corregir y las lee el staff de cada market, así que
     * quedan fuera, igual que en `get-help-page`.
     *
     * @param  array<int, string>  $links  rutas `docs/modules/<módulo>/<carpeta>/<página>.md[#ancla]`
     * @return array<int, string>
     */
    private function catalogLinks(array $links): array
    {
        return collect($links)
            ->filter(fn (string $link): bool => $this->pages->find(Str::before(Str::after($link, HelpPages::ROOT . '/'), '#')) !== null)
            ->values()
            ->all();
    }

    /**
     * Raíces únicas de un texto: minúsculas sin acentos, sin palabras vacías ni de menos de 3
     * letras, sin la `s` final y recortadas a STEM_LENGTH letras.
     *
     * @return array<int, string>
     */
    public static function stems(string $text): array
    {
        $stopwords = array_map(fn (string $word): string => Str::ascii($word), self::STOPWORDS);
        $words = preg_split('/[^a-z0-9]+/', Str::ascii(mb_strtolower($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($words)
            ->reject(fn (string $word): bool => strlen($word) < 3 || in_array($word, $stopwords, true))
            ->map(fn (string $word): string => substr(strlen($word) > 3 ? rtrim($word, 's') : $word, 0, self::STEM_LENGTH))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{meta: array<string, mixed>, items: array<int, array<string, mixed>>}
     */
    private function load(): array
    {
        return $this->bank ??= json_decode(
            (string) file_get_contents(base_path(self::PATH)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}

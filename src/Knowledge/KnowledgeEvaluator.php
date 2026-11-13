<?php

namespace SchoolAid\Assistant\Knowledge;

use Illuminate\Support\Str;

/**
 * Mide un buscador de conocimiento contra el set etiquetado help-eval.json.
 */
class KnowledgeEvaluator
{
    private const TOP = 5;

    /**
     * @param  array<int, array{id: string, question: string, pages: array<int, array{page: string, section?: string}>, negativeType: ?string, split: string}>  $items
     * @return array{answerable: int, top1: float, top5: float, sectionTop5: float, negatives: int, abstained: float}
     */
    public function evaluate(array $items, KnowledgeSearch $search, float $semanticRatio, float $abstainBelow, ?string $split = null): array
    {
        $answerable = 0;
        $negatives = 0;
        $top1 = 0;
        $top5 = 0;
        $sectionTop5 = 0;
        $abstained = 0;

        foreach ($items as $item) {
            if ($split !== null && ($item['split'] ?? null) !== $split) {
                continue;
            }

            $results = $search->search($item['question'], null, self::TOP, $semanticRatio);
            $labels = $item['pages'] ?? [];

            if ($labels === []) {
                $negatives++;
                if ($results === [] || (float) $results[0]['score'] < $abstainBelow) {
                    $abstained++;
                }

                continue;
            }

            $answerable++;
            $top = array_slice($results, 0, self::TOP);
            $labeledPages = array_map(fn (array $label): string => $this->pageOf($label['page']), $labels);

            if ($top !== [] && in_array($this->pageOf($top[0]['page']), $labeledPages, true)) {
                $top1++;
            }

            if ($this->anyPageMatches($top, $labeledPages)) {
                $top5++;
            }

            if ($this->anySectionMatches($top, $labels)) {
                $sectionTop5++;
            }
        }

        return [
            'answerable' => $answerable,
            'top1' => $this->fraction($top1, $answerable),
            'top5' => $this->fraction($top5, $answerable),
            'sectionTop5' => $this->fraction($sectionTop5, $answerable),
            'negatives' => $negatives,
            'abstained' => $this->fraction($abstained, $negatives),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<int, string>  $labeledPages
     */
    private function anyPageMatches(array $results, array $labeledPages): bool
    {
        foreach ($results as $result) {
            if (in_array($this->pageOf($result['page']), $labeledPages, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<int, array{page: string, section?: string}>  $labels
     */
    private function anySectionMatches(array $results, array $labels): bool
    {
        foreach ($labels as $label) {
            $page = $this->pageOf($label['page']);
            $section = $label['section'] ?? null;

            foreach ($results as $result) {
                if ($this->pageOf($result['page']) !== $page) {
                    continue;
                }

                if ($section === null || ($result['anchor'] ?? null) === Str::slug($section)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function pageOf(string $page): string
    {
        return explode('#', $page, 2)[0];
    }

    private function fraction(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : $count / $total;
    }
}

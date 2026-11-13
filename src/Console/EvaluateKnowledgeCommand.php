<?php

namespace SchoolAid\Assistant\Console;

use SchoolAid\Assistant\Support\AssistantNaming;
use SchoolAid\Assistant\Knowledge\KnowledgeEvaluator;
use SchoolAid\Assistant\Knowledge\KnowledgeSearch;
use Illuminate\Console\Command;

/**
 * Compara la búsqueda de conocimiento (por razón semántica) contra help-eval.json.
 */
class EvaluateKnowledgeCommand extends Command
{
    protected $signature = 'assistant:knowledge:eval
        {--split=test : Subconjunto de help-eval.json a evaluar (calib o test)}
        {--ratio=* : Razones semánticas a comparar (default 0, 0.3, 0.5 y 0.8)}
        {--abstain-below= : Umbral de puntaje bajo el cual se abstiene (default config)}';

    protected $description = 'Evalúa el índice de conocimiento del asistente contra help-eval.json';

    public function handle(KnowledgeEvaluator $evaluator, KnowledgeSearch $search): int
    {
        $path = base_path('docs/modules/_qa-bank/help-eval.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($data) || ! isset($data['items'])) {
            $this->error('No se pudo leer docs/modules/_qa-bank/help-eval.json.');

            return self::FAILURE;
        }

        $ratios = $this->option('ratio') ?: ['0', '0.3', '0.5', '0.8'];
        $abstainBelow = $this->option('abstain-below') !== null && $this->option('abstain-below') !== ''
            ? (float) $this->option('abstain-below')
            : (float) AssistantNaming::config('knowledge.abstain_below');
        $split = (string) $this->option('split');
        $splits = array_values(array_unique(array_column($data['items'], 'split')));

        if (! in_array($split, $splits, true)) {
            $this->error("No hay preguntas con split '{$split}'. Opciones: ".implode(', ', $splits).'.');

            return self::FAILURE;
        }

        $rows = [];
        $counts = null;

        foreach ($ratios as $ratio) {
            $result = $evaluator->evaluate($data['items'], $search, (float) $ratio, $abstainBelow, $split);
            $counts = $result;
            $rows[] = [
                $ratio,
                $this->percent($result['top1']),
                $this->percent($result['top5']),
                $this->percent($result['sectionTop5']),
                $this->percent($result['abstained']),
            ];
        }

        $this->table(['Razón', 'Top-1', 'Top-5', 'Sección Top-5', 'Abstención'], $rows);
        $this->line(sprintf(
            'Split %s · respondibles %d · negativas %d · umbral %s',
            $split,
            $counts['answerable'] ?? 0,
            $counts['negatives'] ?? 0,
            $abstainBelow,
        ));

        return self::SUCCESS;
    }

    private function percent(float $value): string
    {
        return number_format($value * 100, 1).'%';
    }
}

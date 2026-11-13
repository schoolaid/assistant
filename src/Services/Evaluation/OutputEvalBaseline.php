<?php

namespace SchoolAid\Assistant\Services\Evaluation;

/**
 * La línea base del set de salida: `assistant:eval --set=output --save=<archivo>` la escribe y `--compare=<archivo>`
 * compara una corrida nueva contra ella. Una fase del asistente se despliega solo si no hay regresiones
 * (`docs/superpowers/specs/2026-11-12-asistente-salida-graficas-y-archivos-design.md` del front, §10).
 *
 * Se comparan solo los ítems que corrieron (no SKIP) en las dos:
 * - **Fallas:** no puede subir la cantidad de ítems que no pasan.
 * - **Cifras sin respaldo:** no puede subir el total, salvo las revisadas a mano y aceptadas, que se anotan en
 *   `accepted_ungrounded` del archivo de la línea base (se edita a mano, con el motivo en `accepted_notes`).
 *
 * Los ítems que pasaban y ya no, y los que suman cifras sin respaldo, se listan aunque el total no suba.
 */
final class OutputEvalBaseline
{
    /**
     * @param  list<TaskEvalResult>  $results
     * @param  array<string, mixed>  $meta  modelo, esfuerzo, market
     * @return array<string, mixed>
     */
    public static function fromResults(array $results, array $meta): array
    {
        $items = [];

        foreach ($results as $result) {
            $items[$result->id] = [
                'status' => $result->status,
                'failures' => $result->failures,
                'ungrounded' => $result->ungrounded ?? [],
                'judge' => $result->judge,
                'cost_usd' => round($result->costUsd, 4),
                'answer' => $result->finalText,
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            ...$meta,
            'accepted_ungrounded' => 0,
            'accepted_notes' => '',
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $current
     * @return array{regressions: list<string>, notes: list<string>}
     */
    public static function compare(array $baseline, array $current): array
    {
        $before = self::ran((array) ($baseline['items'] ?? []));
        $after = self::ran((array) ($current['items'] ?? []));
        $shared = array_values(array_intersect(array_keys($before), array_keys($after)));
        $notes = [];

        $failingBefore = 0;
        $failingAfter = 0;
        $ungroundedBefore = 0;
        $ungroundedAfter = 0;

        foreach ($shared as $id) {
            $was = $before[$id];
            $is = $after[$id];
            $failingBefore += (int) ($was['status'] !== TaskEvalResult::PASSED);
            $failingAfter += (int) ($is['status'] !== TaskEvalResult::PASSED);
            $wasUngrounded = count((array) ($was['ungrounded'] ?? []));
            $isUngrounded = count((array) ($is['ungrounded'] ?? []));
            $ungroundedBefore += $wasUngrounded;
            $ungroundedAfter += $isUngrounded;

            if ($was['status'] === TaskEvalResult::PASSED && $is['status'] !== TaskEvalResult::PASSED) {
                $notes[] = "{$id}: pasaba y ahora {$is['status']} (".implode('; ', (array) ($is['failures'] ?? [])).')';
            }

            if ($isUngrounded > $wasUngrounded) {
                $notes[] = "{$id}: cifras sin respaldo {$wasUngrounded} → {$isUngrounded} (".implode(', ', (array) $is['ungrounded']).')';
            }
        }

        $allowed = $ungroundedBefore + max(0, (int) ($baseline['accepted_ungrounded'] ?? 0));
        $regressions = [];

        if ($failingAfter > $failingBefore) {
            $regressions[] = "Fallan {$failingAfter} ítems; en la línea base fallaban {$failingBefore}.";
        }

        if ($ungroundedAfter > $allowed) {
            $regressions[] = "Hay {$ungroundedAfter} cifras sin respaldo; la línea base admite {$allowed}.";
        }

        $missing = array_values(array_diff(array_keys($before), array_keys($after)));

        if ($missing !== []) {
            $notes[] = 'No corrieron ahora (no se comparan): '.implode(', ', $missing).'.';
        }

        return ['regressions' => $regressions, 'notes' => $notes];
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, array<string, mixed>>
     */
    private static function ran(array $items): array
    {
        return array_filter(
            $items,
            fn (mixed $item): bool => is_array($item) && ($item['status'] ?? TaskEvalResult::SKIPPED) !== TaskEvalResult::SKIPPED,
        );
    }
}

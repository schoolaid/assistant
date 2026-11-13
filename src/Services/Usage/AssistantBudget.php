<?php

namespace SchoolAid\Assistant\Services\Usage;

use SchoolAid\Assistant\Contracts\BudgetAlerts;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Enums\AssistantKeySourceEnum;
use SchoolAid\Assistant\Enums\AssistantLimitEnum;
use SchoolAid\Assistant\Models\AssistantUsage;
use SchoolAid\Assistant\Models\AssistantUserUsage;
use SchoolAid\Assistant\Services\Anthropic\AnthropicResponse;
use SchoolAid\Assistant\Services\Settings\AssistantSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consumo mensual del asistente por market y por usuario contra sus topes en USD.
 *
 * El consumo vive en la base central (`assistant_usage`, `assistant_user_usage`); los topes efectivos los resuelve
 * {@see AssistantSettings} (con la llave propia del market, los de SAID no aplican). Las sumas son incrementos atómicos
 * en SQL: dos workers del mismo market no se pisan. Lo pagado con la llave propia se suma además en
 * `own_key_cost_usd`: el tope del market cuenta todo, lo que paga SAID es la diferencia.
 *
 * Avisos, una vez por mes cada uno: a SAID (central) al cruzar `assistant.limits.warning_percent` del tope, solo si la
 * respuesta salió con la llave de SAID; al market (campana de quien tiene `Assistant`·`update`) al cruzar su
 * `warning_percent` efectivo.
 */
class AssistantBudget
{
    public function __construct(
        private readonly AssistantSettings $settings,
        private readonly BudgetAlerts $alerts,
        private readonly Workspace $workspace,
    ) {
    }

    public function currentPeriod(): string
    {
        return now(config('app.timezone'))->format('Y-m');
    }

    /**
     * Tope mensual efectivo del market; null = sin tope (solo con llave propia y sin tope elegido).
     */
    public function capUsd(): ?float
    {
        return $this->settings->effective(AssistantLimitEnum::MonthlyBudgetUsd);
    }

    /**
     * Tope mensual efectivo de cada usuario; null = sin tope por usuario.
     */
    public function userCapUsd(): ?float
    {
        return $this->settings->effective(AssistantLimitEnum::UserMonthlyBudgetUsd);
    }

    public function spentUsd(): float
    {
        return (float) AssistantUsage::query()
            ->where('tenant_id', $this->workspace->id())
            ->where('period', $this->currentPeriod())
            ->value('cost_usd');
    }

    public function userSpentUsd(int $userId): float
    {
        return (float) AssistantUserUsage::query()
            ->where('tenant_id', $this->workspace->id())
            ->where('period', $this->currentPeriod())
            ->where('user_id', $userId)
            ->value('cost_usd');
    }

    /**
     * El market ya no puede abrir turnos este mes: tope 0 o el gasto del periodo en el tope o encima. Sin tope (null)
     * nunca. La misma regla del 429 `assistant_budget_exceeded` ({@see self::ensureWithinBudget()}); la página la lee al
     * cargar.
     */
    public function isExceeded(): bool
    {
        return self::reached($this->capUsd(), fn (): float => $this->spentUsd());
    }

    /**
     * La persona llegó a su tope personal del mes (429 `assistant_user_budget_exceeded`).
     */
    public function isUserExceeded(int $userId): bool
    {
        return self::reached($this->userCapUsd(), fn (): float => $this->userSpentUsd($userId));
    }

    /**
     * @throws AssistantBudgetExceeded
     */
    public function ensureWithinBudget(?int $userId = null): void
    {
        if ($this->isExceeded()) {
            throw new AssistantBudgetExceeded();
        }

        if ($userId !== null && $this->isUserExceeded($userId)) {
            throw AssistantBudgetExceeded::forUser();
        }
    }

    /**
     * Suma la respuesta al consumo del mes del market (y del usuario, si se sabe quién) y devuelve su costo en USD.
     */
    public function record(AnthropicResponse $response, ?int $userId = null): float
    {
        $cost = $this->costOf($response);
        $amount = number_format($cost, 6, '.', '');
        $ownKey = $response->keySource === AssistantKeySourceEnum::Own;
        $usage = $this->usageRow();

        AssistantUsage::query()->whereKey($usage->getKey())->update([
            'input_tokens' => DB::raw('input_tokens + '.$response->inputTokens),
            'output_tokens' => DB::raw('output_tokens + '.$response->outputTokens),
            'cache_read_tokens' => DB::raw('cache_read_tokens + '.$response->cacheReadTokens),
            'cache_write_tokens' => DB::raw('cache_write_tokens + '.$response->cacheWriteTokens),
            'cost_usd' => DB::raw('cost_usd + '.$amount),
            'own_key_cost_usd' => DB::raw('own_key_cost_usd + '.($ownKey ? $amount : '0')),
        ]);

        if ($userId !== null) {
            $userUsage = $this->userUsageRow($userId);

            AssistantUserUsage::query()->whereKey($userUsage->getKey())->update([
                'responses' => DB::raw('responses + 1'),
                'cost_usd' => DB::raw('cost_usd + '.$amount),
            ]);
        }

        if (! $ownKey) {
            $this->warnSaidOnce($usage);
        }

        $this->warnMarketOnce($usage);

        return $cost;
    }

    /**
     * La escritura de caché de 1 hora va a `cache_write_1h` (sin esa tarifa en la fila, 2 x la
     * entrada, que es la regla publicada); el resto de la escritura, a `cache_write`.
     */
    public function costOf(AnthropicResponse $response): float
    {
        $prices = $this->pricesFor($response->model);
        $longWrites = min($response->cacheWrite1hTokens, $response->cacheWriteTokens);
        $longWritePrice = (float) ($prices['cache_write_1h'] ?? 2 * (float) ($prices['input'] ?? 0));

        return (
            $response->inputTokens * (float) ($prices['input'] ?? 0)
            + $response->outputTokens * (float) ($prices['output'] ?? 0)
            + $response->cacheReadTokens * (float) ($prices['cache_read'] ?? 0)
            + ($response->cacheWriteTokens - $longWrites) * (float) ($prices['cache_write'] ?? 0)
            + $longWrites * $longWritePrice
        ) / 1_000_000;
    }

    /**
     * @param  callable(): float  $spent
     */
    private static function reached(?float $cap, callable $spent): bool
    {
        if ($cap === null) {
            return false;
        }

        return $cap <= 0 || $spent() >= $cap;
    }

    /**
     * Fila de precios del modelo que respondió. Si no tiene fila, el precio más alto de la tabla en
     * cada componente: así el registro nunca queda por debajo de ningún modelo con fila (la fila más
     * cara por salida podía tener la lectura de caché más barata, que es casi todo el costo de un
     * turno). Sigue sin ser un tope para un modelo más caro que toda la tabla: el aviso del log es
     * para agregar su fila.
     *
     * @return array<string, float|int>
     */
    private function pricesFor(string $model): array
    {
        $table = (array) config('assistant.pricing', []);

        if (isset($table[$model])) {
            return (array) $table[$model];
        }

        Log::warning('Assistant priced an unknown model with the highest price of each component: add its row to assistant.pricing.', ['model' => $model]);

        $highest = [];

        foreach ($table as $row) {
            foreach ((array) $row as $component => $price) {
                $highest[$component] = max((float) ($highest[$component] ?? 0), (float) $price);
            }
        }

        return $highest;
    }

    private function usageRow(): AssistantUsage
    {
        return $this->firstOrCreate(AssistantUsage::class, ['tenant_id' => $this->workspace->id(), 'period' => $this->currentPeriod()]);
    }

    private function userUsageRow(int $userId): AssistantUserUsage
    {
        return $this->firstOrCreate(AssistantUserUsage::class, [
            'tenant_id' => $this->workspace->id(), 'period' => $this->currentPeriod(), 'user_id' => $userId,
        ]);
    }

    /**
     * Dos workers pueden crear la misma fila a la vez: el índice único rechaza al segundo, que la lee.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $keys
     * @return TModel
     */
    private function firstOrCreate(string $model, array $keys): Model
    {
        try {
            return $model::query()->firstOrCreate($keys);
        } catch (QueryException) {
            return $model::query()->where($keys)->firstOrFail();
        }
    }

    private function warnSaidOnce(AssistantUsage $usage): void
    {
        $cap = $this->capUsd();
        $ratio = (float) config('assistant.limits.warning_percent', 80) / 100;

        if ($cap === null || $cap <= 0) {
            return;
        }

        $cost = (float) AssistantUsage::query()->whereKey($usage->getKey())->value('cost_usd');

        if ($cost / $cap < $ratio || ! $this->claim($usage, 'warned_at')) {
            return;
        }

        $this->alerts->warnPlatform((int) $usage->getKey(), $usage->period, $cost, $cap);
    }

    /**
     * El aviso al market es un extra: si falla, la respuesta ya quedó cobrada y el turno sigue.
     */
    private function warnMarketOnce(AssistantUsage $usage): void
    {
        $cap = $this->capUsd();
        $percent = $this->settings->effective(AssistantLimitEnum::WarningPercent);

        if ($cap === null || $cap <= 0 || $percent === null) {
            return;
        }

        $cost = (float) AssistantUsage::query()->whereKey($usage->getKey())->value('cost_usd');

        if ($cost / $cap < $percent / 100 || ! $this->claim($usage, 'market_warned_at')) {
            return;
        }

        try {
            $this->alerts->warnWorkspace((int) $usage->getKey(), $usage->period, $cost, $cap);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function claim(AssistantUsage $usage, string $column): bool
    {
        return AssistantUsage::query()
            ->whereKey($usage->getKey())
            ->whereNull($column)
            ->update([$column => now()]) === 1;
    }
}

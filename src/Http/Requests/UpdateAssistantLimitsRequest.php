<?php

namespace SchoolAid\Assistant\Http\Requests;

use SchoolAid\Assistant\Contracts\AccessGate;
use SchoolAid\Assistant\Enums\AssistantLimitEnum;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los límites que elige el market: cada clave es opcional y null vuelve al valor por omisión. Techo y bloqueo los
 * revisa {@see \SchoolAid\Assistant\Services\Settings\AssistantSettingsUpdater::updateMarketLimits()}.
 */
class UpdateAssistantLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AccessGate::class)->canManageSettings($this->user());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (AssistantLimitEnum::cases() as $limit) {
            $rules[$limit->value] = ['sometimes', ...$limit->rules()];
        }

        return $rules;
    }

    /**
     * @return array<string, float|int|null>
     */
    public function limits(): array
    {
        return array_intersect_key($this->validated(), array_flip(array_column(AssistantLimitEnum::cases(), 'value')));
    }
}

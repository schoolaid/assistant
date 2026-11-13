<?php

namespace SchoolAid\Assistant\Http\Requests;

use SchoolAid\Assistant\Contracts\AccessGate;
use SchoolAid\Assistant\Enums\AssistantLimitEnum;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo general de central: por límite, `{default?, max?}` (null quita el valor). El techo general del tope mensual y el
 * del aviso se ignoran ({@see \SchoolAid\Assistant\Services\Settings\AssistantSettingsUpdater::updateGeneralLimits()}).
 */
class UpdateCentralAssistantLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(AccessGate::class)->canManagePlatformSettings($this->user());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach (AssistantLimitEnum::cases() as $limit) {
            $rules[$limit->value] = ['sometimes', 'array'];
            $rules["{$limit->value}.default"] = ['sometimes', ...$limit->rules()];
            $rules["{$limit->value}.max"] = ['sometimes', ...$limit->rules()];
        }

        return $rules;
    }

    /**
     * @return array<string, array{default?: float|int|null, max?: float|int|null}>
     */
    public function limits(): array
    {
        return array_intersect_key($this->validated(), array_flip(array_column(AssistantLimitEnum::cases(), 'value')));
    }
}

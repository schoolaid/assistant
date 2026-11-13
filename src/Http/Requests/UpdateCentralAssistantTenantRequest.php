<?php

namespace SchoolAid\Assistant\Http\Requests;

use SchoolAid\Assistant\Contracts\AccessGate;
use SchoolAid\Assistant\Enums\AssistantLimitEnum;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo que central fija para un market: el interruptor, si puede traer su llave y, por límite, `{max?, lock?}`. `lock`
 * null quita el bloqueo; `{value}` lo pone y su valor es obligatorio (un bloqueo sin valor dejaría un tope de dinero
 * sin límite).
 */
class UpdateCentralAssistantTenantRequest extends FormRequest
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
        $rules = [
            'enabled' => ['sometimes', 'boolean'],
            'own_key_allowed' => ['sometimes', 'boolean'],
            'overrides' => ['sometimes', 'array'],
        ];

        foreach (AssistantLimitEnum::cases() as $limit) {
            $key = "overrides.{$limit->value}";
            $rules[$key] = ['sometimes', 'array'];
            $rules["{$key}.max"] = ['sometimes', ...$limit->rules()];
            $rules["{$key}.lock"] = ['sometimes', 'nullable', 'array'];
            $rules["{$key}.lock.value"] = ['required_with:'.$key.'.lock', ...array_values(array_diff($limit->rules(), ['nullable']))];
        }

        return $rules;
    }

    /**
     * @return array<string, array{max?: float|int|null, lock?: array{value: float|int}|null}>
     */
    public function overrides(): array
    {
        return array_intersect_key(
            (array) ($this->validated()['overrides'] ?? []),
            array_flip(array_column(AssistantLimitEnum::cases(), 'value')),
        );
    }
}

<?php

namespace SchoolAid\Assistant\Http\Requests;

use SchoolAid\Assistant\Services\Entities\AssistantMentions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los registros a etiquetar antes de adjuntarlos (`refs`, por query string): las mismas reglas y textos que `mentions`
 * al enviar un mensaje ({@see AssistantMentions}), pero obligatorios.
 */
class LabelAssistantEntitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return AssistantMentions::rules('refs', required: true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AssistantMentions::messages('refs');
    }
}

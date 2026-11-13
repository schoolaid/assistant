<?php

namespace SchoolAid\Assistant\Http\Requests;

use SchoolAid\Assistant\Support\AssistantNaming;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La conversación es de quien la crea; el acceso (doble llave) lo revisa EnsureAssistantAccess.
 */
class StoreAssistantConversationRequest extends FormRequest
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
        return [
            'title' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.string' => AssistantNaming::trans('validation.title_string'),
            'title.max' => AssistantNaming::trans('validation.title_max'),
        ];
    }
}

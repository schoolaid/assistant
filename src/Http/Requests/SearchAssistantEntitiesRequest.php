<?php

namespace SchoolAid\Assistant\Http\Requests;

use SchoolAid\Assistant\Support\AssistantNaming;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La búsqueda del selector `@` (por query string): el texto (`q`) y, para ofrecer sus registros cuando no hay texto,
 * la conversación abierta (`conversation`). Si es de otra persona lo decide el controller con la policy.
 */
class SearchAssistantEntitiesRequest extends FormRequest
{
    /**
     * Caracteres máximos del texto a buscar.
     */
    public const MAX_QUERY = 100;

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
            'q' => ['nullable', 'string', 'max:'.self::MAX_QUERY],
            'conversation' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $conversation = AssistantNaming::trans('validation.search_conversation_invalid');

        return [
            'q.string' => AssistantNaming::trans('validation.search_q_string'),
            'q.max' => AssistantNaming::trans('validation.search_q_max', ['max' => self::MAX_QUERY]),
            'conversation.integer' => $conversation,
            'conversation.min' => $conversation,
        ];
    }
}

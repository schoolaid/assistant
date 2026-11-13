<?php

namespace SchoolAid\Assistant\Http\Requests;

use SchoolAid\Assistant\Support\AssistantNaming;
use SchoolAid\Assistant\Models\AssistantMessage;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * «Útil / no útil» de una respuesta del asistente, con comentario opcional. Solo el autor de la conversación califica, y
 * solo mensajes del asistente: `assistant:feedback` lee cada «no útil» junto al pedido que lo antecede.
 */
class StoreAssistantFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('message'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'helpful' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'helpful.required' => AssistantNaming::trans('validation.helpful_required'),
            'helpful.boolean' => AssistantNaming::trans('validation.helpful_boolean'),
            'comment.string' => AssistantNaming::trans('validation.comment_string'),
            'comment.max' => AssistantNaming::trans('validation.comment_max'),
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $message = $this->route('message');

                if ($message instanceof AssistantMessage && $message->role !== 'assistant') {
                    $validator->errors()->add('helpful', AssistantNaming::trans('validation.feedback_not_an_answer'));
                }
            },
        ];
    }
}

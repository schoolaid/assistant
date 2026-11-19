<?php

namespace SchoolAid\Assistant\Http\Requests;

use SchoolAid\Assistant\Services\Entities\AssistantMentions;
use SchoolAid\Assistant\Support\AssistantNaming;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Solo el autor escribe en su conversación ({@see \SchoolAid\Assistant\Policies\AssistantConversationPolicy}).
 *
 * Un mensaje lleva `content` (lo escrito) o `choice_id` (la opción elegida con un botón), nunca los dos. Que la opción
 * se pueda elegir lo revisa {@see \SchoolAid\Assistant\Services\AssistantMessageSender::sendChoice()}.
 *
 * `mentions` ({@see AssistantMentions::rules()}) solo acompaña a `content`. Que cada adjunto se pueda ver lo revisa
 * {@see \SchoolAid\Assistant\Services\AssistantMessageSender::send()}.
 */
class StoreAssistantMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('conversation'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => ['required_without:choice_id', 'nullable', 'string', 'max:4000'],
            'choice_id' => ['nullable', 'string', 'max:64', 'prohibits:content,mentions'],
            ...AssistantMentions::rules('mentions'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required_without' => AssistantNaming::trans('validation.content_required'),
            'content.string' => AssistantNaming::trans('validation.content_string'),
            'content.max' => AssistantNaming::trans('validation.content_max'),
            'choice_id.string' => AssistantNaming::trans('validation.choice_id_string'),
            'choice_id.max' => AssistantNaming::trans('validation.choice_id_string'),
            'choice_id.prohibits' => AssistantNaming::trans('validation.choice_id_with_content'),
            ...AssistantMentions::messages('mentions'),
        ];
    }
}

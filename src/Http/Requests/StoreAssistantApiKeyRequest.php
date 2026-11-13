<?php

namespace SchoolAid\Assistant\Http\Requests;

use SchoolAid\Assistant\Contracts\AccessGate;
use SchoolAid\Assistant\Contracts\Workspace;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Una llave de Anthropic para guardar: la propia del market (`web-api`) o la general (`central-api`). Que Anthropic la
 * acepte lo prueba {@see \SchoolAid\Assistant\Services\Settings\AssistantApiKeys::save()}.
 */
class StoreAssistantApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $gate = app(AccessGate::class);

        return app(Workspace::class)->id() !== null
            ? $gate->canManageSettings($this->user())
            : $gate->canManagePlatformSettings($this->user());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['api_key' => ['required', 'string', 'min:20', 'max:300']];
    }
}

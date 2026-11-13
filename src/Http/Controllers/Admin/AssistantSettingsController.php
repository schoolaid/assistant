<?php

namespace SchoolAid\Assistant\Http\Controllers\Admin;

use SchoolAid\Assistant\Contracts\AccessGate;
use SchoolAid\Assistant\Http\Controllers\AssistantController;
use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Http\Requests\StoreAssistantApiKeyRequest;
use SchoolAid\Assistant\Http\Requests\UpdateAssistantLimitsRequest;
use SchoolAid\Assistant\Services\Settings\AssistantApiKeys;
use SchoolAid\Assistant\Services\Settings\AssistantSettings;
use SchoolAid\Assistant\Services\Settings\AssistantSettingsPresenter;
use SchoolAid\Assistant\Services\Settings\AssistantSettingsRejected;
use SchoolAid\Assistant\Services\Settings\AssistantSettingsUpdater;
use SchoolAid\Assistant\Services\Usage\AssistantBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * Configuración del asistente en el panel del market (`web-api/assistant/settings`): su llave propia, sus límites y su
 * consumo. Todo pide `Assistant`·`update`; no pide el asistente encendido (se puede preparar antes de que SAID lo
 * encienda). Lo que central fijó se ve, pero no se cambia desde aquí.
 */
class AssistantSettingsController extends AssistantController
{
    public function __construct(private readonly AssistantSettingsPresenter $presenter)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json(['data' => $this->presenter->market()]);
    }

    /**
     * @throws AssistantSettingsRejected
     */
    public function updateLimits(UpdateAssistantLimitsRequest $request, AssistantSettingsUpdater $updater): JsonResponse
    {
        $updater->updateMarketLimits($request->limits());

        return response()->json(['data' => $this->presenter->market()]);
    }

    /**
     * Solo si central le permite al market traer su llave (403 `assistant_own_key_not_allowed`). La llave se prueba con
     * Anthropic antes de guardarse (422 `assistant_api_key_invalid`).
     *
     * @throws AssistantSettingsRejected
     */
    public function storeApiKey(StoreAssistantApiKeyRequest $request, AssistantSettings $settings, AssistantApiKeys $keys): JsonResponse
    {
        if (! $settings->ownKeyAllowed()) {
            throw AssistantSettingsRejected::on('api_key', AssistantSettingsRejected::OWN_KEY_NOT_ALLOWED, [], HttpStatus::HTTP_FORBIDDEN);
        }

        $keys->save((string) app(Workspace::class)->id(), (string) $request->validated('api_key'), $request->user()?->email);

        return response()->json(['data' => $this->presenter->market()]);
    }

    /**
     * Quitar la llave propia vuelve a la de SAID, y con ella a sus topes.
     */
    public function destroyApiKey(Request $request, AssistantApiKeys $keys): Response
    {
        $this->guard($request);
        $keys->delete((string) app(Workspace::class)->id());

        return response()->noContent();
    }

    /**
     * Consumo de un mes (`period`, `Y-m`; por omisión el actual) por usuario.
     */
    public function usage(Request $request, AssistantBudget $budget): JsonResponse
    {
        $this->guard($request);
        $period = $request->validate(['period' => ['sometimes', 'date_format:Y-m']])['period'] ?? $budget->currentPeriod();

        return response()->json(['data' => $this->presenter->usage($period)]);
    }

    private function guard(Request $request): void
    {
        abort_unless(app(AccessGate::class)->canManageSettings($request->user()), HttpStatus::HTTP_FORBIDDEN);
    }
}

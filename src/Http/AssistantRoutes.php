<?php

namespace SchoolAid\Assistant\Http;

use SchoolAid\Assistant\Contracts\Workspace;
use SchoolAid\Assistant\Enums\AssistantLimitEnum;
use SchoolAid\Assistant\Http\Controllers\Admin\AssistantConfirmationController;
use SchoolAid\Assistant\Http\Controllers\Admin\AssistantConversationController;
use SchoolAid\Assistant\Http\Controllers\Admin\AssistantEntityController;
use SchoolAid\Assistant\Http\Controllers\Admin\AssistantExportController;
use SchoolAid\Assistant\Http\Controllers\Admin\AssistantFeedbackController;
use SchoolAid\Assistant\Http\Controllers\Admin\AssistantMessageController;
use SchoolAid\Assistant\Http\Controllers\Admin\AssistantSettingsController;
use SchoolAid\Assistant\Http\Controllers\Admin\AssistantSuggestionController;
use SchoolAid\Assistant\Http\Controllers\Admin\AssistantTurnController;
use SchoolAid\Assistant\Http\Middleware\EnsureAssistantAccess;
use SchoolAid\Assistant\Services\Settings\AssistantSettings;
use SchoolAid\Assistant\Support\AssistantNaming;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Las rutas del asistente en el panel y su limitador de peticiones. La app las monta dentro de su
 * propio grupo, donde ya resolvió la sesión, la persona y el espacio de trabajo, con el prefijo de
 * URL y de nombres que quiera.
 */
final class AssistantRoutes
{
    /**
     * - Doble llave en {@see EnsureAssistantAccess} para todas las rutas del chat. El limitador
     *   solo en las que escriben (R-P2-37): el panel hace poll de `turns/{turn}` mientras el
     *   turno corre.
     * - Cada conversación, con sus turnos, confirmaciones y mensajes, es solo de su autor (la
     *   policy que la app registra para los modelos del asistente).
     * - La configuración (llave propia, límites y consumo) pide el permiso de configurar en el
     *   controller y sus FormRequest, pero no el asistente encendido: va fuera de la doble llave.
     *
     * @param  string  $prefix  segmento de URL de las rutas
     * @param  string  $name  prefijo de sus nombres, con el punto final
     */
    public static function panel(string $prefix = 'assistant', string $name = 'assistant.'): void
    {
        Route::prefix($prefix)
            ->middleware([EnsureAssistantAccess::class])
            ->name($name)
            ->group(function (): void {
                Route::get('conversations', [AssistantConversationController::class, 'index'])->name('conversations.index');
                Route::get('conversations/{conversation}/messages', [AssistantMessageController::class, 'index'])
                    ->whereNumber('conversation')->name('conversations.messages.index');
                Route::get('conversations/{conversation}/entities', [AssistantEntityController::class, 'index'])
                    ->whereNumber('conversation')->name('conversations.entities.index');
                Route::get('entities/label', [AssistantEntityController::class, 'label'])->name('entities.label');
                Route::get('entities/search', [AssistantEntityController::class, 'search'])->name('entities.search');
                Route::get('suggestions', [AssistantSuggestionController::class, 'index'])->name('suggestions.index');
                Route::get('turns/{turn}', [AssistantTurnController::class, 'show'])
                    ->whereNumber('turn')->name('turns.show');
                Route::get('exports/{export}', [AssistantExportController::class, 'show'])
                    ->whereUuid('export')->name('exports.show');
                Route::get('exports/{export}/download', [AssistantExportController::class, 'download'])
                    ->whereUuid('export')->name('exports.download');

                Route::middleware(self::throttle())->group(function (): void {
                    Route::post('conversations', [AssistantConversationController::class, 'store'])->name('conversations.store');
                    Route::post('conversations/{conversation}/messages', [AssistantMessageController::class, 'store'])
                        ->whereNumber('conversation')->name('conversations.messages.store');

                    Route::post('confirmations/{confirmation}/confirm', [AssistantConfirmationController::class, 'confirm'])
                        ->whereNumber('confirmation')->name('confirmations.confirm');
                    Route::post('confirmations/{confirmation}/reject', [AssistantConfirmationController::class, 'reject'])
                        ->whereNumber('confirmation')->name('confirmations.reject');

                    Route::post('messages/{message}/feedback', [AssistantFeedbackController::class, 'store'])
                        ->whereNumber('message')->name('messages.feedback');
                });
            });

        Route::prefix("{$prefix}/settings")
            ->name("{$name}settings.")
            ->group(function (): void {
                Route::get('/', [AssistantSettingsController::class, 'show'])->name('show');
                Route::put('limits', [AssistantSettingsController::class, 'updateLimits'])->name('limits.update');
                Route::put('api-key', [AssistantSettingsController::class, 'storeApiKey'])->name('api-key.store');
                Route::delete('api-key', [AssistantSettingsController::class, 'destroyApiKey'])->name('api-key.destroy');
                Route::get('usage', [AssistantSettingsController::class, 'usage'])->name('usage');
            });
    }

    /**
     * El middleware de las rutas que escriben: el limitador lleva el nombre del asistente en la app.
     */
    public static function throttle(): string
    {
        return 'throttle:'.AssistantNaming::name();
    }

    /**
     * Registra el limitador: el ritmo efectivo del espacio de trabajo
     * ({@see AssistantLimitEnum::MessagesPerMinute}: lo elige el espacio bajo el techo de la
     * plataforma, que puede bloquearlo), por espacio + persona. El id del espacio va en la llave
     * porque los ids de usuario se repiten entre bases. Con llave propia el techo sigue: la cola y
     * los workers son de la plataforma.
     */
    public static function rateLimiter(): void
    {
        RateLimiter::for(AssistantNaming::name(), function (Request $request): Limit {
            $user = $request->user();
            $identity = $user !== null ? 'u:'.$user->getAuthIdentifier() : 'ip:'.$request->ip();

            $perMinute = (int) (app(AssistantSettings::class)->effective(AssistantLimitEnum::MessagesPerMinute)
                ?? AssistantLimitEnum::MessagesPerMinute->builtInDefault());

            return Limit::perMinute(max(1, $perMinute))->by(app(Workspace::class)->id().'|'.$identity);
        });
    }
}

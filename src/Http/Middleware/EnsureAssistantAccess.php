<?php

namespace SchoolAid\Assistant\Http\Middleware;

use SchoolAid\Assistant\Contracts\AccessGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Doble llave de `web-api/assistant`: `assistant_enabled` del tenant y `Assistant`·`index` del
 * usuario. Responde 403 JSON `{message, code}` con el código de {@see AssistantAccess::denialFor()}.
 */
class EnsureAssistantAccess
{
    public function __construct(private readonly AccessGate $access)
    {
    }

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $denial = $this->access->denialFor($request->user());

        if ($denial !== null) {
            return response()->json([
                'message' => $this->access->message($denial),
                'code' => $denial,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}

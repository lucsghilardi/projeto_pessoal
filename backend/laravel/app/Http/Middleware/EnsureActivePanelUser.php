<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActivePanelUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');

        if ($user) {
            $user->refresh();
        }

        if (! $user?->is_active) {
            Auth::guard('api')->logout();

            return new JsonResponse([
                'message' => 'Sessao invalida para este usuario.',
            ], 401);
        }

        return $next($request);
    }
}

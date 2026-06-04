<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $credentials['email'] = Str::lower(trim($credentials['email']));
        $throttleKey = $this->throttleKey($credentials['email'], $request->ip());
        $attemptCredentials = [
            ...$credentials,
            'is_active' => true,
        ];

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return response()->json([
                'message' => 'Muitas tentativas de login. Tente novamente em alguns minutos.',
                'retry_after' => RateLimiter::availableIn($throttleKey),
            ], 429);
        }

        if (! $token = Auth::guard('api')->attempt($attemptCredentials)) {
            RateLimiter::hit($throttleKey, 300);

            return response()->json([
                'message' => 'Email ou senha invalidos.',
            ], 401);
        }

        RateLimiter::clear($throttleKey);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
        ]);
    }

    public function me(): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        return response()->json($user);
    }

    public function logout(): JsonResponse
    {
        Auth::guard('api')->logout();

        return response()->json([
            'message' => 'Sessao encerrada com sucesso.',
        ]);
    }

    private function throttleKey(string $email, ?string $ipAddress): string
    {
        return sprintf('%s|%s', Str::lower($email), $ipAddress ?? 'unknown');
    }
}

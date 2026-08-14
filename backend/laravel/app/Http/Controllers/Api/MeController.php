<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;

/**
 * A própria conta. Até aqui só o admin trocava senha (pela tela de Usuários),
 * então quem esquecia dependia dele — e não existe fluxo de recuperação por
 * e-mail (MAIL_MAILER=log).
 */
class MeController extends Controller
{
    public function updatePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user('api');

        // Sem isto o campo "senha atual" vira um oráculo: dá para descobrir a
        // senha por tentativa em cima de um token já válido. Mesmo teto do login.
        $chave = sprintf('senha:%d|%s', $user->id, $request->ip() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($chave, 5)) {
            return response()->json([
                'message' => 'Muitas tentativas. Tente novamente em alguns minutos.',
                'retry_after' => RateLimiter::availableIn($chave),
            ], 429);
        }

        $data = $request->validate([
            'senha_atual' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if (! Hash::check($data['senha_atual'], $user->password)) {
            RateLimiter::hit($chave, 300);

            return response()->json(['message' => 'A senha atual não confere.'], 422);
        }

        RateLimiter::clear($chave);

        $user->update(['password' => Hash::make($data['password'])]);

        // O JWT não carrega o hash da senha, então o token antigo continuaria
        // valendo até expirar. `refresh` o coloca na blacklist e devolve um novo
        // — a rota do Next troca o cookie para quem está trocando a senha.
        $token = Auth::guard('api')->refresh();

        return response()->json([
            'message' => 'Senha alterada com sucesso.',
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
        ]);
    }
}

<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureActivePanelUser;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function ($middleware) {
        $middleware->redirectGuestsTo(fn () => null);

        // Sem isto o grupo `api` do Laravel 12 não tem throttle NENHUM: só o
        // login e a troca de senha se defendiam, cada um com o seu RateLimiter
        // à mão. Os limites de `api` e `ia` estão no AppServiceProvider.
        $middleware->throttleApi();

        // O painel fala com a API pelo proxy do Next, então sem confiar no
        // X-Forwarded-For todo request chega com o IP do container do Next: o
        // throttle do login perde a dimensão de origem e nenhum log sabe de
        // onde veio o ataque. As faixas são as privadas do compose — o nginx
        // do container `backend` não é publicado, então só a rede interna
        // chega aqui. Proto entra para o Laravel gerar URL https; HOST fica
        // FORA de propósito: aceitar X-Forwarded-Host deixaria o Host da
        // requisição na mão de quem chama.
        $middleware->trustProxies(
            at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'auth' => Authenticate::class,
            'panel.active' => EnsureActivePanelUser::class,
        ]);
    })
    ->withExceptions(function ($exceptions) {
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }
        });
    })->create();

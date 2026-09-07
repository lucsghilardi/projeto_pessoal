<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurarLimites();
    }

    /**
     * Tetos de requisição da API. O `api` é aplicado ao grupo inteiro pelo
     * `throttleApi()` do bootstrap/app.php; o `ia` é pendurado nas rotas que
     * chamam a Anthropic (ver routes/api.php).
     */
    private function configurarLimites(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // O webhook da Evolution chega em rajada — uma entrega por mensagem
            // e outra por atualização de status — e já é autenticado por token
            // no controller. Balde próprio para não competir com o painel.
            if ($request->is('api/whatsapp/webhook/*')) {
                return Limit::perMinute(600)->by('whatsapp-webhook');
            }

            // Por conta quando autenticado: uma pessoa não derruba a outra.
            // 180/min cobre folgado a tela mais pesada do painel, que dispara
            // menos de uma dúzia de chamadas ao abrir.
            return Limit::perMinute(180)->by($request->user()?->id ?: $request->ip());
        });

        // Rotas que chamam a Anthropic de forma síncrona (até 16k tokens de
        // saída, 120s de timeout). Sem teto, uma sessão válida queima crédito e
        // prende workers do php-fpm — DoS barato e com fatura. 40/h deixa
        // passar até uma leva grande de comprovantes num dia de organização.
        RateLimiter::for('ia', fn (Request $request) => Limit::perHour(40)
            ->by($request->user()?->id ?: $request->ip()));
    }
}

<?php

use App\Jobs\GerarRelatorioDiario;
use App\Jobs\GerarResumoMatinal;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Relatórios do módulo WhatsApp — executados pelo container `scheduler`
// (php artisan schedule:work). Horários e timezone em config/whatsapp.php.
Schedule::job(new GerarResumoMatinal)
    ->dailyAt((string) config('whatsapp.relatorio.hora_matinal'))
    ->timezone((string) config('whatsapp.relatorio.timezone'));

Schedule::job(new GerarRelatorioDiario)
    ->dailyAt((string) config('whatsapp.relatorio.hora_diario'))
    ->timezone((string) config('whatsapp.relatorio.timezone'));

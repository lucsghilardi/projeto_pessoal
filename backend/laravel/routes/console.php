<?php

use App\Jobs\EnviarLembretesSuplementos;
use App\Jobs\GerarRelatorioDiario;
use App\Jobs\GerarResumoMatinal;
use App\Jobs\LimparAnexosWhatsappOrfaos;
use App\Jobs\SincronizarGarmin;
use App\Jobs\VerificarSessaoWhatsapp;
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

// Módulo Saúde: lembra no WhatsApp os suplementos com horário vencido e sem
// check-in no dia. O job calcula "hoje" em config('saude.timezone') e não
// reenvia o mesmo lembrete (tabela saude_lembretes).
Schedule::job(new EnviarLembretesSuplementos)->everyFiveMinutes();

// Módulo WhatsApp: sonda o socket de cada instância e reergue o que morreu sem
// avisar (sessão zumbi). Sem isto a queda só aparece quando alguém abre a tela
// — em 28/08/2026 foram dois dias parados. A cada 10 min: a sonda toca o
// servidor do WhatsApp, não convém rodar de minuto em minuto.
Schedule::job(new VerificarSessaoWhatsapp)
    ->everyTenMinutes()
    ->withoutOverlapping();

// Módulo WhatsApp: apaga fotos que ficaram no staging (whatsapp/pendentes) sem
// nunca virar refeição ou comprovante — conversa abandonada ou worker morto.
Schedule::job(new LimparAnexosWhatsappOrfaos)
    ->dailyAt('04:00')
    ->timezone((string) config('whatsapp.relatorio.timezone'));

// Módulo Saúde: importa as atividades do Garmin Connect pelo sidecar `garmin`.
// Reprocessa a janela de config('garmin.dias_janela') dias — o dedupe por
// garmin_activity_id garante que reimportar não duplica. Sem GARMIN_ATIVO=true
// o job retorna sem fazer nada.
Schedule::job(new SincronizarGarmin)->hourly();

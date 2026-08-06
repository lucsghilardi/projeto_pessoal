<?php

namespace App\Jobs;

use App\Services\Saude\GarminImportService;
use App\Services\Saude\GarminService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Importa as atividades do Garmin Connect para o módulo Saúde.
 *
 * Reprocessa uma janela de dias (config('garmin.dias_janela')) porque o
 * relógio às vezes sobe a atividade com atraso; o dedupe por
 * garmin_activity_id garante que reimportar não duplica.
 */
class SincronizarGarmin implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public function __construct(private readonly ?int $dias = null) {}

    public function handle(GarminService $garmin, GarminImportService $import): void
    {
        if (! $garmin->configOk()) {
            return;
        }

        try {
            $resultado = $import->sincronizar($this->dias);
        } catch (Throwable $erro) {
            // Token expirado é o caso comum e exige ação manual (login com MFA):
            // não adianta reenfileirar, o próximo ciclo tenta de novo.
            Log::warning('Garmin: sincronização falhou.', ['erro' => $erro->getMessage()]);

            return;
        }

        if (array_sum($resultado) > 0) {
            Log::info('Garmin: sincronização concluída.', $resultado);
        }
    }
}

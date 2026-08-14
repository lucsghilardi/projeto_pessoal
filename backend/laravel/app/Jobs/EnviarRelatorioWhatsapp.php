<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Whatsapp\WhatsappRelatorioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Relatório de um usuário só.
 *
 * Os jobs agendados (GerarRelatorioDiario/GerarResumoMatinal) só enfileiram um
 * destes por instância. Antes eles percorriam todo mundo em série dentro do
 * mesmo job: com uma pessoa isso cabia no timeout, com a família junta cada
 * chamada de IA somava e a última ficava sem relatório.
 */
class EnviarRelatorioWhatsapp implements ShouldQueue
{
    use Queueable;

    public int $timeout = 570;

    public function __construct(
        public readonly int $userId,
        public readonly string $tipo,
    ) {}

    public function handle(WhatsappRelatorioService $service): void
    {
        $user = User::find($this->userId);

        if ($user === null || ! $user->is_active) {
            return;
        }

        try {
            $service->gerarEEnviar($user, $this->tipo);
        } catch (\Throwable $e) {
            Log::error("[whatsapp:relatorio] falha no {$this->tipo} do usuário {$this->userId}: ".$e->getMessage());
        }
    }
}

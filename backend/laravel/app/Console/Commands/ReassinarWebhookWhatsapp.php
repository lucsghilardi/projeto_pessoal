<?php

namespace App\Console\Commands;

use App\Models\WhatsappInstancia;
use App\Services\Whatsapp\EvolutionService;
use Illuminate\Console\Command;

/**
 * Reassina o webhook das instâncias na Evolution.
 *
 * A lista de eventos mora no código (EvolutionService::EVENTOS), mas quem
 * guarda a assinatura é a Evolution, por instância — e ela fica congelada no
 * que valia na criação. Por isso todo evento novo (MESSAGES_DELETE ontem,
 * MESSAGES_EDITED agora) só começa a chegar depois de rodar isto; o deploy
 * sozinho não basta.
 *
 * Seguro de repetir: reassinar só regrava a mesma configuração.
 */
class ReassinarWebhookWhatsapp extends Command
{
    protected $signature = 'whatsapp:reassinar-webhook
                            {--instancia= : instance_name de uma instância específica; sem isso, todas}';

    protected $description = 'Reaponta o webhook das instâncias na Evolution e reassina os eventos atuais';

    public function handle(): int
    {
        $alvo = (string) ($this->option('instancia') ?? '');

        $instancias = WhatsappInstancia::query()
            ->when($alvo !== '', fn ($query) => $query->where('instance_name', $alvo))
            ->orderBy('id')
            ->get();

        if ($instancias->isEmpty()) {
            $this->error($alvo === '' ? 'Nenhuma instância cadastrada.' : "Instância \"{$alvo}\" não encontrada.");

            return self::FAILURE;
        }

        $url = EvolutionService::webhookUrlConfigurada();
        $this->line('  <comment>eventos:</comment> '.implode(', ', EvolutionService::EVENTOS));

        $falhas = 0;
        foreach ($instancias as $instancia) {
            $resp = EvolutionService::forInstancia($instancia)->setWebhook($url);

            if ($resp['sucesso'] ?? false) {
                $this->line("  <info>✓</info> {$instancia->instance_name}");

                continue;
            }

            $falhas++;
            $this->line("  <fg=red>✗</> {$instancia->instance_name}: ".($resp['erro'] ?? 'erro desconhecido'));
        }

        if ($falhas > 0) {
            // Sai diferente de zero para a falha não passar batida num deploy.
            $this->error("{$falhas} de {$instancias->count()} instância(s) não foram reassinadas.");

            return self::FAILURE;
        }

        $this->info("{$instancias->count()} instância(s) reassinada(s).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Varre o staging de fotos do WhatsApp (whatsapp/pendentes) e apaga o que ficou
 * para trás — conversa abandonada, worker que morreu no meio, processo que
 * nunca chegou ao commit.
 *
 * O fechamento normal da conversa já apaga o anexo; isto é a rede de segurança.
 * Só existe porque o diretório é dedicado: nada que tenha sido promovido para
 * saude/refeicoes ou receipts passa por aqui.
 */
class LimparAnexosWhatsappOrfaos implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    private const DIRETORIO = 'whatsapp/pendentes';

    /** Folga generosa sobre o TTL da conversa (30 min): ninguém confirma no dia seguinte. */
    private const HORAS_DE_VIDA = 24;

    public function handle(): void
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::DIRETORIO)) {
            return;
        }

        $limite = now()->subHours(self::HORAS_DE_VIDA)->getTimestamp();
        $apagados = 0;

        foreach ($disk->allFiles(self::DIRETORIO) as $arquivo) {
            if ($disk->lastModified($arquivo) < $limite) {
                $disk->delete($arquivo);
                $apagados++;
            }
        }

        if ($apagados > 0) {
            Log::info("[whatsapp:limpeza] {$apagados} anexo(s) pendente(s) apagado(s).");
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\SaudeRefeicao;
use App\Models\User;
use App\Models\WhatsappMensagem;
use App\Services\Saude\SaudeNutricaoAI;
use App\Services\Saude\SaudeNutricaoService;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Diário alimentar: analisa a foto de prato enviada para si mesmo no WhatsApp
 * (IA vision), registra a refeição e responde com o consumo do dia e o que
 * ainda pode ser consumido de calorias e proteína.
 */
class ProcessarRefeicaoWhatsapp implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 150;

    public function __construct(public int $mensagemId, public string $fotoPath)
    {
    }

    public function handle(
        SaudeNutricaoAI $ia,
        SaudeNutricaoService $nutricao,
        WhatsappSender $sender,
    ): void {
        $mensagem = WhatsappMensagem::with('instancia.user')->find($this->mensagemId);
        if ($mensagem === null || $mensagem->instancia === null) {
            return;
        }

        $user = $mensagem->instancia->user;
        if ($user === null) {
            return;
        }

        // Evita refeição duplicada se o job repetir após falha parcial.
        if (SaudeRefeicao::where('whatsapp_mensagem_id', $mensagem->id)->exists()) {
            return;
        }

        $disk = Storage::disk('local');
        if (! $disk->exists($this->fotoPath)) {
            Log::warning("[whatsapp:calorias] foto não encontrada: {$this->fotoPath}");

            return;
        }

        try {
            $analise = $ia->analisarFoto(
                (string) $disk->get($this->fotoPath),
                (string) $mensagem->media_mime,
                $mensagem->caption,
            );
        } catch (\Throwable $e) {
            // Não relança: retentar aqui geraria múltiplas respostas de erro.
            Log::error('[whatsapp:calorias] falha na análise da foto: '.$e->getMessage());
            $sender->enviarParaMim(
                $mensagem->instancia,
                '⚠️ Não consegui analisar a foto agora. Tente de novo ou lance a refeição manualmente no painel.',
            );

            return;
        }

        if (! $analise['e_comida']) {
            $disk->delete($this->fotoPath);
            $sender->enviarParaMim($mensagem->instancia, '🤔 Não identifiquei comida nessa foto.');

            return;
        }

        $refeicao = self::criarDesdeAnalise($user, $mensagem, $analise, 'whatsapp_foto', $this->fotoPath);
        $resposta = self::montarResposta($refeicao, $nutricao->resumoDia($user, $refeicao->data->toDateString()));

        if (! $sender->enviarParaMim($mensagem->instancia, $resposta)) {
            Log::warning('[whatsapp:calorias] refeição registrada, mas a resposta não foi enviada.');
        }
    }

    /**
     * Cria a refeição a partir de uma análise normalizada da IA. Compartilhado
     * com o fluxo de texto do GTD (ProcessarInboxGtd).
     *
     * @param  array{nome: string, tipo: string, itens: list<array<string, mixed>>, calorias: int, proteinas_g: float, carboidratos_g: float|null, gorduras_g: float|null, confianca: string}  $analise
     */
    public static function criarDesdeAnalise(
        User $user,
        WhatsappMensagem $mensagem,
        array $analise,
        string $origem,
        ?string $fotoPath = null,
    ): SaudeRefeicao {
        $tz = config('saude.timezone');
        $momento = $mensagem->momment > 0
            ? now($tz)->setTimestamp(intdiv($mensagem->momment, 1000))
            : now($tz);

        return SaudeRefeicao::create([
            'user_id' => $user->id,
            'data' => $momento->toDateString(),
            'horario' => $momento->format('H:i:s'),
            'nome' => $analise['nome'],
            'tipo' => $analise['tipo'],
            'itens' => $analise['itens'] !== [] ? $analise['itens'] : null,
            'calorias' => $analise['calorias'],
            'proteinas_g' => $analise['proteinas_g'],
            'carboidratos_g' => $analise['carboidratos_g'],
            'gorduras_g' => $analise['gorduras_g'],
            'confianca' => $analise['confianca'],
            'origem' => $origem,
            'whatsapp_mensagem_id' => $mensagem->id,
            'foto_path' => $fotoPath,
        ]);
    }

    /**
     * Resposta enviada no próprio chat: refeição registrada + consumo do dia +
     * quanto ainda pode ser consumido. Compartilhado com o fluxo de texto.
     *
     * @param  array{consumido: array<string, mixed>, metas: array<string, mixed>, restante: array<string, mixed>}  $resumo
     */
    public static function montarResposta(SaudeRefeicao $refeicao, array $resumo): string
    {
        $tipos = [
            'cafe_da_manha' => 'Café da manhã',
            'almoco' => 'Almoço',
            'jantar' => 'Jantar',
            'lanche' => 'Lanche',
            'outro' => 'Refeição',
        ];
        $confiancas = ['alta' => 'alta', 'media' => 'média', 'baixa' => 'baixa'];

        $tipoLabel = $tipos[$refeicao->tipo] ?? 'Refeição';

        $linhas = [];
        $conf = $refeicao->confianca !== null
            ? ', confiança '.($confiancas[$refeicao->confianca] ?? $refeicao->confianca)
            : '';
        $linhas[] = "🍽️ *{$tipoLabel} — {$refeicao->nome}* (~".self::kcal((int) $refeicao->calorias)." kcal{$conf})";

        $macros = ['Proteínas '.self::gramas((float) $refeicao->proteinas_g)];
        if ($refeicao->carboidratos_g !== null) {
            $macros[] = 'Carboidratos '.self::gramas((float) $refeicao->carboidratos_g);
        }
        if ($refeicao->gorduras_g !== null) {
            $macros[] = 'Gorduras '.self::gramas((float) $refeicao->gorduras_g);
        }
        $linhas[] = implode(' · ', $macros);

        $consumido = $resumo['consumido'];
        $metas = $resumo['metas'];
        $restante = $resumo['restante'];

        $linhas[] = '';
        if ($metas['calorias'] !== null) {
            $prot = $metas['proteinas_g'] !== null
                ? ' · Proteínas '.self::gramas((float) $consumido['proteinas_g']).' de '.self::gramas((float) $metas['proteinas_g'])
                : '';
            $linhas[] = '📊 *Hoje:* '.self::kcal((int) $consumido['calorias']).' de '.self::kcal((int) $metas['calorias']).' kcal'.$prot;

            if ($restante['calorias'] >= 0) {
                $linha = '🎯 Ainda pode consumir *'.self::kcal((int) $restante['calorias']).' kcal*';
                if ($restante['proteinas_g'] !== null && $restante['proteinas_g'] > 0) {
                    $linha .= ' — e faltam *'.self::gramas((float) $restante['proteinas_g']).' de proteína*';
                } elseif ($restante['proteinas_g'] !== null) {
                    $linha .= ' — meta de proteína batida 💪';
                }
                $linhas[] = $linha;
            } else {
                $linhas[] = '🚨 Meta do dia ultrapassada em *'.self::kcal(abs((int) $restante['calorias'])).' kcal*';
                if ($restante['proteinas_g'] !== null && $restante['proteinas_g'] > 0) {
                    $linhas[] = '🎯 Ainda faltam *'.self::gramas((float) $restante['proteinas_g']).' de proteína*';
                }
            }
        } else {
            $linhas[] = '📊 *Hoje:* '.self::kcal((int) $consumido['calorias']).' kcal · Proteínas '.self::gramas((float) $consumido['proteinas_g']);
            $linhas[] = 'ℹ️ Complete seu perfil no painel Saúde (sexo, nascimento, altura, atividade e uma pesagem) para receber metas e projeção.';
        }

        return implode("\n", $linhas);
    }

    private static function kcal(int $valor): string
    {
        return number_format($valor, 0, ',', '.');
    }

    private static function gramas(float $valor): string
    {
        $decimais = $valor == floor($valor) ? 0 : 1;

        return number_format($valor, $decimais, ',', '.').'g';
    }
}

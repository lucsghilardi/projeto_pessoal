<?php

namespace App\Jobs;

use App\Models\SaudeRefeicao;
use App\Models\User;
use App\Models\WhatsappConversa;
use App\Models\WhatsappInstancia;
use App\Models\WhatsappMensagem;
use App\Models\WhatsappSugestao;
use App\Services\Finance\FinanceDestinoResolver;
use App\Services\Finance\ReceiptEntryService;
use App\Services\Saude\SaudeNutricaoAI;
use App\Services\Saude\SaudeNutricaoService;
use App\Services\Whatsapp\WhatsappAnaliseService;
use App\Services\Whatsapp\WhatsappConversaAI;
use App\Services\Whatsapp\WhatsappConversaService;
use App\Services\Whatsapp\WhatsappPropostaTexto;
use App\Services\Whatsapp\WhatsappRespostaParser;
use App\Services\Whatsapp\WhatsappSender;
use App\Services\Whatsapp\WhatsappTaskBridge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Um turno de conversa do chat consigo mesmo: pega o texto que você mandou,
 * decide o que fazer conforme o estado pendente, e responde.
 *
 * O ingest já deixou a conversa em 'processando' (com o estado de origem no
 * payload) antes de despachar — é isso que impede um "sim" repetido de virar
 * dois lançamentos. tries=1 porque um turno executado pela metade (gravou e não
 * respondeu, ou respondeu duas vezes) é pior que um turno perdido; o failed()
 * destrava a conversa e avisa.
 */
class ProcessarMensagemPessoal implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    private WhatsappConversaService $conversas;

    private WhatsappConversaAI $conversaIa;

    private SaudeNutricaoAI $nutricaoIa;

    private SaudeNutricaoService $nutricao;

    private WhatsappAnaliseService $analise;

    private WhatsappTaskBridge $bridge;

    private ReceiptEntryService $lancamentos;

    private FinanceDestinoResolver $destinos;

    private WhatsappPropostaTexto $texto;

    private WhatsappSender $sender;

    private WhatsappInstancia $instancia;

    private User $user;

    public function __construct(public int $mensagemId) {}

    public function handle(
        WhatsappConversaService $conversas,
        WhatsappConversaAI $conversaIa,
        SaudeNutricaoAI $nutricaoIa,
        SaudeNutricaoService $nutricao,
        WhatsappAnaliseService $analise,
        WhatsappTaskBridge $bridge,
        ReceiptEntryService $lancamentos,
        FinanceDestinoResolver $destinos,
        WhatsappPropostaTexto $texto,
        WhatsappSender $sender,
    ): void {
        $mensagem = WhatsappMensagem::with('instancia.user')->find($this->mensagemId);
        $user = $mensagem?->instancia?->user;
        // O webhook da Evolution é público: sem checar is_active aqui, o
        // assistente de quem foi desativado continuaria respondendo e gravando.
        if ($user === null || ! $user->is_active) {
            return;
        }

        $this->conversas = $conversas;
        $this->conversaIa = $conversaIa;
        $this->nutricaoIa = $nutricaoIa;
        $this->nutricao = $nutricao;
        $this->analise = $analise;
        $this->bridge = $bridge;
        $this->lancamentos = $lancamentos;
        $this->destinos = $destinos;
        $this->texto = $texto;
        $this->sender = $sender;
        $this->instancia = $mensagem->instancia;
        $this->user = $user;

        $conversa = $conversas->paraInstancia($this->instancia);
        $retomar = $conversas->retomar($conversa);
        $proposta = $conversas->proposta($conversa);
        $resposta = trim((string) $mensagem->texto);

        match ($retomar['estado']) {
            WhatsappConversa::AGUARDANDO_TIPO_MIDIA => $this->escolherTipoMidia($proposta, $resposta),
            WhatsappConversa::AGUARDANDO_ACAO => $this->escolherAcao($proposta, $resposta),
            WhatsappConversa::AGUARDANDO_CONFIRMACAO => $this->responderConfirmacao($conversa, $proposta, $resposta),
            WhatsappConversa::AGUARDANDO_DESTINO => $this->escolherDestino($conversa, $proposta, $resposta),
            default => $this->proporDeTexto($resposta),
        };
    }

    // ============================================================
    // Sem pendência: nota nova vira proposta
    // ============================================================

    private function proporDeTexto(string $nota): void
    {
        if ($nota === '') {
            $this->fechar();

            return;
        }

        $rota = $this->instancia->calorias_texto_ia
            ? $this->nutricaoIa->rotearNota($nota)
            // Sem o roteador ligado, texto é sempre tarefa — como era antes.
            : ['tipo' => 'tarefa', 'tarefa' => $this->analise->extrairTarefaDeNota($nota), 'refeicao' => null];

        match ($rota['tipo']) {
            'indefinido' => $this->perguntarAcao($nota),
            'refeicao' => $this->proporRefeicao($rota['refeicao']),
            default => $this->proporTarefa($rota['tarefa']),
        };
    }

    /**
     * @param  array<string, mixed>  $analise
     */
    private function proporRefeicao(array $analise): void
    {
        $this->abrir(WhatsappConversa::AGUARDANDO_CONFIRMACAO, WhatsappConversa::FLUXO_REFEICAO, ['analise' => $analise]);
        $this->responder($this->texto->propostaRefeicao($analise));
    }

    /**
     * @param  array{titulo: string, descricao: string|null, prioridade: string, due_date: string|null}  $dados
     */
    private function proporTarefa(array $dados): void
    {
        $this->abrir(WhatsappConversa::AGUARDANDO_CONFIRMACAO, WhatsappConversa::FLUXO_TAREFA, ['dados' => $dados]);
        $this->responder($this->texto->propostaTarefa($dados));
    }

    /**
     * A IA não soube classificar: pergunta em vez de chutar, guardando a nota
     * para que a escolha não obrigue você a digitar tudo de novo.
     */
    private function perguntarAcao(string $nota): void
    {
        $opcoes = [
            ['chave' => WhatsappConversa::FLUXO_TAREFA, 'label' => 'Criar tarefa'],
            ['chave' => WhatsappConversa::FLUXO_REFEICAO, 'label' => 'Registrar refeição'],
            ['chave' => 'cancelar', 'label' => 'Nada, esquece'],
        ];

        $this->abrir(WhatsappConversa::AGUARDANDO_ACAO, null, ['nota' => $nota, 'opcoes' => $opcoes]);
        $this->responder($this->texto->menu('🤔 Não entendi o que fazer com isso. Você quer:', $opcoes));
    }

    // ============================================================
    // Estado: aguardando a escolha do menu de ação
    // ============================================================

    /**
     * @param  array<string, mixed>  $proposta
     */
    private function escolherAcao(array $proposta, string $resposta): void
    {
        $opcoes = $proposta['opcoes'] ?? [];
        $escolha = WhatsappRespostaParser::escolherOpcao($resposta, $opcoes);
        $nota = (string) ($proposta['nota'] ?? '');

        if ($escolha === 'cancelar') {
            $this->cancelar('🗑️ Beleza, esqueci.');

            return;
        }

        if ($escolha === WhatsappConversa::FLUXO_TAREFA) {
            $this->fechar();
            $this->proporTarefa($this->analise->extrairTarefaDeNota($nota));

            return;
        }

        if ($escolha === WhatsappConversa::FLUXO_REFEICAO) {
            $this->fechar();
            $this->proporRefeicao($this->nutricaoIa->analisarTexto($nota));

            return;
        }

        // Nem número nem nome: provavelmente mudou de assunto.
        $this->fechar();
        $this->proporDeTexto($resposta);
    }

    // ============================================================
    // Estado: aguardando o tipo da foto
    // ============================================================

    /**
     * @param  array<string, mixed>  $proposta
     */
    private function escolherTipoMidia(array $proposta, string $resposta): void
    {
        $opcoes = $proposta['opcoes'] ?? [];
        $escolha = WhatsappRespostaParser::escolherOpcao($resposta, $opcoes);

        if ($escolha === 'cancelar') {
            $this->cancelar('🗑️ Descartei a foto.');

            return;
        }

        if ($escolha === null) {
            $this->insistir(WhatsappConversa::AGUARDANDO_TIPO_MIDIA, null, $proposta, '🤔 Não entendi. Essa foto é:', $opcoes);

            return;
        }

        $mensagemOriginalId = null;

        $this->conversas->transicionar($this->instancia, function (WhatsappConversa $c) use ($escolha, &$mensagemOriginalId) {
            $mensagemOriginalId = $c->mensagem_id;
            $this->conversas->abrir(
                conversa: $c,
                estado: WhatsappConversa::PROCESSANDO,
                fluxo: $escolha,
                mensagemId: $c->mensagem_id,
                anexoPath: $c->anexo_path,
                chatId: $c->chat_id,
            );
        });

        if ($mensagemOriginalId !== null) {
            dispatch(new AnalisarAnexoWhatsapp($mensagemOriginalId, $escolha));
        }
    }

    // ============================================================
    // Estado: aguardando confirmação de uma proposta
    // ============================================================

    /**
     * @param  array<string, mixed>  $proposta
     */
    private function responderConfirmacao(WhatsappConversa $conversa, array $proposta, string $resposta): void
    {
        $fluxo = (string) $conversa->fluxo;

        // Já mostramos o menu numerado (1 grava / 2 cancela): aceitar o número
        // aqui é o que torna o menu uma saída de emergência de verdade — senão
        // o "1" voltaria para a IA que já falhou em entender.
        if ($conversa->tentativas > 0) {
            $escolha = WhatsappRespostaParser::numero($resposta, 2);
            if ($escolha !== null) {
                $escolha === 1
                    ? $this->confirmar($fluxo, $proposta)
                    : $this->cancelar('🗑️ Deixa pra lá, não gravei nada.');

                return;
            }
        }

        // Caminho barato: "sim"/"não" isolado não precisa de IA nenhuma.
        $simples = WhatsappRespostaParser::simOuNao($resposta);

        if ($simples === false) {
            $this->cancelar('🗑️ Deixa pra lá, não gravei nada.');

            return;
        }

        if ($simples === true) {
            $this->confirmar($fluxo, $proposta);

            return;
        }

        $interpretacao = $this->conversaIa->interpretarResposta(
            $resposta,
            $fluxo,
            $this->propostaParaIa($fluxo, $proposta),
            $proposta['opcoes'] ?? [],
            $proposta['categorias'] ?? [],
        );

        match ($interpretacao['acao']) {
            'cancelar' => $this->cancelar('🗑️ Deixa pra lá, não gravei nada.'),
            'nova_mensagem' => $this->trocarDeAssunto($resposta),
            'confirmar' => $this->confirmar($fluxo, $this->comDestino($proposta, $interpretacao['destino'])),
            'corrigir' => $this->reapresentar($fluxo, $proposta, $interpretacao),
            default => $this->insistir(
                WhatsappConversa::AGUARDANDO_CONFIRMACAO,
                $fluxo,
                $proposta,
                '🤔 Não entendi. Você quer:',
                [['chave' => 'sim', 'label' => 'Sim, pode gravar'], ['chave' => 'nao', 'label' => 'Não, cancela']],
            ),
        };
    }

    /**
     * Aplica a correção e pergunta DE NOVO. Corrigir nunca grava direto: uma
     * leitura errada da IA viraria dado errado no banco sem passar por você.
     *
     * @param  array<string, mixed>  $proposta
     * @param  array{acao: string, destino: string|null, correcoes: array<string, mixed>|null, resumo_correcao: string|null}  $interpretacao
     */
    private function reapresentar(string $fluxo, array $proposta, array $interpretacao): void
    {
        $proposta = $this->comDestino($proposta, $interpretacao['destino']);
        $correcoes = $interpretacao['correcoes'] ?? [];
        $resumo = $interpretacao['resumo_correcao'];

        $proposta = match ($fluxo) {
            WhatsappConversa::FLUXO_TAREFA => $this->corrigirTarefa($proposta, $correcoes),
            WhatsappConversa::FLUXO_REFEICAO => $this->corrigirRefeicao($proposta, $correcoes),
            WhatsappConversa::FLUXO_COMPROVANTE => $this->corrigirComprovante($proposta, $correcoes),
            default => $proposta,
        };

        $texto = match ($fluxo) {
            WhatsappConversa::FLUXO_TAREFA => $this->texto->propostaTarefa($proposta['dados'], $resumo),
            WhatsappConversa::FLUXO_REFEICAO => $this->texto->propostaRefeicao($proposta['analise'], $resumo),
            default => $this->texto->propostaComprovante($proposta['lancamento'], $proposta['destino_label'] ?? null, $resumo),
        };

        $this->abrir(WhatsappConversa::AGUARDANDO_CONFIRMACAO, $fluxo, $proposta);
        $this->responder($texto);
    }

    /**
     * Você ignorou a pergunta e falou de outra coisa: a pendência morre e a
     * mensagem nova vira nota. Sem isso, só um "cancelar" destravaria o chat.
     */
    private function trocarDeAssunto(string $resposta): void
    {
        $this->fechar();
        $this->responder('📌 Deixei o anterior de lado.');
        $this->proporDeTexto($resposta);
    }

    // ============================================================
    // Estado: aguardando o destino do comprovante
    // ============================================================

    /**
     * @param  array<string, mixed>  $proposta
     */
    private function escolherDestino(WhatsappConversa $conversa, array $proposta, string $resposta): void
    {
        if (WhatsappRespostaParser::simOuNao($resposta) === false) {
            $this->cancelar('🗑️ Deixa pra lá, não gravei nada.');

            return;
        }

        $opcoes = $proposta['opcoes'] ?? [];
        $chave = WhatsappRespostaParser::escolherOpcao($resposta, $opcoes);

        if ($chave === null) {
            $this->insistir(
                WhatsappConversa::AGUARDANDO_DESTINO,
                (string) $conversa->fluxo,
                $proposta,
                '🏦 Não achei essa conta. Lançar em qual?',
                $opcoes,
            );

            return;
        }

        $this->confirmar(WhatsappConversa::FLUXO_COMPROVANTE, $this->comDestino($proposta, $chave));
    }

    // ============================================================
    // Commits
    // ============================================================

    /**
     * @param  array<string, mixed>  $proposta
     */
    private function confirmar(string $fluxo, array $proposta): void
    {
        try {
            match ($fluxo) {
                WhatsappConversa::FLUXO_TAREFA => $this->gravarTarefa($proposta),
                WhatsappConversa::FLUXO_REFEICAO => $this->gravarRefeicao($proposta),
                WhatsappConversa::FLUXO_COMPROVANTE => $this->gravarComprovante($proposta),
                default => $this->fechar(),
            };
        } catch (RuntimeException $e) {
            $this->cancelar('⚠️ '.$e->getMessage());
        } catch (Throwable $e) {
            Log::error("[whatsapp:conversa] falha ao gravar {$fluxo}: ".$e->getMessage());
            $this->cancelar('⚠️ Não consegui gravar agora. Tente pelo painel.');
        }
    }

    /**
     * @param  array<string, mixed>  $proposta
     */
    private function gravarTarefa(array $proposta): void
    {
        $dados = $proposta['dados'];
        $conversa = $this->conversas->paraInstancia($this->instancia);
        $origemId = $conversa->mensagem_id ?? $this->mensagemId;

        // Idempotência: a sugestão já registrada barra a segunda tarefa.
        if (WhatsappSugestao::where('user_id', $this->user->id)->where('contexto', "mensagem:{$origemId}")->exists()) {
            $this->fechar();

            return;
        }

        $task = $this->bridge->criarTarefa($this->user, $dados);

        WhatsappSugestao::create([
            'user_id' => $this->user->id,
            'chat_id' => $conversa->chat_id,
            'origem' => 'gtd',
            'titulo' => $dados['titulo'],
            'descricao' => $dados['descricao'],
            'prioridade' => $dados['prioridade'],
            'due_date' => $dados['due_date'],
            'contexto' => "mensagem:{$origemId}",
            'status' => 'aceita',
            'task_id' => $task->id,
        ]);

        $this->fechar();
        $this->responder($this->texto->tarefaCriada($dados));
    }

    /**
     * @param  array<string, mixed>  $proposta
     */
    private function gravarRefeicao(array $proposta): void
    {
        $conversa = $this->conversas->paraInstancia($this->instancia);
        $origemId = $conversa->mensagem_id ?? $this->mensagemId;
        $anexo = $conversa->anexo_path;

        if (SaudeRefeicao::where('whatsapp_mensagem_id', $origemId)->exists()) {
            $this->fechar();

            return;
        }

        // Horário da mensagem ORIGINAL: usar o do "sim" registraria o almoço no
        // horário da confirmação.
        $original = WhatsappMensagem::find($origemId);
        $tz = config('saude.timezone');
        $momento = ($original?->momment ?? 0) > 0
            ? now($tz)->setTimestamp(intdiv((int) $original->momment, 1000))
            : now($tz);

        $fotoPath = $anexo !== null ? $this->promover($anexo, "saude/refeicoes/{$this->user->id}") : null;

        $refeicao = $this->nutricao->registrarRefeicao(
            user: $this->user,
            analise: $proposta['analise'],
            origem: $anexo !== null ? 'whatsapp_foto' : 'whatsapp_texto',
            momento: $momento,
            whatsappMensagemId: $origemId,
            fotoPath: $fotoPath,
        );

        $resumo = $this->nutricao->resumoDia($this->user, $refeicao->data->toDateString());

        // Anexo já promovido para o diretório da refeição: fechar não pode apagá-lo.
        $this->fechar(manterAnexo: true);
        $this->responder($this->texto->refeicaoRegistrada($refeicao, $resumo));
    }

    /**
     * @param  array<string, mixed>  $proposta
     */
    private function gravarComprovante(array $proposta): void
    {
        $conversa = $this->conversas->paraInstancia($this->instancia);
        $opcoes = $proposta['opcoes'] ?? [];
        $destino = $this->destinos->porChave($opcoes, $proposta['destino'] ?? null);

        // Confirmou sem dizer onde: pergunta em vez de escolher por você.
        if ($destino === null) {
            $this->abrir(WhatsappConversa::AGUARDANDO_DESTINO, WhatsappConversa::FLUXO_COMPROVANTE, $proposta);
            $this->responder($this->texto->menu('🏦 Lançar em qual?', $opcoes));

            return;
        }

        $l = $proposta['lancamento'];
        $dados = [
            'destination' => $destino['tipo'],
            'description' => $l['description'],
            'amount' => $l['amount'],
            'date' => $l['date'],
            'category_id' => $l['category_id'] ?? null,
            'installments_total' => $l['installments_total'] ?? null,
            'bank_account_id' => $destino['tipo'] === 'conta' ? $destino['id'] : null,
            'credit_card_id' => $destino['tipo'] === 'cartao' ? $destino['id'] : null,
        ];

        $fingerprint = ReceiptEntryService::fingerprint($dados['description'], $dados['amount'], $dados['date']);

        if ($this->lancamentos->isDuplicate($this->user->id, $dados, $fingerprint)) {
            $this->cancelar('🧾 Esse lançamento já existe (mesma descrição, valor e data).');

            return;
        }

        $receiptPath = $conversa->anexo_path !== null
            ? $this->promover($conversa->anexo_path, "receipts/{$this->user->id}")
            : '';

        $resultado = $this->lancamentos->lancar($this->user->id, $dados, $receiptPath);

        $this->fechar(manterAnexo: true);
        $this->responder($this->texto->comprovanteLancado($l, $destino['label'], $resultado['quantidade']));
    }

    /**
     * Move o anexo do staging para o diretório definitivo. Cada destino tem sua
     * regra de posse por prefixo (saude/refeicoes/{id} e receipts/{id}), por
     * isso o caminho final só é decidido aqui, no commit.
     */
    private function promover(string $origem, string $diretorio): string
    {
        $disk = Storage::disk('local');
        $destino = $diretorio.'/'.Str::uuid().'.'.pathinfo($origem, PATHINFO_EXTENSION);

        // O disk 'local' tem throw=false: move() devolve false em vez de lançar.
        if (! $disk->move($origem, $destino)) {
            throw new RuntimeException('Não consegui salvar o arquivo. Tente de novo.');
        }

        return $destino;
    }

    // ============================================================
    // Correções
    // ============================================================

    /**
     * @param  array<string, mixed>  $proposta
     * @param  array<string, mixed>  $correcoes
     * @return array<string, mixed>
     */
    private function corrigirTarefa(array $proposta, array $correcoes): array
    {
        foreach (['titulo', 'descricao', 'prioridade', 'due_date'] as $campo) {
            if (isset($correcoes[$campo])) {
                $proposta['dados'][$campo] = $correcoes[$campo];
            }
        }

        return $proposta;
    }

    /**
     * @param  array<string, mixed>  $proposta
     * @param  array<string, mixed>  $correcoes
     * @return array<string, mixed>
     */
    private function corrigirRefeicao(array $proposta, array $correcoes): array
    {
        foreach (['nome', 'tipo', 'calorias', 'proteinas_g'] as $campo) {
            if (isset($correcoes[$campo])) {
                $proposta['analise'][$campo] = $correcoes[$campo];
            }
        }

        // Os itens deixam de somar os totais corrigidos; melhor não exibir uma
        // composição que contradiz o que está escrito.
        if (isset($correcoes['calorias']) || isset($correcoes['proteinas_g'])) {
            $proposta['analise']['itens'] = [];
        }

        return $proposta;
    }

    /**
     * @param  array<string, mixed>  $proposta
     * @param  array<string, mixed>  $correcoes
     * @return array<string, mixed>
     */
    private function corrigirComprovante(array $proposta, array $correcoes): array
    {
        $mapa = ['descricao' => 'description', 'valor' => 'amount', 'data' => 'date', 'parcelas' => 'installments_total'];

        foreach ($mapa as $de => $para) {
            if (isset($correcoes[$de])) {
                $proposta['lancamento'][$para] = $correcoes[$de];
            }
        }

        if (isset($correcoes['categoria_id'])) {
            $proposta['lancamento']['category_id'] = $correcoes['categoria_id'];
            $proposta['lancamento']['category_nome'] = null;
            foreach ($proposta['categorias'] ?? [] as $c) {
                if ($c['id'] === $correcoes['categoria_id']) {
                    $proposta['lancamento']['category_nome'] = $c['name'];
                }
            }
        }

        return $proposta;
    }

    // ============================================================
    // Utilidades
    // ============================================================

    /**
     * @param  array<string, mixed>  $proposta
     * @return array<string, mixed>
     */
    private function comDestino(array $proposta, ?string $chave): array
    {
        $destino = $this->destinos->porChave($proposta['opcoes'] ?? [], $chave);
        if ($destino === null) {
            return $proposta;
        }

        $proposta['destino'] = $destino['chave'];
        $proposta['destino_label'] = $destino['label'];

        return $proposta;
    }

    /**
     * A proposta que vai para a IA, sem os campos de infraestrutura: as listas
     * de opções e categorias já viajam no contexto, repeti-las só gasta token.
     *
     * @param  array<string, mixed>  $proposta
     * @return array<string, mixed>
     */
    private function propostaParaIa(string $fluxo, array $proposta): array
    {
        return match ($fluxo) {
            WhatsappConversa::FLUXO_TAREFA => $proposta['dados'] ?? [],
            WhatsappConversa::FLUXO_REFEICAO => $proposta['analise'] ?? [],
            WhatsappConversa::FLUXO_COMPROVANTE => ($proposta['lancamento'] ?? []) + ['destino' => $proposta['destino_label'] ?? null],
            default => $proposta,
        };
    }

    /**
     * Reapresenta a pergunta como menu numerado (respondível sem IA) e conta a
     * tentativa. Estourado o limite, cancela — ping-pong infinito é pior.
     *
     * @param  array<string, mixed>  $proposta
     * @param  list<array{chave: string, label: string}>  $opcoes
     */
    private function insistir(string $estado, ?string $fluxo, array $proposta, string $pergunta, array $opcoes): void
    {
        $tentativas = 0;

        $this->conversas->transicionar($this->instancia, function (WhatsappConversa $c) use ($estado, $fluxo, $proposta, &$tentativas) {
            $tentativas = $c->tentativas + 1;
            $this->conversas->abrir(
                conversa: $c,
                estado: $estado,
                fluxo: $fluxo,
                mensagemId: $c->mensagem_id,
                payload: $proposta,
                anexoPath: $c->anexo_path,
                chatId: $c->chat_id,
                tentativas: $tentativas,
            );
        });

        if ($tentativas >= (int) config('whatsapp.conversa.max_tentativas')) {
            $this->cancelar('🤷 Não consegui entender. Cancelei por aqui — pelo painel é mais rápido.');

            return;
        }

        $this->responder($this->texto->menu($pergunta, $opcoes));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function abrir(string $estado, ?string $fluxo, ?array $payload): void
    {
        $this->conversas->transicionar($this->instancia, fn (WhatsappConversa $c) => $this->conversas->abrir(
            conversa: $c,
            estado: $estado,
            fluxo: $fluxo,
            // Preserva a mensagem que abriu o fluxo (a foto); numa nota nova é esta.
            mensagemId: $c->mensagem_id ?? $this->mensagemId,
            payload: $payload,
            anexoPath: $c->anexo_path,
            chatId: $c->chat_id,
        ));
    }

    private function cancelar(string $aviso): void
    {
        $this->fechar();
        $this->responder($aviso);
    }

    private function fechar(bool $manterAnexo = false): void
    {
        $this->conversas->transicionar(
            $this->instancia,
            fn (WhatsappConversa $c) => $this->conversas->fechar($c, $manterAnexo),
        );
    }

    private function responder(string $texto): void
    {
        if (! $this->sender->enviarParaMim($this->instancia, $texto)) {
            Log::warning('[whatsapp:conversa] resposta não enviada: '.Str::limit($texto, 60));
        }
    }

    public function failed(?Throwable $e): void
    {
        $mensagem = WhatsappMensagem::with('instancia')->find($this->mensagemId);
        $instancia = $mensagem?->instancia;
        if (! $instancia instanceof WhatsappInstancia) {
            return;
        }

        $conversas = app(WhatsappConversaService::class);
        $conversas->transicionar($instancia, fn (WhatsappConversa $c) => $conversas->fechar($c));

        app(WhatsappSender::class)->enviarParaMim($instancia, '⚠️ Deu erro aqui. Tente de novo.');
    }
}

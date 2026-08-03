"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { ArchiveRestore, Archive, Eye, EyeOff, History, Search, Sparkles, Users } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { appToast } from "@/lib/toast";
import {
  analisarWhatsappChat,
  getWhatsappChats,
  getWhatsappMensagens,
  setWhatsappArquivado,
  setWhatsappMonitorado,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type {
  WhatsappAnaliseResultado,
  WhatsappChat,
  WhatsappMensagem,
} from "@/types/Whatsapp";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Spinner } from "@/components/ui/spinner";

function nomeDoChat(chat: WhatsappChat) {
  return chat.chat_name || chat.sender_name || (chat.phone ? `+${chat.phone}` : chat.chave);
}

function horaCurta(momment: number) {
  return new Date(momment).toLocaleString("pt-BR", {
    day: "2-digit",
    month: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}

export default function WhatsappConversasPage() {
  const [loading, setLoading] = useState(true);
  const [chats, setChats] = useState<WhatsappChat[]>([]);
  const [busca, setBusca] = useState("");
  const [soMonitorados, setSoMonitorados] = useState(false);
  const [chatAtivo, setChatAtivo] = useState<WhatsappChat | null>(null);
  const [mensagens, setMensagens] = useState<WhatsappMensagem[]>([]);
  const [mensagensLoading, setMensagensLoading] = useState(false);
  const [carregandoMais, setCarregandoMais] = useState(false);
  const [analiseOpen, setAnaliseOpen] = useState(false);
  const [analisePeriodo, setAnalisePeriodo] = useState("7");
  const [analisando, setAnalisando] = useState(false);
  const [analise, setAnalise] = useState<WhatsappAnaliseResultado | null>(null);
  const fimDasMensagensRef = useRef<HTMLDivElement | null>(null);
  const chatInicialRef = useRef<number | null>(null);

  const carregarChats = useCallback(async (opts?: { silencioso?: boolean }) => {
    try {
      const data = await getWhatsappChats({
        busca: busca || undefined,
        monitorados: soMonitorados,
      });
      setChats(data.chats);
      return data.chats;
    } catch (error) {
      if (!opts?.silencioso) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar as conversas.");
      }
      return [];
    }
  }, [busca, soMonitorados]);

  // Primeira carga (+ deep link ?chat=ID vindo da visão geral).
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const chatParam = Number(params.get("chat") || 0);
    if (chatParam > 0) chatInicialRef.current = chatParam;

    (async () => {
      const lista = await carregarChats();
      const alvo = chatInicialRef.current
        ? lista.find((c) => c.id === chatInicialRef.current)
        : null;
      if (alvo) void abrirChat(alvo);
      setLoading(false);
    })();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Busca/filtro com debounce.
  useEffect(() => {
    if (loading) return;
    const t = setTimeout(() => void carregarChats({ silencioso: true }), 350);
    return () => clearTimeout(t);
  }, [busca, soMonitorados, carregarChats, loading]);

  // Polling leve: lista a cada 20s, conversa aberta a cada 12s.
  useEffect(() => {
    const t = setInterval(() => void carregarChats({ silencioso: true }), 20000);
    return () => clearInterval(t);
  }, [carregarChats]);

  useEffect(() => {
    if (!chatAtivo) return;
    const t = setInterval(async () => {
      try {
        const data = await getWhatsappMensagens(chatAtivo.id);
        setMensagens((atual) => {
          const ultimaAtual = atual[atual.length - 1]?.id;
          const ultimaNova = data.mensagens[data.mensagens.length - 1]?.id;
          return ultimaAtual === ultimaNova ? atual : data.mensagens;
        });
      } catch {
        // silencioso
      }
    }, 12000);
    return () => clearInterval(t);
  }, [chatAtivo]);

  useEffect(() => {
    fimDasMensagensRef.current?.scrollIntoView({ block: "end" });
  }, [mensagens.length, chatAtivo?.id]);

  async function abrirChat(chat: WhatsappChat) {
    setChatAtivo(chat);
    setMensagens([]);
    setMensagensLoading(true);
    try {
      const data = await getWhatsappMensagens(chat.id);
      setMensagens(data.mensagens);
      setChats((lista) => lista.map((c) => (c.id === chat.id ? { ...c, unread_count: 0 } : c)));
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar as mensagens.");
    } finally {
      setMensagensLoading(false);
    }
  }

  async function carregarMaisAntigas() {
    if (!chatAtivo || mensagens.length === 0) return;
    setCarregandoMais(true);
    try {
      const maisAntiga = mensagens[0];
      const data = await getWhatsappMensagens(chatAtivo.id, maisAntiga.momment);
      if (data.mensagens.length === 0) {
        appToast.info("Não há mensagens mais antigas.");
      } else {
        setMensagens((atual) => [...data.mensagens, ...atual]);
      }
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar o histórico.");
    } finally {
      setCarregandoMais(false);
    }
  }

  async function alternarMonitorado(chat: WhatsappChat) {
    try {
      const { chat: atualizado } = await setWhatsappMonitorado(chat.id, !chat.monitorado);
      setChats((lista) => lista.map((c) => (c.id === atualizado.id ? atualizado : c)));
      if (chatAtivo?.id === atualizado.id) setChatAtivo(atualizado);
      appToast.success(
        atualizado.monitorado
          ? `${nomeDoChat(atualizado)} agora é monitorado pela IA.`
          : `${nomeDoChat(atualizado)} não é mais monitorado.`,
      );
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível alterar o monitoramento.");
    }
  }

  async function alternarArquivado(chat: WhatsappChat) {
    try {
      const { chat: atualizado } = await setWhatsappArquivado(chat.id, !chat.arquivado);
      setChats((lista) => lista.filter((c) => c.id !== atualizado.id));
      if (chatAtivo?.id === atualizado.id) setChatAtivo(null);
      appToast.success(atualizado.arquivado ? "Conversa arquivada." : "Conversa restaurada.");
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível arquivar.");
    }
  }

  async function executarAnalise() {
    if (!chatAtivo) return;
    setAnalisando(true);
    setAnalise(null);
    try {
      const resultado = await analisarWhatsappChat(chatAtivo.id, Number(analisePeriodo));
      setAnalise(resultado);
      if (resultado.sugestoes.length > 0) {
        appToast.success(`${resultado.sugestoes.length} sugestão(ões) de tarefa criadas — veja em Sugestões.`);
      }
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível analisar a conversa.");
    } finally {
      setAnalisando(false);
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando conversas..." />;
  }

  return (
    <div className="space-y-4">
      <DashboardPageHeader
        title="Conversas"
        description="Histórico gravado (somente leitura). Marque com o olho os contatos que a IA deve monitorar."
      />

      <div className="grid gap-4 lg:grid-cols-[340px_1fr]">
        {/* Lista de chats */}
        <Card className="max-h-[75vh] overflow-hidden py-0">
          <CardContent className="flex h-full flex-col gap-2 p-3">
            <div className="flex items-center gap-2">
              <div className="relative flex-1">
                <Search className="absolute left-2 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  className="pl-8"
                  placeholder="Buscar contato..."
                  value={busca}
                  onChange={(e) => setBusca(e.target.value)}
                />
              </div>
              <Button
                variant={soMonitorados ? "default" : "outline"}
                size="icon"
                title={soMonitorados ? "Mostrando só monitorados" : "Mostrar só monitorados"}
                onClick={() => setSoMonitorados((v) => !v)}
              >
                <Eye className="size-4" />
              </Button>
            </div>

            <div className="flex-1 space-y-1 overflow-y-auto">
              {chats.length === 0 ? (
                <p className="py-8 text-center text-sm text-muted-foreground">
                  Nenhuma conversa gravada ainda.
                </p>
              ) : (
                chats.map((chat) => (
                  <button
                    key={chat.id}
                    type="button"
                    onClick={() => abrirChat(chat)}
                    className={`flex w-full items-center gap-2 rounded-md border p-2 text-left transition-colors hover:bg-muted/60 ${
                      chatAtivo?.id === chat.id ? "border-emerald-600 bg-muted/60" : "border-transparent"
                    }`}
                  >
                    <div className="min-w-0 flex-1">
                      <p className="flex items-center gap-1 truncate text-sm font-medium">
                        {chat.is_group ? <Users className="size-3 shrink-0 text-muted-foreground" /> : null}
                        <span className="truncate">{nomeDoChat(chat)}</span>
                        {chat.monitorado ? <Eye className="size-3 shrink-0 text-emerald-600" /> : null}
                      </p>
                      <p className="truncate text-xs text-muted-foreground">
                        {chat.last_message_from_me ? "Você: " : ""}
                        {chat.last_message_text || "—"}
                      </p>
                    </div>
                    {chat.unread_count > 0 ? (
                      <span className="rounded-full bg-emerald-600 px-1.5 text-[10px] font-semibold text-white">
                        {chat.unread_count}
                      </span>
                    ) : null}
                  </button>
                ))
              )}
            </div>
          </CardContent>
        </Card>

        {/* Conversa */}
        <Card className="flex max-h-[75vh] flex-col overflow-hidden py-0">
          {!chatAtivo ? (
            <CardContent className="flex flex-1 items-center justify-center p-6">
              <p className="text-sm text-muted-foreground">Selecione uma conversa para ver o histórico.</p>
            </CardContent>
          ) : (
            <>
              <div className="flex items-center gap-2 border-b p-3">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-semibold">{nomeDoChat(chatAtivo)}</p>
                  <p className="text-xs text-muted-foreground">
                    {chatAtivo.is_group ? "Grupo" : chatAtivo.phone ? `+${chatAtivo.phone}` : ""}
                  </p>
                </div>
                <Button
                  variant={chatAtivo.monitorado ? "default" : "outline"}
                  size="sm"
                  onClick={() => alternarMonitorado(chatAtivo)}
                  title={chatAtivo.monitorado ? "Deixar de monitorar" : "Monitorar com a IA"}
                >
                  {chatAtivo.monitorado ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                  {chatAtivo.monitorado ? "Monitorando" : "Monitorar"}
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    setAnalise(null);
                    setAnaliseOpen(true);
                  }}
                >
                  <Sparkles className="size-4" />
                  Analisar com IA
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  title={chatAtivo.arquivado ? "Restaurar conversa" : "Arquivar conversa"}
                  onClick={() => alternarArquivado(chatAtivo)}
                >
                  {chatAtivo.arquivado ? <ArchiveRestore className="size-4" /> : <Archive className="size-4" />}
                </Button>
              </div>

              <div className="flex-1 space-y-2 overflow-y-auto bg-muted/30 p-4">
                {mensagensLoading ? (
                  <div className="flex justify-center py-8">
                    <Spinner />
                  </div>
                ) : (
                  <>
                    <div className="flex justify-center">
                      <Button variant="ghost" size="sm" onClick={carregarMaisAntigas} disabled={carregandoMais}>
                        {carregandoMais ? <Spinner data-icon="inline-start" /> : <History className="size-4" />}
                        Carregar mais antigas
                      </Button>
                    </div>
                    {mensagens.map((m) => (
                      <div key={m.id} className={`flex ${m.from_me ? "justify-end" : "justify-start"}`}>
                        <div
                          className={`max-w-[75%] rounded-lg px-3 py-2 text-sm shadow-sm ${
                            m.from_me ? "bg-emerald-600 text-white" : "border bg-background"
                          }`}
                        >
                          {chatAtivo.is_group && !m.from_me && m.sender_name ? (
                            <p className="text-xs font-semibold text-emerald-700">{m.sender_name}</p>
                          ) : null}
                          {m.quoted_texto ? (
                            <p
                              className={`mb-1 border-l-2 pl-2 text-xs ${
                                m.from_me ? "border-white/50 text-white/80" : "border-emerald-600 text-muted-foreground"
                              }`}
                            >
                              {m.quoted_texto}
                            </p>
                          ) : null}
                          <p className="whitespace-pre-wrap break-words">{m.texto || `[${m.tipo}]`}</p>
                          <p className={`mt-1 text-right text-[10px] ${m.from_me ? "text-white/70" : "text-muted-foreground"}`}>
                            {horaCurta(m.momment)}
                          </p>
                        </div>
                      </div>
                    ))}
                    <div ref={fimDasMensagensRef} />
                  </>
                )}
              </div>
            </>
          )}
        </Card>
      </div>

      {/* Análise por IA */}
      <Sheet open={analiseOpen} onOpenChange={setAnaliseOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
          <SheetHeader>
            <SheetTitle>Analisar com IA</SheetTitle>
            <SheetDescription>
              {chatAtivo ? `Resumo e sugestões de tarefas para a conversa com ${nomeDoChat(chatAtivo)}.` : ""}
            </SheetDescription>
          </SheetHeader>
          <div className="space-y-4 px-4 pb-6">
            <div className="flex items-end gap-2">
              <div className="flex-1">
                <p className="mb-1 text-sm font-medium">Período</p>
                <Select value={analisePeriodo} onValueChange={setAnalisePeriodo}>
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="3">Últimos 3 dias</SelectItem>
                    <SelectItem value="7">Últimos 7 dias</SelectItem>
                    <SelectItem value="15">Últimos 15 dias</SelectItem>
                    <SelectItem value="30">Últimos 30 dias</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <Button onClick={executarAnalise} disabled={analisando}>
                {analisando ? <Spinner data-icon="inline-start" /> : <Sparkles className="size-4" />}
                Analisar
              </Button>
            </div>

            {analise ? (
              <div className="space-y-4">
                <div>
                  <p className="mb-1 text-sm font-semibold">Resumo</p>
                  <p className="whitespace-pre-wrap text-sm text-muted-foreground">{analise.resumo}</p>
                </div>

                {analise.pontos_de_atencao.length > 0 ? (
                  <div>
                    <p className="mb-1 text-sm font-semibold">Pontos de atenção</p>
                    <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                      {analise.pontos_de_atencao.map((p, i) => (
                        <li key={i}>{p}</li>
                      ))}
                    </ul>
                  </div>
                ) : null}

                <div>
                  <p className="mb-1 text-sm font-semibold">
                    Sugestões de tarefas ({analise.sugestoes.length})
                  </p>
                  {analise.sugestoes.length === 0 ? (
                    <p className="text-sm text-muted-foreground">Nada digno de tarefa nesta conversa.</p>
                  ) : (
                    <div className="space-y-2">
                      {analise.sugestoes.map((s) => (
                        <div key={s.id} className="rounded-md border p-2 text-sm">
                          <p className="font-medium">{s.titulo}</p>
                          {s.descricao ? <p className="text-xs text-muted-foreground">{s.descricao}</p> : null}
                        </div>
                      ))}
                      <p className="text-xs text-muted-foreground">
                        Aceite ou descarte na tela <span className="font-medium">Sugestões</span>.
                      </p>
                    </div>
                  )}
                </div>
              </div>
            ) : null}
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}

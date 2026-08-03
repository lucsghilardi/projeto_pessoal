"use client";

import { useCallback, useEffect, useState } from "react";
import { Check, Inbox, Lightbulb, MessageCircle, Sparkles, X } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { appToast } from "@/lib/toast";
import {
  aceitarWhatsappSugestao,
  descartarWhatsappSugestao,
  getProjects,
  getWhatsappSugestoes,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { Project } from "@/types/Task";
import type { WhatsappSugestao, WhatsappSugestaoStatus } from "@/types/Whatsapp";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import { Tabs } from "@/components/ui/tabs";

const PRIORIDADE_LABEL: Record<string, string> = {
  low: "Baixa",
  medium: "Média",
  high: "Alta",
  urgent: "Urgente",
};

const ORIGEM_LABEL: Record<string, { label: string; icone: typeof Sparkles }> = {
  relatorio: { label: "Relatório", icone: Sparkles },
  analise: { label: "Análise manual", icone: Lightbulb },
  gtd: { label: "Inbox GTD", icone: Inbox },
};

function nomeDoChat(s: WhatsappSugestao) {
  if (!s.chat) return null;
  return s.chat.chat_name || s.chat.sender_name || (s.chat.phone ? `+${s.chat.phone}` : s.chat.chave);
}

export default function WhatsappSugestoesPage() {
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState<WhatsappSugestaoStatus>("pendente");
  const [sugestoes, setSugestoes] = useState<WhatsappSugestao[]>([]);
  const [projetos, setProjetos] = useState<Project[]>([]);
  const [projetoDestino, setProjetoDestino] = useState<string>("auto");
  const [processando, setProcessando] = useState<number | null>(null);

  const carregar = useCallback(async (alvo: WhatsappSugestaoStatus) => {
    try {
      const data = await getWhatsappSugestoes(alvo);
      setSugestoes(data.sugestoes);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar as sugestões.");
    }
  }, []);

  useEffect(() => {
    (async () => {
      await Promise.all([
        carregar("pendente"),
        getProjects()
          .then(setProjetos)
          .catch(() => undefined),
      ]);
      setLoading(false);
    })();
  }, [carregar]);

  async function trocarAba(novo: string) {
    const alvo = novo as WhatsappSugestaoStatus;
    setStatus(alvo);
    await carregar(alvo);
  }

  async function aceitar(s: WhatsappSugestao) {
    setProcessando(s.id);
    try {
      const payload = projetoDestino !== "auto" ? { project_id: Number(projetoDestino) } : {};
      const { task } = await aceitarWhatsappSugestao(s.id, payload);
      appToast.success(`Tarefa criada: ${task.title}`);
      setSugestoes((lista) => lista.filter((item) => item.id !== s.id));
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível aceitar a sugestão.");
    } finally {
      setProcessando(null);
    }
  }

  async function descartar(s: WhatsappSugestao) {
    setProcessando(s.id);
    try {
      await descartarWhatsappSugestao(s.id);
      setSugestoes((lista) => lista.filter((item) => item.id !== s.id));
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível descartar a sugestão.");
    } finally {
      setProcessando(null);
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando sugestões..." />;
  }

  return (
    <div className="space-y-4">
      <DashboardPageHeader
        title="Sugestões de tarefas"
        description="Tudo que a IA sugeriu a partir das suas conversas. Aceitar cria a tarefa no kanban."
      />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <Tabs
          value={status}
          onValueChange={trocarAba}
          items={[
            { value: "pendente", label: "Pendentes" },
            { value: "aceita", label: "Aceitas" },
            { value: "descartada", label: "Descartadas" },
          ]}
        />

        {status === "pendente" ? (
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">Criar tarefas em:</span>
            <Select value={projetoDestino} onValueChange={setProjetoDestino}>
              <SelectTrigger className="w-56">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="auto">Projeto WhatsApp (padrão)</SelectItem>
                {projetos.map((p) => (
                  <SelectItem key={p.id} value={String(p.id)}>
                    {p.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        ) : null}
      </div>

      <Card>
        <CardContent className="space-y-2 p-4">
          {sugestoes.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              {status === "pendente"
                ? "Nenhuma sugestão pendente. Gere um relatório ou analise uma conversa."
                : "Nada por aqui."}
            </p>
          ) : (
            sugestoes.map((s) => {
              const origem = ORIGEM_LABEL[s.origem] ?? ORIGEM_LABEL.analise;
              const OrigemIcone = origem.icone;
              const contato = nomeDoChat(s);

              return (
                <div key={s.id} className="flex items-start gap-3 rounded-md border p-3">
                  <OrigemIcone className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">{s.titulo}</p>
                    {s.descricao ? <p className="text-sm text-muted-foreground">{s.descricao}</p> : null}
                    <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                      <span>{origem.label}</span>
                      {contato ? (
                        <span className="flex items-center gap-1">
                          <MessageCircle className="size-3" /> {contato}
                        </span>
                      ) : null}
                      <span>Prioridade: {PRIORIDADE_LABEL[s.prioridade] ?? s.prioridade}</span>
                      {s.due_date ? (
                        <span>Prazo: {new Date(`${s.due_date.slice(0, 10)}T12:00:00`).toLocaleDateString("pt-BR")}</span>
                      ) : null}
                      {s.contexto ? <span className="italic">{s.contexto}</span> : null}
                    </p>
                  </div>
                  {status === "pendente" ? (
                    <div className="flex shrink-0 gap-1">
                      <Button size="sm" onClick={() => aceitar(s)} disabled={processando === s.id}>
                        {processando === s.id ? <Spinner data-icon="inline-start" /> : <Check className="size-4" />}
                        Aceitar
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => descartar(s)}
                        disabled={processando === s.id}
                        title="Descartar"
                      >
                        <X className="size-4 text-red-600" />
                      </Button>
                    </div>
                  ) : s.status === "aceita" && s.task ? (
                    <span className="shrink-0 text-xs text-emerald-600">→ {s.task.title}</span>
                  ) : null}
                </div>
              );
            })
          )}
        </CardContent>
      </Card>
    </div>
  );
}

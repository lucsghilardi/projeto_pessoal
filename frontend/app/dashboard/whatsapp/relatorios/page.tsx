"use client";

import { useEffect, useState } from "react";
import { CheckCircle2, MessageCircle, Moon, RefreshCw, Sparkles, Sun } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { appToast } from "@/lib/toast";
import { gerarWhatsappRelatorio, getWhatsappRelatorio, getWhatsappRelatorios } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { WhatsappRelatorio } from "@/types/Whatsapp";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Spinner } from "@/components/ui/spinner";

function dataLabel(iso: string) {
  return new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString("pt-BR", {
    weekday: "short",
    day: "2-digit",
    month: "2-digit",
  });
}

function urgenciaClasse(urgencia: string) {
  if (urgencia === "alta") return "text-red-600";
  if (urgencia === "media") return "text-amber-600";
  return "text-muted-foreground";
}

export default function WhatsappRelatoriosPage() {
  const [loading, setLoading] = useState(true);
  const [relatorios, setRelatorios] = useState<WhatsappRelatorio[]>([]);
  const [ativo, setAtivo] = useState<WhatsappRelatorio | null>(null);
  const [gerando, setGerando] = useState<"diario" | "matinal" | null>(null);

  async function carregar(selecionarPrimeiro = false) {
    try {
      const data = await getWhatsappRelatorios();
      setRelatorios(data.relatorios);
      if (selecionarPrimeiro && data.relatorios.length > 0) {
        await abrir(data.relatorios[0].id);
      }
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar os relatórios.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void carregar(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function abrir(id: number) {
    try {
      const { relatorio } = await getWhatsappRelatorio(id);
      setAtivo(relatorio);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível abrir o relatório.");
    }
  }

  async function gerar(tipo: "diario" | "matinal") {
    setGerando(tipo);
    try {
      const { relatorio } = await gerarWhatsappRelatorio(tipo, true);
      appToast.success("Relatório gerado e enviado no seu WhatsApp.");
      setAtivo(relatorio);
      await carregar();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível gerar o relatório.");
    } finally {
      setGerando(null);
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando relatórios..." />;
  }

  const dados = ativo?.dados;

  return (
    <div className="space-y-4">
      <DashboardPageHeader
        title="Relatórios do WhatsApp"
        description="O que a IA encontrou nas suas conversas: pendências, promessas e sugestões."
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" onClick={() => gerar("matinal")} disabled={gerando !== null}>
              {gerando === "matinal" ? <Spinner data-icon="inline-start" /> : <Sun className="size-4" />}
              Gerar briefing
            </Button>
            <Button onClick={() => gerar("diario")} disabled={gerando !== null}>
              {gerando === "diario" ? <Spinner data-icon="inline-start" /> : <Moon className="size-4" />}
              Gerar relatório do dia
            </Button>
          </div>
        }
      />

      <div className="grid gap-4 lg:grid-cols-[280px_1fr]">
        <Card className="max-h-[70vh] overflow-y-auto py-0">
          <CardContent className="space-y-1 p-3">
            {relatorios.length === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">
                Nenhum relatório ainda. Gere um agora ou aguarde o horário agendado.
              </p>
            ) : (
              relatorios.map((r) => (
                <button
                  key={r.id}
                  type="button"
                  onClick={() => abrir(r.id)}
                  className={`flex w-full items-center gap-2 rounded-md border p-2 text-left transition-colors hover:bg-muted/60 ${
                    ativo?.id === r.id ? "border-emerald-600 bg-muted/60" : "border-transparent"
                  }`}
                >
                  {r.tipo === "matinal" ? (
                    <Sun className="size-4 shrink-0 text-amber-500" />
                  ) : (
                    <Moon className="size-4 shrink-0 text-indigo-500" />
                  )}
                  <div className="min-w-0 flex-1">
                    <p className="text-sm font-medium">
                      {r.tipo === "matinal" ? "Briefing" : "Fim de dia"} — {dataLabel(r.referencia_data)}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">{r.dados?.resumo || ""}</p>
                  </div>
                  {r.enviado_em ? (
                    <MessageCircle className="size-3 shrink-0 text-emerald-600" aria-label="Enviado no WhatsApp" />
                  ) : null}
                </button>
              ))
            )}
          </CardContent>
        </Card>

        {!ativo || !dados ? (
          <Card>
            <CardContent className="flex items-center justify-center p-10">
              <p className="text-sm text-muted-foreground">Selecione um relatório.</p>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  {ativo.tipo === "matinal" ? <Sun className="size-4" /> : <Moon className="size-4" />}
                  {ativo.tipo === "matinal" ? "Briefing matinal" : "Relatório de fim de dia"} —{" "}
                  {dataLabel(ativo.referencia_data)}
                </CardTitle>
                <CardDescription>
                  {ativo.enviado_em ? "Enviado no seu WhatsApp. " : ""}
                  {dados.resumo}
                </CardDescription>
              </CardHeader>
            </Card>

            {dados.pendencias.length > 0 ? (
              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="text-base">⚠️ Aguardando sua resposta</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  {dados.pendencias.map((p, i) => (
                    <div key={i} className="rounded-md border p-2 text-sm">
                      <p className="font-medium">
                        {p.contato}
                        {p.horas_esperando ? (
                          <span className="ml-1 text-xs text-muted-foreground">
                            (esperando há {Math.round(p.horas_esperando)}h)
                          </span>
                        ) : null}
                        <span className={`ml-2 text-xs font-semibold uppercase ${urgenciaClasse(p.urgencia)}`}>
                          {p.urgencia}
                        </span>
                      </p>
                      <p className="text-muted-foreground">{p.assunto}</p>
                      {p.ultima_mensagem ? (
                        <p className="mt-1 border-l-2 border-muted pl-2 text-xs italic text-muted-foreground">
                          “{p.ultima_mensagem}”
                        </p>
                      ) : null}
                    </div>
                  ))}
                </CardContent>
              </Card>
            ) : null}

            {dados.promessas.length > 0 ? (
              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="text-base">🤝 Promessas suas em aberto</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  {dados.promessas.map((p, i) => (
                    <div key={i} className="rounded-md border p-2 text-sm">
                      <p className="font-medium">{p.contato}</p>
                      <p className="text-muted-foreground">
                        {p.promessa}
                        {p.prazo_mencionado ? ` — prazo citado: ${p.prazo_mencionado}` : ""}
                      </p>
                    </div>
                  ))}
                </CardContent>
              </Card>
            ) : null}

            {dados.assuntos_perdidos.length > 0 ? (
              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="text-base">🧵 Assuntos que morreram no meio</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  {dados.assuntos_perdidos.map((a, i) => (
                    <div key={i} className="rounded-md border p-2 text-sm">
                      <p>
                        <span className="font-medium">{a.contato}</span>
                        <span className="text-muted-foreground"> — {a.assunto}</span>
                      </p>
                    </div>
                  ))}
                </CardContent>
              </Card>
            ) : null}

            {(ativo.sugestoes?.length ?? 0) > 0 ? (
              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="text-base">💡 Sugestões de tarefas deste relatório</CardTitle>
                  <CardDescription>Aceite ou descarte na tela Sugestões.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-2">
                  {ativo.sugestoes!.map((s) => (
                    <div key={s.id} className="flex items-center gap-2 rounded-md border p-2 text-sm">
                      <div className="min-w-0 flex-1">
                        <p className="font-medium">{s.titulo}</p>
                        {s.descricao ? <p className="text-xs text-muted-foreground">{s.descricao}</p> : null}
                      </div>
                      {s.status === "aceita" ? (
                        <span className="flex items-center gap-1 text-xs font-medium text-emerald-600">
                          <CheckCircle2 className="size-3" /> aceita
                        </span>
                      ) : s.status === "descartada" ? (
                        <span className="text-xs text-muted-foreground">descartada</span>
                      ) : (
                        <span className="text-xs text-amber-600">pendente</span>
                      )}
                    </div>
                  ))}
                </CardContent>
              </Card>
            ) : null}

            {ativo.texto_whatsapp ? (
              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="flex items-center gap-2 text-base">
                    <MessageCircle className="size-4 text-emerald-600" />
                    Como chegou no WhatsApp
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <pre className="whitespace-pre-wrap rounded-md bg-muted/50 p-3 font-sans text-sm">
                    {ativo.texto_whatsapp}
                  </pre>
                </CardContent>
              </Card>
            ) : null}

            <div>
              <Button variant="outline" size="sm" onClick={() => gerar(ativo.tipo)} disabled={gerando !== null}>
                {gerando ? <Spinner data-icon="inline-start" /> : <RefreshCw className="size-4" />}
                Regerar este relatório
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { CheckCircle2, Clock, Eye, MessageCircle, Plug, QrCode, RefreshCw, Sparkles, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { appToast } from "@/lib/toast";
import {
  createWhatsappInstancia,
  deleteWhatsappInstancia,
  gerarWhatsappRelatorio,
  getWhatsappAtencao,
  getWhatsappInstancia,
  getWhatsappQrcode,
  getWhatsappStatus,
  updateWhatsappInstancia,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { WhatsappAtencao, WhatsappAtencaoItem, WhatsappInstancia } from "@/types/Whatsapp";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Spinner } from "@/components/ui/spinner";

function horasLabel(horas?: number | null) {
  if (horas === null || horas === undefined) return "";
  if (horas < 1) return "há menos de 1h";
  if (horas < 48) return `há ${horas}h`;
  return `há ${Math.floor(horas / 24)} dia(s)`;
}

function ToggleRow({
  label,
  description,
  checked,
  onChange,
  disabled,
}: {
  label: string;
  description: string;
  checked: boolean;
  onChange: (value: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <label className="flex cursor-pointer items-start gap-3">
      <input
        type="checkbox"
        className="mt-1 size-4 accent-emerald-600"
        checked={checked}
        disabled={disabled}
        onChange={(e) => onChange(e.target.checked)}
      />
      <span>
        <span className="block text-sm font-medium">{label}</span>
        <span className="block text-xs text-muted-foreground">{description}</span>
      </span>
    </label>
  );
}

function AtencaoLista({ titulo, descricao, itens, vazio }: {
  titulo: string;
  descricao: string;
  itens: WhatsappAtencaoItem[];
  vazio: string;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Clock className="size-4" />
          {titulo}
        </CardTitle>
        <CardDescription>{descricao}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-2">
        {itens.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">{vazio}</p>
        ) : (
          itens.slice(0, 8).map((item) => (
            <Link
              key={item.chat_id}
              href={`/dashboard/whatsapp/conversas?chat=${item.chat_id}`}
              className="flex items-center gap-3 rounded-md border p-2 transition-colors hover:bg-muted/60"
            >
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">
                  {item.nome}
                  {item.monitorado ? <Eye className="ml-1 inline size-3 text-emerald-600" /> : null}
                </p>
                <p className="truncate text-xs text-muted-foreground">{item.ultima_mensagem || "—"}</p>
              </div>
              <span className="shrink-0 text-xs font-medium text-amber-600">{horasLabel(item.horas)}</span>
            </Link>
          ))
        )}
      </CardContent>
    </Card>
  );
}

export default function WhatsappOverviewPage() {
  const [loading, setLoading] = useState(true);
  const [instancia, setInstancia] = useState<WhatsappInstancia | null>(null);
  const [atencao, setAtencao] = useState<WhatsappAtencao | null>(null);
  const [criando, setCriando] = useState(false);
  const [qrOpen, setQrOpen] = useState(false);
  const [qrBase64, setQrBase64] = useState<string | null>(null);
  const [qrLoading, setQrLoading] = useState(false);
  const [gerando, setGerando] = useState<"diario" | "matinal" | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

  const carregar = useCallback(async () => {
    try {
      const [{ instancia: inst }, atencaoData] = await Promise.all([
        getWhatsappInstancia(),
        getWhatsappAtencao().catch(() => null),
      ]);
      setInstancia(inst);
      if (atencaoData) setAtencao(atencaoData);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar o módulo WhatsApp.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void carregar();
  }, [carregar]);

  // Enquanto o QR está aberto, consulta o status até conectar.
  useEffect(() => {
    if (!qrOpen) {
      if (pollRef.current) clearInterval(pollRef.current);
      return;
    }
    pollRef.current = setInterval(async () => {
      try {
        const status = await getWhatsappStatus();
        setInstancia(status.instancia);
        if (status.connected) {
          setQrOpen(false);
          appToast.success("WhatsApp conectado!");
        }
      } catch {
        // silencioso: tenta de novo no próximo tick
      }
    }, 4000);
    return () => {
      if (pollRef.current) clearInterval(pollRef.current);
    };
  }, [qrOpen]);

  async function handleCriar() {
    setCriando(true);
    try {
      const { instancia: inst } = await createWhatsappInstancia();
      setInstancia(inst);
      appToast.success("Instância criada. Agora conecte com o QR code.");
      await abrirQr();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível criar a instância.");
    } finally {
      setCriando(false);
    }
  }

  async function abrirQr() {
    setQrOpen(true);
    setQrLoading(true);
    setQrBase64(null);
    try {
      const qr = await getWhatsappQrcode();
      setQrBase64(qr.base64 || null);
      if (!qr.base64) appToast.error("A Evolution não devolveu um QR code. Tente atualizar.");
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível obter o QR code.");
    } finally {
      setQrLoading(false);
    }
  }

  async function handleAtualizarStatus() {
    try {
      const status = await getWhatsappStatus();
      setInstancia(status.instancia);
      appToast.success(status.connected ? "Sessão conectada." : `Sessão: ${status.session || "desconectada"}.`);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível consultar o status.");
    }
  }

  async function handleToggle(
    campo:
      | "gtd_ativo"
      | "calorias_foto_ativo"
      | "calorias_texto_ia"
      | "relatorio_diario_ativo"
      | "resumo_matinal_ativo",
    valor: boolean,
  ) {
    if (!instancia) return;
    const anterior = instancia;
    setInstancia({ ...instancia, [campo]: valor });
    try {
      const { instancia: inst } = await updateWhatsappInstancia({ [campo]: valor });
      setInstancia(inst);
    } catch (error) {
      setInstancia(anterior);
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível salvar a preferência.");
    }
  }

  async function handleGerar(tipo: "diario" | "matinal") {
    setGerando(tipo);
    try {
      await gerarWhatsappRelatorio(tipo, true);
      appToast.success("Relatório gerado e enviado no seu WhatsApp. Veja em Relatórios.");
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível gerar o relatório.");
    } finally {
      setGerando(null);
    }
  }

  async function handleRemover() {
    if (!window.confirm("Remover a instância? O histórico de conversas gravado será apagado.")) return;
    try {
      await deleteWhatsappInstancia();
      setInstancia(null);
      setAtencao(null);
      appToast.success("Instância removida.");
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover a instância.");
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando WhatsApp..." />;
  }

  const conectado = instancia?.status === "conectado";

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="WhatsApp"
        description="Grava suas conversas, monitora quem importa e transforma pendências em tarefas."
        actions={
          instancia ? (
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" onClick={() => handleGerar("matinal")} disabled={gerando !== null}>
                {gerando === "matinal" ? <Spinner data-icon="inline-start" /> : <Sparkles className="size-4" />}
                Briefing agora
              </Button>
              <Button onClick={() => handleGerar("diario")} disabled={gerando !== null}>
                {gerando === "diario" ? <Spinner data-icon="inline-start" /> : <Sparkles className="size-4" />}
                Relatório do dia agora
              </Button>
            </div>
          ) : null
        }
      />

      {!instancia ? (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <MessageCircle className="size-5 text-emerald-600" />
              Conectar meu WhatsApp
            </CardTitle>
            <CardDescription>
              Cria uma instância na Evolution API e conecta o seu número via QR code. A partir daí, todas as
              conversas passam a ser gravadas para análise.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <Button onClick={handleCriar} disabled={criando}>
              {criando ? <Spinner data-icon="inline-start" /> : <Plug className="size-4" />}
              Criar instância e conectar
            </Button>
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                  {conectado ? (
                    <CheckCircle2 className="size-4 text-emerald-600" />
                  ) : (
                    <Plug className="size-4 text-amber-600" />
                  )}
                  Conexão
                </CardTitle>
                <CardDescription>
                  {conectado
                    ? `Conectado${instancia.phone ? ` como +${instancia.phone}` : ""}.`
                    : `Status: ${instancia.status}. Conecte pelo QR code para começar a gravar.`}
                </CardDescription>
              </CardHeader>
              <CardContent className="flex flex-wrap gap-2">
                {!conectado ? (
                  <Button onClick={abrirQr}>
                    <QrCode className="size-4" />
                    Conectar (QR code)
                  </Button>
                ) : null}
                <Button variant="outline" onClick={handleAtualizarStatus}>
                  <RefreshCw className="size-4" />
                  Atualizar status
                </Button>
                <Button variant="ghost" onClick={handleRemover} title="Remover instância e histórico">
                  <Trash2 className="size-4 text-red-600" />
                  Remover
                </Button>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">Automação</CardTitle>
                <CardDescription>O que a IA faz por você automaticamente.</CardDescription>
              </CardHeader>
              <CardContent className="space-y-3">
                <ToggleRow
                  label="Relatório de fim de dia"
                  description="Todo dia às 19h: quem ficou sem resposta, promessas em aberto e sugestões de tarefas — no seu WhatsApp."
                  checked={instancia.relatorio_diario_ativo}
                  onChange={(v) => handleToggle("relatorio_diario_ativo", v)}
                />
                <ToggleRow
                  label="Briefing matinal"
                  description="Todo dia às 7h30: pendências de ontem e follow-ups do dia."
                  checked={instancia.resumo_matinal_ativo}
                  onChange={(v) => handleToggle("resumo_matinal_ativo", v)}
                />
                <ToggleRow
                  label="Inbox GTD"
                  description="Mensagem enviada para você mesmo vira tarefa no kanban automaticamente."
                  checked={instancia.gtd_ativo}
                  onChange={(v) => handleToggle("gtd_ativo", v)}
                />
                <ToggleRow
                  label="Calorias por foto"
                  description="Foto de prato enviada para você mesmo vira refeição no diário alimentar, com resposta das calorias do dia."
                  checked={instancia.calorias_foto_ativo}
                  onChange={(v) => handleToggle("calorias_foto_ativo", v)}
                />
                <ToggleRow
                  label="IA decide: comida ou tarefa"
                  description="Em mensagens de texto para você mesmo, a IA identifica se é uma refeição ('2 ovos e café') ou uma tarefa. Desligado, todo texto vira tarefa."
                  checked={instancia.calorias_texto_ia}
                  onChange={(v) => handleToggle("calorias_texto_ia", v)}
                />
              </CardContent>
            </Card>
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <AtencaoLista
              titulo="Esperando VOCÊ responder"
              descricao="Conversas em que a última palavra é do contato — a sua dívida de atenção."
              itens={atencao?.aguardando_voce ?? []}
              vazio="Ninguém esperando. Caixa limpa! 🎉"
            />
            <AtencaoLista
              titulo="Você está esperando"
              descricao="Contatos monitorados que ainda não te responderam — candidatos a follow-up."
              itens={atencao?.aguardando_eles ?? []}
              vazio="Nada pendente do outro lado."
            />
          </div>
        </>
      )}

      <Sheet open={qrOpen} onOpenChange={setQrOpen}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Conectar WhatsApp</SheetTitle>
            <SheetDescription>
              No celular: WhatsApp → Configurações → Aparelhos conectados → Conectar um aparelho, e aponte a
              câmera para o QR abaixo.
            </SheetDescription>
          </SheetHeader>
          <div className="flex flex-col items-center gap-4 px-4 pb-6">
            {qrLoading ? (
              <div className="flex h-64 items-center justify-center">
                <Spinner />
              </div>
            ) : qrBase64 ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={qrBase64.startsWith("data:") ? qrBase64 : `data:image/png;base64,${qrBase64}`}
                alt="QR code de conexão do WhatsApp"
                className="size-64 rounded-md border bg-white p-2"
              />
            ) : (
              <p className="py-10 text-center text-sm text-muted-foreground">QR indisponível.</p>
            )}
            <Button variant="outline" onClick={abrirQr} disabled={qrLoading}>
              <RefreshCw className="size-4" />
              Gerar novo QR
            </Button>
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}

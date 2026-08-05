"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Boxes, FileText, Pencil, Plus, Trash2, Upload } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { toNumber } from "@/lib/format";
import { usePrivateFormat } from "@/hooks/use-private-format";
import { appToast } from "@/lib/toast";
import {
  consorcioPropostaUrl,
  createConsorcio,
  deleteConsorcio,
  getConsorcios,
  updateConsorcio,
  uploadConsorcioProposta,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { Consorcio, ConsorcioPayload, ConsorcioStatus, ConsorcioTipo } from "@/types/Consorcio";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
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
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Spinner } from "@/components/ui/spinner";

type FormState = {
  nome: string;
  administradora: string;
  tipo: ConsorcioTipo;
  grupo: string;
  cota: string;
  valor_credito: string;
  valor_mensalidade: string;
  reducao_pct: string;
  prazo_meses: string;
  parcelas_pagas: string;
  valor_pago_inicial: string;
  dia_vencimento: string;
  data_contemplacao: string;
  status: ConsorcioStatus;
  observacoes: string;
};

const emptyForm: FormState = {
  nome: "",
  administradora: "",
  tipo: "novo",
  grupo: "",
  cota: "",
  valor_credito: "",
  valor_mensalidade: "",
  reducao_pct: "",
  prazo_meses: "",
  parcelas_pagas: "",
  valor_pago_inicial: "",
  dia_vencimento: "",
  data_contemplacao: "",
  status: "ativo",
  observacoes: "",
};

const TIPO_LABELS: Record<ConsorcioTipo, string> = {
  contemplado: "Contemplado",
  novo: "Novo",
};

const TIPO_CLASSES: Record<ConsorcioTipo, string> = {
  contemplado: "border-emerald-200 bg-emerald-50 text-emerald-700",
  novo: "border-sky-200 bg-sky-50 text-sky-700",
};

export default function ConsorciosPage() {
  const { formatCurrency } = usePrivateFormat();
  const [consorcios, setConsorcios] = useState<Consorcio[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  const [editing, setEditing] = useState<Consorcio | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [formError, setFormError] = useState<string | null>(null);
  const [propostaFile, setPropostaFile] = useState<File | null>(null);

  async function load() {
    const data = await getConsorcios();
    setConsorcios(data.consorcios);
  }

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const data = await getConsorcios();
        if (mounted) setConsorcios(data.consorcios);
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar os consórcios.");
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  function openCreate() {
    setEditing(null);
    setForm(emptyForm);
    setPropostaFile(null);
    setFormError(null);
    setIsSheetOpen(true);
  }

  function openEdit(consorcio: Consorcio) {
    setEditing(consorcio);
    setForm({
      nome: consorcio.nome,
      administradora: consorcio.administradora ?? "",
      tipo: consorcio.tipo,
      grupo: consorcio.grupo ?? "",
      cota: consorcio.cota ?? "",
      valor_credito: consorcio.valor_credito ?? "",
      valor_mensalidade: consorcio.valor_mensalidade ?? "",
      reducao_pct: consorcio.reducao_pct ?? "",
      prazo_meses: consorcio.prazo_meses != null ? String(consorcio.prazo_meses) : "",
      parcelas_pagas: consorcio.parcelas_pagas != null ? String(consorcio.parcelas_pagas) : "",
      valor_pago_inicial: consorcio.valor_pago_inicial ?? "",
      dia_vencimento: consorcio.dia_vencimento != null ? String(consorcio.dia_vencimento) : "",
      data_contemplacao: consorcio.data_contemplacao ?? "",
      status: consorcio.status,
      observacoes: consorcio.observacoes ?? "",
    });
    setPropostaFile(null);
    setFormError(null);
    setIsSheetOpen(true);
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    if (!form.nome.trim()) {
      setFormError("Informe um nome para o consórcio.");
      return;
    }
    const dia = form.dia_vencimento ? Number(form.dia_vencimento) : null;
    if (dia !== null && (dia < 1 || dia > 31)) {
      setFormError("Dia de vencimento deve estar entre 1 e 31.");
      return;
    }

    const payload: ConsorcioPayload = {
      nome: form.nome.trim(),
      administradora: form.administradora.trim() || null,
      tipo: form.tipo,
      grupo: form.grupo.trim() || null,
      cota: form.cota.trim() || null,
      valor_credito: form.valor_credito ? toNumber(form.valor_credito) : null,
      valor_mensalidade: form.valor_mensalidade ? toNumber(form.valor_mensalidade) : null,
      reducao_pct: form.reducao_pct ? toNumber(form.reducao_pct) : null,
      prazo_meses: form.prazo_meses ? Number(form.prazo_meses) : null,
      parcelas_pagas: form.parcelas_pagas ? Number(form.parcelas_pagas) : null,
      valor_pago_inicial: form.valor_pago_inicial ? toNumber(form.valor_pago_inicial) : null,
      dia_vencimento: dia,
      data_contemplacao: form.tipo === "contemplado" && form.data_contemplacao ? form.data_contemplacao : null,
      status: form.status,
      observacoes: form.observacoes.trim() || null,
    };

    setSaving(true);
    try {
      const saved = editing
        ? await updateConsorcio(editing.id, payload)
        : await createConsorcio(payload);

      if (propostaFile) {
        await uploadConsorcioProposta(saved.id, propostaFile);
      }

      appToast.success(editing ? "Consórcio atualizado." : "Consórcio cadastrado.");
      setIsSheetOpen(false);
      await load();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível salvar o consórcio.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(consorcio: Consorcio) {
    if (!window.confirm(`Excluir o consórcio "${consorcio.nome}"? A proposta será removida. As parcelas viram contas a pagar avulsas (não são apagadas).`)) {
      return;
    }
    try {
      await deleteConsorcio(consorcio.id);
      appToast.success("Consórcio removido.");
      await load();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover o consórcio.");
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando consórcios..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Consórcios"
        description="Cadastre seus consórcios (contemplado ou novo) com grupo, cota e valores. Suba a proposta e clique em um consórcio para gerenciar as parcelas (que aparecem em Contas a pagar)."
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            Novo consórcio
          </Button>
        }
      />

      {consorcios.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Nenhum consórcio cadastrado. Clique em “Novo consórcio”.
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {consorcios.map((consorcio) => {
            // Histórico informado (baseline) + parcelas marcadas pagas no carnê.
            const pagas = (consorcio.parcelas_pagas ?? 0) + (consorcio.parcelas_pagas_count ?? 0);
            return (
              <Card key={consorcio.id} className="relative">
                <Link href={`/dashboard/consorcios/${consorcio.id}`} className="block">
                  <CardHeader>
                    <div className="flex items-center gap-2">
                      <Boxes className="size-5 text-muted-foreground" />
                      <CardTitle className="truncate">{consorcio.nome}</CardTitle>
                    </div>
                    <CardDescription className="flex items-center gap-2">
                      <span
                        className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${TIPO_CLASSES[consorcio.tipo]}`}
                      >
                        {TIPO_LABELS[consorcio.tipo]}
                      </span>
                      {consorcio.administradora ? <span className="truncate">{consorcio.administradora}</span> : null}
                    </CardDescription>
                  </CardHeader>
                  <CardContent className="space-y-2 text-sm">
                    <div className="flex justify-between">
                      <span className="text-muted-foreground">Grupo · Cota</span>
                      <span className="font-medium tabular-nums">
                        {[consorcio.grupo, consorcio.cota].filter(Boolean).join(" · ") || "—"}
                      </span>
                    </div>
                    {consorcio.valor_credito ? (
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Crédito</span>
                        <span className="font-medium tabular-nums">{formatCurrency(toNumber(consorcio.valor_credito))}</span>
                      </div>
                    ) : null}
                    {consorcio.valor_mensalidade ? (
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Mensalidade</span>
                        <span className="font-medium tabular-nums">{formatCurrency(toNumber(consorcio.valor_mensalidade))}</span>
                      </div>
                    ) : null}
                    {consorcio.prazo_meses ? (
                      <div className="flex justify-between">
                        <span className="text-muted-foreground">Parcelas pagas</span>
                        <span className="font-medium tabular-nums">
                          {pagas} / {consorcio.prazo_meses}
                        </span>
                      </div>
                    ) : null}
                  </CardContent>
                </Link>
                <div className="flex items-center justify-between px-4 pb-4">
                  {consorcio.proposta_path ? (
                    <a
                      href={consorcioPropostaUrl(consorcio.id)}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                    >
                      <FileText className="size-3.5" />
                      Ver proposta
                    </a>
                  ) : (
                    <span className="text-xs text-muted-foreground">Sem proposta</span>
                  )}
                  <div className="flex gap-1">
                    <Button variant="ghost" size="icon" title="Editar" onClick={() => openEdit(consorcio)}>
                      <Pencil className="size-4" />
                    </Button>
                    <Button variant="ghost" size="icon" title="Excluir" onClick={() => handleDelete(consorcio)}>
                      <Trash2 className="size-4 text-red-600" />
                    </Button>
                  </div>
                </div>
              </Card>
            );
          })}
        </div>
      )}

      <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{editing ? "Editar consórcio" : "Novo consórcio"}</SheetTitle>
            <SheetDescription>
              Informe os dados do contrato. A proposta pode ser um PDF ou imagem (até 20MB).
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleSubmit}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="co-nome">Nome</FieldLabel>
                <Input id="co-nome" value={form.nome}
                  onChange={(e) => setForm({ ...form, nome: e.target.value })}
                  placeholder="Ex.: Consórcio Imóvel Porto" required />
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="co-tipo">Tipo</FieldLabel>
                  <Select value={form.tipo} onValueChange={(v) => setForm({ ...form, tipo: v as ConsorcioTipo })}>
                    <SelectTrigger id="co-tipo">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="contemplado">Contemplado</SelectItem>
                      <SelectItem value="novo">Novo</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>
                <Field>
                  <FieldLabel htmlFor="co-admin">Administradora</FieldLabel>
                  <Input id="co-admin" value={form.administradora}
                    onChange={(e) => setForm({ ...form, administradora: e.target.value })}
                    placeholder="Porto, Embracon..." />
                </Field>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="co-grupo">Grupo</FieldLabel>
                  <Input id="co-grupo" value={form.grupo}
                    onChange={(e) => setForm({ ...form, grupo: e.target.value })}
                    placeholder="Ex.: 1234" />
                </Field>
                <Field>
                  <FieldLabel htmlFor="co-cota">Cota</FieldLabel>
                  <Input id="co-cota" value={form.cota}
                    onChange={(e) => setForm({ ...form, cota: e.target.value })}
                    placeholder="Ex.: 567" />
                </Field>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="co-credito">Crédito (R$)</FieldLabel>
                  <Input id="co-credito" type="number" step="0.01" min="0" value={form.valor_credito}
                    onChange={(e) => setForm({ ...form, valor_credito: e.target.value })} placeholder="0,00" />
                </Field>
                <Field>
                  <FieldLabel htmlFor="co-mensalidade">Mensalidade (R$)</FieldLabel>
                  <Input id="co-mensalidade" type="number" step="0.01" min="0" value={form.valor_mensalidade}
                    onChange={(e) => setForm({ ...form, valor_mensalidade: e.target.value })} placeholder="0,00" />
                </Field>
              </div>

              <Field>
                <FieldLabel htmlFor="co-reducao">Redução da parcela até contemplar (%)</FieldLabel>
                <Input id="co-reducao" type="number" step="1" min="0" max="90" value={form.reducao_pct}
                  onChange={(e) => setForm({ ...form, reducao_pct: e.target.value })} placeholder="0" />
                <p className="text-xs text-muted-foreground">
                  Se a parcela é reduzida até a contemplação (ex.: 50). Vazio/0 = parcela cheia. Afeta o total estimado.
                </p>
              </Field>

              <div className="grid grid-cols-3 gap-3">
                <Field>
                  <FieldLabel htmlFor="co-prazo">Prazo (meses)</FieldLabel>
                  <Input id="co-prazo" type="number" min="1" max="600" value={form.prazo_meses}
                    onChange={(e) => setForm({ ...form, prazo_meses: e.target.value })} placeholder="120" />
                </Field>
                <Field>
                  <FieldLabel htmlFor="co-pagas">Pagas</FieldLabel>
                  <Input id="co-pagas" type="number" min="0" max="600" value={form.parcelas_pagas}
                    onChange={(e) => setForm({ ...form, parcelas_pagas: e.target.value })} placeholder="0" />
                </Field>
                <Field>
                  <FieldLabel htmlFor="co-dia">Dia venc.</FieldLabel>
                  <Input id="co-dia" type="number" min="1" max="31" value={form.dia_vencimento}
                    onChange={(e) => setForm({ ...form, dia_vencimento: e.target.value })} placeholder="10" />
                </Field>
              </div>

              <Field>
                <FieldLabel htmlFor="co-pago-inicial">Total já pago (R$)</FieldLabel>
                <Input id="co-pago-inicial" type="number" step="0.01" min="0" value={form.valor_pago_inicial}
                  onChange={(e) => setForm({ ...form, valor_pago_inicial: e.target.value })} placeholder="0,00" />
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="co-status">Status</FieldLabel>
                  <Select value={form.status} onValueChange={(v) => setForm({ ...form, status: v as ConsorcioStatus })}>
                    <SelectTrigger id="co-status">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="ativo">Ativo</SelectItem>
                      <SelectItem value="quitado">Quitado</SelectItem>
                      <SelectItem value="cancelado">Cancelado</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>
                {form.tipo === "contemplado" ? (
                  <Field>
                    <FieldLabel htmlFor="co-contemplacao">Contemplação</FieldLabel>
                    <Input id="co-contemplacao" type="date" value={form.data_contemplacao}
                      onChange={(e) => setForm({ ...form, data_contemplacao: e.target.value })} />
                  </Field>
                ) : null}
              </div>

              <Field>
                <FieldLabel htmlFor="co-obs">Observações</FieldLabel>
                <textarea id="co-obs" value={form.observacoes}
                  onChange={(e) => setForm({ ...form, observacoes: e.target.value })}
                  rows={3}
                  className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                  placeholder="Anotações sobre o contrato..." />
              </Field>

              <Field>
                <FieldLabel htmlFor="co-proposta">Proposta (PDF ou imagem)</FieldLabel>
                <Input id="co-proposta" type="file"
                  accept="application/pdf,image/png,image/jpeg,image/webp"
                  onChange={(e) => setPropostaFile(e.target.files?.[0] ?? null)} />
                {editing?.proposta_path && !propostaFile ? (
                  <p className="text-xs text-muted-foreground">
                    Já existe uma proposta enviada. Escolher um novo arquivo substitui a atual.
                  </p>
                ) : null}
                {propostaFile ? (
                  <p className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                    <Upload className="size-3.5" />
                    {propostaFile.name}
                  </p>
                ) : null}
              </Field>

              <FieldError>{formError}</FieldError>
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={saving}>
                {saving ? <Spinner data-icon="inline-start" /> : null}
                {editing ? "Salvar alterações" : "Cadastrar"}
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </div>
  );
}

"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import {
  Cell,
  Label,
  Legend,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip as RechartsTooltip,
} from "recharts";
import {
  ArrowLeft,
  CheckCircle2,
  FileText,
  LineChart,
  ListPlus,
  Pencil,
  Percent,
  Plus,
  RotateCcw,
  Trash2,
  Upload,
  Wallet,
} from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { SummaryCard } from "@/components/dashboard/summary-card";
import { formatCurrency, formatFullDate, toNumber, todayISO } from "@/lib/format";
import { buildProjecao, buildProjecaoContemplado } from "@/lib/consorcio";
import { appToast } from "@/lib/toast";
import {
  aplicarReajusteConsorcio,
  consorcioPropostaUrl,
  createConsorcioParcela,
  deletePayable,
  deleteConsorcioProposta,
  generateConsorcioParcelas,
  getBankAccounts,
  getConsorcioParcelas,
  getConsorcios,
  payPayable,
  unpayPayable,
  updatePayable,
  uploadConsorcioProposta,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { Consorcio, ConsorcioTipo } from "@/types/Consorcio";
import type { BankAccount, Payable } from "@/types/Finance";
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

const TIPO_LABELS: Record<ConsorcioTipo, string> = {
  contemplado: "Contemplado",
  novo: "Novo",
};

type ParcelaFormState = {
  numero: string;
  vencimento: string;
  valor: string;
};

type GenerateFormState = {
  quantidade: string;
  valor: string;
  primeiro_vencimento: string;
  numero_inicial: string;
};

export default function ConsorcioDetailPage() {
  const params = useParams<{ id: string }>();
  const consorcioId = Number(params.id);

  const [consorcio, setConsorcio] = useState<Consorcio | null>(null);
  const [parcelas, setParcelas] = useState<Payable[]>([]);
  const [accounts, setAccounts] = useState<BankAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [notFound, setNotFound] = useState(false);

  // Parcela avulsa (nova/editar)
  const [isParcelaOpen, setIsParcelaOpen] = useState(false);
  const [editing, setEditing] = useState<Payable | null>(null);
  const [pForm, setPForm] = useState<ParcelaFormState>({ numero: "", vencimento: todayISO(), valor: "" });
  const [pError, setPError] = useState<string | null>(null);
  const [savingP, setSavingP] = useState(false);

  // Gerar carnê
  const [isGenerateOpen, setIsGenerateOpen] = useState(false);
  const [gForm, setGForm] = useState<GenerateFormState>({
    quantidade: "",
    valor: "",
    primeiro_vencimento: todayISO(),
    numero_inicial: "1",
  });
  const [gError, setGError] = useState<string | null>(null);
  const [generating, setGenerating] = useState(false);

  // Baixa (pagar)
  const [payTarget, setPayTarget] = useState<Payable | null>(null);
  const [payAccountId, setPayAccountId] = useState("");
  const [payDate, setPayDate] = useState(todayISO());
  const [payError, setPayError] = useState<string | null>(null);
  const [paying, setPaying] = useState(false);

  // Reajuste anual
  const [isReajusteOpen, setIsReajusteOpen] = useState(false);
  const [reajustePct, setReajustePct] = useState("");
  const [reajusteError, setReajusteError] = useState<string | null>(null);
  const [applyingReajuste, setApplyingReajuste] = useState(false);

  const [uploadingProposta, setUploadingProposta] = useState(false);
  const propostaInputRef = useRef<HTMLInputElement | null>(null);

  async function loadParcelas() {
    const data = await getConsorcioParcelas(consorcioId);
    setParcelas(data.parcelas);
  }

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const [list, parc, acc] = await Promise.all([
          getConsorcios(),
          getConsorcioParcelas(consorcioId),
          getBankAccounts(),
        ]);
        if (!mounted) return;
        const found = list.consorcios.find((c) => c.id === consorcioId) ?? null;
        setConsorcio(found);
        setNotFound(!found);
        setParcelas(parc.parcelas);
        setAccounts(acc.accounts);
      } catch (error) {
        if (mounted) {
          appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar o consórcio.");
        }
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, [consorcioId]);

  const pagas = parcelas.filter((p) => p.is_paid);
  const totalParcelas = consorcio?.prazo_meses ?? parcelas.length;
  const proxima = parcelas.find((p) => !p.is_paid) ?? null;

  // Baseline = histórico informado fora do carnê (parcelas_pagas / valor_pago_inicial),
  // somado ao que estiver pago no carnê (contas a pagar vinculadas).
  const baselinePagas = consorcio?.parcelas_pagas ?? 0;
  const baselinePago = consorcio?.valor_pago_inicial != null
    ? toNumber(consorcio.valor_pago_inicial)
    : baselinePagas * toNumber(consorcio?.valor_mensalidade ?? "0");
  const parcelasPagasCount = baselinePagas + pagas.length;
  const totalPago = baselinePago + pagas.reduce((sum, p) => sum + toNumber(p.amount), 0);

  // Total REAL a pagar (sem reajuste) via a mesma lógica da Projeção: para cota nova é a
  // parcela CHEIA × prazo (a reduzida é só adiamento — não some do total); para contemplada
  // é o já pago + as parcelas restantes. Falta = total − já pago.
  const mensalidadeNum = toNumber(consorcio?.valor_mensalidade ?? "0");
  const prazoNum = consorcio?.prazo_meses ?? 0;
  const proj =
    consorcio?.tipo === "contemplado"
      ? buildProjecaoContemplado({
          prazo: prazoNum,
          parcelasPagas: parcelasPagasCount,
          mensalidade: mensalidadeNum,
          valorPagoInicial: totalPago,
          ipcaAnualPct: 0,
        })
      : buildProjecao({
          credito: toNumber(consorcio?.valor_credito ?? "0"),
          prazo: prazoNum,
          parcelaReduzida: mensalidadeNum,
          reducaoPct: toNumber(consorcio?.reducao_pct ?? "0"),
          contemplacaoMes: Math.max(1, Math.round(prazoNum / 2)),
          parcelasPagas: parcelasPagasCount,
          ipcaAnualPct: 0,
        });
  const totalEstimado = prazoNum > 0 && mensalidadeNum > 0 ? proj.totais.totalEstimado : totalPago;
  const falta = Math.max(0, totalEstimado - totalPago);
  const pctPago = totalEstimado > 0 ? Math.round((totalPago / totalEstimado) * 100) : 0;
  const pieData = [
    { name: "Pago", value: Math.round(totalPago), color: "#22c55e" },
    { name: "Falta", value: Math.round(falta), color: "#f59e0b" },
  ];

  function openCreateParcela() {
    setEditing(null);
    const nextNumero = parcelas.reduce((max, p) => Math.max(max, p.installment_number ?? 0), 0) + 1;
    setPForm({
      numero: String(nextNumero || baselinePagas + 1),
      vencimento: todayISO(),
      valor: consorcio?.valor_mensalidade ?? "",
    });
    setPError(null);
    setIsParcelaOpen(true);
  }

  function openEditParcela(p: Payable) {
    setEditing(p);
    setPForm({
      numero: p.installment_number != null ? String(p.installment_number) : "",
      vencimento: p.due_date,
      valor: p.amount,
    });
    setPError(null);
    setIsParcelaOpen(true);
  }

  async function handleSubmitParcela(event: React.FormEvent) {
    event.preventDefault();
    setPError(null);

    if (!pForm.vencimento) {
      setPError("Informe o vencimento.");
      return;
    }
    const valor = toNumber(pForm.valor);
    if (!valor || valor <= 0) {
      setPError("Informe um valor válido.");
      return;
    }

    setSavingP(true);
    try {
      if (editing) {
        // Reusa o endpoint de contas a pagar (mantém descrição/categoria existentes).
        await updatePayable(editing.id, {
          description: editing.description,
          category_id: editing.category_id,
          amount: valor,
          due_date: pForm.vencimento,
        });
        appToast.success("Parcela atualizada.");
      } else {
        await createConsorcioParcela(consorcioId, {
          numero: pForm.numero ? Number(pForm.numero) : null,
          vencimento: pForm.vencimento,
          valor,
        });
        appToast.success("Parcela adicionada.");
      }
      setIsParcelaOpen(false);
      await loadParcelas();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível salvar a parcela.";
      setPError(message);
      appToast.error(message);
    } finally {
      setSavingP(false);
    }
  }

  async function handleGenerate(event: React.FormEvent) {
    event.preventDefault();
    setGError(null);

    const quantidade = Number(gForm.quantidade);
    const valor = toNumber(gForm.valor);
    if (!quantidade || quantidade < 1) {
      setGError("Informe a quantidade de parcelas.");
      return;
    }
    if (!valor || valor <= 0) {
      setGError("Informe o valor da parcela.");
      return;
    }
    if (!gForm.primeiro_vencimento) {
      setGError("Informe o primeiro vencimento.");
      return;
    }

    setGenerating(true);
    try {
      const result = await generateConsorcioParcelas(consorcioId, {
        quantidade,
        valor,
        primeiro_vencimento: gForm.primeiro_vencimento,
        numero_inicial: gForm.numero_inicial ? Number(gForm.numero_inicial) : undefined,
      });
      setParcelas(result.parcelas);
      setIsGenerateOpen(false);
      appToast.success(`${result.criadas} parcela(s) geradas.`);
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível gerar as parcelas.";
      setGError(message);
      appToast.error(message);
    } finally {
      setGenerating(false);
    }
  }

  function openPay(p: Payable) {
    setPayTarget(p);
    setPayAccountId(accounts[0] ? String(accounts[0].id) : "");
    setPayDate(todayISO());
    setPayError(null);
  }

  async function handlePay(event: React.FormEvent) {
    event.preventDefault();
    setPayError(null);
    if (!payTarget) return;
    if (!payAccountId) {
      setPayError("Selecione a conta de origem.");
      return;
    }
    setPaying(true);
    try {
      await payPayable(payTarget.id, { bank_account_id: Number(payAccountId), paid_at: payDate });
      setPayTarget(null);
      await loadParcelas();
      appToast.success("Parcela paga.");
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível dar baixa.";
      setPayError(message);
      appToast.error(message);
    } finally {
      setPaying(false);
    }
  }

  async function handleUnpay(p: Payable) {
    try {
      await unpayPayable(p.id);
      await loadParcelas();
      appToast.success("Baixa estornada.");
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível estornar.");
    }
  }

  async function handleReajuste(event: React.FormEvent) {
    event.preventDefault();
    setReajusteError(null);

    const pct = toNumber(reajustePct);
    if (!pct || pct <= 0) {
      setReajusteError("Informe o percentual do reajuste (ex.: 4,5).");
      return;
    }

    setApplyingReajuste(true);
    try {
      const result = await aplicarReajusteConsorcio(consorcioId, pct);
      setConsorcio(result.consorcio);
      setParcelas(result.parcelas);
      setIsReajusteOpen(false);
      setReajustePct("");
      appToast.success(`Reajuste de ${pct}% aplicado.`);
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível aplicar o reajuste.";
      setReajusteError(message);
      appToast.error(message);
    } finally {
      setApplyingReajuste(false);
    }
  }

  async function handleDeleteParcela(p: Payable) {
    if (!window.confirm(`Excluir a parcela ${p.installment_number ?? ""} (${formatFullDate(p.due_date)})?`)) {
      return;
    }
    try {
      await deletePayable(p.id);
      await loadParcelas();
      appToast.success("Parcela removida.");
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover a parcela.");
    }
  }

  async function handleUploadProposta(file: File | null) {
    if (!file) return;
    setUploadingProposta(true);
    try {
      const updated = await uploadConsorcioProposta(consorcioId, file);
      setConsorcio((prev) => (prev ? { ...prev, proposta_path: updated.proposta_path } : prev));
      appToast.success("Proposta enviada.");
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível enviar a proposta.");
    } finally {
      setUploadingProposta(false);
      if (propostaInputRef.current) propostaInputRef.current.value = "";
    }
  }

  async function handleDeleteProposta() {
    if (!window.confirm("Remover a proposta enviada?")) return;
    try {
      const updated = await deleteConsorcioProposta(consorcioId);
      setConsorcio((prev) => (prev ? { ...prev, proposta_path: updated.proposta_path } : prev));
      appToast.success("Proposta removida.");
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover a proposta.");
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando consórcio..." />;
  }

  if (notFound || !consorcio) {
    return (
      <div className="space-y-4">
        <Link href="/dashboard/consorcios" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
          <ArrowLeft className="size-4" />
          Voltar
        </Link>
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Consórcio não encontrado.
          </CardContent>
        </Card>
      </div>
    );
  }

  const subtitleParts = [
    TIPO_LABELS[consorcio.tipo],
    consorcio.administradora,
    [consorcio.grupo, consorcio.cota].filter(Boolean).length
      ? `Grupo ${consorcio.grupo ?? "—"} · Cota ${consorcio.cota ?? "—"}`
      : null,
  ].filter(Boolean);

  return (
    <div className="space-y-6">
      <Link href="/dashboard/consorcios" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" />
        Voltar para consórcios
      </Link>

      <DashboardPageHeader
        title={consorcio.nome}
        description={subtitleParts.join(" · ")}
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" asChild>
              <Link href={`/dashboard/consorcios/${consorcio.id}/projecao`}>
                <LineChart className="size-4" />
                Projeção
              </Link>
            </Button>
            <Button variant="outline" onClick={() => { setReajusteError(null); setReajustePct(""); setIsReajusteOpen(true); }}>
              <Percent className="size-4" />
              Reajuste anual
            </Button>
            <Button variant="outline" onClick={() => setIsGenerateOpen(true)}>
              <ListPlus className="size-4" />
              Gerar carnê
            </Button>
            <Button onClick={openCreateParcela}>
              <Plus className="size-4" />
              Nova parcela
            </Button>
          </div>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <SummaryCard label="Crédito" value={formatCurrency(toNumber(consorcio.valor_credito ?? "0"))} />
        <SummaryCard label="Mensalidade" value={formatCurrency(toNumber(consorcio.valor_mensalidade ?? "0"))} />
        <SummaryCard
          label="Parcelas pagas"
          value={`${parcelasPagasCount} / ${totalParcelas || "—"}`}
          accentClass="text-emerald-600"
        />
        <SummaryCard label="Total pago" value={formatCurrency(totalPago)} accentClass="text-emerald-600" />
      </div>

      {/* Gráfico: pago x falta (total no centro) */}
      <Card>
        <CardHeader>
          <CardTitle>Pago x falta</CardTitle>
          <CardDescription>
            {parcelasPagasCount}/{totalParcelas || "—"} parcelas · {pctPago}% quitado. Total a pagar sem
            reajuste — veja cenários de IPCA na Projeção.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="grid items-center gap-4 sm:grid-cols-[1fr_220px]">
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie
                    data={pieData}
                    dataKey="value"
                    nameKey="name"
                    innerRadius={72}
                    outerRadius={104}
                    paddingAngle={2}
                    strokeWidth={0}
                  >
                    {pieData.map((d) => (
                      <Cell key={d.name} fill={d.color} />
                    ))}
                    <Label
                      position="center"
                      content={({ viewBox }) => {
                        const vb = viewBox as { cx: number; cy: number };
                        return (
                          <text x={vb.cx} y={vb.cy} textAnchor="middle" dominantBaseline="middle">
                            <tspan x={vb.cx} dy="-0.5em" fontSize="11" fill="#6b7280">Total</tspan>
                            <tspan x={vb.cx} dy="1.5em" fontSize="16" fontWeight="600" fill="#111827">
                              {formatCurrency(totalEstimado)}
                            </tspan>
                          </text>
                        );
                      }}
                    />
                  </Pie>
                  <RechartsTooltip formatter={(v) => formatCurrency(Number(v))} />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            </div>
            <div className="space-y-3 text-sm sm:pr-4">
              <div className="flex items-center justify-between gap-4">
                <span className="flex items-center gap-2 text-muted-foreground">
                  <span className="inline-block size-2.5 rounded-full" style={{ backgroundColor: "#22c55e" }} />
                  Pago
                </span>
                <span className="font-medium tabular-nums text-emerald-600">{formatCurrency(totalPago)}</span>
              </div>
              <div className="flex items-center justify-between gap-4">
                <span className="flex items-center gap-2 text-muted-foreground">
                  <span className="inline-block size-2.5 rounded-full" style={{ backgroundColor: "#f59e0b" }} />
                  Falta
                </span>
                <span className="font-medium tabular-nums text-amber-600">{formatCurrency(falta)}</span>
              </div>
              <div className="flex items-center justify-between gap-4 border-t pt-3">
                <span className="text-muted-foreground">Total estimado</span>
                <span className="font-semibold tabular-nums">{formatCurrency(totalEstimado)}</span>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Proposta */}
      <Card>
        <CardContent className="flex flex-wrap items-center justify-between gap-3 py-4">
          <div className="flex items-center gap-2 text-sm">
            <FileText className="size-4 text-muted-foreground" />
            {consorcio.proposta_path ? (
              <a
                href={consorcioPropostaUrl(consorcio.id)}
                target="_blank"
                rel="noopener noreferrer"
                className="font-medium text-sky-700 hover:underline"
              >
                Ver proposta enviada
              </a>
            ) : (
              <span className="text-muted-foreground">Nenhuma proposta enviada ainda.</span>
            )}
          </div>
          <div className="flex items-center gap-2">
            <input
              ref={propostaInputRef}
              type="file"
              accept="application/pdf,image/png,image/jpeg,image/webp"
              className="hidden"
              onChange={(e) => handleUploadProposta(e.target.files?.[0] ?? null)}
            />
            <Button
              variant="outline"
              size="sm"
              disabled={uploadingProposta}
              onClick={() => propostaInputRef.current?.click()}
            >
              {uploadingProposta ? <Spinner data-icon="inline-start" /> : <Upload className="size-4" />}
              {consorcio.proposta_path ? "Substituir" : "Enviar proposta"}
            </Button>
            {consorcio.proposta_path ? (
              <Button variant="ghost" size="sm" onClick={handleDeleteProposta}>
                <Trash2 className="size-4 text-red-600" />
              </Button>
            ) : null}
          </div>
        </CardContent>
      </Card>

      {/* Parcelas (contas a pagar vinculadas) */}
      <Card>
        <CardContent className="p-0">
          {parcelas.length === 0 ? (
            <div className="py-10 text-center text-sm text-muted-foreground">
              Nenhuma parcela no carnê. Use “Gerar carnê” para criar todas de uma vez ou “Nova parcela”.
              As parcelas também aparecem em <strong>Contas a pagar</strong>.
            </div>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-16">#</TableHead>
                  <TableHead>Vencimento</TableHead>
                  <TableHead className="text-right">Valor</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="w-44 text-right">Ações</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {parcelas.map((p) => (
                  <TableRow key={p.id} className={p.is_paid ? "bg-emerald-50/40" : undefined}>
                    <TableCell className="tabular-nums text-muted-foreground">{p.installment_number ?? "—"}</TableCell>
                    <TableCell className="tabular-nums">{formatFullDate(p.due_date)}</TableCell>
                    <TableCell className="text-right font-medium tabular-nums">{formatCurrency(toNumber(p.amount))}</TableCell>
                    <TableCell>
                      {p.is_paid ? (
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">
                          <CheckCircle2 className="size-3.5" />
                          {`Paga${p.paid_at ? ` · ${formatFullDate(p.paid_at)}` : ""}${p.bank_account ? ` · ${p.bank_account.name}` : ""}`}
                        </span>
                      ) : (
                        <span className="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                          Em aberto
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-1">
                        {p.is_paid ? (
                          <Button variant="ghost" size="sm" title="Estornar baixa" onClick={() => handleUnpay(p)}>
                            <RotateCcw className="size-4" />
                            Estornar
                          </Button>
                        ) : (
                          <Button variant="outline" size="sm" title="Pagar" onClick={() => openPay(p)}>
                            <Wallet className="size-4" />
                            Pagar
                          </Button>
                        )}
                        <Button variant="ghost" size="icon" title="Editar" onClick={() => openEditParcela(p)}>
                          <Pencil className="size-4" />
                        </Button>
                        <Button variant="ghost" size="icon" title="Excluir" onClick={() => handleDeleteParcela(p)}>
                          <Trash2 className="size-4 text-red-600" />
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>

      {proxima ? (
        <p className="text-sm text-muted-foreground">
          Próximo vencimento: <span className="font-medium text-foreground">{formatFullDate(proxima.due_date)}</span>{" "}
          ({formatCurrency(toNumber(proxima.amount))})
        </p>
      ) : null}

      {/* Sheet: nova/editar parcela */}
      <Sheet open={isParcelaOpen} onOpenChange={setIsParcelaOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{editing ? "Editar parcela" : "Nova parcela"}</SheetTitle>
            <SheetDescription>
              Parcela do carnê — também aparece em Contas a pagar.
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleSubmitParcela}>
            <FieldGroup className="gap-4">
              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="p-numero">Nº da parcela</FieldLabel>
                  <Input id="p-numero" type="number" min="1" value={pForm.numero}
                    disabled={editing !== null}
                    onChange={(e) => setPForm({ ...pForm, numero: e.target.value })} placeholder="1" />
                </Field>
                <Field>
                  <FieldLabel htmlFor="p-venc">Vencimento</FieldLabel>
                  <Input id="p-venc" type="date" value={pForm.vencimento}
                    onChange={(e) => setPForm({ ...pForm, vencimento: e.target.value })} required />
                </Field>
              </div>
              <Field>
                <FieldLabel htmlFor="p-valor">Valor (R$)</FieldLabel>
                <Input id="p-valor" type="number" step="0.01" min="0" value={pForm.valor}
                  onChange={(e) => setPForm({ ...pForm, valor: e.target.value })} placeholder="0,00" required />
              </Field>
              <FieldError>{pError}</FieldError>
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={savingP}>
                {savingP ? <Spinner data-icon="inline-start" /> : null}
                {editing ? "Salvar alterações" : "Adicionar"}
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>

      {/* Sheet: gerar carnê */}
      <Sheet open={isGenerateOpen} onOpenChange={setIsGenerateOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Gerar carnê</SheetTitle>
            <SheetDescription>
              Cria várias parcelas de uma vez (contas a pagar), somando 1 mês a cada uma.
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleGenerate}>
            <FieldGroup className="gap-4">
              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="g-qtd">Quantidade</FieldLabel>
                  <Input id="g-qtd" type="number" min="1" max="600" value={gForm.quantidade}
                    onChange={(e) => setGForm({ ...gForm, quantidade: e.target.value })} placeholder="120" required />
                </Field>
                <Field>
                  <FieldLabel htmlFor="g-valor">Valor da parcela (R$)</FieldLabel>
                  <Input id="g-valor" type="number" step="0.01" min="0" value={gForm.valor}
                    onChange={(e) => setGForm({ ...gForm, valor: e.target.value })} placeholder="0,00" required />
                </Field>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="g-venc">1º vencimento</FieldLabel>
                  <Input id="g-venc" type="date" value={gForm.primeiro_vencimento}
                    onChange={(e) => setGForm({ ...gForm, primeiro_vencimento: e.target.value })} required />
                </Field>
                <Field>
                  <FieldLabel htmlFor="g-num">Nº inicial</FieldLabel>
                  <Input id="g-num" type="number" min="1" value={gForm.numero_inicial}
                    onChange={(e) => setGForm({ ...gForm, numero_inicial: e.target.value })} placeholder="1" />
                </Field>
              </div>
              <FieldError>{gError}</FieldError>
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={generating}>
                {generating ? <Spinner data-icon="inline-start" /> : null}
                Gerar
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>

      {/* Sheet: baixa (pagar) */}
      <Sheet open={payTarget !== null} onOpenChange={(open) => { if (!open) setPayTarget(null); }}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Pagar parcela</SheetTitle>
            <SheetDescription>
              {payTarget
                ? `${formatCurrency(toNumber(payTarget.amount))} · vence ${formatFullDate(payTarget.due_date)}. O valor é debitado do saldo da conta.`
                : ""}
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handlePay}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="pay-conta">Conta de origem</FieldLabel>
                <Select value={payAccountId} onValueChange={setPayAccountId}>
                  <SelectTrigger id="pay-conta">
                    <SelectValue placeholder="Selecione a conta" />
                  </SelectTrigger>
                  <SelectContent>
                    {accounts.map((a) => (
                      <SelectItem key={a.id} value={String(a.id)}>
                        {a.name} ({formatCurrency(toNumber(a.balance))})
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              <Field>
                <FieldLabel htmlFor="pay-data">Data do pagamento</FieldLabel>
                <Input id="pay-data" type="date" value={payDate} onChange={(e) => setPayDate(e.target.value)} />
              </Field>
              <FieldError>{payError}</FieldError>
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={paying || accounts.length === 0}>
                {paying ? <Spinner data-icon="inline-start" /> : null}
                {accounts.length === 0 ? "Cadastre uma conta primeiro" : "Confirmar pagamento"}
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>

      {/* Sheet: reajuste anual */}
      <Sheet open={isReajusteOpen} onOpenChange={setIsReajusteOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Aplicar reajuste anual</SheetTitle>
            <SheetDescription>
              Sobe a mensalidade padrão e as parcelas em aberto pelo percentual informado (IPCA do
              período). Parcelas já pagas não mudam.
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleReajuste}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="r-pct">Reajuste (%)</FieldLabel>
                <Input id="r-pct" type="number" step="0.01" min="0" value={reajustePct}
                  onChange={(e) => setReajustePct(e.target.value)} placeholder="4,5" required />
              </Field>
              <FieldError>{reajusteError}</FieldError>
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={applyingReajuste}>
                {applyingReajuste ? <Spinner data-icon="inline-start" /> : null}
                Aplicar
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </div>
  );
}

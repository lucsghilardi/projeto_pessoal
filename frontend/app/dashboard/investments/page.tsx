"use client";

import { useEffect, useMemo, useState } from "react";
import { Pencil, Plus, PlusCircle, RefreshCw, Trash2, TrendingDown, TrendingUp } from "lucide-react";
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip as RechartsTooltip,
  XAxis,
  YAxis,
} from "recharts";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { SummaryCard } from "@/components/dashboard/summary-card";
import { formatCurrency, toNumber, todayISO } from "@/lib/format";
import { appToast } from "@/lib/toast";
import {
  addContribution,
  bulkUpdateInvestmentValues,
  createInvestment,
  deleteInvestment,
  getInvestmentInstitutions,
  getInvestmentSummary,
  getInvestmentTags,
  getInvestments,
  updateInvestment,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type {
  Investment,
  InvestmentPayload,
  InvestmentSummary,
  InvestmentType,
} from "@/types/Investment";
import type { InvestmentTag } from "@/types/InvestmentTag";
import type { InvestmentInstitution } from "@/types/InvestmentInstitution";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

const TYPE_LABELS: Record<InvestmentType, string> = {
  caixinha: "Caixinha",
  poupanca: "Poupança",
  acoes: "Ações",
  fii: "FII",
  fundo: "Fundo",
  tesouro: "Tesouro Direto",
  cdb: "CDB",
  cripto: "Cripto",
  previdencia: "Previdência",
  outro: "Outro",
};

const TYPE_OPTIONS = Object.entries(TYPE_LABELS) as Array<[InvestmentType, string]>;

const CHART_COLORS = [
  "#2563eb",
  "#16a34a",
  "#f59e0b",
  "#db2777",
  "#0891b2",
  "#7c3aed",
  "#dc2626",
  "#65a30d",
  "#0d9488",
  "#64748b",
];

type FormState = {
  name: string;
  type: InvestmentType;
  currency: "BRL" | "USD";
  institution: string;
  applied_amount: string;
  current_amount: string;
  notes: string;
  is_active: boolean;
  tag_ids: number[];
};

const emptyForm: FormState = {
  name: "",
  type: "caixinha",
  currency: "BRL",
  institution: "",
  applied_amount: "",
  current_amount: "",
  notes: "",
  is_active: true,
  tag_ids: [],
};

function formatPercent(value: number) {
  return `${value > 0 ? "+" : ""}${value.toFixed(2).replace(".", ",")}%`;
}

function investmentToForm(investment: Investment): FormState {
  return {
    name: investment.name,
    type: investment.type,
    currency: investment.currency === "USD" ? "USD" : "BRL",
    institution: investment.institution ?? "",
    applied_amount: String(investment.applied_amount),
    current_amount: String(investment.current_amount),
    notes: investment.notes ?? "",
    is_active: investment.is_active,
    tag_ids: investment.tags.map((tag) => tag.id),
  };
}

export default function InvestmentsPage() {
  const [investments, setInvestments] = useState<Investment[]>([]);
  const [tags, setTags] = useState<InvestmentTag[]>([]);
  const [institutions, setInstitutions] = useState<InvestmentInstitution[]>([]);
  const [summary, setSummary] = useState<InvestmentSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  const [editing, setEditing] = useState<Investment | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [formError, setFormError] = useState<string | null>(null);

  // Aporte (incluir dinheiro novo)
  const [contributeTarget, setContributeTarget] = useState<Investment | null>(null);
  const [contributeAmount, setContributeAmount] = useState("");
  const [contributeDate, setContributeDate] = useState("");
  const [contributeError, setContributeError] = useState<string | null>(null);
  const [savingContribute, setSavingContribute] = useState(false);

  // Atualização de saldo em massa
  const [isUpdateOpen, setIsUpdateOpen] = useState(false);
  const [updateValues, setUpdateValues] = useState<Record<number, string>>({});
  const [savingUpdate, setSavingUpdate] = useState(false);

  async function reload() {
    const [list, summaryData, tagList, institutionList] = await Promise.all([
      getInvestments(),
      getInvestmentSummary(),
      getInvestmentTags(),
      getInvestmentInstitutions(),
    ]);
    setInvestments(list);
    setSummary(summaryData);
    setTags(tagList);
    setInstitutions(institutionList);
  }

  useEffect(() => {
    let mounted = true;

    (async () => {
      try {
        const [list, summaryData, tagList, institutionList] = await Promise.all([
          getInvestments(),
          getInvestmentSummary(),
          getInvestmentTags(),
          getInvestmentInstitutions(),
        ]);
        if (mounted) {
          setInvestments(list);
          setSummary(summaryData);
          setTags(tagList);
          setInstitutions(institutionList);
        }
      } catch (error) {
        appToast.error(
          error instanceof ApiError
            ? error.message
            : "Não foi possível carregar os investimentos.",
        );
      } finally {
        if (mounted) {
          setLoading(false);
        }
      }
    })();

    return () => {
      mounted = false;
    };
  }, []);

  const byTypeChart = useMemo(
    () =>
      (summary?.by_type ?? []).map((item) => ({
        name: TYPE_LABELS[item.type] ?? item.type,
        value: item.current,
      })),
    [summary],
  );

  const byPurposeChart = useMemo(
    () =>
      (summary?.by_purpose ?? []).map((item) => ({
        name: item.name,
        value: item.current,
        color: item.color,
      })),
    [summary],
  );

  const evolutionChart = useMemo(
    () =>
      (summary?.evolution ?? []).map((point) => ({
        date: new Date(`${point.date}T00:00:00`).toLocaleDateString("pt-BR", {
          day: "2-digit",
          month: "2-digit",
        }),
        Patrimônio: point.current,
        Aplicado: point.applied,
      })),
    [summary],
  );

  // Ranking "rendendo mais": rentabilidade desc; itens sem base (aplicado 0) ao final.
  const ranking = useMemo(
    () =>
      [...investments]
        .filter((investment) => investment.is_active)
        .sort((a, b) => {
          const aHasBase = toNumber(a.applied_amount) > 0 ? 1 : 0;
          const bHasBase = toNumber(b.applied_amount) > 0 ? 1 : 0;
          if (aHasBase !== bHasBase) {
            return bHasBase - aHasBase;
          }
          return b.profit_percent - a.profit_percent;
        }),
    [investments],
  );

  // Opções do Select de instituição: cadastradas + a atual do form (caso não exista mais).
  const institutionNames = institutions.map((institution) => institution.name);
  const institutionOptions =
    form.institution && !institutionNames.includes(form.institution)
      ? [form.institution, ...institutionNames]
      : institutionNames;

  function openCreate() {
    setEditing(null);
    setForm(emptyForm);
    setFormError(null);
    setIsSheetOpen(true);
  }

  function openEdit(investment: Investment) {
    setEditing(investment);
    setForm(investmentToForm(investment));
    setFormError(null);
    setIsSheetOpen(true);
  }

  function toggleTag(tagId: number) {
    setForm((prev) => ({
      ...prev,
      tag_ids: prev.tag_ids.includes(tagId)
        ? prev.tag_ids.filter((id) => id !== tagId)
        : [...prev.tag_ids, tagId],
    }));
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    if (!form.name.trim()) {
      setFormError("Informe o nome do investimento.");
      return;
    }

    const payload: InvestmentPayload = {
      name: form.name.trim(),
      type: form.type,
      currency: form.currency,
      institution: form.institution.trim() || null,
      applied_amount: toNumber(form.applied_amount),
      current_amount: toNumber(form.current_amount),
      notes: form.notes.trim() || null,
      is_active: form.is_active,
      tag_ids: form.tag_ids,
    };

    if (Number.isNaN(payload.applied_amount) || Number.isNaN(payload.current_amount)) {
      setFormError("Valores aplicados/atuais precisam ser números.");
      return;
    }

    setSaving(true);
    try {
      if (editing) {
        await updateInvestment(editing.id, payload);
        appToast.success("Investimento atualizado.");
      } else {
        await createInvestment(payload);
        appToast.success("Investimento criado.");
      }
      setIsSheetOpen(false);
      await reload();
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar o investimento.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(investment: Investment) {
    if (!window.confirm(`Excluir o investimento "${investment.name}"?`)) {
      return;
    }

    try {
      await deleteInvestment(investment.id);
      appToast.success("Investimento removido.");
      await reload();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível remover o investimento.",
      );
    }
  }

  function openContribute(investment: Investment) {
    setContributeTarget(investment);
    setContributeAmount("");
    setContributeDate(todayISO());
    setContributeError(null);
  }

  async function handleContribute(event: React.FormEvent) {
    event.preventDefault();
    if (!contributeTarget) {
      return;
    }

    const amount = toNumber(contributeAmount);
    if (!amount || amount <= 0) {
      setContributeError("Informe um valor maior que zero.");
      return;
    }

    setSavingContribute(true);
    try {
      await addContribution(contributeTarget.id, {
        amount,
        contributed_at: contributeDate || undefined,
      });
      appToast.success("Aporte incluído.");
      setContributeTarget(null);
      await reload();
    } catch (error) {
      const message =
        error instanceof ApiError ? error.message : "Não foi possível incluir o aporte.";
      setContributeError(message);
      appToast.error(message);
    } finally {
      setSavingContribute(false);
    }
  }

  function openBulkUpdate() {
    const initial: Record<number, string> = {};
    investments
      .filter((investment) => investment.is_active)
      .forEach((investment) => {
        initial[investment.id] = String(investment.current_amount);
      });
    setUpdateValues(initial);
    setIsUpdateOpen(true);
  }

  async function handleBulkUpdate(event: React.FormEvent) {
    event.preventDefault();

    const items = Object.entries(updateValues)
      .map(([id, value]) => ({ id: Number(id), current_amount: toNumber(value) }))
      .filter((item) => !Number.isNaN(item.current_amount) && item.current_amount >= 0);

    if (items.length === 0) {
      return;
    }

    setSavingUpdate(true);
    try {
      await bulkUpdateInvestmentValues(items);
      appToast.success("Valores atualizados.");
      setIsUpdateOpen(false);
      await reload();
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível atualizar os valores.",
      );
    } finally {
      setSavingUpdate(false);
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando investimentos..." />;
  }

  const totals = summary?.totals;
  const profitPositive = (totals?.profit ?? 0) >= 0;

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Investimentos"
        description="Cadastre suas posições (caixinhas, bolsa, fundos, Tesouro) e acompanhe a rentabilidade por propósito."
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" onClick={openBulkUpdate}>
              <RefreshCw className="size-4" />
              Atualizar valores
            </Button>
            <Button onClick={openCreate}>
              <Plus className="size-4" />
              Novo investimento
            </Button>
          </div>
        }
      />

      {summary && summary.usd_brl ? (
        <p className="text-xs text-muted-foreground">
          Cotação do dólar: US$ 1,00 = {formatCurrency(summary.usd_brl)}
          {summary.usd_brl_available ? "" : " (valor aproximado — cotação indisponível no momento)"}
          {" · totais convertidos para BRL"}
        </p>
      ) : null}

      {/* Cards de resumo */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <SummaryCard label="Patrimônio atual" value={formatCurrency(totals?.current ?? 0)} />
        <SummaryCard label="Total aplicado" value={formatCurrency(totals?.applied ?? 0)} />
        <SummaryCard
          label="Ganho/Perda"
          value={formatCurrency(totals?.profit ?? 0)}
          accentClass={profitPositive ? "text-emerald-600" : "text-red-600"}
        />
        <SummaryCard
          label="Rentabilidade"
          value={formatPercent(totals?.profit_percent ?? 0)}
          accentClass={profitPositive ? "text-emerald-600" : "text-red-600"}
          icon={profitPositive ? <TrendingUp className="size-4" /> : <TrendingDown className="size-4" />}
        />
      </div>

      {/* Gráficos */}
      <div className="grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <Card>
          <CardHeader>
            <CardTitle>Evolução do patrimônio</CardTitle>
            <CardDescription>Registrada a cada atualização dos seus investimentos.</CardDescription>
          </CardHeader>
          <CardContent className="h-72">
            {evolutionChart.length > 0 ? (
              <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={evolutionChart} margin={{ left: 4, right: 8, top: 8 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                  <XAxis dataKey="date" fontSize={12} tickLine={false} axisLine={false} />
                  <YAxis fontSize={12} tickLine={false} axisLine={false} width={70}
                    tickFormatter={(v) => formatCurrency(Number(v))} />
                  <RechartsTooltip formatter={(v) => formatCurrency(Number(v))} />
                  <Area type="monotone" dataKey="Aplicado" stroke="#94a3b8" fill="#94a3b8" fillOpacity={0.15} />
                  <Area type="monotone" dataKey="Patrimônio" stroke="#2563eb" fill="#2563eb" fillOpacity={0.2} />
                </AreaChart>
              </ResponsiveContainer>
            ) : (
              <EmptyChart />
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Distribuição por tipo</CardTitle>
            <CardDescription>Quanto há em cada categoria.</CardDescription>
          </CardHeader>
          <CardContent className="h-72">
            {byTypeChart.length > 0 ? (
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie data={byTypeChart} dataKey="value" nameKey="name" innerRadius={50} outerRadius={90} paddingAngle={2}>
                    {byTypeChart.map((_, index) => (
                      <Cell key={index} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                    ))}
                  </Pie>
                  <RechartsTooltip formatter={(v) => formatCurrency(Number(v))} />
                </PieChart>
              </ResponsiveContainer>
            ) : (
              <EmptyChart />
            )}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Por propósito</CardTitle>
          <CardDescription>
            Total atual associado a cada objetivo. Um investimento com vários propósitos é
            somado em todos eles.
          </CardDescription>
        </CardHeader>
        <CardContent className="h-64">
          {byPurposeChart.length > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={byPurposeChart} margin={{ left: 4, right: 8, top: 8 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" vertical={false} />
                <XAxis dataKey="name" fontSize={12} tickLine={false} axisLine={false} />
                <YAxis fontSize={12} tickLine={false} axisLine={false} width={70}
                  tickFormatter={(v) => formatCurrency(Number(v))} />
                <RechartsTooltip formatter={(v) => formatCurrency(Number(v))} />
                <Bar dataKey="value" radius={[6, 6, 0, 0]}>
                  {byPurposeChart.map((entry, index) => (
                    <Cell key={index} fill={entry.color || CHART_COLORS[index % CHART_COLORS.length]} />
                  ))}
                </Bar>
              </BarChart>
            </ResponsiveContainer>
          ) : (
            <EmptyChart label="Crie propósitos e vincule aos investimentos." />
          )}
        </CardContent>
      </Card>

      {/* Rendendo mais */}
      <Card>
        <CardHeader>
          <CardTitle>O que está rendendo mais</CardTitle>
          <CardDescription>
            Ordenado pela rentabilidade. O rendimento é o ganho real (atual − aplicado); os aportes
            feitos depois aparecem à parte e não contam como rendimento.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {ranking.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">Sem investimentos ativos.</p>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-8">#</TableHead>
                    <TableHead>Investimento</TableHead>
                    <TableHead className="text-right">Rendimento</TableHead>
                    <TableHead className="text-right">Rentab.</TableHead>
                    <TableHead className="text-right">Aportado depois</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {ranking.map((investment, index) => {
                    const positive = investment.profit >= 0;
                    const contributed = toNumber(investment.contributed_total ?? 0);
                    return (
                      <TableRow key={investment.id}>
                        <TableCell className="text-muted-foreground">{index + 1}</TableCell>
                        <TableCell>
                          <div className="font-medium">{investment.name}</div>
                          <div className="text-xs text-muted-foreground">
                            {TYPE_LABELS[investment.type] ?? investment.type}
                            {investment.currency === "USD" ? " · US$" : ""}
                          </div>
                        </TableCell>
                        <TableCell className={`text-right tabular-nums ${positive ? "text-emerald-600" : "text-red-600"}`}>
                          {formatCurrency(investment.profit, investment.currency)}
                        </TableCell>
                        <TableCell className={`text-right tabular-nums ${positive ? "text-emerald-600" : "text-red-600"}`}>
                          {formatPercent(investment.profit_percent)}
                        </TableCell>
                        <TableCell className="text-right tabular-nums text-muted-foreground">
                          {contributed > 0 ? formatCurrency(contributed, investment.currency) : "—"}
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Tabela */}
      <Card>
        <CardHeader>
          <CardTitle>Meus investimentos</CardTitle>
          <CardDescription>{investments.length} cadastrado(s).</CardDescription>
        </CardHeader>
        <CardContent>
          {investments.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Nenhum investimento ainda. Clique em “Novo investimento” para começar.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Nome</TableHead>
                    <TableHead>Tipo</TableHead>
                    <TableHead className="text-right">Aplicado</TableHead>
                    <TableHead className="text-right">Atual</TableHead>
                    <TableHead className="text-right">Rentab.</TableHead>
                    <TableHead>Propósitos</TableHead>
                    <TableHead className="text-right">Ações</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {investments.map((investment) => {
                    const positive = investment.profit >= 0;
                    return (
                      <TableRow key={investment.id} className={investment.is_active ? "" : "opacity-60"}>
                        <TableCell>
                          <div className="font-medium">{investment.name}</div>
                          {investment.institution ? (
                            <div className="text-xs text-muted-foreground">{investment.institution}</div>
                          ) : null}
                        </TableCell>
                        <TableCell>{TYPE_LABELS[investment.type] ?? investment.type}</TableCell>
                        <TableCell className="text-right tabular-nums">
                          {formatCurrency(toNumber(investment.applied_amount), investment.currency)}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                          {formatCurrency(toNumber(investment.current_amount), investment.currency)}
                          {investment.currency === "USD" && summary?.usd_brl ? (
                            <div className="text-xs text-muted-foreground">
                              ≈ {formatCurrency(toNumber(investment.current_amount) * summary.usd_brl)}
                            </div>
                          ) : null}
                        </TableCell>
                        <TableCell className={`text-right tabular-nums ${positive ? "text-emerald-600" : "text-red-600"}`}>
                          {formatPercent(investment.profit_percent)}
                          <div className="text-xs">{formatCurrency(investment.profit)}</div>
                        </TableCell>
                        <TableCell>
                          <div className="flex flex-wrap gap-1">
                            {investment.tags.map((tag) => (
                              <span
                                key={tag.id}
                                className="rounded-full border px-2 py-0.5 text-xs"
                                style={{ borderColor: tag.color, color: tag.color }}
                              >
                                {tag.name}
                              </span>
                            ))}
                          </div>
                        </TableCell>
                        <TableCell className="text-right">
                          <div className="flex justify-end gap-1">
                            <Button variant="ghost" size="icon" title="Incluir aporte"
                              onClick={() => openContribute(investment)}>
                              <PlusCircle className="size-4 text-emerald-600" />
                            </Button>
                            <Button variant="ghost" size="icon" title="Editar"
                              onClick={() => openEdit(investment)}>
                              <Pencil className="size-4" />
                            </Button>
                            <Button variant="ghost" size="icon" title="Excluir"
                              onClick={() => handleDelete(investment)}>
                              <Trash2 className="size-4 text-red-600" />
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    );
                  })}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Sheet de criar/editar */}
      <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{editing ? "Editar investimento" : "Novo investimento"}</SheetTitle>
            <SheetDescription>
              Informe quanto foi aplicado e quanto vale hoje; a rentabilidade é calculada automaticamente.
            </SheetDescription>
          </SheetHeader>

          <form className="px-4 pb-4" onSubmit={handleSubmit}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="name">Nome</FieldLabel>
                <Input id="name" value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  placeholder="Ex.: Caixinha Nubank" required />
              </Field>

              <Field>
                <FieldLabel htmlFor="type">Tipo</FieldLabel>
                <Select value={form.type} onValueChange={(value) => setForm({ ...form, type: value as InvestmentType })}>
                  <SelectTrigger id="type">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {TYPE_OPTIONS.map(([value, label]) => (
                      <SelectItem key={value} value={value}>{label}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>

              <Field>
                <FieldLabel htmlFor="currency">Moeda</FieldLabel>
                <Select
                  value={form.currency}
                  onValueChange={(value) => setForm({ ...form, currency: value as "BRL" | "USD" })}
                >
                  <SelectTrigger id="currency">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="BRL">Real (R$)</SelectItem>
                    <SelectItem value="USD">Dólar (US$)</SelectItem>
                  </SelectContent>
                </Select>
              </Field>

              <Field>
                <FieldLabel htmlFor="institution">Instituição</FieldLabel>
                <Select
                  value={form.institution ? form.institution : "__none__"}
                  onValueChange={(value) =>
                    setForm({ ...form, institution: value === "__none__" ? "" : value })
                  }
                >
                  <SelectTrigger id="institution">
                    <SelectValue placeholder="Selecione" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">Nenhuma</SelectItem>
                    {institutionOptions.map((institutionName) => (
                      <SelectItem key={institutionName} value={institutionName}>
                        {institutionName}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <FieldDescription>
                  Cadastre novas em “Instituições” no menu lateral.
                </FieldDescription>
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="applied">
                    Valor aplicado ({form.currency === "USD" ? "US$" : "R$"})
                  </FieldLabel>
                  <Input id="applied" type="number" step="0.01" min="0" value={form.applied_amount}
                    onChange={(e) => setForm({ ...form, applied_amount: e.target.value })} placeholder="0,00" />
                </Field>
                <Field>
                  <FieldLabel htmlFor="current">
                    Valor atual ({form.currency === "USD" ? "US$" : "R$"})
                  </FieldLabel>
                  <Input id="current" type="number" step="0.01" min="0" value={form.current_amount}
                    onChange={(e) => setForm({ ...form, current_amount: e.target.value })} placeholder="0,00" />
                </Field>
              </div>

              <Field>
                <FieldLabel>Propósitos</FieldLabel>
                {tags.length === 0 ? (
                  <p className="text-xs text-muted-foreground">
                    Nenhum propósito criado ainda. Crie em “Propósitos” no menu lateral.
                  </p>
                ) : (
                  <div className="flex flex-wrap gap-2">
                    {tags.map((tag) => {
                      const selected = form.tag_ids.includes(tag.id);
                      return (
                        <button
                          key={tag.id}
                          type="button"
                          onClick={() => toggleTag(tag.id)}
                          className="rounded-full border px-3 py-1 text-xs transition"
                          style={{
                            borderColor: tag.color,
                            backgroundColor: selected ? tag.color : "transparent",
                            color: selected ? "#fff" : tag.color,
                          }}
                        >
                          {tag.name}
                        </button>
                      );
                    })}
                  </div>
                )}
              </Field>

              <Field>
                <FieldLabel htmlFor="notes">Observações</FieldLabel>
                <Input id="notes" value={form.notes}
                  onChange={(e) => setForm({ ...form, notes: e.target.value })} placeholder="Opcional" />
              </Field>

              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.is_active}
                  onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
                Ativo (entra nos totais e gráficos)
              </label>

              <FieldError>{formError}</FieldError>
            </FieldGroup>

            <SheetFooter className="px-0">
              <Button type="submit" disabled={saving}>
                {saving ? <Spinner data-icon="inline-start" /> : null}
                {editing ? "Salvar alterações" : "Criar investimento"}
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>

      {/* Sheet de aporte */}
      <Sheet open={contributeTarget !== null} onOpenChange={(open) => { if (!open) setContributeTarget(null); }}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Incluir aporte</SheetTitle>
            <SheetDescription>
              {contributeTarget ? `${contributeTarget.name} — ` : ""}
              digite só o valor que está colocando. Entra como dinheiro novo (soma no aplicado e no
              atual) e não conta como rendimento.
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleContribute}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="contribute-amount">
                  Valor do aporte ({contributeTarget?.currency === "USD" ? "US$" : "R$"})
                </FieldLabel>
                <Input id="contribute-amount" type="number" step="0.01" min="0"
                  value={contributeAmount} onChange={(e) => setContributeAmount(e.target.value)}
                  placeholder="0,00" autoFocus />
              </Field>
              <Field>
                <FieldLabel htmlFor="contribute-date">Data</FieldLabel>
                <Input id="contribute-date" type="date"
                  value={contributeDate} onChange={(e) => setContributeDate(e.target.value)} />
              </Field>
              <FieldError>{contributeError}</FieldError>
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={savingContribute}>
                {savingContribute ? <Spinner data-icon="inline-start" /> : null}
                Incluir aporte
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>

      {/* Sheet de atualização de saldo em massa */}
      <Sheet open={isUpdateOpen} onOpenChange={setIsUpdateOpen}>
        <SheetContent className="flex w-full flex-col sm:max-w-lg">
          <SheetHeader>
            <SheetTitle>Atualizar valores</SheetTitle>
            <SheetDescription>
              Preencha o saldo atual de cada investimento. A diferença em relação ao aplicado vira o
              rendimento.
            </SheetDescription>
          </SheetHeader>
          <form className="flex min-h-0 flex-1 flex-col px-4 pb-4" onSubmit={handleBulkUpdate}>
            <div className="min-h-0 flex-1 space-y-2 overflow-y-auto">
              {investments.filter((investment) => investment.is_active).map((investment) => (
                <div key={investment.id} className="flex items-center gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-medium">{investment.name}</div>
                    <div className="text-xs text-muted-foreground">
                      {TYPE_LABELS[investment.type] ?? investment.type}
                      {" · "}
                      {investment.currency === "USD" ? "US$" : "R$"}
                    </div>
                  </div>
                  <Input
                    type="number" step="0.01" min="0"
                    className="w-36"
                    value={updateValues[investment.id] ?? ""}
                    onChange={(e) =>
                      setUpdateValues((prev) => ({ ...prev, [investment.id]: e.target.value }))
                    }
                  />
                </div>
              ))}
            </div>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={savingUpdate}>
                {savingUpdate ? <Spinner data-icon="inline-start" /> : null}
                Salvar valores
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </div>
  );
}

function EmptyChart({ label = "Sem dados ainda." }: { label?: string }) {
  return (
    <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
      {label}
    </div>
  );
}

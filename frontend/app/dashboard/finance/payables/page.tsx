"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import {
  Check,
  ChevronLeft,
  ChevronRight,
  Clock,
  CreditCard as CreditCardIcon,
  ExternalLink,
  Pencil,
  Plus,
  RotateCcw,
  Trash2,
} from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { SummaryCard } from "@/components/dashboard/summary-card";
import { currentMonth, formatDate, monthLabel, shiftMonth, toNumber, todayISO } from "@/lib/format";
import { usePrivateFormat } from "@/hooks/use-private-format";
import { appToast } from "@/lib/toast";
import {
  createPayable,
  deletePayable,
  getBankAccounts,
  getCreditCardInvoicesForecast,
  getFinanceCategories,
  getPayables,
  payPayable,
  unpayPayable,
  updatePayable,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { CreditCardInvoiceForecast } from "@/types/CreditCard";
import type { BankAccount, FinanceCategory, Payable, PayableKind } from "@/types/Finance";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
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

const KIND_LABELS: Record<PayableKind, string> = {
  avulsa: "Avulsa",
  fixa: "Fixa",
  parcelada: "Parcelada",
};

/**
 * A tabela mistura contas a pagar (registros do banco) com a previsão das faturas
 * de cartão (calculada, somente leitura). `sortDate`/`sortLabel` reproduzem no
 * cliente o orderBy('due_date')->orderBy('description') que o backend aplica.
 */
type PayablesRow =
  | { kind: "payable"; key: string; sortDate: string; sortLabel: string; payable: Payable }
  | { kind: "invoice"; key: string; sortDate: string; sortLabel: string; invoice: CreditCardInvoiceForecast };

type InvoiceVisual = {
  row: string;
  muted: boolean;
  badge: string;
  label: string;
  icon: React.ReactNode;
};

/**
 * Estados visuais da linha de fatura. `opacity-60` é reservado para "pago" (mesmo
 * sinal das contas a pagar); enquanto o ciclo não fecha a linha usa fundo/texto
 * apagados para dizer "previsão", sem parecer concluída. A fatura paga vem antes
 * de tudo: paga adiantado não deve ficar com cara de previsão.
 */
function invoiceVisual(invoice: CreditCardInvoiceForecast): InvoiceVisual {
  if (invoice.total > 0 && invoice.paid_total >= invoice.total) {
    return {
      row: "opacity-60",
      muted: false,
      badge: "border-emerald-200 bg-emerald-50 text-emerald-700",
      label: "Paga",
      icon: <Check className="size-3" />,
    };
  }

  if (!invoice.is_closed) {
    return {
      row: "bg-muted/40",
      muted: true,
      badge: "border-dashed border-slate-300 bg-transparent text-muted-foreground",
      label: "Em aberto",
      icon: <Clock className="size-3" />,
    };
  }

  if (invoice.paid_total > 0) {
    return {
      row: "",
      muted: false,
      badge: "border-sky-200 bg-sky-50 text-sky-700",
      label: "Parcial",
      icon: null,
    };
  }

  if (invoice.total === 0) {
    return {
      row: "",
      muted: true,
      badge: "border-slate-200 bg-slate-50 text-slate-600",
      label: "Sem gastos",
      icon: null,
    };
  }

  return {
    row: "",
    muted: false,
    badge: "border-amber-200 bg-amber-50 text-amber-700",
    label: "Fechada",
    icon: null,
  };
}

type FormState = {
  description: string;
  category_id: string;
  amount: string;
  due_date: string;
  kind: PayableKind;
  installments_total: string;
};

const emptyForm: FormState = {
  description: "",
  category_id: "",
  amount: "",
  due_date: todayISO(),
  kind: "avulsa",
  installments_total: "12",
};

export default function PayablesPage() {
  const { formatCurrency } = usePrivateFormat();
  const [month, setMonth] = useState(currentMonth());
  const [payables, setPayables] = useState<Payable[]>([]);
  const [invoices, setInvoices] = useState<CreditCardInvoiceForecast[]>([]);
  const [categories, setCategories] = useState<FinanceCategory[]>([]);
  const [accounts, setAccounts] = useState<BankAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  const [editing, setEditing] = useState<Payable | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [formError, setFormError] = useState<string | null>(null);

  // Baixa (escolher conta + data)
  const [payTarget, setPayTarget] = useState<Payable | null>(null);
  const [payAccountId, setPayAccountId] = useState("");
  const [payDate, setPayDate] = useState("");
  const [savingPay, setSavingPay] = useState(false);

  async function loadMonth(targetMonth: string) {
    const [list, forecast] = await Promise.all([
      getPayables(targetMonth),
      getCreditCardInvoicesForecast(targetMonth),
    ]);
    setPayables(list);
    setInvoices(forecast);
  }

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const [list, cats, acc, forecast] = await Promise.all([
          getPayables(month),
          getFinanceCategories("despesa"),
          getBankAccounts(),
          // A previsão do cartão é complementar: se o módulo falhar, a tela de
          // contas a pagar continua funcionando.
          getCreditCardInvoicesForecast(month).catch(() => [] as CreditCardInvoiceForecast[]),
        ]);
        if (mounted) {
          setPayables(list);
          setCategories(cats);
          setAccounts(acc.accounts);
          setInvoices(forecast);
        }
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar as contas.");
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, [month]);

  // As faturas entram nos totais fechadas ou não — é o que vai sair no mês.
  // "Pago" usa paid_total (e não um booleano) porque fatura aceita baixa parcial.
  const total =
    payables.reduce((sum, p) => sum + toNumber(p.amount), 0) +
    invoices.reduce((sum, i) => sum + i.total, 0);
  const paid =
    payables.filter((p) => p.is_paid).reduce((sum, p) => sum + toNumber(p.amount), 0) +
    invoices.reduce((sum, i) => sum + i.paid_total, 0);
  const pending = total - paid;

  const rows: PayablesRow[] = [
    ...payables.map((payable): PayablesRow => ({
      kind: "payable",
      key: `payable-${payable.id}`,
      sortDate: payable.due_date.slice(0, 10),
      sortLabel: payable.description,
      payable,
    })),
    ...invoices.map((invoice): PayablesRow => ({
      kind: "invoice",
      // invoice_id é null enquanto a fatura não existe no banco: a chave estável é o cartão.
      key: `invoice-${invoice.credit_card_id}`,
      sortDate: invoice.due_date.slice(0, 10),
      sortLabel: `Fatura ${invoice.card_name}`,
      invoice,
    })),
  ].sort((a, b) => {
    if (a.sortDate !== b.sortDate) return a.sortDate < b.sortDate ? -1 : 1;
    // No mesmo dia, as contas vêm antes das faturas.
    if (a.kind !== b.kind) return a.kind === "payable" ? -1 : 1;
    return a.sortLabel.localeCompare(b.sortLabel, "pt-BR");
  });

  function openCreate() {
    setEditing(null);
    setForm({ ...emptyForm, due_date: `${month}-05` });
    setFormError(null);
    setIsSheetOpen(true);
  }

  function openEdit(payable: Payable) {
    setEditing(payable);
    setForm({
      description: payable.description,
      category_id: payable.category_id ? String(payable.category_id) : "",
      amount: String(payable.amount),
      due_date: payable.due_date.slice(0, 10),
      kind: payable.kind,
      installments_total: String(payable.installments_total ?? "12"),
    });
    setFormError(null);
    setIsSheetOpen(true);
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);
    if (!form.description.trim()) {
      setFormError("Informe a descrição.");
      return;
    }
    const amount = toNumber(form.amount);
    if (!amount || amount <= 0) {
      setFormError("Informe um valor maior que zero.");
      return;
    }

    setSaving(true);
    try {
      const categoryId = form.category_id ? Number(form.category_id) : null;
      if (editing) {
        await updatePayable(editing.id, {
          description: form.description.trim(),
          category_id: categoryId,
          amount,
          due_date: form.due_date,
        });
        appToast.success("Conta atualizada.");
      } else {
        await createPayable({
          description: form.description.trim(),
          category_id: categoryId,
          amount,
          due_date: form.due_date,
          kind: form.kind,
          installments_total: form.kind === "parcelada" ? Number(form.installments_total) : undefined,
        });
        appToast.success("Conta criada.");
      }
      setIsSheetOpen(false);
      await loadMonth(month);
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível salvar a conta.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  function openPay(payable: Payable) {
    setPayTarget(payable);
    setPayAccountId(accounts[0] ? String(accounts[0].id) : "");
    setPayDate(todayISO());
  }

  async function handlePay(event: React.FormEvent) {
    event.preventDefault();
    if (!payTarget || !payAccountId) return;
    setSavingPay(true);
    try {
      await payPayable(payTarget.id, {
        bank_account_id: Number(payAccountId),
        paid_at: payDate || undefined,
      });
      appToast.success("Baixa registrada e saldo atualizado.");
      setPayTarget(null);
      await loadMonth(month);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível dar baixa.");
    } finally {
      setSavingPay(false);
    }
  }

  async function handleUnpay(payable: Payable) {
    if (!window.confirm(`Estornar a baixa de "${payable.description}"? O valor volta ao saldo da conta.`)) {
      return;
    }
    try {
      await unpayPayable(payable.id);
      appToast.success("Baixa estornada.");
      await loadMonth(month);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível estornar.");
    }
  }

  async function handleDelete(payable: Payable) {
    let scope: "one" | "group" = "one";
    if (payable.group_id) {
      scope = window.confirm(
        `"${payable.description}" faz parte de uma série (${KIND_LABELS[payable.kind].toLowerCase()}).\n\nOK = excluir TODA a série. Cancelar = excluir só esta.`,
      )
        ? "group"
        : "one";
    } else if (!window.confirm(`Excluir "${payable.description}"?`)) {
      return;
    }
    try {
      await deletePayable(payable.id, scope);
      appToast.success("Removido.");
      await loadMonth(month);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover.");
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando contas a pagar..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Contas a pagar"
        description="Contas avulsas, fixas (mensais) e parceladas, organizadas por categoria."
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            Nova conta
          </Button>
        }
      />

      {/* Navegação de mês */}
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <Button variant="outline" size="icon" onClick={() => setMonth(shiftMonth(month, -1))}>
            <ChevronLeft className="size-4" />
          </Button>
          <span className="min-w-44 text-center text-sm font-medium">{monthLabel(month)}</span>
          <Button variant="outline" size="icon" onClick={() => setMonth(shiftMonth(month, 1))}>
            <ChevronRight className="size-4" />
          </Button>
        </div>
      </div>

      {/* Resumo do mês */}
      <div className="grid gap-4 sm:grid-cols-3">
        <SummaryCard label="Total do mês" value={formatCurrency(total)} />
        <SummaryCard label="Pago" value={formatCurrency(paid)} accentClass="text-emerald-600" />
        <SummaryCard label="Pendente" value={formatCurrency(pending)} accentClass="text-red-600" />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Lançamentos de {monthLabel(month)}</CardTitle>
          <CardDescription>
            {payables.length} conta(s)
            {invoices.length > 0 ? ` e ${invoices.length} fatura(s) de cartão` : ""} no mês.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {rows.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Nenhuma conta neste mês. Clique em “Nova conta”.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-32">Status</TableHead>
                    <TableHead>Descrição</TableHead>
                    <TableHead>Categoria</TableHead>
                    <TableHead>Vencimento</TableHead>
                    <TableHead className="text-right">Valor</TableHead>
                    <TableHead className="text-right">Ações</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {rows.map((row) => {
                    if (row.kind === "invoice") {
                      const invoice = row.invoice;
                      const visual = invoiceVisual(invoice);

                      return (
                        <TableRow key={row.key} className={visual.row}>
                          <TableCell>
                            <span
                              className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs ${visual.badge}`}
                            >
                              {visual.icon}
                              {visual.label}
                            </span>
                          </TableCell>
                          <TableCell>
                            <div className={`font-medium ${visual.muted ? "text-muted-foreground" : ""}`}>
                              Fatura {invoice.card_name}
                              {invoice.last_four ? (
                                <span className="text-muted-foreground"> ····{invoice.last_four}</span>
                              ) : null}
                            </div>
                            <div className="text-xs text-muted-foreground">
                              {invoice.is_closed
                                ? `Fechou em ${formatDate(invoice.closing_date)}`
                                : `Previsão · fecha em ${formatDate(invoice.closing_date)}`}
                              {!invoice.card_is_active ? " · cartão inativo" : ""}
                            </div>
                          </TableCell>
                          <TableCell>
                            <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                              <CreditCardIcon className="size-3.5" />
                              Cartão de crédito
                            </span>
                          </TableCell>
                          <TableCell className={`tabular-nums ${visual.muted ? "text-muted-foreground" : ""}`}>
                            {formatDate(invoice.due_date)}
                          </TableCell>
                          <TableCell
                            className={`text-right tabular-nums ${visual.muted ? "text-muted-foreground" : ""}`}
                          >
                            {formatCurrency(invoice.total)}
                          </TableCell>
                          <TableCell className="text-right">
                            <div className="flex justify-end gap-1">
                              {/* O pagamento da fatura (inclusive parcial) fica na tela do cartão. */}
                              <Button variant="ghost" size="icon" title="Abrir fatura do cartão" asChild>
                                <Link href={`/dashboard/finance/credit-cards/${invoice.credit_card_id}`}>
                                  <ExternalLink className="size-4" />
                                </Link>
                              </Button>
                            </div>
                          </TableCell>
                        </TableRow>
                      );
                    }

                    const payable = row.payable;

                    return (
                      <TableRow key={row.key} className={payable.is_paid ? "opacity-60" : ""}>
                        <TableCell>
                          {payable.is_paid ? (
                            <div className="space-y-0.5">
                              <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                                <Check className="size-3" /> Pago
                              </span>
                              {payable.bank_account ? (
                                <div className="text-xs text-muted-foreground">via {payable.bank_account.name}</div>
                              ) : null}
                            </div>
                          ) : (
                            <Button variant="outline" size="sm" onClick={() => openPay(payable)}>
                              Dar baixa
                            </Button>
                          )}
                        </TableCell>
                        <TableCell>
                          <div className="font-medium">{payable.description}</div>
                          <div className="text-xs text-muted-foreground">
                            {payable.kind === "parcelada" && payable.installment_number
                              ? `Parcela ${payable.installment_number}/${payable.installments_total}`
                              : KIND_LABELS[payable.kind]}
                          </div>
                        </TableCell>
                        <TableCell>
                          {payable.category ? (
                            <span className="inline-flex items-center gap-1.5 text-sm">
                              <span className="size-2.5 rounded-full" style={{ backgroundColor: payable.category.color }} />
                              {payable.category.name}
                            </span>
                          ) : (
                            <span className="text-xs text-muted-foreground">—</span>
                          )}
                        </TableCell>
                        <TableCell className="tabular-nums">{formatDate(payable.due_date)}</TableCell>
                        <TableCell className="text-right tabular-nums">{formatCurrency(toNumber(payable.amount))}</TableCell>
                        <TableCell className="text-right">
                          <div className="flex justify-end gap-1">
                            {payable.is_paid ? (
                              <Button variant="ghost" size="icon" title="Estornar baixa" onClick={() => handleUnpay(payable)}>
                                <RotateCcw className="size-4 text-amber-600" />
                              </Button>
                            ) : null}
                            <Button variant="ghost" size="icon" title="Editar" onClick={() => openEdit(payable)}>
                              <Pencil className="size-4" />
                            </Button>
                            <Button variant="ghost" size="icon" title="Excluir" onClick={() => handleDelete(payable)}>
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

      <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{editing ? "Editar conta" : "Nova conta a pagar"}</SheetTitle>
            <SheetDescription>
              {editing
                ? "Edita apenas este lançamento."
                : "Fixa gera os próximos 12 meses; parcelada gera as parcelas com vencimento mensal."}
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleSubmit}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="description">Descrição</FieldLabel>
                <Input id="description" value={form.description}
                  onChange={(e) => setForm({ ...form, description: e.target.value })}
                  placeholder="Ex.: Aluguel, Internet, Notebook" required />
              </Field>

              <Field>
                <FieldLabel htmlFor="category">Categoria</FieldLabel>
                <Select value={form.category_id || "__none__"}
                  onValueChange={(value) => setForm({ ...form, category_id: value === "__none__" ? "" : value })}>
                  <SelectTrigger id="category">
                    <SelectValue placeholder="Selecione" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">Sem categoria</SelectItem>
                    {categories.map((category) => (
                      <SelectItem key={category.id} value={String(category.id)}>{category.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>

              {!editing ? (
                <Field>
                  <FieldLabel htmlFor="kind">Tipo</FieldLabel>
                  <Select value={form.kind} onValueChange={(value) => setForm({ ...form, kind: value as PayableKind })}>
                    <SelectTrigger id="kind">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="avulsa">Avulsa (uma vez)</SelectItem>
                      <SelectItem value="fixa">Fixa (todo mês)</SelectItem>
                      <SelectItem value="parcelada">Parcelada</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>
              ) : null}

              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="amount">
                    {form.kind === "parcelada" ? "Valor da parcela (R$)" : "Valor (R$)"}
                  </FieldLabel>
                  <Input id="amount" type="number" step="0.01" min="0" value={form.amount}
                    onChange={(e) => setForm({ ...form, amount: e.target.value })} placeholder="0,00" />
                </Field>
                <Field>
                  <FieldLabel htmlFor="due">{editing ? "Vencimento" : "1º vencimento"}</FieldLabel>
                  <Input id="due" type="date" value={form.due_date}
                    onChange={(e) => setForm({ ...form, due_date: e.target.value })} />
                </Field>
              </div>

              {!editing && form.kind === "parcelada" ? (
                <Field>
                  <FieldLabel htmlFor="installments">Número de parcelas</FieldLabel>
                  <Input id="installments" type="number" min="2" max="360" value={form.installments_total}
                    onChange={(e) => setForm({ ...form, installments_total: e.target.value })} />
                </Field>
              ) : null}

              <FieldError>{formError}</FieldError>
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={saving}>
                {saving ? <Spinner data-icon="inline-start" /> : null}
                {editing ? "Salvar alterações" : "Criar"}
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>

      {/* Sheet de baixa */}
      <Sheet open={payTarget !== null} onOpenChange={(open) => { if (!open) setPayTarget(null); }}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Dar baixa</SheetTitle>
            <SheetDescription>
              {payTarget ? `${payTarget.description} — ${formatCurrency(toNumber(payTarget.amount))}. ` : ""}
              Escolha a conta de onde sai o pagamento; o saldo dela será debitado.
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handlePay}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="pay-account">Pagar com</FieldLabel>
                <Select value={payAccountId} onValueChange={setPayAccountId}>
                  <SelectTrigger id="pay-account">
                    <SelectValue placeholder="Selecione a conta" />
                  </SelectTrigger>
                  <SelectContent>
                    {accounts.map((account) => (
                      <SelectItem key={account.id} value={String(account.id)}>{account.name}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              <Field>
                <FieldLabel htmlFor="pay-date">Data do pagamento</FieldLabel>
                <Input id="pay-date" type="date" value={payDate} onChange={(e) => setPayDate(e.target.value)} />
              </Field>
              {accounts.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                  Cadastre uma conta em “Contas (saldos)” primeiro.
                </p>
              ) : null}
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={savingPay || !payAccountId}>
                {savingPay ? <Spinner data-icon="inline-start" /> : null}
                Confirmar baixa
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </div>
  );
}

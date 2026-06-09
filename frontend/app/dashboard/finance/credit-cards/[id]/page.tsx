"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, ChevronLeft, ChevronRight, Pencil, Plus, RotateCcw, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { SummaryCard } from "@/components/dashboard/summary-card";
import {
  currentMonth,
  formatCurrency,
  formatDate,
  formatFullDate,
  monthLabel,
  shiftMonth,
  toNumber,
  todayISO,
} from "@/lib/format";
import { appToast } from "@/lib/toast";
import {
  createCreditCardTransaction,
  deleteCreditCardInvoicePayment,
  deleteCreditCardTransaction,
  getBankAccounts,
  getCreditCardInvoice,
  getCreditCards,
  getFinanceCategories,
  payCreditCardInvoice,
  resolveCreditCardInvoice,
  updateCreditCardTransaction,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { BankAccount, FinanceCategory } from "@/types/Finance";
import type {
  CreditCard,
  CreditCardInvoice,
  CreditCardInvoiceStatus,
  CreditCardTransaction,
} from "@/types/CreditCard";
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

const STATUS_LABELS: Record<CreditCardInvoiceStatus, string> = {
  aberta: "Aberta",
  parcial: "Parcial",
  paga: "Paga",
};

const STATUS_CLASSES: Record<CreditCardInvoiceStatus, string> = {
  aberta: "border-amber-200 bg-amber-50 text-amber-700",
  parcial: "border-sky-200 bg-sky-50 text-sky-700",
  paga: "border-emerald-200 bg-emerald-50 text-emerald-700",
};

type AmountMode = "total" | "parcela";

type TxFormState = {
  credit_card_id: string;
  category_id: string;
  description: string;
  amount: string;
  purchase_date: string;
  installments_total: string;
  amount_mode: AmountMode;
  reference_month: string;
};

function emptyTxForm(): TxFormState {
  return {
    credit_card_id: "",
    category_id: "",
    description: "",
    amount: "",
    purchase_date: todayISO(),
    installments_total: "1",
    amount_mode: "total",
    reference_month: currentMonth(),
  };
}

const INSTALLMENT_SHORTCUTS = [2, 3, 6, 10, 12, 18, 24];

/**
 * Divide o valor digitado entre as parcelas, espelhando o backend: quando o valor
 * é o total, o centavo que sobra da divisão vai na 1ª parcela.
 */
function splitInstallments(amount: number, count: number, amountIsTotal: boolean): number[] {
  const n = Math.max(1, count);
  if (n < 2 || !amountIsTotal) {
    return Array.from({ length: n }, () => Math.round(amount * 100) / 100);
  }
  const totalCents = Math.round(amount * 100);
  const base = Math.floor(totalCents / n);
  const remainder = totalCents - base * n;
  return Array.from({ length: n }, (_, i) => (base + (i === 0 ? remainder : 0)) / 100);
}

export default function CreditCardInvoicePage() {
  const params = useParams<{ id: string }>();
  const cardId = Number(params.id);

  const [card, setCard] = useState<CreditCard | null>(null);
  const [cards, setCards] = useState<CreditCard[]>([]);
  const [categories, setCategories] = useState<FinanceCategory[]>([]);
  const [accounts, setAccounts] = useState<BankAccount[]>([]);
  const [month, setMonth] = useState(currentMonth());
  const [invoice, setInvoice] = useState<CreditCardInvoice | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadingInvoice, setLoadingInvoice] = useState(false);

  // Lançar / editar gasto
  const [isTxOpen, setIsTxOpen] = useState(false);
  const [editingTx, setEditingTx] = useState<CreditCardTransaction | null>(null);
  const [txForm, setTxForm] = useState<TxFormState>(emptyTxForm());
  const [txError, setTxError] = useState<string | null>(null);
  const [savingTx, setSavingTx] = useState(false);

  // Pagamento
  const [isPayOpen, setIsPayOpen] = useState(false);
  const [payAccountId, setPayAccountId] = useState("");
  const [payAmount, setPayAmount] = useState("");
  const [payDate, setPayDate] = useState("");
  const [savingPay, setSavingPay] = useState(false);

  async function loadInvoice(targetMonth: string) {
    setInvoice(await getCreditCardInvoice(cardId, targetMonth));
  }

  // Carrega cartão, categorias e contas uma vez.
  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const [cardsResp, cats, acc] = await Promise.all([
          getCreditCards(),
          getFinanceCategories("despesa"),
          getBankAccounts(),
        ]);
        if (!mounted) return;
        setCards(cardsResp.credit_cards);
        setCard(cardsResp.credit_cards.find((c) => c.id === cardId) ?? null);
        setCategories(cats);
        setAccounts(acc.accounts);
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar o cartão.");
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, [cardId]);

  // Carrega a fatura do mês selecionado.
  useEffect(() => {
    let mounted = true;
    setLoadingInvoice(true);
    (async () => {
      try {
        const data = await getCreditCardInvoice(cardId, month);
        if (mounted) setInvoice(data);
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar a fatura.");
      } finally {
        if (mounted) setLoadingInvoice(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, [cardId, month]);

  const referenceOptions = (() => {
    const opts: string[] = [];
    for (let i = -2; i <= 18; i++) opts.push(shiftMonth(currentMonth(), i));
    if (txForm.reference_month && !opts.includes(txForm.reference_month)) opts.push(txForm.reference_month);
    return Array.from(new Set(opts)).sort();
  })();

  // Plano de parcelas para o formulário de lançamento (preview ao vivo).
  const installmentsCount = Math.max(1, Number(txForm.installments_total) || 1);
  const isParcelado = installmentsCount >= 2;
  const amountValue = toNumber(txForm.amount);
  const installmentPlan = splitInstallments(amountValue, installmentsCount, txForm.amount_mode === "total");
  const perInstallment = installmentPlan[0] ?? 0;
  const planTotal = installmentPlan.reduce((sum, value) => sum + value, 0);
  const isCustomInstallments = isParcelado && !INSTALLMENT_SHORTCUTS.includes(installmentsCount);
  const installmentsInputRef = useRef<HTMLInputElement>(null);

  function setInstallments(value: string) {
    setTxForm((prev) => ({ ...prev, installments_total: value }));
  }

  function openCreateTx() {
    setEditingTx(null);
    setTxForm({ ...emptyTxForm(), credit_card_id: String(cardId), reference_month: month });
    setTxError(null);
    setIsTxOpen(true);
    // Sugere a fatura pela data de hoje.
    void suggestInvoice(todayISO());
  }

  function openEditTx(tx: CreditCardTransaction) {
    setEditingTx(tx);
    setTxForm({
      credit_card_id: String(tx.credit_card_id),
      category_id: tx.category_id ? String(tx.category_id) : "",
      description: tx.description,
      amount: String(tx.amount),
      purchase_date: tx.purchase_date.slice(0, 10),
      installments_total: String(tx.installments_total ?? "1"),
      amount_mode: "parcela",
      reference_month: month,
    });
    setTxError(null);
    setIsTxOpen(true);
  }

  async function suggestInvoice(date: string) {
    if (!date) return;
    try {
      const window = await resolveCreditCardInvoice(cardId, date);
      setTxForm((prev) => ({ ...prev, reference_month: window.reference_month }));
    } catch {
      // mantém a seleção atual em caso de falha
    }
  }

  function onPurchaseDateChange(date: string) {
    setTxForm((prev) => ({ ...prev, purchase_date: date }));
    if (!editingTx) void suggestInvoice(date);
  }

  async function handleSubmitTx(event: React.FormEvent) {
    event.preventDefault();
    setTxError(null);

    if (!txForm.description.trim()) {
      setTxError("Informe a descrição.");
      return;
    }
    const amount = toNumber(txForm.amount);
    if (!amount || amount <= 0) {
      setTxError("Informe um valor maior que zero.");
      return;
    }
    const installments = Number(txForm.installments_total) || 1;

    setSavingTx(true);
    try {
      const categoryId = txForm.category_id ? Number(txForm.category_id) : null;
      if (editingTx) {
        const targetCardId = Number(txForm.credit_card_id) || cardId;
        const cardChanged = targetCardId !== editingTx.credit_card_id;
        await updateCreditCardTransaction(editingTx.id, {
          credit_card_id: cardChanged ? targetCardId : undefined,
          category_id: categoryId,
          description: txForm.description.trim(),
          amount,
          reference_month: txForm.reference_month || undefined,
        });
        if (cardChanged) {
          const dest = cards.find((c) => c.id === targetCardId);
          appToast.success(
            editingTx.group_id
              ? `Parcelamento movido para ${dest?.name ?? "outro cartão"}.`
              : `Lançamento movido para ${dest?.name ?? "outro cartão"}.`,
          );
        } else {
          appToast.success("Lançamento atualizado.");
        }
      } else {
        const parcelado = installments >= 2;
        await createCreditCardTransaction({
          credit_card_id: cardId,
          category_id: categoryId,
          description: txForm.description.trim(),
          amount,
          purchase_date: txForm.purchase_date,
          installments_total: parcelado ? installments : undefined,
          amount_is_total: parcelado && txForm.amount_mode === "total",
          reference_month: txForm.reference_month || undefined,
        });
        appToast.success(parcelado ? `Compra parcelada em ${installments}x lançada.` : "Gasto lançado.");
      }
      setIsTxOpen(false);
      // Vai para a fatura onde o lançamento caiu.
      const target = txForm.reference_month || month;
      if (target !== month) {
        setMonth(target);
      } else {
        await loadInvoice(month);
      }
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível salvar o lançamento.";
      setTxError(message);
      appToast.error(message);
    } finally {
      setSavingTx(false);
    }
  }

  async function handleDeleteTx(tx: CreditCardTransaction) {
    let scope: "one" | "group" = "one";
    if (tx.group_id) {
      scope = window.confirm(
        `"${tx.description}" é parcelado (${tx.installments_total}x).\n\nOK = excluir TODAS as parcelas. Cancelar = excluir só esta.`,
      )
        ? "group"
        : "one";
    } else if (!window.confirm(`Excluir "${tx.description}"?`)) {
      return;
    }
    try {
      await deleteCreditCardTransaction(tx.id, scope);
      appToast.success("Removido.");
      await loadInvoice(month);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover.");
    }
  }

  function openPay() {
    if (!invoice) return;
    setPayAccountId(accounts[0] ? String(accounts[0].id) : "");
    setPayAmount(invoice.remaining > 0 ? String(invoice.remaining.toFixed(2)) : "");
    setPayDate(todayISO());
    setIsPayOpen(true);
  }

  async function handlePay(event: React.FormEvent) {
    event.preventDefault();
    if (!invoice?.id || !payAccountId) return;
    const amount = toNumber(payAmount);
    if (!amount || amount <= 0) {
      appToast.error("Informe um valor maior que zero.");
      return;
    }
    setSavingPay(true);
    try {
      const updated = await payCreditCardInvoice(invoice.id, {
        bank_account_id: Number(payAccountId),
        amount,
        paid_at: payDate || undefined,
      });
      setInvoice(updated);
      appToast.success("Pagamento registrado e saldo atualizado.");
      setIsPayOpen(false);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível registrar o pagamento.");
    } finally {
      setSavingPay(false);
    }
  }

  async function handleUnpay(paymentId: number) {
    if (!invoice?.id) return;
    if (!window.confirm("Estornar este pagamento? O valor volta ao saldo da conta.")) return;
    try {
      const updated = await deleteCreditCardInvoicePayment(invoice.id, paymentId);
      setInvoice(updated);
      appToast.success("Pagamento estornado.");
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível estornar.");
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando cartão..." />;
  }

  if (!card) {
    return (
      <div className="space-y-4">
        <Link href="/dashboard/finance/credit-cards" className="inline-flex items-center gap-1 text-sm text-muted-foreground">
          <ArrowLeft className="size-4" /> Voltar
        </Link>
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Cartão não encontrado.
          </CardContent>
        </Card>
      </div>
    );
  }

  const status = invoice?.status ?? "aberta";

  return (
    <div className="space-y-6">
      <Link href="/dashboard/finance/credit-cards" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" /> Cartões
      </Link>

      <DashboardPageHeader
        title={card.name}
        description={[card.brand, card.last_four ? `•••• ${card.last_four}` : null, `fecha dia ${card.closing_day}, vence dia ${card.due_day}`]
          .filter(Boolean)
          .join(" · ")}
        actions={
          <Button onClick={openCreateTx}>
            <Plus className="size-4" />
            Lançar gasto
          </Button>
        }
      />

      {/* Navegação de mês */}
      <div className="flex items-center gap-2">
        <Button variant="outline" size="icon" onClick={() => setMonth(shiftMonth(month, -1))}>
          <ChevronLeft className="size-4" />
        </Button>
        <span className="min-w-44 text-center text-sm font-medium">Fatura de {monthLabel(month)}</span>
        <Button variant="outline" size="icon" onClick={() => setMonth(shiftMonth(month, 1))}>
          <ChevronRight className="size-4" />
        </Button>
      </div>

      {/* Resumo da fatura */}
      <div className="grid gap-4 sm:grid-cols-4">
        <SummaryCard label="Total da fatura" value={formatCurrency(invoice?.total ?? 0)} />
        <SummaryCard label="Pago" value={formatCurrency(invoice?.paid_total ?? 0)} accentClass="text-emerald-600" />
        <SummaryCard label="Restante" value={formatCurrency(invoice?.remaining ?? 0)} accentClass="text-red-600" />
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Vencimento</CardDescription>
          </CardHeader>
          <CardContent className="space-y-2">
            <p className="text-sm font-medium tabular-nums">
              {invoice ? formatFullDate(invoice.due_date) : "—"}
            </p>
            <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs ${STATUS_CLASSES[status]}`}>
              {STATUS_LABELS[status]}
            </span>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="flex-row items-center justify-between">
          <div>
            <CardTitle>Lançamentos</CardTitle>
            <CardDescription>{invoice?.transactions.length ?? 0} gasto(s) nesta fatura.</CardDescription>
          </div>
          {invoice?.id && invoice.remaining > 0 ? (
            <Button variant="outline" onClick={openPay}>
              Pagar fatura
            </Button>
          ) : null}
        </CardHeader>
        <CardContent>
          {loadingInvoice ? (
            <div className="flex justify-center py-8">
              <Spinner />
            </div>
          ) : !invoice || invoice.transactions.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Nenhum gasto nesta fatura. Clique em “Lançar gasto”.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Descrição</TableHead>
                    <TableHead>Categoria</TableHead>
                    <TableHead>Compra</TableHead>
                    <TableHead className="text-right">Valor</TableHead>
                    <TableHead className="text-right">Ações</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {invoice.transactions.map((tx) => (
                    <TableRow key={tx.id}>
                      <TableCell>
                        <div className="font-medium">{tx.description}</div>
                        {tx.installment_number ? (
                          <div className="text-xs text-muted-foreground">
                            Parcela {tx.installment_number}/{tx.installments_total}
                          </div>
                        ) : null}
                      </TableCell>
                      <TableCell>
                        {tx.category ? (
                          <span className="inline-flex items-center gap-1.5 text-sm">
                            <span className="size-2.5 rounded-full" style={{ backgroundColor: tx.category.color }} />
                            {tx.category.name}
                          </span>
                        ) : (
                          <span className="text-xs text-muted-foreground">—</span>
                        )}
                      </TableCell>
                      <TableCell className="tabular-nums">{formatDate(tx.purchase_date)}</TableCell>
                      <TableCell className="text-right tabular-nums">{formatCurrency(toNumber(tx.amount))}</TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-1">
                          <Button variant="ghost" size="icon" title="Editar" onClick={() => openEditTx(tx)}>
                            <Pencil className="size-4" />
                          </Button>
                          <Button variant="ghost" size="icon" title="Excluir" onClick={() => handleDeleteTx(tx)}>
                            <Trash2 className="size-4 text-red-600" />
                          </Button>
                        </div>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Pagamentos da fatura */}
      {invoice && invoice.payments.length > 0 ? (
        <Card>
          <CardHeader>
            <CardTitle>Pagamentos</CardTitle>
            <CardDescription>Pagamentos já feitos nesta fatura.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-2">
            {invoice.payments.map((payment) => (
              <div key={payment.id} className="flex items-center justify-between gap-3 text-sm">
                <div className="text-muted-foreground">
                  {formatFullDate(payment.paid_at)}
                  {payment.bank_account ? ` · via ${payment.bank_account.name}` : ""}
                </div>
                <div className="flex items-center gap-2">
                  <span className="font-medium tabular-nums">{formatCurrency(toNumber(payment.amount))}</span>
                  <Button variant="ghost" size="icon" title="Estornar" onClick={() => handleUnpay(payment.id)}>
                    <RotateCcw className="size-4 text-amber-600" />
                  </Button>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>
      ) : null}

      {/* Sheet de lançamento / edição */}
      <Sheet open={isTxOpen} onOpenChange={setIsTxOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{editingTx ? "Editar lançamento" : "Lançar gasto"}</SheetTitle>
            <SheetDescription>
              {editingTx
                ? "Edite o gasto e, se quiser, mova-o para outro cartão ou fatura."
                : "A fatura é sugerida pela data da compra, mas você pode trocá-la."}
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleSubmitTx}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="tx-description">Descrição</FieldLabel>
                <Input id="tx-description" value={txForm.description}
                  onChange={(e) => setTxForm({ ...txForm, description: e.target.value })}
                  placeholder="Ex.: Mercado, Restaurante" required />
              </Field>

              <Field>
                <FieldLabel htmlFor="tx-category">Categoria</FieldLabel>
                <Select value={txForm.category_id || "__none__"}
                  onValueChange={(value) => setTxForm({ ...txForm, category_id: value === "__none__" ? "" : value })}>
                  <SelectTrigger id="tx-category">
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

              {editingTx ? (
                <>
                  <Field>
                    <FieldLabel htmlFor="tx-card">Cartão</FieldLabel>
                    <Select value={txForm.credit_card_id}
                      onValueChange={(value) => setTxForm({ ...txForm, credit_card_id: value })}>
                      <SelectTrigger id="tx-card">
                        <SelectValue placeholder="Selecione o cartão" />
                      </SelectTrigger>
                      <SelectContent>
                        {cards.map((c) => (
                          <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {editingTx.group_id && Number(txForm.credit_card_id) !== editingTx.credit_card_id ? (
                      <p className="text-xs text-muted-foreground">
                        Todas as {editingTx.installments_total} parcelas serão movidas para o novo cartão.
                      </p>
                    ) : null}
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="tx-amount">Valor (R$)</FieldLabel>
                    <Input id="tx-amount" type="number" step="0.01" min="0" value={txForm.amount}
                      onChange={(e) => setTxForm({ ...txForm, amount: e.target.value })} placeholder="0,00" />
                  </Field>
                  <Field>
                    <FieldLabel htmlFor="tx-invoice">Fatura</FieldLabel>
                    <Select value={txForm.reference_month}
                      onValueChange={(value) => setTxForm({ ...txForm, reference_month: value })}>
                      <SelectTrigger id="tx-invoice">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {referenceOptions.map((ref) => (
                          <SelectItem key={ref} value={ref}>{monthLabel(ref)}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Field>
                </>
              ) : (
                <>
                  <Field>
                    <FieldLabel htmlFor="tx-date">Data da compra</FieldLabel>
                    <Input id="tx-date" type="date" value={txForm.purchase_date}
                      onChange={(e) => onPurchaseDateChange(e.target.value)} />
                  </Field>

                  {/* À vista x Parcelado */}
                  <Field>
                    <FieldLabel>Forma de pagamento</FieldLabel>
                    <div className="grid grid-cols-2 gap-2">
                      <Button type="button" variant={isParcelado ? "outline" : "default"}
                        onClick={() => setInstallments("1")}>
                        À vista
                      </Button>
                      <Button type="button" variant={isParcelado ? "default" : "outline"}
                        onClick={() => setInstallments(installmentsCount >= 2 ? String(installmentsCount) : "2")}>
                        Parcelado
                      </Button>
                    </div>
                  </Field>

                  {isParcelado ? (
                    <Field>
                      <FieldLabel htmlFor="tx-installments">Número de parcelas</FieldLabel>
                      <Input id="tx-installments" ref={installmentsInputRef} type="number" min="2" max="360"
                        value={txForm.installments_total} onChange={(e) => setInstallments(e.target.value)} />
                      <div className="flex flex-wrap gap-1.5 pt-1">
                        {INSTALLMENT_SHORTCUTS.map((n) => (
                          <button key={n} type="button" onClick={() => setInstallments(String(n))}
                            className={`rounded-full border px-2.5 py-0.5 text-xs transition ${
                              installmentsCount === n
                                ? "border-primary bg-primary text-primary-foreground"
                                : "border-input text-muted-foreground hover:bg-accent"
                            }`}>
                            {n}x
                          </button>
                        ))}
                        <button type="button"
                          onClick={() => {
                            const input = installmentsInputRef.current;
                            if (input) {
                              input.focus();
                              input.select();
                            }
                          }}
                          className={`rounded-full border px-2.5 py-0.5 text-xs transition ${
                            isCustomInstallments
                              ? "border-primary bg-primary text-primary-foreground"
                              : "border-input text-muted-foreground hover:bg-accent"
                          }`}>
                          {isCustomInstallments ? `${installmentsCount}x` : "Outro…"}
                        </button>
                      </div>
                    </Field>
                  ) : null}

                  {isParcelado ? (
                    <Field>
                      <FieldLabel>Como informar o valor?</FieldLabel>
                      <div className="grid grid-cols-2 gap-2">
                        <Button type="button" variant={txForm.amount_mode === "total" ? "default" : "outline"}
                          onClick={() => setTxForm((p) => ({ ...p, amount_mode: "total" }))}>
                          Valor total
                        </Button>
                        <Button type="button" variant={txForm.amount_mode === "parcela" ? "default" : "outline"}
                          onClick={() => setTxForm((p) => ({ ...p, amount_mode: "parcela" }))}>
                          Por parcela
                        </Button>
                      </div>
                    </Field>
                  ) : null}

                  <Field>
                    <FieldLabel htmlFor="tx-amount">
                      {isParcelado
                        ? txForm.amount_mode === "total"
                          ? "Valor total da compra (R$)"
                          : "Valor de cada parcela (R$)"
                        : "Valor (R$)"}
                    </FieldLabel>
                    <Input id="tx-amount" type="number" step="0.01" min="0" value={txForm.amount}
                      onChange={(e) => setTxForm({ ...txForm, amount: e.target.value })} placeholder="0,00" />
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="tx-invoice">{isParcelado ? "Fatura da 1ª parcela" : "Fatura"}</FieldLabel>
                    <Select value={txForm.reference_month}
                      onValueChange={(value) => setTxForm({ ...txForm, reference_month: value })}>
                      <SelectTrigger id="tx-invoice">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {referenceOptions.map((ref) => (
                          <SelectItem key={ref} value={ref}>{monthLabel(ref)}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Field>

                  {/* Preview do plano de parcelas */}
                  {isParcelado && amountValue > 0 ? (
                    <div className="rounded-lg border bg-muted/40 p-3">
                      <div className="flex items-baseline justify-between">
                        <span className="text-sm font-medium">
                          {installmentsCount}x de {formatCurrency(perInstallment)}
                        </span>
                        <span className="text-xs text-muted-foreground">Total {formatCurrency(planTotal)}</span>
                      </div>
                      <p className="mt-1 text-xs text-muted-foreground">
                        1ª em {monthLabel(txForm.reference_month)} · última em{" "}
                        {monthLabel(shiftMonth(txForm.reference_month, installmentsCount - 1))}
                      </p>
                      <div className="mt-2 max-h-36 space-y-1 overflow-y-auto">
                        {installmentPlan.map((value, i) => (
                          <div key={i} className="flex justify-between text-xs">
                            <span className="text-muted-foreground">
                              {i + 1}/{installmentsCount} · {monthLabel(shiftMonth(txForm.reference_month, i))}
                            </span>
                            <span className="tabular-nums">{formatCurrency(value)}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  ) : null}
                </>
              )}

              <FieldError>{txError}</FieldError>
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={savingTx}>
                {savingTx ? <Spinner data-icon="inline-start" /> : null}
                {editingTx ? "Salvar alterações" : "Lançar"}
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>

      {/* Sheet de pagamento */}
      <Sheet open={isPayOpen} onOpenChange={setIsPayOpen}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Pagar fatura</SheetTitle>
            <SheetDescription>
              {invoice ? `Restante: ${formatCurrency(invoice.remaining)}. ` : ""}
              Você pode pagar tudo ou um valor parcial. O saldo da conta escolhida será debitado.
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
              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="pay-amount">Valor (R$)</FieldLabel>
                  <Input id="pay-amount" type="number" step="0.01" min="0" value={payAmount}
                    onChange={(e) => setPayAmount(e.target.value)} placeholder="0,00" />
                </Field>
                <Field>
                  <FieldLabel htmlFor="pay-date">Data</FieldLabel>
                  <Input id="pay-date" type="date" value={payDate} onChange={(e) => setPayDate(e.target.value)} />
                </Field>
              </div>
              {accounts.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                  Cadastre uma conta em “Contas (saldos)” primeiro.
                </p>
              ) : null}
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={savingPay || !payAccountId}>
                {savingPay ? <Spinner data-icon="inline-start" /> : null}
                Confirmar pagamento
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </div>
  );
}

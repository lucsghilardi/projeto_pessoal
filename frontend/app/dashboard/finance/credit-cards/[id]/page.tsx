"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft, ChevronLeft, ChevronRight, Pencil, Plus, RotateCcw, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
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

function formatCurrency(value: number) {
  return new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(value);
}

function toNumber(value: string) {
  return Number.parseFloat(value || "0");
}

function currentMonth() {
  return new Date().toISOString().slice(0, 7);
}

function monthLabel(month: string) {
  const [year, mo] = month.split("-").map(Number);
  return new Date(year, mo - 1, 1)
    .toLocaleDateString("pt-BR", { month: "long", year: "numeric" })
    .replace(/^./, (c) => c.toUpperCase());
}

function shiftMonth(month: string, delta: number) {
  const [year, mo] = month.split("-").map(Number);
  const d = new Date(year, mo - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

function formatDate(value: string) {
  return new Date(value).toLocaleDateString("pt-BR", { day: "2-digit", month: "2-digit" });
}

function formatFullDate(value: string) {
  return new Date(value).toLocaleDateString("pt-BR", { day: "2-digit", month: "2-digit", year: "numeric" });
}

type TxFormState = {
  category_id: string;
  description: string;
  amount: string;
  purchase_date: string;
  installments_total: string;
  reference_month: string;
};

function emptyTxForm(): TxFormState {
  return {
    category_id: "",
    description: "",
    amount: "",
    purchase_date: new Date().toISOString().slice(0, 10),
    installments_total: "1",
    reference_month: currentMonth(),
  };
}

export default function CreditCardInvoicePage() {
  const params = useParams<{ id: string }>();
  const cardId = Number(params.id);

  const [card, setCard] = useState<CreditCard | null>(null);
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

  function openCreateTx() {
    setEditingTx(null);
    setTxForm({ ...emptyTxForm(), reference_month: month });
    setTxError(null);
    setIsTxOpen(true);
    // Sugere a fatura pela data de hoje.
    void suggestInvoice(new Date().toISOString().slice(0, 10));
  }

  function openEditTx(tx: CreditCardTransaction) {
    setEditingTx(tx);
    setTxForm({
      category_id: tx.category_id ? String(tx.category_id) : "",
      description: tx.description,
      amount: String(tx.amount),
      purchase_date: tx.purchase_date.slice(0, 10),
      installments_total: String(tx.installments_total ?? "1"),
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
        await updateCreditCardTransaction(editingTx.id, {
          category_id: categoryId,
          description: txForm.description.trim(),
          amount,
          reference_month: txForm.reference_month || undefined,
        });
        appToast.success("Lançamento atualizado.");
      } else {
        await createCreditCardTransaction({
          credit_card_id: cardId,
          category_id: categoryId,
          description: txForm.description.trim(),
          amount,
          purchase_date: txForm.purchase_date,
          installments_total: installments >= 2 ? installments : undefined,
          reference_month: txForm.reference_month || undefined,
        });
        appToast.success("Gasto lançado.");
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
    setPayDate(new Date().toISOString().slice(0, 10));
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
                ? "Edite o gasto e, se quiser, mova-o para outra fatura."
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

              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="tx-amount">
                    {!editingTx && Number(txForm.installments_total) >= 2 ? "Valor da parcela (R$)" : "Valor (R$)"}
                  </FieldLabel>
                  <Input id="tx-amount" type="number" step="0.01" min="0" value={txForm.amount}
                    onChange={(e) => setTxForm({ ...txForm, amount: e.target.value })} placeholder="0,00" />
                </Field>
                {!editingTx ? (
                  <Field>
                    <FieldLabel htmlFor="tx-date">Data da compra</FieldLabel>
                    <Input id="tx-date" type="date" value={txForm.purchase_date}
                      onChange={(e) => onPurchaseDateChange(e.target.value)} />
                  </Field>
                ) : null}
              </div>

              {!editingTx ? (
                <Field>
                  <FieldLabel htmlFor="tx-installments">Parcelas (1 = à vista)</FieldLabel>
                  <Input id="tx-installments" type="number" min="1" max="360" value={txForm.installments_total}
                    onChange={(e) => setTxForm({ ...txForm, installments_total: e.target.value })} />
                </Field>
              ) : null}

              <Field>
                <FieldLabel htmlFor="tx-invoice">
                  {!editingTx && Number(txForm.installments_total) >= 2 ? "Fatura da 1ª parcela" : "Fatura"}
                </FieldLabel>
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

function SummaryCard({ label, value, accentClass }: { label: string; value: string; accentClass?: string }) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardDescription>{label}</CardDescription>
      </CardHeader>
      <CardContent>
        <p className={`text-2xl font-semibold tabular-nums ${accentClass ?? ""}`}>{value}</p>
      </CardContent>
    </Card>
  );
}

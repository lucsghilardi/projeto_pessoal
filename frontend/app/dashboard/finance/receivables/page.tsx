"use client";

import { useEffect, useState } from "react";
import { Check, ChevronLeft, ChevronRight, Pencil, Plus, RotateCcw, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { appToast } from "@/lib/toast";
import {
  createReceivable,
  deleteReceivable,
  getBankAccounts,
  getFinanceCategories,
  getReceivables,
  receiveReceivable,
  unreceiveReceivable,
  updateReceivable,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { BankAccount, FinanceCategory, Receivable, ReceivableKind } from "@/types/Finance";
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

const KIND_LABELS: Record<ReceivableKind, string> = {
  avulsa: "Avulsa",
  fixa: "Fixa",
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

type FormState = {
  description: string;
  category_id: string;
  amount: string;
  due_date: string;
  kind: ReceivableKind;
};

const emptyForm: FormState = {
  description: "",
  category_id: "",
  amount: "",
  due_date: new Date().toISOString().slice(0, 10),
  kind: "avulsa",
};

export default function ReceivablesPage() {
  const [month, setMonth] = useState(currentMonth());
  const [receivables, setReceivables] = useState<Receivable[]>([]);
  const [categories, setCategories] = useState<FinanceCategory[]>([]);
  const [accounts, setAccounts] = useState<BankAccount[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  const [editing, setEditing] = useState<Receivable | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [formError, setFormError] = useState<string | null>(null);

  // Recebimento (escolher conta + data)
  const [receiveTarget, setReceiveTarget] = useState<Receivable | null>(null);
  const [receiveAccountId, setReceiveAccountId] = useState("");
  const [receiveDate, setReceiveDate] = useState("");
  const [savingReceive, setSavingReceive] = useState(false);

  async function loadMonth(targetMonth: string) {
    setReceivables(await getReceivables(targetMonth));
  }

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const [list, cats, acc] = await Promise.all([
          getReceivables(month),
          getFinanceCategories("receita"),
          getBankAccounts(),
        ]);
        if (mounted) {
          setReceivables(list);
          setCategories(cats);
          setAccounts(acc.accounts);
        }
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar os recebimentos.");
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [month]);

  const total = receivables.reduce((sum, r) => sum + toNumber(r.amount), 0);
  const received = receivables.filter((r) => r.is_received).reduce((sum, r) => sum + toNumber(r.amount), 0);
  const pending = total - received;

  function openCreate() {
    setEditing(null);
    setForm({ ...emptyForm, due_date: `${month}-05` });
    setFormError(null);
    setIsSheetOpen(true);
  }

  function openEdit(receivable: Receivable) {
    setEditing(receivable);
    setForm({
      description: receivable.description,
      category_id: receivable.category_id ? String(receivable.category_id) : "",
      amount: String(receivable.amount),
      due_date: receivable.due_date.slice(0, 10),
      kind: receivable.kind,
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
        await updateReceivable(editing.id, {
          description: form.description.trim(),
          category_id: categoryId,
          amount,
          due_date: form.due_date,
        });
        appToast.success("Recebimento atualizado.");
      } else {
        await createReceivable({
          description: form.description.trim(),
          category_id: categoryId,
          amount,
          due_date: form.due_date,
          kind: form.kind,
        });
        appToast.success("Recebimento criado.");
      }
      setIsSheetOpen(false);
      await loadMonth(month);
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível salvar.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  function openReceive(receivable: Receivable) {
    setReceiveTarget(receivable);
    setReceiveAccountId(accounts[0] ? String(accounts[0].id) : "");
    setReceiveDate(new Date().toISOString().slice(0, 10));
  }

  async function handleReceive(event: React.FormEvent) {
    event.preventDefault();
    if (!receiveTarget || !receiveAccountId) return;
    setSavingReceive(true);
    try {
      await receiveReceivable(receiveTarget.id, {
        bank_account_id: Number(receiveAccountId),
        received_at: receiveDate || undefined,
      });
      appToast.success("Recebimento confirmado e saldo atualizado.");
      setReceiveTarget(null);
      await loadMonth(month);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível confirmar.");
    } finally {
      setSavingReceive(false);
    }
  }

  async function handleUnreceive(receivable: Receivable) {
    if (!window.confirm(`Estornar o recebimento de "${receivable.description}"? O valor sai do saldo da conta.`)) {
      return;
    }
    try {
      await unreceiveReceivable(receivable.id);
      appToast.success("Recebimento estornado.");
      await loadMonth(month);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível estornar.");
    }
  }

  async function handleDelete(receivable: Receivable) {
    let scope: "one" | "group" = "one";
    if (receivable.group_id) {
      scope = window.confirm(
        `"${receivable.description}" faz parte de uma série (fixa).\n\nOK = excluir TODA a série. Cancelar = excluir só este.`,
      )
        ? "group"
        : "one";
    } else if (!window.confirm(`Excluir "${receivable.description}"?`)) {
      return;
    }
    try {
      await deleteReceivable(receivable.id, scope);
      appToast.success("Removido.");
      await loadMonth(month);
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover.");
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando contas a receber..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Contas a receber"
        description="Salário, renda extra e outros recebimentos — avulsos ou fixos mensais."
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            Novo recebimento
          </Button>
        }
      />

      <div className="flex items-center gap-2">
        <Button variant="outline" size="icon" onClick={() => setMonth(shiftMonth(month, -1))}>
          <ChevronLeft className="size-4" />
        </Button>
        <span className="min-w-44 text-center text-sm font-medium">{monthLabel(month)}</span>
        <Button variant="outline" size="icon" onClick={() => setMonth(shiftMonth(month, 1))}>
          <ChevronRight className="size-4" />
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <SummaryCard label="Total do mês" value={formatCurrency(total)} />
        <SummaryCard label="Recebido" value={formatCurrency(received)} accentClass="text-emerald-600" />
        <SummaryCard label="A receber" value={formatCurrency(pending)} accentClass="text-amber-600" />
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Recebimentos de {monthLabel(month)}</CardTitle>
          <CardDescription>{receivables.length} no mês.</CardDescription>
        </CardHeader>
        <CardContent>
          {receivables.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Nenhum recebimento neste mês. Clique em “Novo recebimento”.
            </p>
          ) : (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-32">Status</TableHead>
                    <TableHead>Descrição</TableHead>
                    <TableHead>Categoria</TableHead>
                    <TableHead>Previsto</TableHead>
                    <TableHead className="text-right">Valor</TableHead>
                    <TableHead className="text-right">Ações</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {receivables.map((receivable) => (
                    <TableRow key={receivable.id} className={receivable.is_received ? "opacity-60" : ""}>
                      <TableCell>
                        {receivable.is_received ? (
                          <div className="space-y-0.5">
                            <span className="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs text-emerald-700">
                              <Check className="size-3" /> Recebido
                            </span>
                            {receivable.bank_account ? (
                              <div className="text-xs text-muted-foreground">em {receivable.bank_account.name}</div>
                            ) : null}
                          </div>
                        ) : (
                          <Button variant="outline" size="sm" onClick={() => openReceive(receivable)}>
                            Receber
                          </Button>
                        )}
                      </TableCell>
                      <TableCell>
                        <div className="font-medium">{receivable.description}</div>
                        <div className="text-xs text-muted-foreground">{KIND_LABELS[receivable.kind]}</div>
                      </TableCell>
                      <TableCell>
                        {receivable.category ? (
                          <span className="inline-flex items-center gap-1.5 text-sm">
                            <span className="size-2.5 rounded-full" style={{ backgroundColor: receivable.category.color }} />
                            {receivable.category.name}
                          </span>
                        ) : (
                          <span className="text-xs text-muted-foreground">—</span>
                        )}
                      </TableCell>
                      <TableCell className="tabular-nums">{formatDate(receivable.due_date)}</TableCell>
                      <TableCell className="text-right tabular-nums text-emerald-600">
                        {formatCurrency(toNumber(receivable.amount))}
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-1">
                          {receivable.is_received ? (
                            <Button variant="ghost" size="icon" title="Estornar" onClick={() => handleUnreceive(receivable)}>
                              <RotateCcw className="size-4 text-amber-600" />
                            </Button>
                          ) : null}
                          <Button variant="ghost" size="icon" title="Editar" onClick={() => openEdit(receivable)}>
                            <Pencil className="size-4" />
                          </Button>
                          <Button variant="ghost" size="icon" title="Excluir" onClick={() => handleDelete(receivable)}>
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

      <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{editing ? "Editar recebimento" : "Novo recebimento"}</SheetTitle>
            <SheetDescription>
              {editing ? "Edita apenas este lançamento." : "Fixo gera os próximos 12 meses (ex.: salário)."}
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleSubmit}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="r-description">Descrição</FieldLabel>
                <Input id="r-description" value={form.description}
                  onChange={(e) => setForm({ ...form, description: e.target.value })}
                  placeholder="Ex.: Salário, Freela" required />
              </Field>

              <Field>
                <FieldLabel htmlFor="r-category">Categoria</FieldLabel>
                <Select value={form.category_id || "__none__"}
                  onValueChange={(value) => setForm({ ...form, category_id: value === "__none__" ? "" : value })}>
                  <SelectTrigger id="r-category">
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
                  <FieldLabel htmlFor="r-kind">Tipo</FieldLabel>
                  <Select value={form.kind} onValueChange={(value) => setForm({ ...form, kind: value as ReceivableKind })}>
                    <SelectTrigger id="r-kind">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="avulsa">Avulsa (uma vez)</SelectItem>
                      <SelectItem value="fixa">Fixa (todo mês)</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>
              ) : null}

              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="r-amount">Valor (R$)</FieldLabel>
                  <Input id="r-amount" type="number" step="0.01" min="0" value={form.amount}
                    onChange={(e) => setForm({ ...form, amount: e.target.value })} placeholder="0,00" />
                </Field>
                <Field>
                  <FieldLabel htmlFor="r-due">{editing ? "Previsto" : "1ª data prevista"}</FieldLabel>
                  <Input id="r-due" type="date" value={form.due_date}
                    onChange={(e) => setForm({ ...form, due_date: e.target.value })} />
                </Field>
              </div>

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

      {/* Sheet de recebimento */}
      <Sheet open={receiveTarget !== null} onOpenChange={(open) => { if (!open) setReceiveTarget(null); }}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Confirmar recebimento</SheetTitle>
            <SheetDescription>
              {receiveTarget ? `${receiveTarget.description} — ${formatCurrency(toNumber(receiveTarget.amount))}. ` : ""}
              Escolha a conta que recebeu; o saldo dela será creditado.
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleReceive}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="receive-account">Receber em</FieldLabel>
                <Select value={receiveAccountId} onValueChange={setReceiveAccountId}>
                  <SelectTrigger id="receive-account">
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
                <FieldLabel htmlFor="receive-date">Data do recebimento</FieldLabel>
                <Input id="receive-date" type="date" value={receiveDate} onChange={(e) => setReceiveDate(e.target.value)} />
              </Field>
              {accounts.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                  Cadastre uma conta em “Contas (saldos)” primeiro.
                </p>
              ) : null}
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={savingReceive || !receiveAccountId}>
                {savingReceive ? <Spinner data-icon="inline-start" /> : null}
                Confirmar recebimento
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

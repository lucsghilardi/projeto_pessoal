"use client";

import { useEffect, useState } from "react";
import { History, Pencil, Plus, RotateCcw, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { appToast } from "@/lib/toast";
import {
  createAcerto,
  deleteAcerto,
  deleteAcertoSettlement,
  getAcertos,
  getBankAccounts,
  getFinanceCategories,
  settleAcerto,
  updateAcerto,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { Acerto, AcertoDirection, BankAccount, FinanceCategory } from "@/types/Finance";
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

function formatCurrency(value: number) {
  return new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(value);
}

function toNumber(value: string | number) {
  return typeof value === "number" ? value : Number.parseFloat(value || "0");
}

function formatDate(value: string) {
  return new Date(value).toLocaleDateString("pt-BR", { day: "2-digit", month: "2-digit", year: "2-digit" });
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

type FormState = {
  direction: AcertoDirection;
  category_id: string;
  description: string;
  amount: string;
  notes: string;
};

const emptyForm: FormState = {
  direction: "receber",
  category_id: "",
  description: "",
  amount: "",
  notes: "",
};

export default function AcertosPage() {
  const [acertos, setAcertos] = useState<Acerto[]>([]);
  const [despesaCategories, setDespesaCategories] = useState<FinanceCategory[]>([]);
  const [receitaCategories, setReceitaCategories] = useState<FinanceCategory[]>([]);
  const [accounts, setAccounts] = useState<BankAccount[]>([]);
  const [loading, setLoading] = useState(true);

  // Criar/editar
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  const [editing, setEditing] = useState<Acerto | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  // Dar baixa
  const [settleTarget, setSettleTarget] = useState<Acerto | null>(null);
  const [settleAmount, setSettleAmount] = useState("");
  const [settleAccountId, setSettleAccountId] = useState("");
  const [settleDate, setSettleDate] = useState("");
  const [savingSettle, setSavingSettle] = useState(false);

  // Histórico de baixas
  const [historyTarget, setHistoryTarget] = useState<Acerto | null>(null);

  async function load() {
    setAcertos(await getAcertos());
  }

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const [list, despesas, receitas, acc] = await Promise.all([
          getAcertos(),
          getFinanceCategories("despesa"),
          getFinanceCategories("receita"),
          getBankAccounts(),
        ]);
        if (mounted) {
          setAcertos(list);
          setDespesaCategories(despesas);
          setReceitaCategories(receitas);
          setAccounts(acc.accounts);
        }
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar os acertos.");
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  const receber = acertos.filter((a) => a.direction === "receber");
  const pagar = acertos.filter((a) => a.direction === "pagar");
  const totalReceber = receber.reduce((sum, a) => sum + toNumber(a.remaining), 0);
  const totalPagar = pagar.reduce((sum, a) => sum + toNumber(a.remaining), 0);

  const categoriesForForm = form.direction === "pagar" ? despesaCategories : receitaCategories;

  function openCreate() {
    setEditing(null);
    setForm(emptyForm);
    setFormError(null);
    setIsSheetOpen(true);
  }

  function openEdit(acerto: Acerto) {
    setEditing(acerto);
    setForm({
      direction: acerto.direction,
      category_id: acerto.category_id ? String(acerto.category_id) : "",
      description: acerto.description,
      amount: String(acerto.amount),
      notes: acerto.notes ?? "",
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
      const notes = form.notes.trim() || null;
      if (editing) {
        await updateAcerto(editing.id, {
          description: form.description.trim(),
          category_id: categoryId,
          amount,
          notes,
        });
        appToast.success("Acerto atualizado.");
      } else {
        await createAcerto({
          direction: form.direction,
          description: form.description.trim(),
          category_id: categoryId,
          amount,
          notes,
        });
        appToast.success("Acerto criado.");
      }
      setIsSheetOpen(false);
      await load();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível salvar o acerto.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  function openSettle(acerto: Acerto) {
    setSettleTarget(acerto);
    setSettleAmount(String(toNumber(acerto.remaining)));
    setSettleAccountId(accounts[0] ? String(accounts[0].id) : "");
    setSettleDate(today());
  }

  async function handleSettle(event: React.FormEvent) {
    event.preventDefault();
    if (!settleTarget || !settleAccountId) return;
    const amount = toNumber(settleAmount);
    if (!amount || amount <= 0) {
      appToast.error("Informe um valor maior que zero.");
      return;
    }
    setSavingSettle(true);
    try {
      await settleAcerto(settleTarget.id, {
        amount,
        bank_account_id: Number(settleAccountId),
        settled_at: settleDate || undefined,
      });
      appToast.success(
        settleTarget.direction === "receber"
          ? "Baixa registrada e saldo creditado."
          : "Baixa registrada e saldo debitado.",
      );
      setSettleTarget(null);
      await load();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível dar baixa.");
    } finally {
      setSavingSettle(false);
    }
  }

  async function handleUnsettle(acerto: Acerto, settlementId: number) {
    if (!window.confirm("Estornar esta baixa? O saldo da conta será revertido.")) {
      return;
    }
    try {
      const updated = await deleteAcertoSettlement(acerto.id, settlementId);
      appToast.success("Baixa estornada.");
      setHistoryTarget(updated);
      await load();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível estornar.");
    }
  }

  async function handleDelete(acerto: Acerto) {
    const settledCount = acerto.settlements?.length ?? 0;
    const warning =
      settledCount > 0
        ? `Excluir "${acerto.description}"? As ${settledCount} baixa(s) serão estornadas e o saldo das contas revertido.`
        : `Excluir "${acerto.description}"?`;
    if (!window.confirm(warning)) {
      return;
    }
    try {
      await deleteAcerto(acerto.id);
      appToast.success("Acerto removido.");
      await load();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover.");
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando acertos..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Acertos"
        description="Valores a pagar e a receber sem prazo. Dê baixa (parcial ou total) escolhendo a conta; o saldo é ajustado e o restante vai diminuindo."
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            Novo acerto
          </Button>
        }
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <SummaryCard label="A receber (restante)" value={formatCurrency(totalReceber)} accentClass="text-emerald-600" />
        <SummaryCard label="A pagar (restante)" value={formatCurrency(totalPagar)} accentClass="text-red-600" />
        <SummaryCard
          label="Saldo líquido"
          value={formatCurrency(totalReceber - totalPagar)}
          accentClass={totalReceber - totalPagar >= 0 ? "text-emerald-600" : "text-red-600"}
        />
      </div>

      <AcertoSection
        title="A receber"
        emptyLabel="Nenhum valor a receber. Clique em “Novo acerto”."
        items={receber}
        onSettle={openSettle}
        onHistory={setHistoryTarget}
        onEdit={openEdit}
        onDelete={handleDelete}
      />

      <AcertoSection
        title="A pagar"
        emptyLabel="Nenhum valor a pagar. Clique em “Novo acerto”."
        items={pagar}
        onSettle={openSettle}
        onHistory={setHistoryTarget}
        onEdit={openEdit}
        onDelete={handleDelete}
      />

      {/* Criar / editar */}
      <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{editing ? "Editar acerto" : "Novo acerto"}</SheetTitle>
            <SheetDescription>
              {editing
                ? "Edite os dados. O tipo (pagar/receber) não muda após criado."
                : "Cadastre um valor a pagar ou a receber, sem prazo, para ir baixando aos poucos."}
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleSubmit}>
            <FieldGroup className="gap-4">
              {!editing ? (
                <Field>
                  <FieldLabel htmlFor="direction">Tipo</FieldLabel>
                  <Select
                    value={form.direction}
                    onValueChange={(value) =>
                      setForm({ ...form, direction: value as AcertoDirection, category_id: "" })
                    }
                  >
                    <SelectTrigger id="direction">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="receber">A receber (crédito)</SelectItem>
                      <SelectItem value="pagar">A pagar (dívida)</SelectItem>
                    </SelectContent>
                  </Select>
                </Field>
              ) : null}

              <Field>
                <FieldLabel htmlFor="description">Descrição</FieldLabel>
                <Input
                  id="description"
                  value={form.description}
                  onChange={(e) => setForm({ ...form, description: e.target.value })}
                  placeholder="Ex.: Venda de computador para a prima"
                  required
                />
              </Field>

              <Field>
                <FieldLabel htmlFor="category">Categoria</FieldLabel>
                <Select
                  value={form.category_id || "__none__"}
                  onValueChange={(value) => setForm({ ...form, category_id: value === "__none__" ? "" : value })}
                >
                  <SelectTrigger id="category">
                    <SelectValue placeholder="Selecione" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="__none__">Sem categoria</SelectItem>
                    {categoriesForForm.map((category) => (
                      <SelectItem key={category.id} value={String(category.id)}>
                        {category.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <p className="text-xs text-muted-foreground">
                  Usada nos relatórios quando você der baixa.
                </p>
              </Field>

              <Field>
                <FieldLabel htmlFor="amount">Valor total (R$)</FieldLabel>
                <Input
                  id="amount"
                  type="number"
                  step="0.01"
                  min="0"
                  value={form.amount}
                  onChange={(e) => setForm({ ...form, amount: e.target.value })}
                  placeholder="0,00"
                />
              </Field>

              <Field>
                <FieldLabel htmlFor="notes">Observações (opcional)</FieldLabel>
                <Input
                  id="notes"
                  value={form.notes}
                  onChange={(e) => setForm({ ...form, notes: e.target.value })}
                  placeholder="Ex.: combinado de pagar até dezembro"
                />
              </Field>

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

      {/* Dar baixa */}
      <Sheet open={settleTarget !== null} onOpenChange={(open) => { if (!open) setSettleTarget(null); }}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{settleTarget?.direction === "pagar" ? "Pagar" : "Receber"}</SheetTitle>
            <SheetDescription>
              {settleTarget
                ? `${settleTarget.description} — restam ${formatCurrency(toNumber(settleTarget.remaining))}. `
                : ""}
              {settleTarget?.direction === "pagar"
                ? "O valor sai do saldo da conta escolhida."
                : "O valor entra no saldo da conta escolhida."}
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleSettle}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="settle-amount">Valor (R$)</FieldLabel>
                <Input
                  id="settle-amount"
                  type="number"
                  step="0.01"
                  min="0"
                  value={settleAmount}
                  onChange={(e) => setSettleAmount(e.target.value)}
                  placeholder="0,00"
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="settle-account">
                  {settleTarget?.direction === "pagar" ? "Pagar com" : "Receber na conta"}
                </FieldLabel>
                <Select value={settleAccountId} onValueChange={setSettleAccountId}>
                  <SelectTrigger id="settle-account">
                    <SelectValue placeholder="Selecione a conta" />
                  </SelectTrigger>
                  <SelectContent>
                    {accounts.map((account) => (
                      <SelectItem key={account.id} value={String(account.id)}>
                        {account.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
              <Field>
                <FieldLabel htmlFor="settle-date">Data</FieldLabel>
                <Input id="settle-date" type="date" value={settleDate} onChange={(e) => setSettleDate(e.target.value)} />
              </Field>
              {accounts.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                  Cadastre uma conta em “Contas (saldos)” primeiro.
                </p>
              ) : null}
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={savingSettle || !settleAccountId}>
                {savingSettle ? <Spinner data-icon="inline-start" /> : null}
                Confirmar baixa
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>

      {/* Histórico de baixas */}
      <Sheet open={historyTarget !== null} onOpenChange={(open) => { if (!open) setHistoryTarget(null); }}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Baixas</SheetTitle>
            <SheetDescription>
              {historyTarget
                ? `${historyTarget.description} — ${formatCurrency(toNumber(historyTarget.settled_amount))} de ${formatCurrency(toNumber(historyTarget.amount))} baixado.`
                : ""}
            </SheetDescription>
          </SheetHeader>
          <div className="px-4 pb-4">
            {(historyTarget?.settlements?.length ?? 0) === 0 ? (
              <p className="py-8 text-center text-sm text-muted-foreground">Nenhuma baixa registrada ainda.</p>
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Data</TableHead>
                    <TableHead>Conta</TableHead>
                    <TableHead className="text-right">Valor</TableHead>
                    <TableHead className="text-right">Ações</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {historyTarget?.settlements?.map((s) => (
                    <TableRow key={s.id}>
                      <TableCell className="tabular-nums">{formatDate(s.settled_at)}</TableCell>
                      <TableCell>{s.bank_account?.name ?? "—"}</TableCell>
                      <TableCell className="text-right tabular-nums">{formatCurrency(toNumber(s.amount))}</TableCell>
                      <TableCell className="text-right">
                        <Button
                          variant="ghost"
                          size="icon"
                          title="Estornar baixa"
                          onClick={() => historyTarget && handleUnsettle(historyTarget, s.id)}
                        >
                          <RotateCcw className="size-4 text-amber-600" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            )}
          </div>
        </SheetContent>
      </Sheet>
    </div>
  );
}

function AcertoSection({
  title,
  emptyLabel,
  items,
  onSettle,
  onHistory,
  onEdit,
  onDelete,
}: {
  title: string;
  emptyLabel: string;
  items: Acerto[];
  onSettle: (a: Acerto) => void;
  onHistory: (a: Acerto) => void;
  onEdit: (a: Acerto) => void;
  onDelete: (a: Acerto) => void;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{items.length} item(ns).</CardDescription>
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <p className="py-8 text-center text-sm text-muted-foreground">{emptyLabel}</p>
        ) : (
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Descrição</TableHead>
                  <TableHead>Categoria</TableHead>
                  <TableHead className="text-right">Total</TableHead>
                  <TableHead className="text-right">Baixado</TableHead>
                  <TableHead className="text-right">Restante</TableHead>
                  <TableHead className="text-right">Ações</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {items.map((acerto) => {
                  const remaining = toNumber(acerto.remaining);
                  const settled = toNumber(acerto.settled_amount);
                  return (
                    <TableRow key={acerto.id} className={acerto.is_settled ? "opacity-60" : ""}>
                      <TableCell>
                        <div className="font-medium">{acerto.description}</div>
                        {acerto.notes ? (
                          <div className="text-xs text-muted-foreground">{acerto.notes}</div>
                        ) : null}
                      </TableCell>
                      <TableCell>
                        {acerto.category ? (
                          <span className="inline-flex items-center gap-1.5 text-sm">
                            <span className="size-2.5 rounded-full" style={{ backgroundColor: acerto.category.color }} />
                            {acerto.category.name}
                          </span>
                        ) : (
                          <span className="text-xs text-muted-foreground">—</span>
                        )}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatCurrency(toNumber(acerto.amount))}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {formatCurrency(settled)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums font-semibold">
                        {acerto.is_settled ? (
                          <span className="text-emerald-600">Quitado</span>
                        ) : (
                          formatCurrency(remaining)
                        )}
                      </TableCell>
                      <TableCell className="text-right">
                        <div className="flex justify-end gap-1">
                          {!acerto.is_settled ? (
                            <Button variant="outline" size="sm" onClick={() => onSettle(acerto)}>
                              Dar baixa
                            </Button>
                          ) : null}
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Histórico de baixas"
                            onClick={() => onHistory(acerto)}
                          >
                            <History className="size-4" />
                          </Button>
                          <Button variant="ghost" size="icon" title="Editar" onClick={() => onEdit(acerto)}>
                            <Pencil className="size-4" />
                          </Button>
                          <Button variant="ghost" size="icon" title="Excluir" onClick={() => onDelete(acerto)}>
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

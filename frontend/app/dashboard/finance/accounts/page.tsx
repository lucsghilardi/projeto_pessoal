"use client";

import { useEffect, useState } from "react";
import { Plus, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { formatCurrency, toNumber } from "@/lib/format";
import { appToast } from "@/lib/toast";
import {
  createBankAccount,
  deleteBankAccount,
  getBankAccounts,
  updateBankAccount,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { BankAccount } from "@/types/Finance";
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
import { Spinner } from "@/components/ui/spinner";

export default function AccountsPage() {
  const [accounts, setAccounts] = useState<BankAccount[]>([]);
  const [balances, setBalances] = useState<Record<number, string>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  const [newName, setNewName] = useState("");
  const [newBalance, setNewBalance] = useState("");
  const [formError, setFormError] = useState<string | null>(null);

  function hydrate(list: BankAccount[]) {
    setAccounts(list);
    const map: Record<number, string> = {};
    list.forEach((account) => {
      map[account.id] = String(account.balance);
    });
    setBalances(map);
  }

  async function load() {
    const data = await getBankAccounts();
    hydrate(data.accounts);
  }

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const data = await getBankAccounts();
        if (mounted) hydrate(data.accounts);
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar as contas.");
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  const total = accounts.reduce((sum, account) => sum + toNumber(balances[account.id] ?? "0"), 0);

  async function handleSaveBalances() {
    setSaving(true);
    try {
      await Promise.all(
        accounts.map((account) =>
          updateBankAccount(account.id, {
            name: account.name,
            balance: toNumber(balances[account.id] ?? "0"),
          }),
        ),
      );
      appToast.success("Saldos atualizados.");
      await load();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível salvar os saldos.");
    } finally {
      setSaving(false);
    }
  }

  async function handleCreate(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);
    if (!newName.trim()) {
      setFormError("Informe o nome da conta.");
      return;
    }
    try {
      await createBankAccount({ name: newName.trim(), balance: toNumber(newBalance) });
      appToast.success("Conta criada.");
      setIsSheetOpen(false);
      setNewName("");
      setNewBalance("");
      await load();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível criar a conta.";
      setFormError(message);
      appToast.error(message);
    }
  }

  async function handleDelete(account: BankAccount) {
    if (!window.confirm(`Excluir a conta "${account.name}"?`)) return;
    try {
      await deleteBankAccount(account.id);
      appToast.success("Conta removida.");
      await load();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover a conta.");
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando contas..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Contas (saldos)"
        description="Quanto você tem hoje em cada conta. Edite os valores e clique em salvar."
        actions={
          <Button variant="outline" onClick={() => setIsSheetOpen(true)}>
            <Plus className="size-4" />
            Nova conta
          </Button>
        }
      />

      <Card>
        <CardHeader className="pb-2">
          <CardDescription>Total disponível</CardDescription>
        </CardHeader>
        <CardContent>
          <p className="text-3xl font-semibold tabular-nums text-emerald-600">{formatCurrency(total)}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Minhas contas</CardTitle>
          <CardDescription>{accounts.length} conta(s).</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {accounts.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">Nenhuma conta. Clique em “Nova conta”.</p>
          ) : (
            accounts.map((account) => (
              <div key={account.id} className="flex items-center gap-3">
                <div className="min-w-0 flex-1 text-sm font-medium">{account.name}</div>
                <div className="flex items-center gap-1">
                  <span className="text-sm text-muted-foreground">R$</span>
                  <Input
                    type="number"
                    step="0.01"
                    className="w-40"
                    value={balances[account.id] ?? ""}
                    onChange={(e) => setBalances((prev) => ({ ...prev, [account.id]: e.target.value }))}
                  />
                </div>
                <Button variant="ghost" size="icon" title="Excluir" onClick={() => handleDelete(account)}>
                  <Trash2 className="size-4 text-red-600" />
                </Button>
              </div>
            ))
          )}

          {accounts.length > 0 ? (
            <div className="pt-2">
              <Button onClick={handleSaveBalances} disabled={saving}>
                {saving ? <Spinner data-icon="inline-start" /> : null}
                Salvar saldos
              </Button>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Nova conta</SheetTitle>
            <SheetDescription>Banco/carteira onde você guarda dinheiro.</SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleCreate}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="account-name">Nome</FieldLabel>
                <Input id="account-name" value={newName} onChange={(e) => setNewName(e.target.value)}
                  placeholder="Ex.: C6, Carteira" required />
              </Field>
              <Field>
                <FieldLabel htmlFor="account-balance">Saldo inicial (R$)</FieldLabel>
                <Input id="account-balance" type="number" step="0.01" value={newBalance}
                  onChange={(e) => setNewBalance(e.target.value)} placeholder="0,00" />
              </Field>
              <FieldError>{formError}</FieldError>
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit">Criar conta</Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </div>
  );
}

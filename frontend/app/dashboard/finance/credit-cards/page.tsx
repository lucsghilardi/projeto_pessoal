"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { CreditCard as CreditCardIcon, Pencil, Plus, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { formatCurrency, toNumber } from "@/lib/format";
import { appToast } from "@/lib/toast";
import {
  createCreditCard,
  deleteCreditCard,
  getCreditCards,
  updateCreditCard,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { CreditCard, CreditCardPayload } from "@/types/CreditCard";
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

type FormState = {
  name: string;
  brand: string;
  last_four: string;
  limit: string;
  closing_day: string;
  due_day: string;
};

const emptyForm: FormState = {
  name: "",
  brand: "",
  last_four: "",
  limit: "",
  closing_day: "",
  due_day: "",
};

export default function CreditCardsPage() {
  const [cards, setCards] = useState<CreditCard[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  const [editing, setEditing] = useState<CreditCard | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [formError, setFormError] = useState<string | null>(null);

  async function load() {
    const data = await getCreditCards();
    setCards(data.credit_cards);
  }

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const data = await getCreditCards();
        if (mounted) setCards(data.credit_cards);
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar os cartões.");
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
    setFormError(null);
    setIsSheetOpen(true);
  }

  function openEdit(card: CreditCard) {
    setEditing(card);
    setForm({
      name: card.name,
      brand: card.brand ?? "",
      last_four: card.last_four ?? "",
      limit: card.limit ?? "",
      closing_day: String(card.closing_day),
      due_day: String(card.due_day),
    });
    setFormError(null);
    setIsSheetOpen(true);
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    if (!form.name.trim()) {
      setFormError("Informe o nome do cartão.");
      return;
    }
    const closing = Number(form.closing_day);
    const due = Number(form.due_day);
    if (!closing || closing < 1 || closing > 31) {
      setFormError("Dia de corte deve estar entre 1 e 31.");
      return;
    }
    if (!due || due < 1 || due > 31) {
      setFormError("Dia de vencimento deve estar entre 1 e 31.");
      return;
    }
    if (form.last_four && !/^\d{4}$/.test(form.last_four)) {
      setFormError("Os últimos 4 dígitos devem ter 4 números.");
      return;
    }

    const payload: CreditCardPayload = {
      name: form.name.trim(),
      brand: form.brand.trim() || null,
      last_four: form.last_four.trim() || null,
      limit: form.limit ? toNumber(form.limit) : null,
      closing_day: closing,
      due_day: due,
    };

    setSaving(true);
    try {
      if (editing) {
        await updateCreditCard(editing.id, payload);
        appToast.success("Cartão atualizado.");
      } else {
        await createCreditCard(payload);
        appToast.success("Cartão cadastrado.");
      }
      setIsSheetOpen(false);
      await load();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível salvar o cartão.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(card: CreditCard) {
    if (!window.confirm(`Excluir o cartão "${card.name}"? Todas as faturas e lançamentos dele serão removidos.`)) {
      return;
    }
    try {
      await deleteCreditCard(card.id);
      appToast.success("Cartão removido.");
      await load();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover o cartão.");
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando cartões..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Cartões de crédito"
        description="Cadastre seus cartões com dia de corte e vencimento. Clique em um cartão para ver as faturas e lançar gastos."
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            Novo cartão
          </Button>
        }
      />

      {cards.length === 0 ? (
        <Card>
          <CardContent className="py-10 text-center text-sm text-muted-foreground">
            Nenhum cartão cadastrado. Clique em “Novo cartão”.
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {cards.map((card) => (
            <Card key={card.id} className="relative">
              <Link href={`/dashboard/finance/credit-cards/${card.id}`} className="block">
                <CardHeader>
                  <div className="flex items-center gap-2">
                    <CreditCardIcon className="size-5 text-muted-foreground" />
                    <CardTitle className="truncate">{card.name}</CardTitle>
                  </div>
                  <CardDescription>
                    {[card.brand, card.last_four ? `•••• ${card.last_four}` : null]
                      .filter(Boolean)
                      .join(" · ") || "Cartão de crédito"}
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-2 text-sm">
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Fecha dia</span>
                    <span className="font-medium tabular-nums">{card.closing_day}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Vence dia</span>
                    <span className="font-medium tabular-nums">{card.due_day}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-muted-foreground">Saldo devedor</span>
                    <span className="font-medium tabular-nums text-red-600">
                      {formatCurrency(card.outstanding ?? 0)}
                    </span>
                  </div>
                  {card.limit !== null ? (
                    <div className="flex justify-between">
                      <span className="text-muted-foreground">Limite disponível</span>
                      <span className="font-medium tabular-nums text-emerald-600">
                        {formatCurrency(card.available_limit ?? 0)}
                      </span>
                    </div>
                  ) : null}
                </CardContent>
              </Link>
              <div className="flex justify-end gap-1 px-4 pb-4">
                <Button variant="ghost" size="icon" title="Editar" onClick={() => openEdit(card)}>
                  <Pencil className="size-4" />
                </Button>
                <Button variant="ghost" size="icon" title="Excluir" onClick={() => handleDelete(card)}>
                  <Trash2 className="size-4 text-red-600" />
                </Button>
              </div>
            </Card>
          ))}
        </div>
      )}

      <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{editing ? "Editar cartão" : "Novo cartão"}</SheetTitle>
            <SheetDescription>
              O dia de corte fecha a fatura; o vencimento é quando ela deve ser paga.
            </SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleSubmit}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="cc-name">Nome</FieldLabel>
                <Input id="cc-name" value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  placeholder="Ex.: Nubank, Itaú Mastercard" required />
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="cc-brand">Bandeira</FieldLabel>
                  <Input id="cc-brand" value={form.brand}
                    onChange={(e) => setForm({ ...form, brand: e.target.value })}
                    placeholder="Visa, Master..." />
                </Field>
                <Field>
                  <FieldLabel htmlFor="cc-last4">Últimos 4 dígitos</FieldLabel>
                  <Input id="cc-last4" inputMode="numeric" maxLength={4} value={form.last_four}
                    onChange={(e) => setForm({ ...form, last_four: e.target.value })}
                    placeholder="1234" />
                </Field>
              </div>

              <Field>
                <FieldLabel htmlFor="cc-limit">Limite (R$)</FieldLabel>
                <Input id="cc-limit" type="number" step="0.01" min="0" value={form.limit}
                  onChange={(e) => setForm({ ...form, limit: e.target.value })} placeholder="0,00" />
              </Field>

              <div className="grid grid-cols-2 gap-3">
                <Field>
                  <FieldLabel htmlFor="cc-closing">Dia de corte</FieldLabel>
                  <Input id="cc-closing" type="number" min="1" max="31" value={form.closing_day}
                    onChange={(e) => setForm({ ...form, closing_day: e.target.value })} placeholder="20" />
                </Field>
                <Field>
                  <FieldLabel htmlFor="cc-due">Dia de vencimento</FieldLabel>
                  <Input id="cc-due" type="number" min="1" max="31" value={form.due_day}
                    onChange={(e) => setForm({ ...form, due_day: e.target.value })} placeholder="5" />
                </Field>
              </div>

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

"use client";

import { useEffect, useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { appToast } from "@/lib/toast";
import {
  createInvestmentTag,
  deleteInvestmentTag,
  getInvestmentTags,
  updateInvestmentTag,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { InvestmentTag } from "@/types/InvestmentTag";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
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
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

const DEFAULT_COLOR = "#16a34a";

export default function PurposesPage() {
  const [tags, setTags] = useState<InvestmentTag[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  const [editing, setEditing] = useState<InvestmentTag | null>(null);
  const [name, setName] = useState("");
  const [color, setColor] = useState(DEFAULT_COLOR);
  const [formError, setFormError] = useState<string | null>(null);

  async function reload() {
    setTags(await getInvestmentTags());
  }

  useEffect(() => {
    let mounted = true;

    (async () => {
      try {
        const data = await getInvestmentTags();
        if (mounted) {
          setTags(data);
        }
      } catch (error) {
        appToast.error(
          error instanceof ApiError
            ? error.message
            : "Não foi possível carregar os propósitos.",
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

  function openCreate() {
    setEditing(null);
    setName("");
    setColor(DEFAULT_COLOR);
    setFormError(null);
    setIsSheetOpen(true);
  }

  function openEdit(tag: InvestmentTag) {
    setEditing(tag);
    setName(tag.name);
    setColor(tag.color || DEFAULT_COLOR);
    setFormError(null);
    setIsSheetOpen(true);
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    if (!name.trim()) {
      setFormError("Informe o nome do propósito.");
      return;
    }

    setSaving(true);
    try {
      if (editing) {
        await updateInvestmentTag(editing.id, { name: name.trim(), color });
        appToast.success("Propósito atualizado.");
      } else {
        await createInvestmentTag({ name: name.trim(), color });
        appToast.success("Propósito criado.");
      }
      setIsSheetOpen(false);
      await reload();
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar o propósito.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(tag: InvestmentTag) {
    if (!window.confirm(`Excluir o propósito "${tag.name}"? Ele será desvinculado dos investimentos.`)) {
      return;
    }

    try {
      await deleteInvestmentTag(tag.id);
      appToast.success("Propósito removido.");
      await reload();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível remover o propósito.",
      );
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando propósitos..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Propósitos"
        description="Objetivos para classificar seus investimentos (ex.: computador, casa, carro)."
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            Novo propósito
          </Button>
        }
      />

      <Card>
        <CardHeader>
          <CardTitle>Meus propósitos</CardTitle>
          <CardDescription>{tags.length} cadastrado(s).</CardDescription>
        </CardHeader>
        <CardContent>
          {tags.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Nenhum propósito ainda. Clique em “Novo propósito”.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Propósito</TableHead>
                  <TableHead>Investimentos</TableHead>
                  <TableHead className="text-right">Ações</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {tags.map((tag) => (
                  <TableRow key={tag.id}>
                    <TableCell>
                      <span
                        className="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm"
                        style={{ borderColor: tag.color, color: tag.color }}
                      >
                        <span className="size-2.5 rounded-full" style={{ backgroundColor: tag.color }} />
                        {tag.name}
                      </span>
                    </TableCell>
                    <TableCell>{tag.investments_count ?? 0}</TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-1">
                        <Button variant="ghost" size="icon" onClick={() => openEdit(tag)}>
                          <Pencil className="size-4" />
                        </Button>
                        <Button variant="ghost" size="icon" onClick={() => handleDelete(tag)}>
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

      <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{editing ? "Editar propósito" : "Novo propósito"}</SheetTitle>
            <SheetDescription>Dê um nome e uma cor para identificar o objetivo.</SheetDescription>
          </SheetHeader>

          <form className="px-4 pb-4" onSubmit={handleSubmit}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="purpose-name">Nome</FieldLabel>
                <Input id="purpose-name" value={name}
                  onChange={(e) => setName(e.target.value)} placeholder="Ex.: Computador" required />
              </Field>

              <Field>
                <FieldLabel htmlFor="purpose-color">Cor</FieldLabel>
                <div className="flex items-center gap-3">
                  <input id="purpose-color" type="color" value={color}
                    onChange={(e) => setColor(e.target.value)}
                    className="h-10 w-14 cursor-pointer rounded border" />
                  <span className="text-sm text-muted-foreground">{color}</span>
                </div>
              </Field>

              <FieldError>{formError}</FieldError>
            </FieldGroup>

            <SheetFooter className="px-0">
              <Button type="submit" disabled={saving}>
                {saving ? <Spinner data-icon="inline-start" /> : null}
                {editing ? "Salvar alterações" : "Criar propósito"}
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </div>
  );
}

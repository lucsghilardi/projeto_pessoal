"use client";

import { useEffect, useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { appToast } from "@/lib/toast";
import {
  createInvestmentInstitution,
  deleteInvestmentInstitution,
  getInvestmentInstitutions,
  updateInvestmentInstitution,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { InvestmentInstitution } from "@/types/InvestmentInstitution";
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

export default function InstitutionsPage() {
  const [institutions, setInstitutions] = useState<InvestmentInstitution[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  const [editing, setEditing] = useState<InvestmentInstitution | null>(null);
  const [name, setName] = useState("");
  const [formError, setFormError] = useState<string | null>(null);

  async function reload() {
    setInstitutions(await getInvestmentInstitutions());
  }

  useEffect(() => {
    let mounted = true;

    (async () => {
      try {
        const data = await getInvestmentInstitutions();
        if (mounted) {
          setInstitutions(data);
        }
      } catch (error) {
        appToast.error(
          error instanceof ApiError
            ? error.message
            : "Não foi possível carregar as instituições.",
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
    setFormError(null);
    setIsSheetOpen(true);
  }

  function openEdit(institution: InvestmentInstitution) {
    setEditing(institution);
    setName(institution.name);
    setFormError(null);
    setIsSheetOpen(true);
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    if (!name.trim()) {
      setFormError("Informe o nome da instituição.");
      return;
    }

    setSaving(true);
    try {
      if (editing) {
        await updateInvestmentInstitution(editing.id, { name: name.trim() });
        appToast.success("Instituição atualizada.");
      } else {
        await createInvestmentInstitution({ name: name.trim() });
        appToast.success("Instituição criada.");
      }
      setIsSheetOpen(false);
      await reload();
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar a instituição.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(institution: InvestmentInstitution) {
    if (!window.confirm(`Excluir a instituição "${institution.name}"?`)) {
      return;
    }

    try {
      await deleteInvestmentInstitution(institution.id);
      appToast.success("Instituição removida.");
      await reload();
    } catch (error) {
      appToast.error(
        error instanceof ApiError
          ? error.message
          : "Não foi possível remover a instituição.",
      );
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando instituições..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Instituições"
        description="Bancos e corretoras usados nos seus investimentos (ex.: Itaú, Inter, BTG, Nubank)."
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            Nova instituição
          </Button>
        }
      />

      <Card>
        <CardHeader>
          <CardTitle>Minhas instituições</CardTitle>
          <CardDescription>{institutions.length} cadastrada(s).</CardDescription>
        </CardHeader>
        <CardContent>
          {institutions.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted-foreground">
              Nenhuma instituição ainda. Clique em “Nova instituição”.
            </p>
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Instituição</TableHead>
                  <TableHead className="text-right">Ações</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {institutions.map((institution) => (
                  <TableRow key={institution.id}>
                    <TableCell className="font-medium">{institution.name}</TableCell>
                    <TableCell className="text-right">
                      <div className="flex justify-end gap-1">
                        <Button variant="ghost" size="icon" onClick={() => openEdit(institution)}>
                          <Pencil className="size-4" />
                        </Button>
                        <Button variant="ghost" size="icon" onClick={() => handleDelete(institution)}>
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
            <SheetTitle>{editing ? "Editar instituição" : "Nova instituição"}</SheetTitle>
            <SheetDescription>Nome do banco ou corretora.</SheetDescription>
          </SheetHeader>

          <form className="px-4 pb-4" onSubmit={handleSubmit}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="institution-name">Nome</FieldLabel>
                <Input id="institution-name" value={name}
                  onChange={(e) => setName(e.target.value)} placeholder="Ex.: XP Investimentos" required />
              </Field>

              <FieldError>{formError}</FieldError>
            </FieldGroup>

            <SheetFooter className="px-0">
              <Button type="submit" disabled={saving}>
                {saving ? <Spinner data-icon="inline-start" /> : null}
                {editing ? "Salvar alterações" : "Criar instituição"}
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </div>
  );
}

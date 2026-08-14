"use client";

import { useState } from "react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { useAuth } from "@/context/AuthContext";
import { appToast } from "@/lib/toast";
import { ROLE_LABELS } from "@/lib/user-roles";
import { updateOwnPassword } from "@/services/api";
import { ApiError } from "@/services/apiError";

const formVazio = {
  senha_atual: "",
  password: "",
  password_confirmation: "",
};

export default function PerfilPage() {
  const { user, loading } = useAuth();
  const [form, setForm] = useState(formVazio);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    if (form.password !== form.password_confirmation) {
      setFormError("A confirmação não confere com a nova senha.");
      return;
    }

    setSaving(true);
    try {
      await updateOwnPassword(form);
      setForm(formVazio);
      appToast.success("Senha alterada com sucesso.");
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível alterar a senha.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando perfil..." />;
  }

  if (!user) {
    return null;
  }

  return (
    <div className="space-y-5">
      <DashboardPageHeader
        title="Perfil"
        description="Seus dados de acesso ao painel."
      />

      <Card>
        <CardHeader>
          <CardTitle>Conta</CardTitle>
          <CardDescription>
            Nome e e-mail são alterados por um administrador, na tela de
            Usuários.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <dl className="grid gap-4 sm:grid-cols-3">
            <div>
              <dt className="text-xs text-muted-foreground">Nome</dt>
              <dd className="font-medium">{user.name}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">E-mail</dt>
              <dd className="font-medium break-all">{user.email}</dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">Perfil</dt>
              <dd className="font-medium">{ROLE_LABELS[user.role]}</dd>
            </div>
          </dl>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Trocar senha</CardTitle>
          <CardDescription>
            Não existe recuperação por e-mail: esquecendo a senha, um
            administrador precisa redefinir para você.
          </CardDescription>
        </CardHeader>
        <CardContent>
          <form className="max-w-md" onSubmit={handleSubmit}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="senha-atual">Senha atual</FieldLabel>
                <Input
                  id="senha-atual"
                  type="password"
                  autoComplete="current-password"
                  value={form.senha_atual}
                  onChange={(e) =>
                    setForm((atual) => ({ ...atual, senha_atual: e.target.value }))
                  }
                  required
                />
              </Field>

              <Field>
                <FieldLabel htmlFor="senha-nova">Nova senha</FieldLabel>
                <Input
                  id="senha-nova"
                  type="password"
                  autoComplete="new-password"
                  value={form.password}
                  onChange={(e) =>
                    setForm((atual) => ({ ...atual, password: e.target.value }))
                  }
                  required
                />
                <FieldDescription>
                  No mínimo 8 caracteres, com letras e números.
                </FieldDescription>
              </Field>

              <Field>
                <FieldLabel htmlFor="senha-confirmacao">
                  Confirmar nova senha
                </FieldLabel>
                <Input
                  id="senha-confirmacao"
                  type="password"
                  autoComplete="new-password"
                  value={form.password_confirmation}
                  onChange={(e) =>
                    setForm((atual) => ({
                      ...atual,
                      password_confirmation: e.target.value,
                    }))
                  }
                  required
                />
              </Field>

              <FieldError>{formError}</FieldError>

              <div>
                <Button type="submit" disabled={saving}>
                  {saving ? <Spinner data-icon="inline-start" /> : null}
                  Salvar nova senha
                </Button>
              </div>
            </FieldGroup>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}

'use client';

import { useState } from 'react';
import Image from "next/image";
import { ShieldCheck } from "lucide-react";

import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Spinner } from "@/components/ui/spinner"
import { Card, CardContent } from "@/components/ui/card"
import {
  Field,
  FieldDescription,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/components/ui/field"
import { Input } from "@/components/ui/input"

import { login } from '@/services/api';
import { ApiError } from '@/services/apiError';
import { appToast } from "@/lib/toast";

export function LoginForm({
  className,
  ...props
}: React.ComponentProps<"div">) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleLogin(e: React.FormEvent) {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      await login(email, password);
      appToast.success('Login realizado com sucesso.');
      window.location.assign('/dashboard');
    } catch (err) {
      const message =
        err instanceof ApiError
          ? err.message
          : 'Nao foi possivel autenticar no momento.';

      setError(message);
      appToast.error(message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className={cn("flex flex-col gap-6", className)} {...props}>
      <Card className="overflow-hidden border-zinc-950/10 bg-white p-0 shadow-[0_28px_90px_-38px_rgba(24,24,27,0.42)]">
        <CardContent className="grid p-0 lg:grid-cols-[1.08fr_0.92fr]">
          
          <form className="p-8 lg:p-10" onSubmit={handleLogin}>
            <FieldGroup className="gap-6">
              <div className="space-y-3">
                <div className="space-y-2">
                  <h1 className="text-2xl font-bold  tracking-tight text-zinc-950 text-center">
                    Entrar no painel
                  </h1>
                  <p className="text-sm leading-6 text-zinc-600 text-center">
                    Use apenas e-mail e senha cadastrados pela administracao.
                  </p>
                </div>
              </div>
              <Field>
                <FieldLabel htmlFor="email">Email</FieldLabel>
                <Input
                  id="email"
                  type="email"
                  autoComplete="username"
                  placeholder="admin@nitrogymacademia.com.br"
                  value={email}
                  onChange={e => setEmail(e.target.value)}
                  required
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="password">Senha</FieldLabel>
                <Input
                  id="password"
                  type="password"
                  autoComplete="current-password"
                  value={password}
                  onChange={e => setPassword(e.target.value)}
                  required
                />
              </Field>
              <FieldError>{error}</FieldError>
              <Button type="submit" className="w-full" disabled={loading}>
                {loading ? (
                  <>
                    <Spinner data-icon="inline-start" />
                    Entrando...
                  </>
                ) : (
                  <>
                    <ShieldCheck className="size-4" />
                    Entrar com seguranca
                  </>
                )}
              </Button>
              <FieldDescription className="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 text-zinc-600">
                O cadastro de novos usuarios nao fica disponivel nesta tela.
                Se voce precisa de acesso, solicite a criacao da conta a um
                administrador.
              </FieldDescription>
              <FieldDescription className="text-center text-xs leading-5 text-zinc-500">
                Ao continuar, sua sessao sera iniciada apenas para a area
                administrativa do site.
              </FieldDescription>
            </FieldGroup>
          </form>
          <div className="relative overflow-hidden border-b border-zinc-200 bg-[radial-gradient(circle_at_top_left,_rgba(220,38,38,0.14),_transparent_42%),linear-gradient(180deg,_#fafaf9_0%,_#f5f5f4_100%)] p-8 lg:border-r lg:border-b-0 lg:p-10">
            <Image
              src="/assets/banner/new-banner-home-mobile.jpg"
              alt="Image"
              width={600}
              height={600}
              className="absolute inset-0 h-full w-full object-cover bg-center dark:brightness-[0.2] dark:grayscale"
            />
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

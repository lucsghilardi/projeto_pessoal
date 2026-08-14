import { NextRequest, NextResponse } from "next/server";

import { AUTH_COOKIE_NAME, BACKEND_API_URL, getAuthCookieMaxAge } from "@/lib/auth";

type PasswordResponse = {
  message?: string;
  access_token: string;
  expires_in?: number;
};

async function parseJsonResponse(response: Response) {
  const text = await response.text();

  if (!text) {
    return null;
  }

  try {
    return JSON.parse(text) as unknown;
  } catch {
    return { message: text };
  }
}

/**
 * Rota própria em vez do proxy genérico: trocar a senha invalida o token atual
 * no backend, então o cookie precisa ser reescrito com o token novo — senão a
 * pessoa é deslogada logo depois de trocar a senha.
 */
export async function PUT(request: NextRequest) {
  const token = request.cookies.get(AUTH_COOKIE_NAME)?.value;

  if (!token) {
    return NextResponse.json({ message: "Sessao expirada." }, { status: 401 });
  }

  let payload: unknown;

  try {
    payload = await request.json();
  } catch {
    return NextResponse.json({ message: "Requisicao invalida." }, { status: 400 });
  }

  const upstreamResponse = await fetch(`${BACKEND_API_URL}/me/password`, {
    method: "PUT",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: `Bearer ${token}`,
    },
    body: JSON.stringify(payload),
    cache: "no-store",
  });

  const responseBody = await parseJsonResponse(upstreamResponse);

  if (!upstreamResponse.ok) {
    return NextResponse.json(
      responseBody ?? { message: "Falha ao alterar a senha." },
      { status: upstreamResponse.status },
    );
  }

  const data = responseBody as PasswordResponse;
  const response = NextResponse.json({ message: data.message ?? "Senha alterada com sucesso." });

  response.cookies.set({
    name: AUTH_COOKIE_NAME,
    value: data.access_token,
    httpOnly: true,
    maxAge: getAuthCookieMaxAge(data.expires_in),
    path: "/",
    sameSite: "strict",
    secure: process.env.NODE_ENV === "production",
  });

  return response;
}

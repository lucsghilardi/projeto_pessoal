import { NextResponse } from "next/server";

import { AUTH_COOKIE_NAME, BACKEND_API_URL, getAuthCookieMaxAge } from "@/lib/auth";

type LoginResponse = {
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

export async function POST(request: Request) {
  let payload: unknown;

  try {
    payload = await request.json();
  } catch {
    return NextResponse.json(
      { message: "Requisicao invalida." },
      { status: 400 },
    );
  }

  const upstreamResponse = await fetch(`${BACKEND_API_URL}/login`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
    },
    body: JSON.stringify(payload),
    cache: "no-store",
  });

  const responseBody = await parseJsonResponse(upstreamResponse);

  if (!upstreamResponse.ok) {
    return NextResponse.json(
      responseBody ?? { message: "Falha ao autenticar." },
      { status: upstreamResponse.status },
    );
  }

  const data = responseBody as LoginResponse;
  const response = NextResponse.json({ authenticated: true });

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

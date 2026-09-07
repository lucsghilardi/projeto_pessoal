import { NextRequest, NextResponse } from "next/server";

import { AUTH_COOKIE_NAME, BACKEND_API_URL } from "@/lib/auth";

const FORWARDED_HEADERS = ["accept", "content-type"];

// Sem repassar isto, todo request chega ao Laravel com o IP deste container: o
// throttle do login perde a dimensão de origem e nenhum log sabe de onde veio
// o ataque. Quem preenche estes cabeçalhos é o Nginx do host (ver
// deploy/nginx-sistemaraiz.conf); `x-forwarded-host` fica de fora de propósito,
// para o Host da requisição não virar entrada de quem chama.
const FORWARDED_CLIENT_HEADERS = ["x-forwarded-for", "x-forwarded-proto"];

// O que volta para o navegador. `content-disposition` e os dois de segurança
// entram porque os anexos privados (comprovante, proposta, foto de refeição)
// dependem deles para não serem interpretados como HTML.
const RESPONSE_HEADERS = [
  "content-type",
  "content-disposition",
  "content-security-policy",
  "x-content-type-options",
];

// A Evolution entrega os webhooks pela rede interna do compose. Expor o caminho
// aqui só daria à internet uma rota fora do auth:api para tentar o token.
const BLOCKED_PATHS = [/^whatsapp\/webhook(\/|$)/];

/**
 * `new URL(base + "/" + segmentos)` resolve ".." como o navegador resolveria —
 * "/api/proxy/../../up" sai do /api e bate em outra rota do backend, levando o
 * Bearer da pessoa junto. A URL também desfaz "%2e%2e", então não basta olhar o
 * texto: os segmentos são recusados quando são travessia e reencodados depois,
 * para que qualquer resto codificado chegue ao backend como nome literal.
 */
function isTraversal(segment: string) {
  return segment.length === 0 || segment === "." || segment === "..";
}

async function handleProxy(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> },
) {
  const { path } = await context.params;

  if (path.some(isTraversal)) {
    return NextResponse.json({ message: "Rota invalida." }, { status: 400 });
  }

  const upstreamPath = path.map(encodeURIComponent).join("/");

  if (BLOCKED_PATHS.some((blocked) => blocked.test(upstreamPath))) {
    return NextResponse.json({ message: "Rota nao encontrada." }, { status: 404 });
  }

  const upstreamUrl = new URL(`${BACKEND_API_URL}/${upstreamPath}`);

  upstreamUrl.search = request.nextUrl.search;

  const headers = new Headers();

  [...FORWARDED_HEADERS, ...FORWARDED_CLIENT_HEADERS].forEach((headerName) => {
    const headerValue = request.headers.get(headerName);

    if (headerValue) {
      headers.set(headerName, headerValue);
    }
  });

  const token = request.cookies.get(AUTH_COOKIE_NAME)?.value;

  if (token) {
    headers.set("Authorization", `Bearer ${token}`);
  }

  const init: RequestInit = {
    method: request.method,
    headers,
    cache: "no-store",
  };

  if (!["GET", "HEAD"].includes(request.method)) {
    const body = await request.arrayBuffer();

    if (body.byteLength > 0) {
      init.body = body;
    }
  }

  try {
    const upstreamResponse = await fetch(upstreamUrl, init);
    const responseHeaders = new Headers();

    RESPONSE_HEADERS.forEach((headerName) => {
      const headerValue = upstreamResponse.headers.get(headerName);

      if (headerValue) {
        responseHeaders.set(headerName, headerValue);
      }
    });

    return new NextResponse(await upstreamResponse.arrayBuffer(), {
      status: upstreamResponse.status,
      headers: responseHeaders,
    });
  } catch {
    return NextResponse.json(
      { message: "Nao foi possivel conectar a API." },
      { status: 502 },
    );
  }
}

export const GET = handleProxy;
export const POST = handleProxy;
export const PUT = handleProxy;
export const PATCH = handleProxy;
export const DELETE = handleProxy;

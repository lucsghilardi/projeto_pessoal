"""
Sidecar do Garmin Connect.

Expõe só o que o Laravel precisa, já com os campos curados — o PHP fica burro
de propósito. Nada aqui é publicado para fora da rede interna do compose.

Autenticação: usa o token store da lib `garminconnect` (um diretório com
`garmin_tokens.json`). A lib renova o token sozinha e **rotaciona o refresh
token**, regravando o arquivo. Por isso este processo tem que ser o único
escrevendo nesse diretório — daí `--workers 1` e um único cliente global.

E-mail/senha nunca entram aqui: o login exige MFA, que não dá para responder
por HTTP. Se os tokens morrerem, `/health` acusa e a recuperação é manual:

    docker compose run --rm -it garmin python auth_cli.py
"""

import base64
import binascii
import logging
import os
from pathlib import Path
from typing import Annotated, Any

from fastapi import Depends, FastAPI, Header, HTTPException, Query
from garminconnect import (
    Garmin,
    GarminConnectAuthenticationError,
    GarminConnectConnectionError,
    GarminConnectTooManyRequestsError,
)

logger = logging.getLogger("garmin-sidecar")
logging.basicConfig(level=logging.INFO)

TOKENSTORE = os.getenv("GARMINTOKENS", "/tokens")
SIDECAR_TOKEN = os.getenv("GARMIN_SIDECAR_TOKEN", "")
TOKENS_BASE64 = os.getenv("GARMIN_TOKENS_BASE64", "")

app = FastAPI(title="Garmin sidecar", docs_url=None, redoc_url=None)

# Cliente único do processo. `None` enquanto os tokens não valem.
_client: Garmin | None = None
_erro_login: str | None = None


def _semear_tokens() -> None:
    """
    Em produção o volume nasce vazio: escreve o token store a partir do
    GARMIN_TOKENS_BASE64 do .env, se o arquivo ainda não existir. Nunca
    sobrescreve o que já está lá — o arquivo em disco é mais novo, porque a
    renovação regrava nele.
    """
    destino = Path(TOKENSTORE) / "garmin_tokens.json"

    if destino.exists() or not TOKENS_BASE64:
        return

    try:
        conteudo = base64.b64decode(TOKENS_BASE64)
    except (binascii.Error, ValueError):
        logger.error("GARMIN_TOKENS_BASE64 não é base64 válido — ignorando.")
        return

    destino.parent.mkdir(parents=True, exist_ok=True)
    destino.write_bytes(conteudo)
    logger.info("Token store semeado a partir de GARMIN_TOKENS_BASE64.")


def _login() -> None:
    global _client, _erro_login

    try:
        cliente = Garmin()
        # login(tokenstore) é o que seta o caminho interno de gravação — sem
        # ele a lib renova o token em memória e perde na reinicialização.
        cliente.login(TOKENSTORE)
        _client = cliente
        _erro_login = None
        logger.info("Autenticado no Garmin Connect.")
    except Exception as erro:  # a lib levanta tipos variados no login
        _client = None
        _erro_login = str(erro)
        logger.warning("Falha ao autenticar no Garmin: %s", erro)


@app.on_event("startup")
def startup() -> None:
    _semear_tokens()
    _login()


def exigir_token(x_garmin_token: Annotated[str | None, Header()] = None) -> None:
    if not SIDECAR_TOKEN or x_garmin_token != SIDECAR_TOKEN:
        raise HTTPException(status_code=401, detail={"erro": "token_invalido"})


def cliente() -> Garmin:
    """Uma tentativa de re-login antes de desistir (container subiu sem rede)."""
    if _client is None:
        _login()

    if _client is None:
        raise HTTPException(
            status_code=401,
            detail={"erro": "tokens_invalidos", "detalhe": _erro_login or "sem token store"},
        )

    return _client


def _chamar(fn, *args) -> Any:
    """Traduz as falhas da lib para os status que o GarminService entende."""
    try:
        return fn(*args)
    except GarminConnectTooManyRequestsError as erro:
        raise HTTPException(status_code=503, detail={"erro": "rate_limited", "detalhe": str(erro)})
    except GarminConnectAuthenticationError as erro:
        global _client
        _client = None
        raise HTTPException(status_code=401, detail={"erro": "tokens_invalidos", "detalhe": str(erro)})
    except GarminConnectConnectionError as erro:
        raise HTTPException(status_code=502, detail={"erro": "garmin_indisponivel", "detalhe": str(erro)})
    except Exception as erro:
        raise HTTPException(status_code=502, detail={"erro": "garmin_indisponivel", "detalhe": str(erro)})


@app.get("/health")
def health() -> dict:
    """Não exige o token do sidecar: é o healthcheck do compose."""
    if _client is None:
        return {"ok": True, "autenticado": False, "erro": "tokens_invalidos", "detalhe": _erro_login}

    try:
        nome = _client.get_full_name()
    except Exception as erro:
        return {"ok": True, "autenticado": False, "erro": "tokens_invalidos", "detalhe": str(erro)}

    return {"ok": True, "autenticado": True, "nome": nome}


@app.get("/atividades", dependencies=[Depends(exigir_token)])
def atividades(
    de: Annotated[str, Query(pattern=r"^\d{4}-\d{2}-\d{2}$")],
    ate: Annotated[str, Query(pattern=r"^\d{4}-\d{2}-\d{2}$")],
) -> dict:
    brutas = _chamar(cliente().get_activities_by_date, de, ate) or []

    return {
        "atividades": [
            {
                "id": a.get("activityId"),
                "nome": a.get("activityName"),
                "tipo": (a.get("activityType") or {}).get("typeKey"),
                "inicio_local": a.get("startTimeLocal"),
                "duracao_seg": a.get("duration"),
                "distancia_m": a.get("distance"),
                "calorias": a.get("calories"),
                "fc_media": a.get("averageHR"),
                "fc_maxima": a.get("maxHR"),
            }
            for a in brutas
        ]
    }


@app.get("/dia", dependencies=[Depends(exigir_token)])
def dia(data: Annotated[str, Query(pattern=r"^\d{4}-\d{2}-\d{2}$")]) -> dict:
    """
    Resumo do dia. `calorias_ativas` cobre o dia inteiro, inclusive a caminhada
    que não virou atividade registrada — é por isso que ele vale mais que somar
    sessão a sessão no cálculo de gasto.
    """
    stats = _chamar(cliente().get_stats, data) or {}

    moderados = stats.get("moderateIntensityMinutes") or 0
    vigorosos = stats.get("vigorousIntensityMinutes") or 0

    return {
        "data": stats.get("calendarDate") or data,
        "passos": stats.get("totalSteps"),
        "calorias_ativas": stats.get("activeKilocalories"),
        "calorias_totais": stats.get("totalKilocalories"),
        "fc_repouso": stats.get("restingHeartRate"),
        # O Garmin conta o vigoroso em dobro na meta semanal de 150 min.
        "minutos_intensidade": moderados + vigorosos * 2,
    }

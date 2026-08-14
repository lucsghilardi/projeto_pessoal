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
from datetime import datetime, timezone
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


def _cadencia(fonte: dict) -> Any:
    """Corrida reporta `averageRunCadence`; bike, `averageBikeCadence`."""
    return fonte.get("averageRunCadence") or fonte.get("averageBikeCadence")


@app.get("/atividade", dependencies=[Depends(exigir_token)])
def atividade(id: Annotated[int, Query(ge=1)]) -> dict:
    """
    Detalhe de UMA atividade: splits por km, tempo em cada zona de FC e o resumo
    completo que o `/atividades` não carrega.

    São três chamadas ao Garmin por atividade, então isto é **sob demanda** — o
    Laravel chama quando alguém abre a corrida no painel e guarda o resultado.
    Puxar isso no sync horário multiplicaria as requisições pela janela inteira
    e derrubaria tudo no rate-limit.

    `duracao_seg` e `distancia_m` vêm daqui com a precisão original: a coluna
    `duracao_min` do banco é arredondada para minuto inteiro, o que já basta
    para o resumo semanal mas estraga o cálculo de pace.
    """
    bruto = _chamar(cliente().get_activity, id) or {}
    resumo = bruto.get("summaryDTO") or {}

    splits = _chamar(cliente().get_activity_splits, id) or {}
    voltas = splits.get("lapDTOs") or []

    # A rota devolve lista, apesar do type hint da lib dizer dict.
    zonas = _chamar(cliente().get_activity_hr_in_timezones, id) or []
    if isinstance(zonas, dict):
        zonas = zonas.get("hrTimeInZones") or []

    return {
        "id": bruto.get("activityId") or id,
        "nome": bruto.get("activityName"),
        "tipo": (bruto.get("activityTypeDTO") or {}).get("typeKey"),
        "inicio_local": resumo.get("startTimeLocal"),
        "duracao_seg": resumo.get("duration"),
        "tempo_movimento_seg": resumo.get("movingDuration"),
        "distancia_m": resumo.get("distance"),
        "velocidade_media_mps": resumo.get("averageSpeed"),
        "velocidade_max_mps": resumo.get("maxSpeed"),
        "fc_media": resumo.get("averageHR"),
        "fc_maxima": resumo.get("maxHR"),
        "fc_minima": resumo.get("minHR"),
        "calorias": resumo.get("calories"),
        "cadencia_media": _cadencia(resumo),
        "cadencia_maxima": resumo.get("maxRunCadence"),
        # O Garmin manda a passada em centímetros.
        "passada_media_cm": resumo.get("strideLength"),
        "tempo_solo_ms": resumo.get("groundContactTime"),
        "oscilacao_vertical_cm": resumo.get("verticalOscillation"),
        "passos": resumo.get("steps"),
        "potencia_media": resumo.get("averagePower"),
        "potencia_normalizada": resumo.get("normalizedPower"),
        "training_effect_aerobico": resumo.get("trainingEffect"),
        "training_effect_anaerobico": resumo.get("anaerobicTrainingEffect"),
        "carga_treino": resumo.get("activityTrainingLoad"),
        "vo2max": resumo.get("vO2MaxValue"),
        "elevacao_ganho_m": resumo.get("elevationGain"),
        "elevacao_perda_m": resumo.get("elevationLoss"),
        "fc_recuperacao": resumo.get("recoveryHeartRate"),
        "splits": [
            {
                "numero": volta.get("lapIndex"),
                "distancia_m": volta.get("distance"),
                "duracao_seg": volta.get("duration"),
                "tempo_movimento_seg": volta.get("movingDuration"),
                "velocidade_media_mps": volta.get("averageSpeed"),
                "velocidade_max_mps": volta.get("maxSpeed"),
                "fc_media": volta.get("averageHR"),
                "fc_maxima": volta.get("maxHR"),
                "cadencia": _cadencia(volta),
                "potencia": volta.get("averagePower"),
                "calorias": volta.get("calories"),
                "elevacao_ganho_m": volta.get("elevationGain"),
                "elevacao_perda_m": volta.get("elevationLoss"),
            }
            for volta in voltas
        ],
        "zonas_fc": [
            {
                "zona": zona.get("zoneNumber"),
                "segundos": zona.get("secsInZone"),
                "fc_minima": zona.get("zoneLowBoundary"),
            }
            for zona in zonas
        ],
    }


@app.get("/perfil", dependencies=[Depends(exigir_token)])
def perfil() -> dict:
    """
    Os números que o próprio Garmin calcula sobre a forma física: VO2max, limiar
    de lactato e previsões de prova. É a base do comparativo do painel, e muda
    devagar — o Laravel atualiza junto com o sync normal.

    As previsões falham sozinhas em conta nova (sem histórico o Garmin não
    prediz nada), então elas não podem derrubar o resto do payload.
    """
    dados = _chamar(cliente().get_user_profile) or {}
    usuario = dados.get("userData") or {}

    try:
        previsoes = cliente().get_race_predictions() or {}
    except Exception as erro:
        logger.info("Sem previsões de prova: %s", erro)
        previsoes = {}

    return {
        "sexo": usuario.get("gender"),
        "nascimento": usuario.get("birthDate"),
        # O Garmin guarda o peso em gramas.
        "peso_g": usuario.get("weight"),
        "altura_cm": usuario.get("height"),
        "vo2max": usuario.get("vo2MaxRunning"),
        "fc_limiar": usuario.get("lactateThresholdHeartRate"),
        "velocidade_limiar_mps": usuario.get("lactateThresholdSpeed"),
        "previsoes": {
            "data": previsoes.get("calendarDate"),
            "seg_5k": previsoes.get("time5K"),
            "seg_10k": previsoes.get("time10K"),
            "seg_21k": previsoes.get("timeHalfMarathon"),
            "seg_42k": previsoes.get("timeMarathon"),
        },
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


def _hora_local(epoch_ms: Any) -> str | None:
    """
    Os campos `*TimestampLocal` já vêm deslocados para o fuso do relógio, então
    lê-los como UTC devolve a hora de parede certa ("00:35"). Usar o timestamp
    GMT aqui daria a hora errada por 3h.
    """
    if not isinstance(epoch_ms, (int, float)):
        return None

    return datetime.fromtimestamp(epoch_ms / 1000, tz=timezone.utc).strftime("%H:%M")


@app.get("/sono", dependencies=[Depends(exigir_token)])
def sono(data: Annotated[str, Query(pattern=r"^\d{4}-\d{2}-\d{2}$")]) -> dict:
    """
    A noite que TERMINOU nesta data — é assim que o Garmin indexa o sono: quem
    dormiu 23h do dia 7 e acordou 6h do dia 8 aparece em `data=2026-08-08`.

    O payload cru passa de 50 KB (série temporal de movimento, respiração e
    estágios minuto a minuto). Nada disso interessa ao painel, então só o
    resumo do `dailySleepDTO` atravessa para o Laravel.
    """
    bruto = _chamar(cliente().get_sleep_data, data) or {}
    dto = bruto.get("dailySleepDTO") or {}
    overall = (dto.get("sleepScores") or {}).get("overall") or {}

    return {
        "data": dto.get("calendarDate") or data,
        # None quando a noite não foi medida — o Laravel usa isto para pular o dia.
        "duracao_seg": dto.get("sleepTimeSeconds"),
        "profundo_seg": dto.get("deepSleepSeconds"),
        "leve_seg": dto.get("lightSleepSeconds"),
        "rem_seg": dto.get("remSleepSeconds"),
        "acordado_seg": dto.get("awakeSleepSeconds"),
        "cochilo_seg": dto.get("napTimeSeconds"),
        "score": overall.get("value"),
        # "POOR" | "FAIR" | "GOOD" | "EXCELLENT"
        "score_qualificador": overall.get("qualifierKey"),
        # `awakeCount` some em alguns firmwares; `restlessMomentsCount` cobre.
        "despertares": dto.get("awakeCount", bruto.get("restlessMomentsCount")),
        "estresse_medio": dto.get("avgSleepStress"),
        # Este vive na raiz do payload, não no DTO.
        "hrv_medio": bruto.get("avgOvernightHrv"),
        "inicio_local": _hora_local(dto.get("sleepStartTimestampLocal")),
        "fim_local": _hora_local(dto.get("sleepEndTimestampLocal")),
    }

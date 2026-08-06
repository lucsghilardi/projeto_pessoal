# Sidecar do Garmin Connect: o Laravel fala com ele pela rede interna do
# compose (http://garmin:8000). Existe porque não há cliente PHP mantido para
# a API do Garmin — ver garmin/README.md.
FROM python:3.11-slim

WORKDIR /app

RUN pip install --no-cache-dir --upgrade pip

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# GARMINTOKENS aponta para o volume; a lib grava ali o token renovado.
ENV GARMINTOKENS=/tokens \
    PYTHONUNBUFFERED=1

EXPOSE 8000

# --workers 1 é obrigatório: o refresh token é rotacionado a cada renovação,
# então dois processos com o mesmo token store derrubam um ao outro.
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "1"]

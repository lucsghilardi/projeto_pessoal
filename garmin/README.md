# Sidecar do Garmin Connect

Serviço Python que o Laravel consome pela rede interna do compose
(`http://garmin:8000`) para importar atividades do Garmin Connect no módulo
Saúde.

## Por que existe um serviço Python aqui

A API do Garmin Connect usada aqui **não é oficial**. Não há cliente PHP
mantido para ela, e a autenticação envolve rotação de token e TLS
impersonation no login — coisas que a lib `garminconnect` já resolve. O sidecar
isola isso: o PHP só faz HTTP e recebe os campos já curados.

A alternativa oficial (Garmin Developer Program, com webhooks push) é voltada a
empresas e tem aprovação de prazo indeterminado. Se um dia o sidecar quebrar
sem conserto, o plano B é uma ponte via Strava — a coluna `origem` das tabelas
já prevê outras fontes.

## Tokens — a única parte delicada

O login grava um *token store* (`garmin_tokens.json`) no volume montado em
`/tokens`. A lib renova o token sozinha **e rotaciona o refresh token**,
regravando o arquivo.

Duas consequências que não dá para contornar com retry:

1. **Um processo por token store.** Daí `uvicorn --workers 1` e um único
   cliente global em `app.py`. Se dois processos carregarem o mesmo arquivo, o
   primeiro que renovar invalida a cópia do outro.
2. **Não compartilhe o token store com outro cliente** (por exemplo, um MCP do
   Garmin rodando na sua máquina). Cada um precisa da própria sessão — o Garmin
   aceita sessões paralelas. Para separar, aponte o outro cliente para outro
   diretório, por exemplo `GARMINTOKENS=~/.garminconnect_mcp`.

E-mail e senha **nunca** entram no container: o login pede MFA, que não se
responde por HTTP, e a lib travaria num `input()` sem TTY.

### Primeiro login / recuperação

Quando `/health` responder `autenticado: false`:

```bash
docker compose run --rm -it garmin python auth_cli.py
docker compose restart garmin
```

Em produção dá para semear o volume sem login interativo, colocando no `.env`
do Laravel o conteúdo do token store em base64 (`GARMIN_TOKENS_BASE64`):

```bash
base64 -i ~/.garminconnect/garmin_tokens.json
```

O `app.py` só escreve esse conteúdo se o arquivo ainda não existir — o que está
no volume é sempre mais novo, porque a renovação regrava nele.

## Endpoints

Todos exigem o header `X-Garmin-Token` (igual a `GARMIN_SIDECAR_TOKEN`), menos
`/health`.

| Rota | Devolve |
|---|---|
| `GET /health` | `{ok, autenticado, nome}` |
| `GET /atividades?de=&ate=` | lista de atividades curadas (id, tipo, início, duração, distância, calorias, FC) |
| `GET /dia?data=` | passos, calorias ativas/totais, FC de repouso, minutos de intensidade |

Erros: `401 tokens_invalidos` · `503 rate_limited` · `502 garmin_indisponivel`.
O `GarminService` do Laravel traduz cada um em mensagem acionável.

## Variáveis

Ficam no `.env` do Laravel (o compose usa `env_file`) — ver `.env.example`:
`GARMIN_ATIVO`, `GARMIN_SIDECAR_URL`, `GARMIN_SIDECAR_TOKEN`,
`GARMIN_USER_EMAIL`, `GARMIN_DIAS_JANELA`, `GARMIN_TOKENS_BASE64`.

## Atualizar a lib

`requirements.txt` tem versões fixas de propósito. Subir a versão de
`garminconnect` é decisão consciente: teste `/health` e `/atividades` logo
depois, porque a API do outro lado muda sem aviso.

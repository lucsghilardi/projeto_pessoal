"""
Login interativo no Garmin Connect, para recuperar o token store.

Só é necessário quando `/health` responde `autenticado: false` — os tokens
renovam sozinhos enquanto o sidecar estiver rodando. Precisa de TTY porque o
Garmin pede o código MFA:

    docker compose run --rm -it garmin python auth_cli.py

As credenciais são digitadas na hora e não ficam em lugar nenhum: o que é
gravado no volume é só o token store.
"""

import os
import sys
from getpass import getpass

from garminconnect import Garmin

TOKENSTORE = os.getenv("GARMINTOKENS", "/tokens")


def pedir_mfa() -> str:
    print("\nO Garmin pediu verificação em duas etapas — confira o e-mail/SMS.")
    return input("Código MFA: ").strip()


def main() -> int:
    print(f"Login no Garmin Connect. Os tokens vão para {TOKENSTORE}.\n")

    email = input("E-mail: ").strip()
    senha = getpass("Senha: ")

    if not email or not senha:
        print("E-mail e senha são obrigatórios.", file=sys.stderr)
        return 1

    try:
        # prompt_mfa é chamado pela própria lib quando o Garmin pede o código.
        garmin = Garmin(email=email, password=senha, prompt_mfa=pedir_mfa)
        garmin.login()
        garmin.client.dump(TOKENSTORE)
    except Exception as erro:
        print(f"\nFalhou: {erro}", file=sys.stderr)
        return 1

    print(f"\nOK — tokens gravados em {TOKENSTORE}. Reinicie o serviço:")
    print("  docker compose restart garmin")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())

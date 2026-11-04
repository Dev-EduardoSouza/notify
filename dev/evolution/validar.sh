#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

set -a
. ./.env
set +a

BASE="${EVOLUTION_BASE:-http://localhost:8080}"
INSTANCIA="${EVOLUTION_INSTANCE:-notify-teste}"
DESTINO="${TESTE_NUMERO:-}"
CHAVE="$AUTHENTICATION_API_KEY"

chamar() {
  curl -s -w "\nHTTP %{http_code}\n" -H "apikey: $CHAVE" -H "Content-Type: application/json" "$@"
}

exigir_destino() {
  if [ -z "$DESTINO" ]; then
    echo "Defina TESTE_NUMERO em dev/evolution/.env (DDI+DDD+número, só dígitos)" >&2
    exit 1
  fi
}

case "${1:-}" in
  criar)
    chamar -X POST "$BASE/instance/create" \
      -d "{\"instanceName\":\"$INSTANCIA\",\"qrcode\":true,\"integration\":\"WHATSAPP-BAILEYS\"}" \
      | sed -E 's/"base64":"[^"]*"/"base64":"..."/; s/"code":"[^"]*"/"code":"..."/'
    ;;
  qr)
    chamar "$BASE/instance/connect/$INSTANCIA" > qr.json
    python3 - <<'PY'
import base64, json, re
corpo = open("qr.json").read().split("\nHTTP")[0]
dados = json.loads(corpo)
imagem = dados.get("base64")
if not imagem:
    print("sem QR na resposta:", corpo[:300])
    raise SystemExit(1)
open("qr.png", "wb").write(base64.b64decode(re.sub(r"^data:image/png;base64,", "", imagem)))
print("QR salvo em dev/evolution/qr.png; código de pareamento:", dados.get("pairingCode"))
PY
    rm -f qr.json
    ;;
  estado)
    chamar "$BASE/instance/connectionState/$INSTANCIA"
    ;;
  enviar)
    exigir_destino
    chamar -X POST "$BASE/message/sendText/$INSTANCIA" \
      -d "{\"number\":\"$DESTINO\",\"text\":\"[validação notify] mensagem de teste $(date +%H:%M:%S)\"}"
    ;;
  enviar-invalido)
    chamar -X POST "$BASE/message/sendText/$INSTANCIA" \
      -d '{"number":"123","text":"[validação notify] número inválido"}'
    ;;
  apagar)
    chamar -X DELETE "$BASE/instance/delete/$INSTANCIA"
    ;;
  *)
    echo "uso: validar.sh criar|qr|estado|enviar|enviar-invalido|apagar" >&2
    exit 2
    ;;
esac

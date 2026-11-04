# notify

Serviço HTTP que entrega mensagens de texto em nome de outros apps. Um app chama `POST /v1/messages` com uma chave de API, e o notify entrega a mensagem no Telegram ou no WhatsApp.

No Telegram a entrega é imediata, sem fila e sem guardar o texto. No WhatsApp, pela Evolution API, a mensagem entra numa fila no Postgres e um worker a envia com pausa crescente entre os envios, para reduzir o risco de banimento do número. O texto sai do banco assim que a mensagem é enviada ou falha de vez.

Cada app recebe uma chave presa a uma lista de canais e destinatários, e o `.env` guarda só o hash sha256. Os apps enviam um apelido (`"to": "eduardo"`) e nunca veem o chat id nem o número. O log tem uma linha JSON por requisição, sem texto, token, chave nem chat id.

Laravel 13 e PHP 8.3. Roda em hospedagem compartilhada só com o Telegram. O WhatsApp precisa de um servidor com Postgres e de um processo sempre ativo, como um VPS.

## Sumário

1. [Instalação local](#instalação-local)
2. [Telegram](#telegram)
3. [WhatsApp](#whatsapp)
4. [Chaves de API](#chaves-de-api)
5. [Contrato da API](#contrato-da-api)
6. [Deploy](#deploy)
7. [Logs](#logs)
8. [Desenvolvimento](#desenvolvimento)

## Instalação local

Requisitos: PHP 8.3 ou mais novo com as extensões `curl`, `json`, `mbstring` e `openssl`, e Composer 2. Para o WhatsApp, a extensão `pdo_pgsql`.

```bash
git clone https://github.com/Dev-EduardoSouza/notify.git
cd notify
composer install
cp .env.example .env
php artisan key:generate
```

Para rodar na sua máquina, ajuste estas linhas do `.env`:

```dotenv
APP_ENV=local
APP_URL=http://localhost:8000
```

Preencha `TELEGRAM_BOT_TOKEN` e `TELEGRAM_RECIPIENTS` ([Telegram](#telegram)) e `API_KEYS` ([Chaves de API](#chaves-de-api)). Depois confira o ambiente:

```bash
php artisan diagnose
```

O comando checa a versão do PHP, as extensões, as pastas graváveis, as variáveis obrigatórias e o acesso HTTPS ao Telegram. Ele precisa terminar com `All checks passed.`. Use `--offline` para pular o teste de rede.

```bash
php artisan serve
curl http://localhost:8000/health
```

A resposta esperada é `{"status":"ok"}`.

## Telegram

1. No Telegram, abra uma conversa com o @BotFather e mande `/newbot`.
2. Escolha o nome e o usuário do bot. O BotFather responde com o token (`123456789:AA...`). Coloque em `TELEGRAM_BOT_TOKEN`.
3. Abra a conversa com o seu bot e mande qualquer mensagem. O bot só escreve para quem já falou com ele.
4. Descubra o chat id:

   ```bash
   curl "https://api.telegram.org/bot<TOKEN>/getUpdates"
   ```

   O número está em `result[].message.chat.id`. Em grupos ele começa com `-`.
5. Registre o destinatário em `TELEGRAM_RECIPIENTS`, no formato `apelido:chat_id`, separando vários por vírgula:

   ```dotenv
   TELEGRAM_RECIPIENTS=eduardo:123456789,equipe:-1001234567890
   ```

Um apelido tem de 1 a 64 caracteres entre letras minúsculas, dígitos, `_` e `-`, e começa com letra ou dígito.

## WhatsApp

O canal usa a [Evolution API](https://github.com/evolution-foundation/evolution-api), que é não oficial: o WhatsApp pode banir o número que envia. Use um chip dedicado a este serviço, nunca o número pessoal, e mantenha o volume baixo.

### Evolution local

A pasta `dev/evolution/` traz um `docker-compose.yml` com a Evolution `v2.3.7`, o Postgres e o Redis dela, na rede `lerd` do Podman. No host, as portas ficam só em `127.0.0.1` (8080 e 5433).

```bash
cd dev/evolution
cp .env.example .env              # troque a senha do Postgres, a chave e o DATABASE_CONNECTION_URI
systemctl --user start podman.socket
podman compose up -d
```

Com Docker no lugar do Podman, use `docker compose` e tire a rede `lerd` do compose.

Para parear o chip, abra `http://localhost:8080/manager`, informe a chave `AUTHENTICATION_API_KEY`, entre na instância e leia o QR no menu Aparelhos conectados do WhatsApp. O script `dev/evolution/validar.sh` faz o mesmo pela linha de comando:

```bash
dev/evolution/validar.sh criar    # cria a instância notify-teste
dev/evolution/validar.sh qr       # grava o QR em dev/evolution/qr.png (expira em cerca de 1 minuto)
dev/evolution/validar.sh estado   # "open" quando o chip está conectado
dev/evolution/validar.sh enviar   # texto de teste para TESTE_NUMERO, definido em dev/evolution/.env
```

### Banco da fila

A fila usa Postgres. Crie um usuário e um banco para o notify e aponte o `.env` para ele:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=notify
DB_USERNAME=notify
DB_PASSWORD=<senha>
```

Depois rode `php artisan migrate`. A tabela `whatsapp_outbox` guarda cada mensagem com `status` (`queued`, `sending`, `sent` ou `failed`), o número de tentativas e a hora da próxima tentativa. A tabela `whatsapp_pacing` guarda o contador que define a pausa entre envios.

### Variáveis do canal

```dotenv
WHATSAPP_API_BASE=http://localhost:8080
WHATSAPP_API_KEY=<chave da Evolution>
WHATSAPP_INSTANCE=notify-teste
WHATSAPP_RECIPIENTS=eduardo:5500000000000
WHATSAPP_IDLE_RESET_MINUTES=30
WHATSAPP_MAX_ATTEMPTS=5
```

Os destinatários seguem o formato `apelido:número`, com DDI, DDD e número só com dígitos.

### Worker

A API só grava a mensagem na fila e responde `202`. Quem envia é o comando:

```bash
php artisan whatsapp:work
```

Ele roda em laço, pega a mensagem `queued` mais antiga com a hora de tentativa vencida e a envia pela Evolution. Use `--once` para sair quando não houver mais nada vencido.

### Pausa entre envios

Depois de cada envio o worker espera `min(90, 3 × 2^(n ÷ 40))` segundos, onde `n` é o número de mensagens enviadas na sequência:

| Mensagens na sequência | Pausa |
|---|---|
| 0 a 39 | 3 s |
| 40 a 79 | 6 s |
| 80 a 119 | 12 s |
| 120 a 159 | 24 s |
| 160 a 199 | 48 s |
| 200 ou mais | 90 s |

Depois de `WHATSAPP_IDLE_RESET_MINUTES` minutos sem envio, a sequência volta a zero.

### Falhas

A Evolution respondendo `429` ou `5xx`, ou sem responder, é tentada de novo. Os outros erros `4xx`, como número que não existe no WhatsApp, também contam como tentativa. A espera cresce a cada falha, `min(90, 3 × 2^(tentativa-1))` segundos (3, 6, 12, 24 e 48), e na `WHATSAPP_MAX_ATTEMPTS`-ésima falha a mensagem vira `failed`. O texto é apagado do banco quando a mensagem vira `sent` ou `failed`, e `last_error` nunca contém o número nem a chave.

### Reinício

Ao iniciar, o worker devolve à fila as mensagens que ficaram em `sending` por uma queda. Se a queda ocorrer depois do envio e antes do registro, essa mensagem pode sair duas vezes.

### Um worker por vez

Cada processo conta o próprio ritmo. Dois workers juntos enviam mais rápido do que a pausa permite e aumentam o risco de banimento. Em produção, mantenha um único worker sempre ativo com `systemd` ou `supervisor`.

## Chaves de API

Cada app cliente tem uma chave. A chave em si fica só com o app, e o `.env` guarda o hash dela, junto com os canais e destinatários que ela pode usar.

```bash
php artisan keys:generate meu-app --channels=telegram --to=eduardo
```

- `meu-app` é o nome da chave: de 1 a 64 caracteres entre letras, dígitos, `.`, `_` e `-`. Ele aparece nos logs.
- `--channels` e `--to` aceitam listas separadas por vírgula. Os destinatários precisam existir na lista do canal (`TELEGRAM_RECIPIENTS` ou `WHATSAPP_RECIPIENTS`).

A saída tem duas linhas:

```
key: ntf_3q2-7wEXAMPLE...
env: API_KEYS="app-antigo|9f86d0...|telegram|eduardo;meu-app|5e8848...|telegram|eduardo"
```

1. Entregue a linha `key:` ao app cliente. Ela não fica salva em lugar nenhum, então gere outra se perder.
2. Troque a linha `API_KEYS` do `.env` pela linha `env:`, que já traz as chaves anteriores mais a nova.
3. Em produção, rode `php artisan config:cache`.

Cada entrada de `API_KEYS` segue o formato `nome|sha256|canais|destinatários`, separadas por `;`. Para revogar uma chave, apague a entrada dela e rode `config:cache` de novo.

## Contrato da API

A especificação OpenAPI está em [`openapi.yaml`](openapi.yaml). Dá para abri-la no Swagger Editor ou usá-la para gerar um cliente.

| Método | Rota | Autenticação |
|---|---|---|
| `GET` | `/health` | nenhuma |
| `POST` | `/v1/messages` | `Authorization: Bearer <chave>` |

`GET /health` responde `200 {"status":"ok"}` enquanto o app estiver de pé.

### `POST /v1/messages`

Corpo JSON com exatamente três campos:

| Campo | Tipo | Regra |
|---|---|---|
| `channel` | string | canal permitido para a chave: `telegram` ou `whatsapp` |
| `to` | string | apelido do destinatário, permitido para a chave |
| `text` | string | até 20000 caracteres, texto puro |

Campos a mais são recusados. No Telegram, textos longos são divididos em partes de até 3500 caracteres, de preferência em quebra de linha ou espaço. No WhatsApp o texto vai inteiro, numa única mensagem.

No Telegram, o `200` só volta depois que todas as partes foram entregues.

```http
HTTP/1.1 200 OK

{"status":"sent","parts":1}
```

No WhatsApp, a resposta é `202`, porque a mensagem só foi enfileirada. O `id` identifica a linha na fila.

```http
HTTP/1.1 202 Accepted

{"status":"queued","id":123}
```

Exemplo:

```bash
BASE=http://localhost:8000
KEY=ntf_...                         # chave gerada com keys:generate

curl -s "$BASE/v1/messages" \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{"channel":"telegram","to":"eduardo","text":"Olá pelo notify"}'
```

### Erros

Todo erro volta em JSON com `code` e `message`:

```json
{"code":"recipient_not_allowed","message":"This API key is not allowed to send to this recipient."}
```

| Status | `code` | Quando |
|---|---|---|
| 400 | `bad_request` | corpo sem `Content-Type: application/json`, JSON inválido ou que não seja um objeto |
| 401 | `unauthorized` | header `Authorization` ausente ou chave desconhecida |
| 403 | `channel_not_allowed` | a chave não tem permissão para o canal |
| 403 | `recipient_not_allowed` | a chave não tem permissão para o destinatário |
| 413 | `payload_too_large` | corpo maior que `MAX_BODY_BYTES` (padrão 256 KB) |
| 422 | `validation_failed` | campo faltando, campo a mais, tipo errado, apelido fora do formato ou texto acima de 20000 caracteres; o detalhe vem em `errors` |
| 422 | `unknown_channel` | a chave permite o canal, mas ele não existe no serviço |
| 422 | `unknown_recipient` | a chave permite o apelido, mas ele não está na lista do canal |
| 429 | `rate_limited` | a chave passou de `RATE_LIMIT_PER_MINUTE` (padrão 30) requisições no minuto; o header `Retry-After` diz quantos segundos esperar |
| 502 | `channel_failed` | o Telegram recusou a entrega ou não respondeu, mesmo depois das novas tentativas internas |

As verificações seguem esta ordem: tamanho do corpo, chave, rate limit, formato do JSON, campos, permissão de canal, permissão de destinatário e, por último, a entrega. O WhatsApp não gera `502`, porque a entrega acontece depois da resposta.

## Deploy

### Hospedagem compartilhada (só Telegram)

O layout no servidor separa o código da pasta pública:

```
~/notify/                         código, vendor, .env, storage (fora da web)
~/public_html/notify/             raiz do subdomínio
    index.php                     cópia de public/index.php
    .htaccess                     cópia de public/.htaccess
    app-path.php                  diz ao index.php onde está ~/notify
```

Gere o pacote na sua máquina, com a árvore do git limpa:

```bash
dev/release.sh
```

O script imprime o caminho do `.tar.gz`, feito a partir do último commit, com o `vendor/` de produção e sem `.env`, testes nem a pasta `dev/`. Envie e extraia no servidor:

```bash
scp -P <porta> build/notify-*.tar.gz <usuario>@<host>:~
ssh -p <porta> <usuario>@<host>
```

Na primeira instalação:

```bash
cd ~
tar -xzf notify-*.tar.gz && rm notify-*.tar.gz
cd ~/notify
cp .env.example .env
chmod 600 .env
nano .env                         # TELEGRAM_BOT_TOKEN, TELEGRAM_RECIPIENTS, API_KEYS
php artisan key:generate --force
php artisan diagnose
php artisan config:cache
```

Depois crie o subdomínio apontando para `public_html/notify` e copie os arquivos públicos:

```bash
cd ~/public_html/notify
cp ~/notify/public/index.php ~/notify/public/.htaccess ~/notify/public/robots.txt .
echo "<?php return '$HOME/notify';" > app-path.php
```

Na atualização, a versão nova entra numa pasta limpa e herda o `.env` e o `storage` da anterior.

```bash
cd ~
mv notify notify-old
tar -xzf notify-*.tar.gz && rm notify-*.tar.gz
cp notify-old/.env notify/
cp -a notify-old/storage/. notify/storage/
cd notify && php artisan config:cache
```

Rode `config:cache` sempre que o `.env` mudar. Nunca rode `php artisan migrate` numa hospedagem sem Postgres: as migrations existem só para a fila do WhatsApp.

Validação:

```bash
curl -s https://notify.example.com/health                                   # {"status":"ok"}
curl -s -o /dev/null -w "%{http_code}\n" http://notify.example.com/health   # 301
curl -s -o /dev/null -w "%{http_code}\n" https://notify.example.com/.env    # 403
```

### Servidor com WhatsApp

Precisa de PHP 8.3, Postgres, a Evolution API e um processo sempre ativo para o `php artisan whatsapp:work`. O restante segue [WhatsApp](#whatsapp): banco, `php artisan migrate`, variáveis do canal e um único worker.

## Logs

Os logs ficam em `storage/logs/`, um arquivo por dia, apagados depois de `LOG_DAILY_DAYS` dias (padrão 14). Cada requisição a `/v1/messages` gera uma linha JSON:

```json
{"message":"message request","context":{"key":"meu-app","channel":"telegram","to":"eduardo","status":200,"parts":1},"level_name":"INFO","datetime":"..."}
```

Nos erros, a linha traz o `code` no lugar de `parts`. Nas mensagens do WhatsApp, traz o `queued_id`. O texto, o token do bot, a chave e o chat id nunca entram no log. `channel` e `to` só entram quando têm formato válido.

## Desenvolvimento

```bash
composer test                                   # PHPUnit
vendor/bin/phpstan analyse --memory-limit=1G    # nível 6
vendor/bin/pint                                 # formatação
```

Os testes não fazem chamadas externas: toda requisição HTTP sem `Http::fake()` falha. Eles usam SQLite em memória no lugar do Postgres, então rodam sem nenhum serviço de pé.

Para adicionar um canal, implemente `App\Notifications\Channels\NotificationChannel`, registre a classe em `AppServiceProvider::CHANNELS` e crie a lista de destinatários em `config/notifications.php`.

Se o PHP local roda pelo Lerd, o `artisan serve` fica dentro do container e não é acessível pelo host: use `lerd link notify` e acesse `http://notify.test`. O PHP do Lerd também não recebe variáveis do shell (`VAR=x php ...` não tem efeito), então tudo vai no `.env`. Para interromper o worker nesse ambiente, use `podman exec lerd-php85-fpm pkill -f whatsapp:work`, porque Ctrl+C e `timeout` só encerram o cliente.

Regras do projeto: código sem comentários (só PHPDoc de tipos exigido pelo PHPStan), commits em Conventional Commits em português e sem assinatura de ferramenta.

## Licença

MIT. Veja o arquivo [LICENSE](LICENSE).

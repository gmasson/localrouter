# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).

---

## [0.3] — 2026-08-02

Revisão completa antes da divulgação. A configuração da 0.2 continua funcionando: os recursos removidos estão listados em **Migração** no fim.

### Corrigido

- **Stream cortado pelo provedor era tratado como sucesso.** Uma conexão que fecha limpa no meio retorna `200` ao `curl`, então o app recebia meia resposta achando que era a inteira. Agora o stream só é concluído quando chega o terminador do protocolo (`[DONE]`/`finish_reason` no OpenAI, `message_stop` no Anthropic); sem ele, vira failover.
- **`200` sem conteúdo aproveitável era entregue como resposta.** Free tier devolve `200` com `{"error": ...}` ou `choices` vazio quando a cota acaba; o app recebia `content: null`. Agora conta como falha do provedor e o router passa para o próximo.
- **Objeto JSON vazio virava array.** Ferramenta sem argumentos chegava ao provedor com `"properties": []` (inválido em JSON Schema, recusado por Anthropic, Mistral, Google e vLLM).
- **`RETRY_SAME_PROVIDER` nunca disparava** — a opção existia na configuração e não fazia nada. Agora funciona e virou `1` por padrão.
- **Erro JSON era escrito dentro de um stream SSE já aberto.** Agora, com o stream aberto, a falha vira um evento de erro seguido de `[DONE]`.
- **Erro `400` do provedor colocava ele em quarentena** — um `400` quase sempre vem do que o app mandou. Agora rotaciona sem castigo e, se todos recusarem, volta como `400 invalid_request_error`.
- **`model` e `id` do provedor vazavam no streaming.** O app recebia o id do upstream e o nome completo do modelo, quebrando SDKs que conferem `response.model`. Agora o stream reescreve ambos e usa um único id por requisição, mesmo numa retomada.
- **`n` era repassado ao provedor.** Como o gateway devolve uma escolha só, `n: 3` fazia o provedor gerar e cobrar três respostas para o app receber uma. Saiu de `PASSTHROUGH_OPENAI`.
- **`htmlspecialchars` corrompia a mensagem de erro do provedor** (`max_tokens > 4096` virava `max_tokens &gt; 4096`). Trocado por `strip_tags`.

### Adicionado

- **`POST /embeddings`** com a mesma rotação, cooldown e critério de resposta aproveitável do chat. Só provedores de dialeto `openai` — a API Anthropic não tem endpoint equivalente. (Na 0.3 era configurado em `EMBEDDING_MODELS`; ver "Reorganização de arquivos".)
- **Teto diário por provedor** (`'rpd'` em `PROVIDERS`). Free tier limita por requisições por dia, e o cooldown só reage depois do `429` — com `rpd`, o provedor sai da fila ao bater o limite, antes de gastar a tentativa. Consumo visível em `GET /health/providers`.
- **`TOTAL_DEADLINE_SECONDS`** — teto de tempo da requisição inteira. Sem ele o pior caso era `MAX_ATTEMPTS × REQUEST_TIMEOUT` (doze minutos com os padrões).
- **`STREAM_HEARTBEAT_SECONDS`** — comentário SSE periódico enquanto o provedor está em silêncio, para proxy não derrubar a conexão enquanto o modelo "pensa".
- **`STRATEGY = 'fastest'`** — ordena os provedores pela latência medida nas métricas.
- **Blocos de raciocínio** (`thinking` do Anthropic, `reasoning_content` do OpenAI) traduzidos nos dois sentidos e entregues em `message.reasoning_content`.
- **`CONFIG_ERROR_COOLDOWN`** — quarentena mais longa para erro de configuração do provedor (401/403/404).
- **Rate limit por chave** — era um contador global; um app em loop consumia a cota dos outros.
- **Tokens no log e nas métricas.**
- **`X-Router-Fallback`** quando `MODEL_FALLBACKS` troca o modelo, e **`X-Router-Attempts`** nas respostas de erro.
- **`X-Request-Id` do cliente reaproveitado** quando vem em formato seguro.
- **Provedor que ignora `stream: true`** e devolve JSON normal é convertido em stream.
- **`php index.php test <modelo>`** e **`php index.php sync`** — chamada real que mostra a fila, e conferência dos ids de modelo contra cada provedor.
- **`providers-extra.php.example`** — catálogo com o resto dos provedores, para copiar e colar.
- **Reconexão de cold start por provedor** (`retries`/`retry_delay` em `PROVIDERS`, sobrescrevíveis por entrada de `MODELS`). Servidores serverless que escalam a zero (Modal e similares) derrubam a primeira chamada enquanto o container sobe — conexão recusada, 5xx de proxy ou o 404 `route not found` característico. Com `retries > 0`, o router espera `retry_delay` segundos e reconecta no mesmo host em vez de rotacionar para um provedor mais caro. Padrão `0` (uma tentativa, como sempre foi).

### Alterado

- **Catálogos `PROVIDERS` e `MODELS` movidos para a raiz.** Passaram a viver em `providers.php` e `models.php`, incluídos pelo `config.php` via `require`. Assim chaves e ids de modelo ficam fora do código versionado. Para usar um provedor do catálogo extra, copie a linha de `providers-extra.php.example` e cole em `providers.php`.
- **`EMBEDDING_MODELS` fundido em `MODELS` via campo `type`.** Modelos de embedding vivem no mesmo `models.php`, marcados com `'type' => 'embedding'` (ausente = `'chat'`). O roteamento filtra por `type`: `/chat/completions` só atende `chat`, `/embeddings` só `embedding`. Nova allowlist `MODEL_TYPES` valida os valores.
- **Helper `env()` centraliza o carregamento de chaves.** Lê na ordem: variável de ambiente real do SO/shell/Docker → `data/.env` → `''`. O ambiente real sempre vence; o `.env` é parseado uma vez por processo.
- **`php index.php check` trata provedor sem chave como nota, não erro.** Em runtime (`SKIP_EMPTY_REMOTE_KEY`) o provedor sem chave é pulado e o gateway segue com os demais; só vira erro quando um modelo fica sem **nenhum** provedor viável.
- **A retomada de stream ficou correta em dois pontos.** A repetição do trecho final é cortada por comparação de sufixo, e um stream que cai depois de já ter emitido um `tool_call` não é mais retomado — o app receberia um `arguments` JSON pela metade. Nesse caso o router para e fecha o stream com erro.
- **O dialeto OpenAI deixou de ser repasse cru.** Passa pela mesma máquina de estado do Anthropic, o que permite detectar o corte, capturar erro dentro do `200` e reescrever a identidade do modelo.
- **`config.php` enxuto.** Traz só os provedores usados pelos modelos de fábrica. Provedor sem chave gera um aviso com a lista de modelos que dependem dele, e não um aviso por entrada.
- **Documentação separada.** `README.md` ficou com o essencial e o quick start; a referência completa foi para `DOCS.md`.
- **CORS completo:** `Allow-Methods`, `Max-Age`, `Vary: Origin` e `Expose-Headers` — sem o último o navegador esconde os `X-Router-*` do JavaScript.
- **Compressão ligada fora do streaming** (gzip encurta o download) e desligada nele (atrasaria o primeiro byte).
- **TLS explícito:** `CURLOPT_SSL_VERIFYPEER` e `CURLOPT_SSL_VERIFYHOST` declarados.
- **`COOLDOWN_SECONDS` passou a `60`** por padrão. `0` desliga o mecanismo inteiro, inclusive a quarentena longa.
- **Injeção no log bloqueada:** `\t`, `\n` e `\r` saem dos campos livres antes de gravar a linha TSV.
- **`json_decode` com limite de profundidade** (64 níveis) no corpo recebido.
- **Redação de segredos ampliada** para `nvapi-`, `hf_`, `AIza` e `Bearer <token>`, além das chaves conhecidas em `PROVIDERS` e `MODELS`.

### Removido

- **Health probe ativo** (`HEALTH_PROBE_ENABLED`, `HEALTH_PROBE_TOKENS`, `HEALTH_PROBE_INTERVAL` e `?probe=1`). Estava quebrado desde a migração das chaves para `PROVIDERS` (procurava a chave no lugar errado), era opt-in e gastava cota. As métricas passivas respondem a mesma pergunta com dados reais.
- **Backend SQLite das métricas** (`METRICS_BACKEND = 'sqlite'` e `METRICS_DB`). O log TSV já responde qualquer recorte de histórico via `awk`/`cut`, sem extensão extra do PHP.
- **Pool de conexões curl.** Substituído por um handle único reaproveitado — as tentativas de uma requisição são sequenciais e o cache de conexões do libcurl vive dentro do handle.

### Migração a partir da 0.2

Se você mantém o seu `config.php`, o `php index.php check` aponta o que falta. Em resumo:

```php
// Remover (as constantes não existem mais):
//   METRICS_DB
//   HEALTH_PROBE_ENABLED, HEALTH_PROBE_TOKENS, HEALTH_PROBE_INTERVAL
//   EMBEDDING_MODELS  (mova as entradas para models.php com 'type' => 'embedding')

// Trocar, se você usava o backend de banco:
define('METRICS_BACKEND', 'file');   // antes: 'sqlite'

// Acrescentar:
define('CONFIG_ERROR_COOLDOWN', 900);
define('TOTAL_DEADLINE_SECONDS', 300);
define('STREAM_HEARTBEAT_SECONDS', 15);
define('MODEL_TYPES', ['chat', 'embedding']);  // allowlist de types em MODELS
```

`MODELS`, `PROVIDERS`, as chaves e os apps que já apontam para o gateway continuam iguais. Entradas de `EMBEDDING_MODELS` viram entradas de `MODELS` com `'type' => 'embedding'` em `models.php`.

---

## [0.2]

- Chaves de provedor movidas de `MODELS` para `PROVIDERS`, onde são herdadas por todos os modelos daquele serviço. `MODELS` mantém a sobrescrita pontual para quando um modelo precisa de outra conta.
- `key` passou a aceitar um array de strings, expandindo o provedor em vários candidatos independentes na rotação — uma forma de somar contas do mesmo serviço.
- Endpoint `/messages` removido: a API exposta passou a ser exclusivamente no formato OpenAI, com a tradução para provedores Anthropic acontecendo por dentro.
- Circuit breaker, rate limit do gateway, métricas por provedor, `X-Request-Id` e allowlist de IPs.
- `LOG_FILE` renomeado de `router.log` para `localrouter.log`.

## [0.1]

- Primeira versão pública: rotação entre provedores por modelo, tradução OpenAI ↔ Anthropic, streaming e `php index.php check`.

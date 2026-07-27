# LocalRouter 0.1

Gateway de IA open-source (MIT) com API **no formato OpenAI** e rotação automática de provedores — que podem falar tanto o dialeto OpenAI quanto o Anthropic. Publica um endpoint compatível com OpenAI **e** com Anthropic, e por trás dele mantém uma lista de provedores por modelo. Quando um provedor devolve erro, estoura o limite de requisições ou fica sem crédito, o router passa para o próximo na mesma requisição — o app que chamou não percebe a troca.

O problema que ele resolve: você tem a mesma família de modelo disponível em Groq, Cerebras, OpenRouter e no provedor oficial, cada um com um limite gratuito diferente. Sem router, o app quebra quando o limite do dia acaba. Com router, ele só migra.

Seu app enxerga um único endpoint compatível com qualquer SDK OpenAI. Por trás, o LocalRouter traduz para provedores Anthropic quando preciso — inclusive em streaming.

Roda de duas formas:

- **Local** (padrão, recomendado para começar): na sua máquina, com XAMPP/WAMP/MAMP ou `php -S`, em `http://localhost`.
- **Publicado em um site/domínio**: enviado para a raiz pública de uma hospedagem (`public_html`, `www`), em `https://seudominio.com`.

Os exemplos abaixo priorizam o uso local; quando houver diferença para o publicado, ela aparece ao lado.

---

## Índice

1. [Como funciona](#como-funciona)
2. [Requisitos](#requisitos)
3. [Instalação](#instalação)
4. [Configuração](#configuração)
5. [Apontando seus apps](#apontando-seus-apps)
6. [Endpoints](#endpoints)
7. [Rotação e tratamento de erros](#rotação-e-tratamento-de-erros)
8. [Tradução para provedores Anthropic](#tradução-para-provedores-anthropic)
9. [Logs](#logs)
10. [Segurança](#segurança)
11. [Erros comuns](#erros-comuns)
12. [Limitações conhecidas](#limitações-conhecidas)

---

## Como funciona

Cada requisição percorre quatro etapas:

1. **Normalização** — o corpo recebido (formato OpenAI) vira uma forma canônica neutra: sistema, mensagens, blocos de conteúdo, ferramentas e parâmetros.
2. **Montagem** — a forma canônica é traduzida para o dialeto do provedor da vez.
3. **Chamada** — se voltar 200, segue. Qualquer outra coisa registra o motivo e tenta o próximo provedor.
4. **Resposta** — o resultado volta sempre no formato OpenAI, não importa o dialeto do provedor que atendeu.

---

## Requisitos

| Item | Versão | Observação |
|---|---|---|
| PHP | 8.0 ou superior | usa `match`, `str_ends_with` e promoção de propriedade no construtor |
| ext-curl | — | obrigatória, é o que fala com os provedores |
| ext-json | — | obrigatória |
| Apache | 2.4 | opcional; sem ele as URLs ficam `/index.php/chat/completions` |

Zero dependências externas. Sem Composer, sem build.

---

## Instalação

Estrutura entregue (a mesma em localhost ou publicado):

```
raiz do projeto/
├── robots.txt              ← no publicado, precisa ficar na raiz do domínio
├── LICENSE                 ← MIT
├── index.php               ← ponto de entrada (web + linha de comando)
├── config.php              ← TODA a configuração (texto ou env)
├── .htaccess               ← rewrite, HTTPS, bloqueio de robôs e de src/
├── src/
│   ├── gateway.php          ← fluxo, guards, estado, log
│   ├── providers.php        ← chamadas HTTP aos provedores
│   ├── formats.php          ← tradução OpenAI ↔ canônico ↔ Anthropic
│   ├── streaming.php        ← tradutores SSE
│   ├── cli.php              ← utilitários de linha de comando (genkey, check)
│   └── .htaccess            ← Require all denied
└── data/                   ← criada sozinha se cooldown/rate limit forem ativados
```

Cada arquivo de `src/` e o `config.php` morrem em silêncio se acessados sem o bootstrap (`defined('LOCALROUTER') or exit`) — segunda tranca além do `.htaccess`.

### Local (recomendado para começar)

Você só precisa do PHP 8.0+ com ext-curl. Três caminhos comuns:

**XAMPP / WAMP / MAMP** — copie a pasta do projeto para a raiz web do bundle (`C:\xampp\htdocs\localrouter` no XAMPP) e acesse `http://localhost/localrouter`.

**Servidor embutido do PHP** — de dentro da pasta do projeto:

```bash
php -S localhost:8000
# agora o gateway responde em http://localhost:8000
```

**Qualquer pasta servida por PHP** — basta apontar o document root para a pasta do projeto.

Em localhost o `REQUIRE_HTTPS` é isento de propósito (o PHP detecta `127.0.0.1`/`::1`), então HTTP puro funciona para testes.

### Publicado em um site/domínio

1. Envie os arquivos para a raiz pública do site (`public_html`, `www` ou equivalente).
2. Envie `robots.txt` para a **raiz do domínio**. Rastreadores só leem `https://seudominio.com/robots.txt`; um `robots.txt` dentro de uma subpasta é ignorado. Quem bloqueia a pasta de fato é o cabeçalho `X-Robots-Tag`, definido no `.htaccess`.
3. Em produção, prefira apontar `LOG_FILE` e `STATE_FILE` para **fora** do docroot (ver `config.php`) — em servidor sem `.htaccess` (Nginx) o `router.log` poderia ficar exposto.

### Configurar e testar (serve para os dois)

1. Abra **`config.php`** e configure. Nada fora dele precisa ser editado.
2. Confirme que funcionou:

```bash
# local
curl http://localhost:8000/models -H "Authorization: Bearer SUA_CHAVE_DO_GATEWAY"

# publicado
curl https://seudominio.com/models -H "Authorization: Bearer SUA_CHAVE_DO_GATEWAY"
```

A resposta lista os modelos configurados. Se voltar `503` com aviso sobre a chave padrão, você ainda não trocou `GATEWAY_KEYS` — o router se recusa a servir nessa condição, de propósito.

---

## Configuração

Tudo fica em `config.php`, em `define()`. Cada valor pode ser **texto puro ou variável de ambiente** — sua escolha, valor a valor:

```php
// Direto no arquivo (simples, mas não versione com chaves reais):
'key' => 'gsk_abc123',

// Ou via variável de ambiente (painel da hospedagem, Docker, CI):
'key' => getenv('GROQ_API_KEY') ?: '',

// O mesmo vale para qualquer define():
define('GATEWAY_KEYS', [getenv('LR_GATEWAY_KEY') ?: '']);
```

O `?: ''` garante string vazia quando a env não existe — e chave vazia é sempre recusada, então env ausente **falha fechado**. Depois de configurar, valide com `php index.php check`.

> Por que `define()` e não `const`? `const` em PHP não aceita chamada de função — `const X = getenv(...)` é erro fatal. `define()` se comporta igual e libera o `getenv()`.

### `GATEWAY_KEYS`

As chaves que **seus apps** usam para falar com o router. Não confunda com as chaves dos provedores.

```php
define('GATEWAY_KEYS', [
    'sk-lr-4f9a2c8e1b7d3a6f5c0e9b2d',
]);
```

Gere uma chave aleatória de verdade com o próprio arquivo:

```bash
php index.php genkey
# sk-lr-4f9a2c8e1b7d3a6f5c0e9b2d...
```

Aceita várias chaves — útil para revogar o acesso de um app sem afetar os outros. Uma lista vazia ou a chave de exemplo faz o gateway recusar todas as requisições.

### `PROVIDERS` — catálogo de URLs e dialeto

Cada provedor é cadastrado **uma vez** com um nome livre, a URL base e o tipo de API (`openai` ou `anthropic`) — só isso. A chave de API fica em cada entrada de `MODELS`, porque cada modelo pode usar uma conta/chave diferente do mesmo serviço. O `type` é fixo por provedor: OpenRouter sempre fala `openai`, a API nativa da Anthropic sempre fala `anthropic`. O arquivo já vem com 21 provedores pré-preenchidos (Groq, Cerebras, OpenRouter, Google AI Studio, GitHub Models, Mistral, NVIDIA, Hugging Face, SiliconFlow, Ollama, Vercel AI Gateway, OpenCode, Cloudflare, Anthropic, OpenAI, xAI, Fireworks, Nebius, Novita, Z AI e um provedor local para Ollama na sua máquina).

```php
define('PROVIDERS', [
    'groq'        => ['url' => 'https://api.groq.com/openai/v1'],
    'openrouter1' => ['url' => 'https://openrouter.ai/api/v1'],
    'openrouter2' => ['url' => 'https://openrouter.ai/api/v1'],
    'anthropic'   => ['url' => 'https://api.anthropic.com/v1', 'type' => 'anthropic'],
]);
```

| Campo | O que é |
|---|---|
| `url` | URL **base**, sem a rota final. O router anexa `/chat/completions` (openai) ou `/messages` (anthropic) conforme o `type` declarado no provedor. |
| `type` | Dialeto do provedor: `openai` (padrão, se omitido) ou `anthropic`. Define qual rota e quais cabeçalhos o router usa. |

O nome é livre — use sufixos para **várias contas do mesmo serviço** (`openrouter1`, `openrouter2`, `groq_5`). Isso não é cosmético: o log, o cabeçalho `X-Router-Provider` e o cooldown passam a distinguir cada conta, coisa que a URL sozinha não faz (duas contas OpenRouter compartilham o mesmo host).

**Erro comum:** colocar em `url` a rota completa (`.../v1/chat/completions`). O router anexaria a rota de novo e todas as chamadas voltariam 404. Use só a base.

### `MODELS` — o que seu app pede

A chave do array é o nome que **seu app pede**; cada entrada aponta para um provedor do catálogo `PROVIDERS`, diz o id do modelo lá dentro e traz a **chave de API** do provedor.

```php
define('MODELS', [
    'gpt-oss-120b' => [
        ['provider' => 'groq',        'model' => 'openai/gpt-oss-120b',      'key' => getenv('GROQ_API_KEY') ?: '', 'weight' => 6],
        ['provider' => 'cerebras',    'model' => 'gpt-oss-120b',             'key' => getenv('CEREBRAS_API_KEY') ?: '', 'weight' => 3],
        ['provider' => 'openrouter1', 'model' => 'openai/gpt-oss-120b:free', 'key' => getenv('OPENROUTER_KEY_1') ?: ''],
    ],
    'claude-sonnet' => [
        ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'key' => getenv('ANTHROPIC_API_KEY') ?: ''],
        ['provider' => 'openrouter1', 'model' => 'anthropic/claude-sonnet-4.5', 'key' => getenv('OPENROUTER_KEY_1') ?: ''],
    ],
]);
```

| Campo | O que é |
|---|---|
| `provider` | Nome em `PROVIDERS`. |
| `model` | O id do modelo **naquele provedor**. O mesmo modelo tem ids diferentes em cada um. |
| `key` | A chave de API daquele provedor (texto ou `getenv`). Obrigatório; `''` para provedor local sem chave. |
| `weight` | Opcional (padrão `1`). Com `STRATEGY = 'random'`, peso 6 tende a abrir a fila 6× mais que peso 1 — dá mais tráfego a quem tem limite maior, sem nunca excluir ninguém da rotação. |
| `params` | Opcional. Parâmetros só daquele provedor — ver abaixo. |

A separação entre `PROVIDERS` (URL + type) e `MODELS` (key) existe para que cada modelo possa usar uma conta/chave diferente do mesmo serviço sem duplicar a URL. O `type` fica em `PROVIDERS` porque é fixo por provedor: OpenRouter sempre fala o dialeto OpenAI, a API nativa da Anthropic sempre fala Anthropic.

### Parâmetros por modelo

Um modelo pode fixar `temperature`, `top_p`, `top_k`, `max_tokens`, `stop` e qualquer item de `PASSTHROUGH_OPENAI` (`seed`, `response_format`, `reasoning_effort`…). Todos são opcionais. Para isso, use a forma com `params` e `providers`:

```php
'redacao-criativa' => [
    'params' => ['temperature' => 1.1, 'top_p' => 0.95],
    'providers' => [
        ['provider' => 'groq',     'model' => 'openai/gpt-oss-120b', 'key' => getenv('GROQ_API_KEY') ?: ''],
        // este provedor específico responde melhor mais frio:
        ['provider' => 'cerebras', 'model' => 'gpt-oss-120b', 'key' => getenv('CEREBRAS_API_KEY') ?: '', 'params' => ['temperature' => 0.6]],
    ],
],
```

A forma antiga (lista simples de provedores, sem `params`) continua válida — nesse caso valem só os padrões globais.

**Precedência**, do mais forte ao mais fraco:

1. O que o app enviou na requisição
2. `params` da entrada do provedor
3. `params` do modelo
4. `DEFAULT_PARAMS`

Cada camada apenas **preenche o que está em branco** — a configuração nunca sobrescreve um valor que o app pediu de propósito. Um app que manda `temperature: 0` para extrair JSON continua recebendo `0`, mesmo que o modelo esteja configurado com `1.1`. Se você quiser o contrário (impor um teto independentemente do app), o ponto de mudança é a função `apply_params`, que está comentada para isso.

`null` em qualquer camada significa "sem opinião": passa a decisão para a camada seguinte. Se ninguém opinar, o parâmetro simplesmente não é enviado e o provedor aplica o padrão dele.

Parâmetros que não fazem sentido para o dialeto de destino são descartados: `seed` e `response_format` chegam a provedores `openai` e não a provedores `anthropic`; `top_k` é o inverso. `php index.php check` avisa sobre nome desconhecido e valor fora de faixa (`temperature` 0–2, `top_p` 0–1).

Um mesmo modelo lógico pode misturar dialetos: o primeiro provedor Anthropic, o segundo um agregador que fala OpenAI. A tradução acontece por dentro.

### Os ids de modelo envelhecem — e isso é normal

O `config.php` vem com um catálogo inicial de modelos gratuitos conferido em julho de 2026. Trate-o como ponto de partida, não como verdade permanente: a Groq já aposentou os modelos Llama, e DeepSeek, Mistral e Gemini perderam suas versões `:free` na OpenRouter. Slugs de modelo grátis mudam praticamente toda semana.

Para descobrir o que está no ar hoje, consulte a documentação do provedor (a página de modelos no painel dele, ou o endpoint `GET /v1/models` com a sua chave). Enquanto um id estiver desatualizado, o provedor responde 404 e o LocalRouter simplesmente pula para o próximo — o serviço não cai por causa disso.

### Demais constantes

| Constante | Padrão | Para que serve |
|---|---|---|
| `STRATEGY` | `'random'` | `random` sorteia a ordem a cada requisição, ponderado pelo `weight` de cada provedor; `priority` sempre segue a ordem do array (e ignora `weight`). |
| `MAX_ATTEMPTS` | `4` | Teto de provedores tentados por requisição. `0` remove o limite. |
| `PASSTHROUGH_OPENAI` | lista | Parâmetros repassados intactos a provedores de dialeto `openai` (`seed`, `response_format`, `reasoning_effort`…). Provedores `anthropic` não os recebem: não há equivalente exato. |
| `EXPOSE_PROVIDER_HEADER` | `true` | Devolve `X-Router-Provider`, `X-Router-Model` e `X-Router-Attempt`. Desligue se os clientes não forem seus. |
| `MODEL_FALLBACKS` | `[]` | Fallback **entre modelos**: esgotados os provedores de um, segue nos do outro. Ex.: `['claude-sonnet' => 'gpt-oss-120b']`. |
| `COOLDOWN_SECONDS` | `0` (off) | Provedor que falhou por limite, crédito ou 5xx fica de castigo por N segundos e nem é tentado. Exige `STATE_FILE` gravável. |
| `RATE_LIMIT_PER_MINUTE` | `0` (off) | Teto de requisições por minuto do próprio gateway. Segura app em loop e chave vazada. |
| `STATE_FILE` | `data/state.json` | Arquivo de estado do cooldown e do rate limit. A pasta nasce com um `.htaccess` próprio negando acesso web. |
| `ALLOWED_IPS` | `[]` (todos) | Allowlist de IPs. Aceita IP exato ou prefixo (`'192.168.'`). Usa `REMOTE_ADDR`, nunca `X-Forwarded-For`. |
| `REQUIRE_HTTPS` | `true` | Recusa requisição em HTTP puro no nível do PHP (localhost isento). Defesa em profundidade além do `.htaccess`. |
| `TRUSTED_PROXIES` | `[]` (nenhum) | Proxies que terminam o TLS na frente do PHP (Cloudflare, nginx, balanceadores). Só de IPs desta lista o gateway confia em `X-Forwarded-Proto` para decidir HTTPS. Vazio = nunca confia no cabeçalho. Aceita IP exato ou prefixo, igual a `ALLOWED_IPS`. |
| `LOG_FILE` | `router.log` | Caminho do log. String vazia desativa. |
| `LOG_MAX_BYTES` | `5 MB` | Ao passar disso o log vira `.1` e recomeça. |
| `CONNECT_TIMEOUT` | `10` | Segundos para abrir conexão com o provedor. |
| `REQUEST_TIMEOUT` | `180` | Segundos por tentativa, fora de streaming. |
| `STREAM_STALL_TIME` | `60` | Em streaming, segundos sem receber bytes antes de abortar. |
| `MAX_BODY_BYTES` | `8 MB` | Teto do corpo aceito do cliente. |
| `DEFAULT_PARAMS` | tudo `null` | Valores usados quando nem o app nem o modelo definiram o parâmetro. `null` = não envia nada e deixa o provedor decidir. |
| `DEFAULT_MAX_TOKENS` | `4096` | Último recurso para `max_tokens`: a API Anthropic exige o campo, então este valor evita que a chamada seja recusada quando ninguém definiu nada. |
| `ALLOW_ORIGIN` | `''` | Origem para CORS. Vazio desliga; só preencha se algum front-end chamar direto do navegador. |
| `ANTHROPIC_VERSION` | `2023-06-01` | Valor do cabeçalho `anthropic-version`. |

`STRATEGY = 'random'` é o padrão por um motivo: o router não guarda estado entre requisições. Com `priority`, o provedor nº 1 recebe todo o tráfego e, depois que ele estoura o limite, **toda** requisição paga uma ida e volta perdida antes de cair no nº 2. O sorteio dilui esse custo.

---

## Apontando seus apps

Qualquer SDK ou app compatível com OpenAI:

| Campo | Local | Publicado |
|---|---|---|
| Base URL | `http://localhost:8000` | `https://seudominio.com` |
| API key | uma das `GATEWAY_KEYS` | uma das `GATEWAY_KEYS` |
| Modelo | a chave do array `MODELS` | a chave do array `MODELS` |

```bash
# local
curl http://localhost:8000/chat/completions \
  -H "Authorization: Bearer SUA_CHAVE_DO_GATEWAY" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-oss-120b",
    "messages": [{"role": "user", "content": "Explique rotação de API em uma frase."}]
  }'

# publicado
curl https://seudominio.com/chat/completions \
  -H "Authorization: Bearer SUA_CHAVE_DO_GATEWAY" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "gpt-oss-120b",
    "messages": [{"role": "user", "content": "Explique rotação de API em uma frase."}]
  }'
```

## Linha de comando

O mesmo arquivo funciona como utilitário no terminal (rode de dentro da pasta do projeto):

```bash
php index.php genkey          # gera uma chave para colar em GATEWAY_KEYS
php index.php check           # valida a configuração e aponta erros comuns
```

O `check` detecta chave de exemplo esquecida, referência a provedor inexistente, `type` inválido em `PROVIDERS`, `url` com a rota completa em vez da base, provedor em HTTP puro fora de localhost, entrada incompleta, `weight` inválido e fallback apontando para modelo inexistente. Sai com código `1` quando há problema — dá para usar em CI ou em script de deploy.

## Endpoints

A API é **exclusivamente no formato OpenAI**. (Quem migrou de versões antigas: `/messages` deixou de existir; provedores Anthropic continuam suportados por dentro.)

| Método | Rota | Descrição |
|---|---|---|
| `POST` | `/chat/completions` | Entrada e saída no formato OpenAI. Aceita `stream: true`. |
| `GET` | `/models` | Lista os modelos configurados. Exige autenticação. |
| `GET` | `/health` | `{"status":"ok"}` quando o gateway está configurado; `503 {"status":"unconfigured"}` com a chave de fábrica. **Sem autenticação** e fora da allowlist de IPs de propósito — o monitor local (`curl http://localhost:8000/health`) nunca é barrado, e a resposta não carrega nada sensível. |

As rotas casam pelo **sufixo** do caminho. Isso faz funcionar tanto `/chat/completions` (com o rewrite do `.htaccess`) quanto `/index.php/chat/completions` (sem rewrite, em servidor que não permite `.htaccess`).

---

## Funcionalidades opcionais

Todas desligadas por padrão e ativadas por constante, sem tocar no resto do código.

**Cooldown de provedores** (`COOLDOWN_SECONDS`) — o gateway padrão é 100% sem estado: um provedor esgotado será tentado de novo na requisição seguinte. Com cooldown ativo, quem falhou por rate limit, crédito ou 5xx fica fora da rotação pelo tempo configurado, e a requisição seguinte vai direto a quem está saudável. Falhas de configuração (401, 403, 404) **não** geram castigo de propósito: cooldown mascararia um erro que precisa de correção, não de espera. Se todos os provedores estiverem de castigo, o castigo é ignorado — tentar é melhor que falhar parado.

**Rate limit do gateway** (`RATE_LIMIT_PER_MINUTE`) — janela fixa global de um minuto. Não substitui o rate limit dos provedores; protege contra um app seu em loop e limita o estrago de uma chave do gateway vazada.

**Peso por provedor** (`weight` em cada provedor) — distribui o tráfego proporcionalmente ao limite de cada um. Sorteio ponderado **sem reposição**: o peso muda a frequência com que cada provedor abre a fila, nunca a disponibilidade — todos continuam na rotação de failover.

**Fallback entre modelos** (`MODEL_FALLBACKS`) — a rotação padrão é só entre provedores do mesmo modelo. Com fallback, esgotar `claude-sonnet` pode continuar em `gpt-oss-120b`. A resposta volta com o nome do modelo **pedido** (o que os SDKs esperam); o cabeçalho `X-Router-Model` mostra qual modelo real atendeu. Ciclos (`A → B → A`) são detectados e ignorados.

**Allowlist de IPs** (`ALLOWED_IPS`) — segunda tranca além da chave, útil quando só os seus servidores devem alcançar o gateway. Compara com `REMOTE_ADDR`; `X-Forwarded-For` é ignorado porque qualquer cliente pode forjá-lo.

**HTTPS obrigatório no PHP** (`REQUIRE_HTTPS`) — o `.htaccess` já redireciona, mas ele não roda em todo servidor. Esta constante recusa (não redireciona) requisições em HTTP puro: se a chave chegou por HTTP, ela já trafegou exposta — redirecionar só faria o cliente reenviá-la.

## Rotação e tratamento de erros

Qualquer resposta diferente de `200` faz o router registrar o motivo e seguir para o próximo provedor:

| Situação | Registro no log |
|---|---|
| `429` | `rate limit` |
| `402` | `sem credito` |
| `401` / `403` | `chave rejeitada` |
| `404` | `modelo inexistente no provedor` |
| `5xx` | `erro do provedor` |
| Timeout, DNS, conexão recusada | `rede: <detalhe do curl>` |

Esgotada a lista (ou atingido `MAX_ATTEMPTS`), o cliente recebe o **último** erro, já traduzido para o dialeto dele, com o motivo em texto.

**Erro `400` também rotaciona.** Isso é deliberado — provedores compatíveis divergem em quais parâmetros aceitam, e o que um recusa outro aceita. O custo é que uma requisição realmente malformada percorre a lista inteira antes de devolver o erro. Se preferir falhar rápido nesse caso, o ponto de mudança é a função `failure_reason`.

### Streaming

Nada é enviado ao cliente antes de o provedor confirmar `200`. Por isso `429`, `401` e `5xx` continuam rotacionando normalmente mesmo em streaming.

Depois que o primeiro byte sai, não existe volta: a resposta parcial já está com o cliente. **Mas se a conexão com o provedor cair no meio do stream, o router não desiste** — ele pega o texto já emitido, injeta como mensagem `assistant` no próximo provedor da fila e pede a **continuação** a partir de onde parou (com uma instrução no `system` e `tool_choice: 'none'` para não virar chamada de ferramenta). O cliente recebe o texto completo, emendado, sem perceber a troca. Só se **todos** os provedores caírem no meio é que o stream é encerrado com `[DONE]` (o que já foi emitido fica com o cliente).

A distinção importa: se foi o **cliente** que desconectou, não há sentido em continuar — ninguém está lendo. Nesse caso o gateway aborta a chamada ao provedor para não pagar tokens à toa.

---

## Tradução para provedores Anthropic

Provedor de dialeto `openai` recebe a requisição praticamente como chegou (streaming repassado byte a byte). Provedor `anthropic` recebe tudo traduzido, e a resposta volta convertida para chunks OpenAI em tempo real.

### Convertido

- Prompt de sistema (mensagem `system` → campo `system`)
- Texto, ferramentas (`function.parameters` → `input_schema`), `tool_choice`, `tool_calls` ↔ `tool_use`, `role: tool` ↔ `tool_result`
- `max_tokens`, `temperature`, `top_p`, `top_k`, sequências de parada, motivo de parada, contagem de tokens
- Eventos de streaming, incluindo tool calls em stream

### Conteúdo multimodal

| Bloco de entrada (formato OpenAI) | Provedor `openai` | Provedor `anthropic` |
|---|---|---|
| `text` | ✔ | ✔ |
| `image_url` (URL ou `data:` base64) | ✔ | ✔ (vira `source.url` / `source.base64`) |
| `file` com `file_data` (PDF etc.) | ✔ | ✔ (vira bloco `document`) |
| `input_audio` | ✔ | ✖ descartado — a API Anthropic não aceita áudio de entrada |
| `file` com `file_id` | ✖ ignorado | ✖ ignorado — upload prévio na OpenAI não viaja entre provedores |

### Não convertido

`response_format` (modo JSON), `seed`, `reasoning_effort`, `logprobs` e afins passam intactos a provedores `openai` (lista `PASSTHROUGH_OPENAI`) e são descartados para provedores `anthropic`: não existe equivalente exato do outro lado — e inventar um faria o router mentir sobre o que foi pedido.

---

## Logs

Uma linha por tentativa, separada por tabulação:

```
2026-07-23T14:02:11+00:00	gpt-oss-120b	groq	openai/gpt-oss-120b	429	310ms	rate limit (Rate limit reached)
2026-07-23T14:02:11+00:00	gpt-oss-120b	cerebras	gpt-oss-120b	200	842ms	ok
```

Campos: data ISO, modelo pedido, **nome do provedor** (o de `PROVIDERS`, não o host — é o que separa `openrouter1` de `openrouter2`), id do modelo no provedor, status HTTP, duração, resultado.

O log **não** grava chaves de API nem o conteúdo das mensagens. Chaves que apareçam em mensagens de erro do provedor são substituídas por `[chave omitida]` antes de qualquer gravação ou resposta.

Para ver a taxa de erro por provedor:

```bash
cut -f3,7 router.log | sort | uniq -c | sort -rn
```

---

## Segurança

O que já está implementado:

- **Falha fechada.** Sem chave válida, sem resposta. Com a chave de exemplo ainda no lugar, o gateway se recusa a servir.
- **Comparação em tempo constante** das chaves do gateway (`hash_equals`), o que evita descobrir a chave medindo tempo de resposta.
- **Redação de segredos** em mensagens de erro dos provedores — vários devolvem a própria chave no texto do erro.
- **Sem redirecionamento automático**, para a chave do provedor não ser reenviada a outro host.
- **Erros genéricos** para o cliente: nada de caminho de arquivo, stack trace ou detalhe interno.
- **HTTPS forçado** duas vezes: redirect no `.htaccess` e recusa no PHP (`REQUIRE_HTTPS`), exceto em `localhost`.
- **Corpo limitado antes de ler**: requisição acima de `MAX_BODY_BYTES` é rejeitada pelo tamanho declarado e a leitura nunca passa do teto — corpo gigante não consome memória.
- **Aborto por desconexão**: cliente que fecha a conexão no meio de um streaming derruba também a chamada ao provedor.
- **`Authorization` preservado em CGI/FastCGI** pelo `.htaccess` — sem isso, metade das hospedagens compartilhadas devolveria 401 para tudo.
- **Allowlist de IPs opcional** (`ALLOWED_IPS`) como segunda tranca.

O que depende de você:

- **Não versione o arquivo com chaves reais.** Se o projeto for para um repositório público, troque os valores por `getenv('GROQ_KEY')` e defina as variáveis no painel da hospedagem.
- **Confirme que `router.log` não é servido.** O `.htaccess` bloqueia `.log`, mas em Nginx a regra equivalente precisa ser escrita no `server` block.
- **Rotacione a chave do gateway** se ela circular por apps de terceiros.

---

## Erros comuns

**`503` — "Troque a chave padrao em GATEWAY_KEYS"**
Proteção intencional. Gere uma chave e substitua a de exemplo.

**`404` — "Modelo nao configurado neste gateway"**
O app está pedindo o id do provedor (`openai/gpt-oss-120b`) em vez da chave do array `MODELS` (`gpt-oss-120b`). Chame `GET /models` para ver os nomes válidos.

**`401` no gateway**
A chave enviada pelo app não está em `GATEWAY_KEYS`. O padrão é `Authorization: Bearer <chave>`; `x-api-key` também é aceito.

**Todos os provedores falharam com "modelo inexistente no provedor"**
Os ids em `MODELS` saíram de catálogo, ou você colocou a rota completa em `url`. Consulte a documentação do provedor (ou o endpoint `GET /v1/models` dele com a sua chave) para ver o que está no ar agora.

**`404` em `/messages`**
A API é exclusivamente OpenAI desde a reorganização. Aponte o app para `/chat/completions`.

**`404` em `/chat/completions`, mas `/index.php/chat/completions` funciona**
O `mod_rewrite` está desligado ou o `AllowOverride` do servidor não permite `.htaccess`. Use a URL com `index.php` ou peça o ajuste à hospedagem.

**Streaming chega todo de uma vez, no fim**
Algum proxy está segurando os chunks. O router já envia `X-Accel-Buffering: no`, mas compressão na frente dele anula o efeito — desative gzip para `text/event-stream`.

**`400` em modelos `o1`/`o3` da OpenAI oficial**
Essas linhas exigem `max_completion_tokens` no lugar de `max_tokens`. O router envia `max_tokens`, que é o aceito por Groq, Cerebras, OpenRouter e pela maioria dos compatíveis. Para a OpenAI oficial com esses modelos, ajuste `build_openai_payload`.

---

## Limitações conhecidas

- **Sem estado por padrão.** Um provedor que estourou o limite será tentado de novo na próxima chamada — a menos que `COOLDOWN_SECONDS` seja ativado, que resolve exatamente isso ao custo de um arquivo de estado.
- **Retomada de stream depende de outro provedor saudável.** Se o provedor cair no meio do stream e todos os demais também falharem, o cliente recebe só o que já foi emitido (encerrado com `[DONE]`). A retomada também não é perfeita: o provedor que continua pode repetir algumas palavras do ponto de corte ou mudar levemente o tom.
- **Sem contabilidade de custo.** O log registra tempo e status, não tokens. Para orçamento por provedor seria preciso somar o `usage` de cada resposta.
- **Fallback entre modelos é opcional e manual.** Só acontece se você mapear em `MODEL_FALLBACKS`.
- **Cliente que desconecta no meio do stream** faz o gateway abortar a chamada ao provedor (para não pagar tokens à toa), mas os tokens já gerados até ali foram cobrados.

---

## Licença

MIT — texto completo no arquivo `LICENSE`.

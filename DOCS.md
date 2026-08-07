# LocalRouter v0.3 — documentação

Referência completa. Para começar rápido, veja o [README](README.md).

## Índice

1. [Instalação](#instalação)
2. [Configuração](#configuração)
3. [Constantes](#constantes)
4. [Apontando seus apps](#apontando-seus-apps)
5. [Linha de comando](#linha-de-comando)
6. [Endpoints](#endpoints)
7. [Rotação e tratamento de erros](#rotação-e-tratamento-de-erros)
8. [Funcionalidades opcionais](#funcionalidades-opcionais)
9. [Tradução para provedores Anthropic](#tradução-para-provedores-anthropic)
10. [Logs e métricas](#logs-e-métricas)
11. [Segurança](#segurança)
12. [Testes](#testes)
13. [Troubleshooting](#troubleshooting)
14. [Erros comuns](#erros-comuns)
15. [Limitações conhecidas](#limitações-conhecidas)
16. [Docker](#docker)

---


## Instalação

Cada arquivo de `src/` e o `config.php` morrem em silêncio se acessados sem o bootstrap (`defined('LOCALROUTER') or exit`) — segunda tranca além do `.htaccess`. A pasta `data/` nasce sozinha quando cooldown, rate limit ou métricas são ativados, com um `.htaccess` próprio negando acesso web.

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
2. Envie `robots.txt` para a **raiz do domínio**. Rastreadores só leem `https://seudominio.com/robots.txt`; um `robots.txt` dentro de uma subpasta é ignorado. Quem bloqueia a pasta de fato é o cabeçalho `X-Robots-Tag`, enviado em duas camadas: pelo PHP em toda resposta (vale em qualquer servidor) e pelo `.htaccess` via `mod_headers`.
3. Em produção, prefira apontar `LOG_FILE` e `STATE_FILE` para **fora** do docroot (ver `config.php`) — em servidor sem `.htaccess` (Nginx) o `localrouter.log` poderia ficar exposto.

### Configurar e testar (serve para os dois)

1. Ajuste o mínimo para subir:
   - **`LR_GATEWAY_KEY`** (em `data/.env`) — copie `data/.env.example` para `data/.env` e preencha com uma chave real (`php index.php genkey` gera uma).
   - **`PROVIDERS`** (em `providers.php`) — em pelo menos um provedor, preencha a `key` com a sua chave real (ou defina a variável de ambiente correspondente em `data/.env`).
   Os catálogos `PROVIDERS` e `MODELS` vivem em arquivos separados na raiz (`providers.php` e `models.php`), incluídos pelo `config.php` via `require` — assim chaves e ids de modelo ficam fora do código versionado. Modelos de embedding também ficam em `models.php`, marcados com `'type' => 'embedding'` (ausente = `'chat'`).
2. Valide a configuração:

```bash
php index.php check
```

3. Confirme que funcionou:

```bash
# local
curl http://localhost:8000/models -H "Authorization: Bearer SUA_CHAVE_DO_GATEWAY"

# publicado
curl https://seudominio.com/models -H "Authorization: Bearer SUA_CHAVE_DO_GATEWAY"
```

A resposta lista os modelos configurados. Se voltar `503` com aviso sobre a chave padrão, você ainda não definiu `LR_GATEWAY_KEY` no `data/.env` — o router se recusa a servir nessa condição, de propósito.

---

## Configuração

A configuração fica em `config.php` (e nos catálogos `providers.php` e `models.php` incluídos por ele), em `define()`. Cada valor pode ser **texto puro ou variável de ambiente** (lida via `env('X')`) — sua escolha, valor a valor:

### Conceitos-chave (leia antes de editar)

O `config.php` (com os catálogos em `providers.php` e `models.php`) tem três camadas que se referenciam. Entender a relação entre elas evita 90% dos erros de configuração:

| Camada | Onde fica | O que guarda | Analogia |
|---|---|---|---|
| **`GATEWAY_KEYS`** | `config.php` | As chaves que **seus apps** usam para falar com o router. | A senha da portaria do prédio — quem chega precisa saber. |
| **`PROVIDERS`** | `providers.php` | Catálogo de URLs + dialeto (`openai`/`anthropic`) dos provedores upstream **e a chave de API padrão de cada um**. | A lista de balcões do prédio, com o endereço, o idioma falado em cada um e a senha de acesso. |
| **`MODELS`** | `models.php` | O nome que seu app pede → qual provedor (de `PROVIDERS`) atende → qual id do modelo lá. Pode **sobrescrever pontualmente** a `key` herdada de `PROVIDERS`. | A recepção: o visitante pede "gpt-oss-120b"; o recepcionista consulta a lista e sabe a qual balcão ir. |

Fluxo das referências: `MODELS` cita um nome de `PROVIDERS`; `PROVIDERS` cita uma URL e guarda a chave padrão do provedor. A `key` fica em `PROVIDERS` por padrão — assim você cadastra a chave de cada serviço **uma vez** e todos os modelos que usam aquele provedor a herdam. Quando um modelo precisar de uma conta/chave diferente do mesmo serviço, basta sobrescrever `key` na entrada específica de `MODELS`.

#### Exemplo mínimo que funciona

O menor setup que sobe — uma chave do gateway, um provedor, um modelo. `GATEWAY_KEYS` no `config.php`; `PROVIDERS` e `MODELS` em arquivos separados na raiz, incluídos via `require`:

```php
// config.php (padrão de fábrica — a chave vem de data/.env)
define('GATEWAY_KEYS', array_values(array_filter([
    env('LR_GATEWAY_KEY'),
], static fn (string $key): bool => trim($key) !== '')));
define('PROVIDERS', require __DIR__ . '/providers.php');
define('MODELS',    require __DIR__ . '/models.php');
```

```ini
# data/.env
LR_GATEWAY_KEY="sk-lr-sua-chave-real-aqui"
```

```php
// providers.php
return [
    'groq' => ['url' => 'https://api.groq.com/openai/v1', 'key' => env('GROQ_API_KEY')],
];
```

```php
// models.php
return [
    'gpt-oss-120b' => [
        ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
        // ...
    ],
    // ...
];
```

A `key` fica em `PROVIDERS` — todos os modelos que citam `groq` herdam essa chave automaticamente. A partir daqui, adicione mais provedores ao mesmo modelo (rotação), mais modelos, `weight`, `params`, `system_prompt` — e, se precisar, sobrescreva `key` pontualmente em uma entrada de `MODELS` — conforme as seções a seguir.

```php
// Direto no arquivo (simples, mas não torne público com chaves reais):
'key' => 'gsk_abc123',

// Ou via variável de ambiente (painel da hospedagem, Docker, CI):
'key' => env('GROQ_API_KEY'),

// O mesmo vale para qualquer define():
define('GATEWAY_KEYS', [env('LR_GATEWAY_KEY')]);
```

`env('X')` lê na ordem: (1) variável de ambiente real do SO/shell/Docker, se existir e não for vazia; (2) valor declarado em `data/.env`; (3) `''`. O ambiente real sempre vence sobre o arquivo, que só preenche o que estiver vazio.

O `env()` garante string vazia quando nem o ambiente nem o `.env` definem a variável — e chave vazia é sempre recusada, então env ausente **falha fechado**. Depois de configurar, valide com `php index.php check`.

> Por que `define()` e não `const`? `const` em PHP não aceita chamada de função — `const X = env(...)` é erro fatal. `define()` se comporta igual e libera o `env()`.

#### Arquivo `.env` (opcional)

Em localhost, exportar variáveis de ambiente no shell antes do `php -S` é incômodo. Se o arquivo `data/.env` existir, a função `env()` o carrega sob demanda (parseado uma única vez por processo, com `parse_ini_string` nativo) e usa como fallback quando a variável de ambiente real não existe ou está vazia. Variável de ambiente real (painel da hospedagem, shell, Docker) sempre tem prioridade — `.env` só preenche o que estiver vazio.

```ini
# data/.env  (NÃO versione este arquivo)
GROQ_API_KEY=gsk_abc123
OPENROUTER_KEY="com espaço"
# comentário
```

Mantenha `data/.env` no `.gitignore`. O `.htaccess` já bloqueia `data/` e arquivos `.env`.

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
# vai gerar algo tipo: sk-lr-4f9a2c8e1b7d3a6f5c0e9b2d...
```

Aceita várias chaves — útil para revogar o acesso de um app sem afetar os outros. Uma lista vazia faz o gateway recusar todas as requisições.

A primeira entrada também aceita a variável `LR_GATEWAY_KEY` (de `data/.env` ou do painel da hospedagem), que é o padrão de fábrica do `config.php` — assim a chave do gateway não precisa ficar versionada junto com o código:

```php
define('GATEWAY_KEYS', [
    env('LR_GATEWAY_KEY'),
]);
```

### `PROVIDERS` — catálogo de URLs, dialeto e chave padrão

Cada provedor é cadastrado **uma vez** com um nome livre, a URL base, o tipo de API (`openai` ou `anthropic`) e a **chave de API padrão** — só isso. A `key` fica em `PROVIDERS` para que todos os modelos que usam aquele provedor a herdem automaticamente; quando um modelo precisar de uma conta/chave diferente do mesmo serviço, basta sobrescrever `key` na entrada específica de `MODELS`. O `type` é fixo por provedor: OpenRouter sempre fala `openai`, a API nativa da Anthropic sempre fala `anthropic`. O `providers.php` traz **só os provedores usados pelos modelos que vêm de fábrica** — para que `php index.php check` fale do que importa em vez de listar vinte serviços sem chave. O resto do catálogo (Groq, Cerebras, Google AI Studio, Mistral, Hugging Face, SiliconFlow, Vercel AI Gateway, Cloudflare, OpenAI, xAI, Fireworks, Nebius, Novita, Z AI, LM Studio, vLLM) está em **`providers-extra.php.example`**: abra, copie a linha que quiser e cole em `providers.php`. Alguns serviços falam mais de um dialeto no mesmo host — o OpenCode Zen/Go, por exemplo, serve modelos Claude e Qwen via `/messages` (Anthropic) além do `/chat/completions` (OpenAI). Como o `type` é fixo por entrada de `PROVIDERS`, o catálogo traz a variante `opencode_anthropic` (mesma URL, `type => 'anthropic'`) para esses casos.

O catálogo vive em `providers.php` e retorna um array (lido pelo `config.php` via `require`). A função `env()` já está definida no momento do include, então as chaves podem ser lidas normalmente:

```php
// providers.php
return [
    'groq'        => ['url' => 'https://api.groq.com/openai/v1', 'key' => env('GROQ_API_KEY')],
    'openrouter1' => ['url' => 'https://openrouter.ai/api/v1',   'key' => env('OPENROUTER_KEY_1')],
    'openrouter2' => ['url' => 'https://openrouter.ai/api/v1',   'key' => env('OPENROUTER_KEY_2')],
    'anthropic'   => ['url' => 'https://api.anthropic.com/v1', 'type' => 'anthropic', 'key' => env('ANTHROPIC_API_KEY')],
];
```

| Campo | O que é |
|---|---|
| `url` | URL da API, como o provedor a publica — com ou sem a rota final (`/chat/completions`, `/messages`). O router ajusta internamente e anexa a rota certa conforme o `type` declarado no provedor. |
| `type` | Dialeto do provedor: `openai` (padrão, se omitido) ou `anthropic`. Define qual rota e quais cabeçalhos o router usa. |
| `key` | A chave de API padrão daquele provedor. Aceita **string** (legado) ou **array de strings** — array expande a entrada em N candidatos independentes (mesmo provedor/modelo, chaves diferentes), cada um rotacionado com seu próprio cooldown/breaker/latência. Evita repetir a entrada para múltiplas chaves do mesmo provedor. `''` para provedor local sem chave. |
| `rpd` | *Opcional.* Teto de requisições **por dia** daquela conta. O router conta as tentativas em `state.json` e tira o provedor da fila ao bater o limite, em vez de descobrir isso no 429 — que já custou a requisição. Omita se o provedor não publica limite diário. |
| `retries` | *Opcional (padrão `0`).* Tentativas extras de reconexão **no mesmo host** quando a falha parece um servidor serverless "acordando" — conexão recusada/resetada, 5xx de proxy ou o 404 `route not found` que o proxy devolve enquanto o container não subiu (caso da Modal e similares, que escalam a zero). Só essas falhas disparam retry; 401/403/429/400 nunca. Pode ser sobrescrito por entrada de `MODELS`. |
| `retry_delay` | *Opcional (padrão `10`).* Segundos de espera entre as reconexões de `retries`. A espera é fatiada para respeitar o `TOTAL_DEADLINE_SECONDS` e parar cedo se o cliente desistir. |

O nome é livre — use sufixos para **várias contas do mesmo serviço** (`openrouter1`, `openrouter2`, `groq_5`). Isso não é cosmético: o log, o cabeçalho `X-Router-Provider` e o cooldown passam a distinguir cada conta, coisa que a URL sozinha não faz (duas contas OpenRouter compartilham o mesmo host).

### `MODELS` — o que seu app pede

A chave do array é o nome que **seu app pede**; cada entrada aponta para um provedor do catálogo `PROVIDERS` e diz o id do modelo lá dentro. A `key` é **herdada de `PROVIDERS`** por padrão — só declare `key` aqui quando quiser sobrescrever pontualmente (por exemplo, para usar uma conta diferente daquele provedor só naquele modelo).

O catálogo vive em `models.php` e retorna um array (lido pelo `config.php` via `require`). A função `env()` já está definida no momento do include:

```php
// models.php
return [
    'gpt-oss-120b' => [
        ['provider' => 'groq',        'model' => 'openai/gpt-oss-120b',      'weight' => 6],
        ['provider' => 'cerebras',    'model' => 'gpt-oss-120b',             'weight' => 3],
        ['provider' => 'openrouter1', 'model' => 'openai/gpt-oss-120b:free'],
    ],
    'claude-sonnet' => [
        ['provider' => 'anthropic',  'model' => 'claude-sonnet-4.5'],
        ['provider' => 'openrouter1', 'model' => 'anthropic/claude-sonnet-4.5'],
    ],
];
```

| Campo | O que é |
|---|---|
| `provider` | Nome em `PROVIDERS`. |
| `model` | O id do modelo **naquele provedor**. O mesmo modelo tem ids diferentes em cada um. |
| `key` | Opcional. Sobrescreve pontualmente a `key` herdada de `PROVIDERS`. Aceita **string** (legado) ou **array de strings** — array expande a entrada em N candidatos independentes (mesmo provedor/modelo, chaves diferentes), cada um rotacionado com seu próprio cooldown/breaker/latência. `''` para forçar provedor local sem chave. Se omitido, herda de `PROVIDERS`. |
| `weight` | Opcional (padrão `1`). Com `STRATEGY = 'random'`, peso 6 tende a abrir a fila 6× mais que peso 1 — dá mais tráfego a quem tem limite maior, sem nunca excluir ninguém da rotação. |
| `params` | Opcional. Parâmetros só daquele provedor — ver abaixo. |
| `retries` / `retry_delay` | Opcionais. Sobrescrevem pontualmente os valores herdados de `PROVIDERS` — útil quando um modelo específico precisa de um ritmo de reconexão diferente (ver a tabela de `PROVIDERS`). |

A separação entre `PROVIDERS` (URL + type + key padrão) e `MODELS` (override pontual de key) existe para que você cadastre a chave de cada serviço **uma vez** em `PROVIDERS` e todos os modelos herdem — sem duplicar a chave em cada entrada de modelo. O `type` fica em `PROVIDERS` porque é fixo por provedor: OpenRouter sempre fala o dialeto OpenAI, a API nativa da Anthropic sempre fala Anthropic.

#### Múltiplas chaves do mesmo provedor

Em vez de repetir a entrada N vezes para N chaves do mesmo provedor, declare `key` como array — seja em `PROVIDERS` (padrão para todos os modelos que o citam) ou em uma entrada específica de `MODELS` (override pontual):

```php
// Em providers.php — vale para todos os modelos que citam 'nvidia':
return [
    'nvidia' => [
        'url' => 'https://integrate.api.nvidia.com/v1',
        'key' => [
            env('NVIDIA_API_KEY'),
            env('NVIDIA_API_KEY_2'),
            env('NVIDIA_API_KEY_3'),
        ],
    ],
];

// Ou, pontualmente, em uma entrada de models.php (sobrescreve o padrão de PROVIDERS):
'grok-4.5' => [
    [
        'provider' => 'openrouter',
        'model'    => 'x-ai/grok-4.5',
        'key'      => [
            env('OPENROUTER_KEY_1'),
            env('OPENROUTER_KEY_2'),
            env('OPENROUTER_KEY_3'),
        ],
    ],
],
```

O router cria 3 candidatos (`nvidia#1`, `nvidia#2`, `nvidia#3`) e rotaciona entre eles como se fossem provedores independentes — cada um com seu próprio cooldown, circuit breaker e latência. Chaves vazias dentro do array são filtradas antes da expansão, então você pode deixar as 3 posições no config mesmo usando só 1 em dev. O `label` de cada expansão ganha um sufixo `#N` para distingui-las no log, no cabeçalho `X-Router-Provider` e no estado de cooldown/breaker.

### `type` por modelo — chat, embedding e futuros

Cada entrada de `MODELS` pode declarar `type` para dizer em qual endpoint atende. Distinto do dialeto do provedor (`openai`/`anthropic`, que vive em `PROVIDERS`): `type` é o **tipo de modelo**, não o dialeto da API. Ausente = `'chat'` (retrocompatível). Valores válidos estão na allowlist `MODEL_TYPES` (em `config.php`); adicione novos lá quando surgirem (rerank, moderation…).

```php
// models.php
return [
    // chat (padrão — type ausente):
    'gpt-oss-120b' => [
        ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
    ],

    // embedding — atende em POST /embeddings:
    'embed-small' => [
        'type' => 'embedding',
        'providers' => [
            ['provider' => 'openrouter', 'model' => 'openai/text-embedding-3-small'],
            ['provider' => 'google',     'model' => 'text-embedding-004'],
        ],
    ],
];
```

O roteamento é por `type`: `/chat/completions` só atende modelos `chat`, `/embeddings` só atende `embedding`. Um modelo `embedding` pedido em `/chat/completions` devolve `404` (e vice-versa) — o app pediu no endpoint errado. `GET /models` lista todos, com `owned_by` distinto por `type` (`localrouter/chat`, `localrouter/embedding`…).

Vale a mesma rotação, o mesmo cooldown e o mesmo critério de resposta aproveitável do chat: `200` sem nenhum vetor conta como falha e passa para o próximo provedor. Duas restrições para `embedding`:

- **Só dialeto `openai`.** A API Anthropic não tem endpoint de embeddings; um provedor `anthropic` em um modelo `embedding` é apontado por `php index.php check` e pulado em runtime.
- **Sem `MODEL_FALLBACKS`.** Trocar o modelo de embedding muda a dimensão do vetor e invalida o índice de quem armazenou — o silêncio aqui seria caro demais.

Sem nenhum modelo `embedding` configurado, `/embeddings` responde `404`.

### Parâmetros por modelo

Um modelo pode fixar `temperature`, `top_p`, `top_k`, `max_tokens`, `stop` e qualquer item de `PASSTHROUGH_OPENAI` (`seed`, `response_format`, `reasoning_effort`…). **Qualquer outro nome também é aceito** — parâmetros não reconhecidos são repassados como `extra` aos provedores de dialeto `openai`, permitindo campos próprios de provedores exóticos (Modal, vLLM, LM Studio). A allowlist `PASSTHROUGH_OPENAI` só filtra o que VEM DA REQUISIÇÃO do cliente; o que o admin define no `config` passa direto. Provedores `anthropic` não recebem extras (não há equivalente seguro no dialeto). Todos são opcionais. Para isso, use a forma com `params` e `providers`:

```php
'redacao-criativa' => [
    'params' => ['temperature' => 1.1, 'top_p' => 0.95],
    'providers' => [
        ['provider' => 'groq',     'model' => 'openai/gpt-oss-120b'],
        // este provedor específico responde melhor mais frio:
        ['provider' => 'cerebras', 'model' => 'gpt-oss-120b', 'params' => ['temperature' => 0.6]],
    ],
],
```

A forma antiga (lista simples de provedores, sem `params`) continua válida — nesse caso valem só os padrões globais.

#### `system_prompt` por modelo

Um modelo pode fixar um prompt de sistema que o router injeta **antes** das mensagens do app, em toda requisição:

```php
'assistente-juridico' => [
    'system_prompt' => 'Você é um assistente jurídico conciso. Responda em português, cite o artigo quando relevante.',
    'providers' => [
        ['provider' => 'groq', 'model' => 'openai/gpt-oss-120b'],
    ],
],
```

Se o app também enviar mensagens `system`, o `system_prompt` do modelo vem **primeiro** e o do app é anexado em seguida — a precedência do app é mantida no conteúdo. Útil para fixar persona/regras sem obrigar todo app que usa o modelo a repetir o prompt.

**Precedência**, do mais forte ao mais fraco:

1. `params` do modelo
2. `params` da entrada do provedor
3. O que o app enviou na requisição

A configuração **sempre sobrescreve** o que o app pediu — o admin que configura o modelo sabe o que o provedor exige; o app não. Um app que manda `temperature: 0` para extrair JSON recebe `1.1` se o modelo estiver configurado assim. Para deixar o provedor decidir, basta não definir o parâmetro: `null` (ou ausência) significa "sem opinião" e o campo simplesmente não é enviado.

Parâmetros que não fazem sentido para o dialeto de destino são descartados: `seed` e `response_format` chegam a provedores `openai` e não a provedores `anthropic`; `top_k` é o inverso. `php index.php check` valida faixas dos parâmetros cujo significado é padronizado (`temperature` 0–2, `top_p` 0–1, `top_k` ≥ 0, `max_tokens` ≥ 1) e **não** reclama de nomes desconhecidos — eles são repassados como `extra` justamente para suportar campos próprios de provedores exóticos.

Um mesmo modelo lógico pode misturar dialetos: o primeiro provedor Anthropic, o segundo um agregador que fala OpenAI. A tradução acontece por dentro.

### Os ids de modelo envelhecem — e isso é normal

O `models.php` vem com um catálogo inicial de modelos gratuitos conferido em julho de 2026. Trate-o como ponto de partida, não como verdade permanente: a Groq já aposentou os modelos Llama, e DeepSeek, Mistral e Gemini perderam suas versões `:free` na OpenRouter. Slugs de modelo grátis mudam praticamente toda semana.

Para saber quais ids sumiram, rode:

```bash
php index.php sync
```

Ele pergunta a cada provedor o catálogo atual (`GET /models`) e lista os ids do seu `models.php` que não existem mais, dizendo em qual modelo cada um está declarado. Enquanto um id estiver desatualizado, o provedor responde 404, o LocalRouter pula para o próximo e o serviço não cai — mas você paga o provedor seguinte, normalmente o mais caro. Desde a 0.3 esse 404 também coloca o provedor em quarentena longa (`CONFIG_ERROR_COOLDOWN`), então a perda acontece uma vez a cada quinze minutos em vez de a cada requisição.

---

## Constantes

Todas ficam em `config.php`, em `define()` (com `PROVIDERS` e `MODELS` vindo de `providers.php` e `models.php` via `require`). As três primeiras (`GATEWAY_KEYS`, `PROVIDERS`, `MODELS`) estão descritas acima; o resto é opcional.

| Constante | Padrão | Para que serve |
|---|---|---|
| `MODEL_TYPES` | `['chat','embedding']` | Allowlist de valores válidos para o campo `type` em cada entrada de `MODELS`. Ausente = `'chat'`. Valores fora da lista viram `'chat'` em runtime e são apontados por `php index.php check`. Adicione novos tipos (rerank, moderation…) aqui quando surgirem — o resto do código já filtra por esta lista. |


| Constante | Padrão | Para que serve |
|---|---|---|
| `STRATEGY` | `'priority'` | Ordem em que os provedores de um modelo são tentados. `priority`: a ordem do array. `random`: sorteio ponderado pelo `weight`. `fastest`: menor latência medida (p50 da janela de métricas) — precisa de `METRICS_BACKEND` ligado e, sem dados, cai em `priority`. |
| `MAX_ATTEMPTS` | `0` | Teto de provedores tentados por requisição. `0` (padrão) remove o limite — todos os provedores do modelo são tentados. |
| `PASSTHROUGH_OPENAI` | lista | Parâmetros repassados intactos a provedores de dialeto `openai` (`seed`, `response_format`, `reasoning_effort`…). Provedores `anthropic` não os recebem: não há equivalente exato. **`n` fica de fora de propósito**: o gateway devolve uma escolha só (`choices[0]`), então repassá-lo faria o provedor gerar e cobrar N respostas para o app receber uma. |
| `EXPOSE_PROVIDER_HEADER` | `true` | Devolve `X-Router-Provider`, `X-Router-Model`, `X-Router-Attempt` e, quando houve troca de modelo, `X-Router-Fallback`. Em resposta de erro vem `X-Router-Attempts` com quantos provedores foram tentados. Desligue se os clientes não forem seus. |
| `MODEL_FALLBACKS` | `[]` | Fallback **entre modelos**: esgotados os provedores de um, segue nos do outro. Ex.: `['claude-sonnet' => 'gpt-oss-120b']`. Quando isso acontece o cliente recebe `X-Router-Fallback` com o modelo que realmente respondeu — a troca nunca é silenciosa. |
| `COOLDOWN_SECONDS` | `60` | Provedor que falhou por motivo **passageiro** (limite, crédito, 5xx, rede) fica de castigo por N segundos e nem é tentado. Exige `STATE_FILE` gravável. |
| `CONFIG_ERROR_COOLDOWN` | `900` | Castigo para erro de **configuração** do provedor (401/403 chave rejeitada, 404 modelo inexistente). Bem maior de propósito: id de modelo que mudou não volta a funcionar em um minuto, e insistir de minuto em minuto queima uma tentativa de toda requisição seguinte. `0` usa `COOLDOWN_SECONDS`. |
| `BREAKER_FAILURES` | `0` (off) | Circuit breaker formal além do cooldown. Com N falhas **consecutivas** o provedor fica "aberto" por `BREAKER_OPEN_SECONDS` e só volta via probe (1 req de teste) a cada `BREAKER_PROBE_SECONDS`. Falha de rede pura conta como 0.5. Exige `STATE_FILE`. |
| `BREAKER_OPEN_SECONDS` | `60` | Quanto tempo o circuito fica aberto antes de permitir probe. |
| `BREAKER_PROBE_SECONDS` | `30` | Intervalo entre probes no estado half-open. |
| `RETRY_SAME_PROVIDER` | `1` | Tentativas extras no **mesmo** provedor antes de rotacionar, apenas para falha de rede pura (timeout, DNS, conexão recusada — sem status HTTP). Nunca para 429/402 (cooldown no primeiro erro). |
| `RATE_LIMIT_PER_MINUTE` | `0` (off) | Teto de requisições por minuto **por chave** de `GATEWAY_KEYS`. Por chave, e não global: com vários apps no mesmo gateway, um app em loop não pode consumir a cota dos outros. Ao estourar, devolve `429` com header `Retry-After` (segundos restantes na janela). |
| `STATE_FILE` | `data/state.json` | Arquivo de estado do cooldown, circuit breaker, rate limit e do contador diário `rpd`. A pasta nasce com um `.htaccess` próprio negando acesso web. |
| `METRICS_BACKEND` | `'file'` | Métricas de uso por provedor: `'off'` (desligado) ou `'file'` (JSON rolado em `METRICS_FILE`). Não há backend de banco: o `LOG_FILE` já é TSV, uma tentativa por linha, e `awk`/`cut` respondem qualquer pergunta de histórico sem exigir extensão extra do PHP. |
| `METRICS_FILE` | `data/metrics.json` | Caminho do arquivo de métricas. |
| `METRICS_WINDOW_SECONDS` | `3600` | Janela rolante das métricas. |
| `METRICS_EXPOSE` | `false` | Expõe `GET /metrics` (autenticado). Desligado por padrão. |
| `METRICS_FORMAT` | `'json'` | Formato de `/metrics`: `'json'` ou `'prometheus'` (text format, compatível com Prometheus/Grafana). |
| `ALLOWED_IPS` | `[]` (todos) | Allowlist de IPs. Aceita IP exato ou prefixo (`'192.168.'`). Usa `REMOTE_ADDR`, nunca `X-Forwarded-For`. |
| `REQUIRE_HTTPS` | `true` | Recusa requisição em HTTP puro no nível do PHP (localhost isento). Defesa em profundidade além do `.htaccess`. |
| `TRUSTED_PROXIES` | `[]` (nenhum) | Proxies que terminam o TLS na frente do PHP (Cloudflare, nginx, balanceadores). Só de IPs desta lista o gateway confia em `X-Forwarded-Proto` para decidir HTTPS. Vazio = nunca confia no cabeçalho. Aceita IP exato ou prefixo, igual a `ALLOWED_IPS`. |
| `LOG_FILE` | `data/localrouter.log` | Caminho do log (renomeado de `router.log` em 0.2). String vazia desativa. |
| `LOG_MAX_BYTES` | `5 MiB` | Ao passar disso o log vira `.1` e recomeça. |
| `CONNECT_TIMEOUT` | `10` | Segundos para abrir conexão com o provedor. |
| `REQUEST_TIMEOUT` | `180` | Segundos por tentativa, fora de streaming. |
| `STREAM_HEARTBEAT_SECONDS` | `15` | Em streaming, manda um comentário SSE (`: keep-alive`) a cada N segundos sem dados do provedor. Modelos de raciocínio ficam um ou dois minutos pensando antes do primeiro token, e proxy (Cloudflare, nginx) derruba conexão ociosa bem antes. O comentário é ignorado por qualquer cliente SSE. `0` desativa. |
| `TOTAL_DEADLINE_SECONDS` | `300` | Teto de tempo da **requisição inteira**, somando todas as tentativas. Sem ele o pior caso é `MAX_ATTEMPTS × REQUEST_TIMEOUT` — com os padrões, 12 minutos, tempo que nenhum cliente espera. O router encurta o timeout de cada tentativa para caber no que resta e para de rotacionar quando o orçamento acaba. `0` desativa. |
| `STREAM_STALL_TIME` | `60` | Em streaming, segundos sem receber bytes antes de abortar. |
| `MAX_BODY_BYTES` | `8 MB` | Teto do corpo aceito do cliente. |
| `DEFAULT_MAX_TOKENS` | `4096` | Último recurso para `max_tokens`: a API Anthropic exige o campo, então este valor evita que a chamada seja recusada quando ninguém definiu nada. |
| `ALLOW_ORIGIN` | `''` | Origem para CORS. Vazio desliga; só preencha se algum front-end chamar direto do navegador. |
| `ANTHROPIC_VERSION` | `2023-06-01` | Valor do cabeçalho `anthropic-version`. |
| `SKIP_EMPTY_REMOTE_KEY` | `true` | Pula provedores remotos sem chave em runtime — a chamada iria falhar 401 e gastar uma tentativa à toa. Provedores locais (Ollama, LM Studio) rodam sem chave legitimamente e seguem na fila. |
| `FORCE_MAX_TOKENS_OPENAI` | `false` | Aplica `DEFAULT_MAX_TOKENS` a provedores OpenAI quando o cliente não enviou `max_tokens`. Alguns provedores compatíveis (vLLM, LM Studio) rejeitam requisições sem o campo; outros aceitam mas geram até o limite do contexto. |
| `REQUEST_ID_HEADER` | `true` | Devolve `X-Request-Id` e o grava em log e métricas — correlaciona todas as tentativas (provedores, retries, fallbacks) de uma mesma chamada. Se o app já mandar um `X-Request-Id` de formato seguro, o router reaproveita em vez de gerar outro: o mesmo id aparece nos dois logs. |
| `CA_BUNDLE` | `''` | Caminho para um bundle de CA (`.crt`) usado pelo curl para validar os certificados TLS dos provedores. Necessário no Windows/XAMPP, onde o libcurl não encontra um bundle de CA sozinho e toda chamada HTTPS falha com "unable to get local issuer certificate". Vazio = confia no default do libcurl. |

#### Guardrails (validação de entrada)

Todas desligadas por padrão; ligar não quebra clientes existentes.

| Constante | Padrão | Para que serve |
|---|---|---|
| `INPUT_VALIDATE_CONTENT_TYPE` | `false` | Valida o `Content-Type` da requisição contra `INPUT_ALLOWED_CONTENT_TYPES`. |
| `INPUT_ALLOWED_CONTENT_TYPES` | `['application/json']` | Content-Types aceitos quando `INPUT_VALIDATE_CONTENT_TYPE` está ligado. `[]` = aceita qualquer um. |
| `INPUT_MAX_CHARS` | `null` | Teto de caracteres somando o texto de todas as mensagens. `null` = sem limite. |
| `INPUT_TRIM_WHITESPACE` | `false` | Aplica `trim()` no texto de cada mensagem antes de processar. |
| `INPUT_REJECT_EMPTY_MESSAGE` | `false` | Recusa requisições cujas mensagens não tenham nenhum texto. |
| `INPUT_BLOCK_CLIENT_SYSTEM_PROMPT` | `false` | Descarta mensagens `system`/`developer` enviadas pelo cliente — o `system_prompt` do modelo (em `models.php`) é o único que vale. |
| `INPUT_BLOCK_CLIENT_MODEL_OVERRIDE` | `false` | Ignora o campo `"model"` do cliente e atende sempre no primeiro modelo de chat do catálogo. Útil quando o gateway serve um único modelo. |
| `BLOCKED_TERMS` | `[]` | Lista global de termos proibidos na entrada. |
| `BLOCKED_TERMS_MATCH_MODE` | `'contains'` | Como comparar: `'exact'`, `'contains'` ou `'regex'`. |
| `BLOCKED_TERMS_CASE_INSENSITIVE` | `true` | Ignora maiúsculas/minúsculas na comparação. |
| `INPUT_USE_BLOCKED_TERMS` | `false` | Ativa a checagem de `BLOCKED_TERMS` na entrada. |

Sobre a escolha de `STRATEGY`:

- **`priority`** é o padrão e o mais previsível: o nº 1 recebe todo o tráfego. A desvantagem aparece quando ele estoura o limite — até o cooldown entrar, cada requisição paga uma ida e volta perdida antes de cair no nº 2.
- **`random`** dilui esse custo distribuindo o tráfego. Use `weight` para dar mais peso a quem tem cota maior.
- **`fastest`** ordena pelo p50 medido na janela de métricas. Provedor ainda sem medição recebe a média dos demais, então entra no meio da fila e vai sendo amostrado — não fura a frente de quem já provou ser rápido nem fica esquecido no fim.

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

---

## Linha de comando

O mesmo arquivo funciona como utilitário no terminal (rode de dentro da pasta do projeto):

```bash
php index.php genkey          # gera uma chave para colar em LR_GATEWAY_KEY (data/.env)
php index.php check           # valida a configuração e aponta erros comuns
php index.php providers       # lista os provedores configurados (sem revelar chaves)
php index.php test <modelo>   # faz uma chamada real e mostra qual provedor atendeu
php index.php sync            # confere os ids de modelo contra o catálogo de cada provedor
```

O `check` detecta chave de exemplo esquecida, referência a provedor inexistente, `type` inválido em `PROVIDERS`, `type` de modelo fora da allowlist `MODEL_TYPES`, provedor em HTTP puro fora de localhost, entrada incompleta, `weight` e `rpd` inválidos, fallback apontando para modelo inexistente, provedor `anthropic` em um modelo `type: embedding` e chave faltando. Provedor sem chave gera **um** aviso com a lista de modelos que dependem dele, não um aviso por entrada — o objetivo é caber na tela. Sai com código `1` quando há problema, então serve em CI ou script de deploy.

O `providers` mostra nome, tipo, URL, teto diário e se a chave está definida (`sim`/`nao`/`local`) — para conferir o catálogo sem expor segredos.

O `test` é a forma mais rápida de saber se está tudo de pé: faz uma chamada de verdade e imprime a fila inteira, com o motivo de cada falha até alguém responder.

```
Testando 'gpt-oss-120b' — 2 provedor(es) na fila.

  1. nvidia                   openai/gpt-oss-120b              FALHA   412ms  rate limit (quota exceeded)
  2. openrouter               openai/gpt-oss-120b:free         OK      893ms

  Atendido por 'openrouter' em 893ms.
```

O `sync` ataca o problema operacional nº 1 deste tipo de gateway: **id de modelo envelhece**. Ele pergunta a cada provedor quais modelos existem hoje (`GET /models`) e aponta os ids do seu `models.php` que sumiram. Sem isso o provedor responde 404, o router rotaciona em silêncio para o próximo — normalmente o mais caro — e você só descobre na fatura.

---

## Endpoints

A API é **exclusivamente no formato OpenAI**. (Quem migrou de versões antigas: `/messages` deixou de existir; provedores Anthropic continuam suportados por dentro.)

| Método | Rota | Descrição |
|---|---|---|
| `POST` | `/chat/completions` | Entrada e saída no formato OpenAI. Aceita `stream: true`. |
| `POST` | `/embeddings` | Vetores de embedding, mesma rotação de provedores. Só modelos de `type: embedding` em `MODELS`, e só provedores de dialeto `openai` (a API Anthropic não tem endpoint equivalente). Devolve `404` se não houver nenhum modelo de embedding configurado. |
| `GET` | `/models` | Lista os modelos de chat e de embedding configurados (`owned_by` distingue os dois). Exige autenticação. |
| `GET` | `/health` | `{"status":"ok"}` quando o gateway está configurado; `503 {"status":"unconfigured"}` com a chave de fábrica. **Sem autenticação** e fora da allowlist de IPs de propósito — o monitor local (`curl http://localhost:8000/health`) nunca é barrado, e a resposta não carrega nada sensível. |
| `GET` | `/health/providers` | Health detalhado por provedor: contagem por status HTTP, latência p50/p95, taxa de erro, última tentativa e, para quem declarou `rpd`, o consumo do dia (`rpd_used`/`rpd_limit`). **Autenticado**. Exige `METRICS_BACKEND != 'off'`; caso contrário devolve `503 {"status":"metrics_off"}`. |
| `GET` | `/metrics` | Métricas agregadas por provedor, formato `json` ou `prometheus`. **Autenticado** e **opt-in** (`METRICS_EXPOSE = true`). |

A spec OpenAPI 3 completa está em `openapi.yaml` — abra em qualquer editor Swagger/redoc ou gere um cliente com `openapi-generator`.

As rotas casam pelo **sufixo** do caminho. Isso faz funcionar tanto `/chat/completions` (com o rewrite do `.htaccess`) quanto `/index.php/chat/completions` (sem rewrite, em servidor que não permite `.htaccess`).

---

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

Nada é enviado ao cliente antes de o provedor confirmar `200` **e** emitir o primeiro evento aproveitável. Por isso `429`, `401` e `5xx` continuam rotacionando normalmente mesmo em streaming — e um provedor que abre o SSE só para mandar `{"error": ...}` também rotaciona, sem sujar a resposta.

**O stream só é dado como concluído quando o terminador do provedor chega** (`[DONE]` ou um `finish_reason` no dialeto OpenAI; `message_stop` no Anthropic). Isso não é detalhe: quando o provedor fecha a conexão no meio do stream, o `curl` termina *com sucesso* e status `200` — sem conferir o terminador, o router entregaria meia resposta como se fosse a resposta inteira, e o app não teria como saber. Com a conferência, a queda vira failover.

Por isso o dialeto `openai` também é reescrito evento a evento em vez de repassado cru: além de detectar o corte, o router troca o `id` e o `model` de cada chunk pelos do gateway — o app que pediu `gpt-oss-120b` recebe `gpt-oss-120b`, não `openai/gpt-oss-120b:free`. O `id` do stream é gerado uma vez por requisição, então mesmo uma retomada em outro provedor chega ao cliente como um único stream coerente.

Antes do primeiro token, o router manda um comentário SSE (`: keep-alive`) a cada `STREAM_HEARTBEAT_SECONDS` sem dados do provedor. Modelos de raciocínio ficam um ou dois minutos pensando antes de emitir qualquer coisa, e proxy (Cloudflare, nginx) derruba conexão ociosa bem antes disso. O comentário é ignorado por qualquer cliente SSE e mantém o cano vivo.

Depois que o primeiro byte sai, não existe volta: a resposta parcial já está com o cliente. **Mas se a conexão com o provedor cair no meio do stream, o router não desiste** — ele pega o texto já emitido, injeta como mensagem `assistant` no próximo provedor da fila e pede a **continuação** a partir de onde parou (com uma instrução no `system` e `tool_choice: 'none'` para não virar chamada de ferramenta). O cliente recebe o texto completo, emendado, sem perceber a troca.

**Provedor que ignora `stream: true`** e devolve uma resposta JSON normal (acontece em setups de vLLM e LM Studio) não queima a fila: o conteúdo está correto, só não veio no formato pedido, então o router o reemite como um stream de um chunk. No log isso aparece como `ok-stream-convertido`.

Duas coisas fazem a emenda ficar limpa em vez de só funcionar no papel:

- **A repetição é cortada.** Modelos recomeçam repetindo o final do que já disseram, mesmo com instrução em contrário. O router segura os primeiros bytes da continuação, procura o maior sufixo do texto anterior que seja prefixo dela e descarta essa sobreposição. O cliente recebe `Ola mundo` + `a mundo emendado` como `Ola mundo emendado`.
- **Tool call não é retomado.** Se o stream caiu depois de já ter emitido um `tool_call`, o cliente ficou com um `arguments` JSON pela metade; um segundo provedor abriria outra chamada por cima e sobrariam duas ferramentas quebradas no lugar de uma resposta. Nesse caso o router para, manda um evento de erro e fecha o stream — falhar claro é melhor que emendar errado. Só se **todos** os provedores caírem no meio é que o stream é encerrado: o router emite um evento de erro (`{"error": {...}}`) e depois `[DONE]`, para o app saber que a resposta terminou incompleta em vez de ficar esperando um terminador que nunca vem. O que já foi emitido fica com o cliente.

A distinção importa: se foi o **cliente** que desconectou, não há sentido em continuar — ninguém está lendo. Nesse caso o gateway aborta a chamada ao provedor para não pagar tokens à toa.

---

## Funcionalidades opcionais

Todas desligadas por padrão e ativadas por constante, sem tocar no resto do código.

**Cooldown de provedores** (`COOLDOWN_SECONDS`, `CONFIG_ERROR_COOLDOWN`) — quem falhou fica fora da rotação por um tempo, e a requisição seguinte vai direto a quem está saudável. A janela depende do **tipo** de falha, porque são coisas diferentes:

- **Passageira** (429, 402, 5xx, rede) → `COOLDOWN_SECONDS`, um minuto por padrão. O provedor provavelmente volta sozinho.
- **De configuração** (401/403 chave rejeitada, 404 modelo inexistente) → `CONFIG_ERROR_COOLDOWN`, quinze minutos por padrão. Chave revogada ou id de modelo que mudou não volta sozinho; insistir de minuto em minuto queima uma tentativa de **toda** requisição seguinte. Quando isso acontecer, rode `php index.php sync`.
- **Do cliente** (400) → castigo nenhum. Um 400 quase sempre vem do que o app mandou: prompt maior que o contexto, imagem em formato não suportado, parâmetro que aquele modelo não aceita. O router ainda rotaciona (o próximo provedor pode ter contexto maior), mas não tira ninguém da fila — a requisição seguinte pode estar perfeita. Se todos recusarem, a resposta volta como `400 invalid_request_error` com a mensagem do provedor, e não como erro de infraestrutura.

`COOLDOWN_SECONDS = 0` desliga o mecanismo inteiro, inclusive a quarentena longa.

Se todos os provedores estiverem de castigo, o castigo é ignorado — tentar é melhor que falhar parado, já que o estado é uma estimativa nossa e não a verdade do provedor.

**Circuit breaker** (`BREAKER_FAILURES`) — além do cooldown, um provedor com N falhas **consecutivas** (qualquer tipo) fica "aberto" por `BREAKER_OPEN_SECONDS` e sai da rotação. A recuperação é por **probe**: a cada `BREAKER_PROBE_SECONDS` o router deixa passar 1 requisição de teste; se acertar, o circuito fecha e o provedor volta pleno. Falha de rede pura (timeout, DNS) conta como 0.5 — precisa de duas para somar uma falha inteira, porque erros de rede costumam ser transitórios. Diferente do cooldown (que é por evento), o breaker acumula: um provedor que falha 1x a cada minuto nunca abre, mas um que falha 5x seguidas sim. Funciona sem `COOLDOWN_SECONDS`, mas os dois se complementam.

**Retry no mesmo provedor** (`RETRY_SAME_PROVIDER`) — para falha de rede pura (sem status HTTP), tenta o mesmo provedor N vezes extras antes de rotacionar. Erros de rede costumam ser transitórios e baratos de tentar de novo; rotacionar já pagaria a latência de qualquer jeito. Nunca se aplica a 429/402 (cooldown no primeiro erro) nem a stream já em andamento (o parcial é guardado e a retomada acontece no próximo provedor).

**Reconexão de cold start** (`retries`/`retry_delay` em `PROVIDERS`) — provedores serverless que escalam a zero (Modal e similares) derrubam a primeira chamada enquanto o container sobe: conexão recusada, 5xx do proxy ou o 404 `route not found` característico. Com `'retries' => 2, 'retry_delay' => 10`, o router espera e reconecta **no mesmo host** em vez de rotacionar para um provedor mais caro. Distinto de `RETRY_SAME_PROVIDER`: este é global e só cobre falha de rede pura sem status HTTP; aquele é por provedor e também cobre o 5xx e o 404 de "rota não encontrada" típicos de cold start.

**Métricas por provedor** (`METRICS_BACKEND`) — registra por tentativa: provedor, status HTTP, latência, resultado. Um único backend, `'file'`: JSON rolado em `data/metrics.json`, janela de `METRICS_WINDOW_SECONDS`. Exposto em `GET /health/providers` (sempre que autenticado) e `GET /metrics` (opt-in via `METRICS_EXPOSE`, formato `json` ou `prometheus`). Nunca grava chaves nem conteúdo. Para histórico além da janela, o `LOG_FILE` já é TSV com uma tentativa por linha — `awk` responde qualquer recorte sem exigir extensão extra do PHP.

**Teto diário por provedor** (`rpd` em `PROVIDERS`) — free tier costuma limitar por requisições **por dia**, não por minuto, e o cooldown só reage *depois* do 429, quando a requisição já foi queimada. Declarando `'rpd' => 1000`, o router conta as tentativas do dia em `state.json` e tira o provedor da fila ao bater o limite, antes de custar uma tentativa. O consumo aparece em `GET /health/providers`. Cada chave de um array `key` tem seu próprio contador — são contas diferentes.

**Rate limit do gateway** (`RATE_LIMIT_PER_MINUTE`) — janela fixa de um minuto **por chave** de `GATEWAY_KEYS`. Não substitui o rate limit dos provedores; protege contra um app seu em loop e limita o estrago de uma chave do gateway vazada.

**Peso por provedor** (`weight` em cada provedor) — distribui o tráfego proporcionalmente ao limite de cada um. Sorteio ponderado **sem reposição**: o peso muda a frequência com que cada provedor abre a fila, nunca a disponibilidade — todos continuam na rotação de failover.

**Fallback entre modelos** (`MODEL_FALLBACKS`) — a rotação padrão é só entre provedores do mesmo modelo. Com fallback, esgotar `claude-sonnet` pode continuar em `gpt-oss-120b`. A resposta volta com o nome do modelo **pedido** (o que os SDKs esperam); o cabeçalho `X-Router-Model` mostra qual modelo real atendeu. Ciclos (`A → B → A`) são detectados e ignorados.

**Allowlist de IPs** (`ALLOWED_IPS`) — segunda tranca além da chave, útil quando só os seus servidores devem alcançar o gateway. Compara com `REMOTE_ADDR`; `X-Forwarded-For` é ignorado porque qualquer cliente pode forjá-lo.

**HTTPS obrigatório no PHP** (`REQUIRE_HTTPS`) — o `.htaccess` já redireciona, mas ele não roda em todo servidor. Esta constante recusa (não redireciona) requisições em HTTP puro: se a chave chegou por HTTP, ela já trafegou exposta — redirecionar só faria o cliente reenviá-la.

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

### Raciocínio (reasoning)

Modelos de raciocínio expõem o "pensamento" antes da resposta. O router traduz isso nos dois sentidos e o entrega no campo `reasoning_content` da mensagem — convenção de facto entre os provedores compatíveis com OpenAI (DeepSeek, OpenRouter), e um campo que clientes antigos simplesmente ignoram:

| Provedor | O que chega | O que o cliente recebe |
|---|---|---|
| `anthropic` | bloco `thinking` / `thinking_delta` | `message.reasoning_content` / `delta.reasoning_content` |
| `openai` | `reasoning_content` ou `reasoning` | `message.reasoning_content` |

O raciocínio nunca é **reenviado** numa chamada seguinte: é saída, não entrada. E um provedor que devolve só raciocínio, sem texto nem `tool_call`, conta como resposta vazia e faz o router rotacionar — senão o app receberia `content: null` achando que o modelo decidiu não responder.

### Não convertido

`response_format` (modo JSON), `seed`, `reasoning_effort`, `logprobs` e afins passam intactos a provedores `openai` (lista `PASSTHROUGH_OPENAI`) e são descartados para provedores `anthropic`: não existe equivalente exato do outro lado — e inventar um faria o router mentir sobre o que foi pedido.

---

## Logs e métricas

Cada tentativa vira uma linha TSV, com dez colunas nesta ordem:

```
data | modelo pedido | provedor | modelo no provedor | status | duração | resultado | request id | tokens entrada | tokens saída
```

```
2026-08-02T09:14:03+00:00	gpt-oss-120b	nvidia	openai/gpt-oss-120b	429	412ms	rate limit (quota exceeded)	req_9f2c…	0	0
2026-08-02T09:14:04+00:00	gpt-oss-120b	openrouter	openai/gpt-oss-120b:free	200	893ms	ok	req_9f2c…	118	240
```

TSV porque `cut` e `awk` já resolvem qualquer recorte, sem precisar de banco:

```bash
awk -F'\t' '$5==429 {print $3}' data/localrouter.log | sort | uniq -c   # quem mais bate no limite
awk -F'\t' '{i+=$9; o+=$10} END {print i, o}' data/localrouter.log      # tokens do arquivo inteiro
```

Uma linha por tentativa, separada por tabulação, dez campos (`request_id` só vem junto se `REQUEST_ID_HEADER = true`), em `data/localrouter.log` (renomeado de `router.log` em 0.2):

```
2026-08-02T09:14:03+00:00	gpt-oss-120b	groq	openai/gpt-oss-120b	429	310ms	rate limit (Rate limit reached)	req_9f2c…	0	0
2026-08-02T09:14:04+00:00	gpt-oss-120b	cerebras	gpt-oss-120b	200	842ms	ok	req_9f2c…	241	320
```

Campos: data ISO, modelo pedido, **nome do provedor** (o de `PROVIDERS`, não o host — é o que separa `openrouter1` de `openrouter2`), id do modelo no provedor, status HTTP, duração, resultado, request id, tokens de entrada, tokens de saída.

O log **não** grava chaves de API nem o conteúdo das mensagens. Chaves que apareçam em mensagens de erro do provedor são substituídas por `[chave omitida]` antes de qualquer gravação ou resposta.

Para ver a taxa de erro por provedor:

```bash
cut -f3,7 data/localrouter.log | sort | uniq -c | sort -rn
```

### Métricas agregadas

O log é texto; para responder "qual provedor tem mais 429 hoje?" ou "qual a latência p95 do Groq?" existem as **métricas** (`METRICS_BACKEND`), que agregam contagem por status, latência p50/p95 e taxa de erro por provedor. Um backend:

- **`file`** — `data/metrics.json`, rolado a cada `METRICS_WINDOW_SECONDS` (default 1h). Leve, sem extensões. (O backend SQLite existiu até a 0.2 e foi removido: o log TSV já dá o histórico que ele entregava, sem exigir `ext-sqlite`.)

Consulte via `GET /health/providers` (sempre que métricas estiverem on) ou `GET /metrics` (opt-in via `METRICS_EXPOSE`, formato `json` ou `prometheus` para Prometheus/Grafana):

```bash
curl http://localhost:8000/metrics \
  -H "Authorization: Bearer SUA_CHAVE"
# O formato segue METRICS_FORMAT do config ('json' ou 'prometheus').
```

---

## Segurança

O que já está implementado:

- **Falha fechada.** Sem chave válida, sem resposta. Sem `LR_GATEWAY_KEY` definida no `data/.env`, o gateway se recusa a servir.
- **Comparação em tempo constante** das chaves do gateway (`hash_equals`), o que evita descobrir a chave medindo tempo de resposta.
- **Redação de segredos** em mensagens de erro dos provedores — vários devolvem a própria chave no texto do erro.
- **Sem redirecionamento automático**, para a chave do provedor não ser reenviada a outro host.
- **Erros genéricos** para o cliente: nada de caminho de arquivo, stack trace ou detalhe interno.
- **HTTPS forçado** duas vezes: redirect no `.htaccess` e recusa no PHP (`REQUIRE_HTTPS`), exceto em `localhost`.
- **Corpo limitado antes de ler**: requisição acima de `MAX_BODY_BYTES` é rejeitada pelo tamanho declarado e a leitura nunca passa do teto — corpo gigante não consome memória.
- **Aborto por desconexão**: cliente que fecha a conexão no meio de um streaming derruba também a chamada ao provedor.
- **`Authorization` preservado em CGI/FastCGI** pelo `.htaccess` — sem isso, metade das hospedagens compartilhadas devolveria 401 para tudo.
- **Allowlist de IPs opcional** (`ALLOWED_IPS`) como segunda tranca.
- **Listagem de diretório desligada** em duas trancas: `Options -Indexes` + `IndexIgnore *` no `.htaccess`, e `data/` bloqueada por rewrite além do `.htaccess` interno que o PHP cria dentro dela.

O que depende de você:

- **Não versione o arquivo com chaves reais.** Se o projeto for para um repositório público, troque os valores por `env('GROQ_KEY')` e defina as variáveis no `data/.env` ou no painel da hospedagem.
- **Confirme que `localrouter.log` não é servido.** O `.htaccess` bloqueia `.log`, mas em Nginx a regra equivalente precisa ser escrita no `server` block.
- **Rotacione a chave do gateway** se ela circular por apps de terceiros.

---

## Troubleshooting

Diagnóstico por etapas, em texto (sem fluxogramas). Siga na ordem — cada passo aponta o próximo.

### 1. A configuração está válida?

```bash
php index.php check
```

Lista problemas de configuração (chaves ausentes, URLs sem protocolo, referências quebradas em `MODELS`, parâmetros fora de faixa). Sai com código `1` se houver qualquer problema, `0` se tudo estiver ok. Provedor remoto sem chave aparece como `[?]` (nota — será ignorado em runtime, o gateway segue com os demais); só vira `[!]` (erro) quando um modelo fica sem **nenhum** provedor viável — todos sem chave e nenhum local. Provedores locais sem chave (Ollama, LM Studio) são legítimos e não geram aviso.

Para ver só os provedores configurados (sem revelar chaves):

```bash
php index.php providers
```

Mostra nome, tipo, URL e se a chave está definida (`sim`/`nao`/`local`).

### 2. O gateway está no ar?

```bash
# local
curl http://localhost:8000/health

# publicado
curl https://seudominio.com/health
```

- `200 {"status":"ok"}` — gateway configurado e respondendo.
- `503 {"status":"unconfigured"}` — `LR_GATEWAY_KEY` não está definida no `data/.env`. Gere uma com `php index.php genkey` e preencha.

### 3. Os provedores estão saudáveis?

```bash
# métricas passivas (latência/erros da janela atual — requer autenticação)
curl https://seudominio.com/health/providers -H "Authorization: Bearer SUA_CHAVE"

```

Provedores que declararam `rpd` trazem também `rpd_used` e `rpd_limit` — é o número que decide se aquele provedor ainda está na fila hoje.

### 4. O que o log diz?

```bash
tail -n 50 data/localrouter.log
```

Cada linha: `data\tmodelo\tprovedor\tmodelo_do_provedor\tstatus\tduração_ms\tresultado\trequest_id`. O `request_id` (presente se `REQUEST_ID_HEADER=true`) correlaciona todas as tentativas de uma mesma chamada — útil para depurar "falhou em algum provedor" sem adivinhar qual. Filtre por um ID:

```bash
grep "req_a1b2c3d4e5f6" data/localrouter.log
```

### 5. O cliente recebeu erro, mas o log mostra 200

O provedor respondeu 200 ao gateway, mas o cliente desconectou antes de receber tudo (stream interrompido). O log registra `cliente-desconectou` nesses casos. Verifique timeout no lado do cliente e estabilidade da conexão.

### 6. Todos os provedores falham com 401

As chaves dos provedores estão vazias ou erradas. Rode `php index.php check` — provedores remotos sem chave aparecem como `[?]` (serão ignorados em runtime) e o `[!]` só surge quando algum modelo fica sem provedor viável. Se `SKIP_EMPTY_REMOTE_KEY=true`, provedores remotos sem chave são pulados em runtime (não gastam tentativa); se `false`, tentam e falham 401. Confirme as variáveis de ambiente ou o `data/.env`.

### 7. Provedor local (Ollama) nunca é tentado

Verifique se a URL em `PROVIDERS` aponta para `http://127.0.0.1:11434/v1` (ou `http://localhost:...`). Provedores locais são detectados pelo host (`127.0.0.1`, `::1`, `localhost`, `*.localhost`) e não são pulados quando a chave está vazia. Se o Ollama não estiver rodando, a chamada falha com status `0` (conexão recusada) e o router tenta o próximo provedor.

### 8. Erro intermitente, some ao recarregar

Provavelmente rate limit ou circuit breaker. Veja `data/state.json`:

```bash
cat data/state.json
```

Mostra cooldowns ativos por provedor e o timestamp da última janela de rate limit. Se um provedor está em cooldown, ele volta sozinho após `COOLDOWN_SECONDS`.

---

## Erros comuns

**`503` — "Defina uma chave propria em GATEWAY_KEYS antes de usar (php index.php genkey)."**
Proteção intencional. Gere uma chave com `php index.php genkey` e preencha `LR_GATEWAY_KEY` no `data/.env`.

**`429` com `Retry-After` — rate limit do gateway**
`RATE_LIMIT_PER_MINUTE` foi atingido. SDKs que respeitam `Retry-After` esperam automaticamente o tempo indicado (segundos restantes na janela).

**`404` — "Modelo nao configurado neste gateway"**
O app está pedindo o id do provedor (`openai/gpt-oss-120b`) em vez da chave do array `MODELS` (`gpt-oss-120b`). Chame `GET /models` para ver os nomes válidos.

**`401` no gateway**
A chave enviada pelo app não está em `GATEWAY_KEYS`. O padrão é `Authorization: Bearer <chave>`; `x-api-key` também é aceito.

**Todos os provedores falharam com "modelo inexistente no provedor"**
Os ids em `models.php` saíram de catálogo. Consulte a documentação do provedor (ou o endpoint `GET /v1/models` dele com a sua chave) para ver o que está no ar agora.

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

- **Sem estado por padrão.** Um provedor que estourou o limite será tentado de novo na próxima chamada — a menos que `COOLDOWN_SECONDS` ou `BREAKER_FAILURES` seja ativado, que resolvem isso ao custo de um arquivo de estado.
- **Retomada de stream depende de outro provedor saudável.** Se o provedor cair no meio do stream e todos os demais também falharem, o cliente recebe só o que já foi emitido (encerrado com `[DONE]`). A retomada também não é perfeita: o provedor que continua pode repetir algumas palavras do ponto de corte ou mudar levemente o tom.
- **Sem orçamento por custo.** O log e as métricas registram tokens (entrada/saída) por tentativa desde a 0.3, mas sem tabela de preços — somar quantidade não é o mesmo que somar dólares. Para orçamento por provedor seria preciso cruzar os tokens do log com o preço vigente de cada modelo.
- **Sem suporte à API Responses (`/responses`) da OpenAI.** O router fala só `/chat/completions` (dialeto OpenAI) e `/messages` (dialeto Anthropic). Modelos só disponíveis pela API Responses (ex.: GPT 5.6 Luna no OpenCode Go) precisam ser cadastrados via um agregador que fale `/chat/completions` (ex.: OpenRouter).
- **Fallback entre modelos é opcional e manual.** Só acontece se você mapear em `MODEL_FALLBACKS`.
- **Cliente que desconecta no meio do stream** faz o gateway abortar a chamada ao provedor (para não pagar tokens à toa), mas os tokens já gerados até ali foram cobrados.
- **Métricas** rolam a janela inteira a cada `METRICS_WINDOW_SECONDS` — não há histórico além da janela atual. Para histórico, o `LOG_FILE` é TSV com uma tentativa por linha: `awk -F'\t' '$5==429' data/localrouter.log` já responde a maioria das perguntas.

---

## Docker

Para rodar em container sem instalar PHP na máquina:

```bash
docker compose up -d
curl http://localhost:8000/health
```

O `docker-compose.yml` sobe o gateway em `http://localhost:8000` com a pasta `data/` montada como volume — logs, estado, métricas e o `data/.env` persistem entre reinícios. As chaves podem ser injetadas de duas formas:

1. **`data/.env`** (montado pelo volume) — edite `data/.env` no host; o container lê na hora (reinicie o container para recarregar, pois o `config.php` lê o `.env` uma vez no boot).

2. **`environment:` no compose** — descomente o bloco no `docker-compose.yml` e preencha. Variável de ambiente real sempre vence o `.env`.

O `Dockerfile` é minimalista: `php:8.2-cli` + `ext-curl`, sem Composer, sem build, sem servidor web — o servidor embutido do PHP basta porque o gateway é uma API stateless pensada para ficar atrás de um reverse proxy (Caddy, Traefik, nginx) em produção. O `healthcheck` do compose bate em `/health`.

Para produção, coloque um reverse proxy na frente com HTTPS (Caddy com auto-TLS é o mais simples) e aponte para `localhost:8000` dentro da rede do compose.

---

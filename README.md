# LocalRouter v0.3

Gateway de IA em PHP puro com API no formato OpenAI e **rotação automática de provedores**. Quando um provedor devolve erro, estoura o limite ou fica sem crédito, o router passa para o próximo na mesma requisição — o app que chamou não percebe a troca.

O problema que ele resolve: o mesmo modelo está disponível em Groq, Cerebras, OpenRouter e no provedor oficial, cada um com um limite gratuito diferente. Sem router, o app quebra quando o limite do dia acaba. Com router, ele só migra.

Seu app fala com um endpoint só, compatível com qualquer SDK OpenAI. Por trás, o LocalRouter traduz para provedores Anthropic quando necessário — inclusive em streaming.

**Sem dependências.** PHP 8.0 e `ext-curl`. Sem Composer, sem banco, sem serviço extra. Sobe em XAMPP, em `php -S` ou numa hospedagem compartilhada de R$ 10.

---

## Quick start

```bash
# 1. Suba o servidor
cd localrouter
php -S localhost:8000

# 2. Em outro terminal, gere sua chave do gateway
php index.php genkey
#    -> copie o resultado (sk-lr-...)

# 3. Configure as chaves
cp data/.env.example data/.env
#    -> preencha LR_GATEWAY_KEY com a chave gerada acima
#    -> preencha OPENROUTER_KEY (ou outra) no data/.env

# 4. Confira e teste de verdade
php index.php check
php index.php test gpt-oss-120b
```

O `test` faz uma chamada real e mostra a fila inteira:

```
Testando 'gpt-oss-120b' — 2 provedor(es) na fila.

  1. nvidia       openai/gpt-oss-120b        FALHA   412ms  rate limit (quota exceeded)
  2. openrouter   openai/gpt-oss-120b:free   OK      893ms

  Atendido por 'openrouter' em 893ms.
```

Funcionando, aponte o app:

```python
from openai import OpenAI

client = OpenAI(base_url="http://localhost:8000", api_key="SUA_CHAVE_DO_GATEWAY")
resposta = client.chat.completions.create(
    model="gpt-oss-120b",
    messages=[{"role": "user", "content": "Olá!"}],
)
```

---

## Como funciona

Cada requisição passa por quatro etapas:

1. **Normalização** — o corpo recebido (formato OpenAI) vira uma forma interna neutra.
2. **Montagem** — essa forma é traduzida para o dialeto do provedor da vez.
3. **Chamada** — o provedor precisa devolver `200` **e** uma resposta aproveitável. Qualquer outra coisa registra o motivo e tenta o próximo. Um `200` com `{"error": ...}` ou com conteúdo em branco conta como falha: free tier responde assim quando a cota acaba.
4. **Resposta** — o resultado volta sempre no formato OpenAI, seja qual for o dialeto de quem atendeu.

---

## Configuração mínima

Tudo fica em `config.php`, em `define()`. Três blocos importam — `GATEWAY_KEYS` no próprio `config.php`, e os catálogos `PROVIDERS` e `MODELS` em arquivos separados na raiz (`providers.php` e `models.php`), incluídos via `require`:

```php
// 1. A chave que SEUS apps usam para falar com o router (em config.php)
define('GATEWAY_KEYS', [env('LR_GATEWAY_KEY')]);

// 2. Onde ficam as APIs e as chaves de cada serviço (em providers.php)
define('PROVIDERS', require __DIR__ . '/providers.php');
//   'groq'       => ['url' => 'https://api.groq.com/openai/v1', 'key' => env('GROQ_API_KEY')],
//   'openrouter' => ['url' => 'https://openrouter.ai/api/v1',   'key' => env('OPENROUTER_KEY')],
//   'anthropic'  => ['url' => 'https://api.anthropic.com/v1', 'type' => 'anthropic', 'key' => env('ANTHROPIC_API_KEY')],

// 3. O nome que seu app pede, e a fila de quem pode atender (em models.php)
define('MODELS', require __DIR__ . '/models.php');
//   'meu-modelo' => [
//       ['provider' => 'groq',       'model' => 'llama-3.3-70b-versatile'],
//       ['provider' => 'openrouter', 'model' => 'meta-llama/llama-3.3-70b-instruct:free'],
//   ],
```

A ordem da lista é a ordem de tentativa. Coloque os gratuitos primeiro.

As chaves vêm de `data/.env` (que fica fora do Git) ou de variáveis de ambiente reais do SO/shell/Docker — lidas via `env('X')`, que prefere o ambiente real e cai para o `.env` só quando a variável não existe ou está vazia. O `providers.php` traz só os provedores usados pelos modelos de fábrica; o catálogo completo — Groq, Cerebras, Google, Mistral, Hugging Face, Cloudflare, xAI, Fireworks e outros — está em **`providers-extra.php.example`**: abra, copie a linha que quiser e cole em `providers.php`.

Modelos de embedding também ficam em `models.php`, marcados com `'type' => 'embedding'` (ausente = `'chat'`) — não há mais uma constante separada. O campo `type` diz em qual endpoint o modelo atende (`/chat/completions` ou `/embeddings`), distinto do dialeto do provedor (`openai`/`anthropic`), que vive em `PROVIDERS`.

---

## Comandos

```bash
php index.php genkey          # gera uma chave para LR_GATEWAY_KEY (data/.env)
php index.php check           # valida a configuração e aponta erros
php index.php providers       # lista os provedores (sem revelar chaves)
php index.php test <modelo>   # chamada real, mostra quem atendeu
php index.php sync            # confere os ids de modelo contra cada provedor
```

O `sync` resolve o incômodo mais comum desse tipo de gateway: **id de modelo envelhece**. Slugs de modelo gratuito mudam quase toda semana. Ele pergunta a cada provedor o catálogo atual e lista os ids do seu `config.php` que sumiram.

---

## Endpoints

| Método | Rota | O que faz |
|---|---|---|
| `POST` | `/chat/completions` | Formato OpenAI, com ou sem `stream: true`. |
| `POST` | `/embeddings` | Vetores, mesma rotação. Modelos de `type: embedding` em `MODELS`. |
| `GET` | `/models` | Modelos de chat e de embedding configurados. |
| `GET` | `/health` | Monitoramento. Sem autenticação. |
| `GET` | `/health/providers` | Latência, taxa de erro e consumo diário por provedor. |
| `GET` | `/metrics` | Contadores em `json` ou `prometheus`. Opt-in. |

Tudo exceto `/health` exige `Authorization: Bearer <chave do gateway>`.

---

## O que ele faz por baixo

- **Rotação com cooldown** — provedor que falhou sai da fila por um tempo. A janela depende do tipo de erro: limite passageiro volta em um minuto, chave revogada espera quinze.
- **Retomada de stream** — se a conexão cair no meio da resposta, o router pede a continuação ao próximo provedor e emenda sem repetir o trecho.
- **Teto diário** — declare `'rpd' => 1000` e o provedor sai da fila ao bater o limite, antes de gastar a tentativa no 429.
- **Tradução de dialeto** — OpenAI ↔ Anthropic, incluindo ferramentas, imagens e blocos de raciocínio.
- **Circuit breaker, rate limit por chave, métricas, allowlist de IP** — tudo opcional, ligado por constante.

---

## Documentação

A referência completa está em **[DOCS.md](DOCS.md)**: instalação em hospedagem, todas as constantes, tradução entre dialetos, streaming, segurança, troubleshooting e Docker.

O histórico de versões está em **[CHANGELOG.md](CHANGELOG.md)**.

---

## Licença

MIT. Use, altere e redistribua à vontade.

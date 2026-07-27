<?php
declare(strict_types=1);

/**
 * LocalRouter — configuracao. Tudo vive neste arquivo, em define().
 *
 * Cada valor aceita texto puro OU variavel de ambiente (getenv('X') ?: '').
 * Valide com:  php index.php check
 */

defined('LOCALROUTER') or exit; // sem o bootstrap este arquivo nao roda sozinho

// =====================================================================
// ACESSO AO GATEWAY
// =====================================================================

/** Chaves que SEUS apps enviam em "Authorization: Bearer <chave>". Gere com: php index.php genkey */
define('GATEWAY_KEYS', [
    'sk-lr-troque-esta-chave',
]);

// =====================================================================
// PROVEDORES 
// Catalogo de URLs e dialeto. Cada provedor declara a URL
// base e o tipo de API (openai ou anthropic). O tipo e fixo por provedor:
// OpenRouter sempre fala openai, a API nativa da Anthropic sempre fala
// anthropic. A chave de API fica em cada entrada de MODELS, assim cada
// modelo pode usar uma conta/chave diferente do mesmo servico sem
// duplicar a URL. Nome livre; use sufixos para varias contas.
// =====================================================================

define('PROVIDERS', [
    'groq'         => ['url' => 'https://api.groq.com/openai/v1'],
    'cerebras'     => ['url' => 'https://api.cerebras.ai/v1'],
    'openrouter'   => ['url' => 'https://openrouter.ai/api/v1'],
    'github'       => ['url' => 'https://models.github.ai/inference'],
    'mistral'      => ['url' => 'https://api.mistral.ai/v1'],
    'nvidia'       => ['url' => 'https://integrate.api.nvidia.com/v1'],
    'huggingface'  => ['url' => 'https://router.huggingface.co/v1'],
    'siliconflow'  => ['url' => 'https://api.siliconflow.cn/v1'],
    'ollama'       => ['url' => 'https://ollama.com/v1'],
    'vercel'       => ['url' => 'https://ai-gateway.vercel.sh/v1'],
    'google'       => ['url' => 'https://generativelanguage.googleapis.com/v1beta/openai'],
    'opencode'     => ['url' => 'https://opencode.ai/zen/v1'],
    'opencode_go'  => ['url' => 'https://opencode.ai/zen/go/v1/chat/completions'],

    // Cloudflare exige o id da conta no caminho — defina CF_ACCOUNT_ID.
    'cloudflare'   => ['url' => 'https://api.cloudflare.com/client/v4/accounts/'
        . (getenv('CF_ACCOUNT_ID') ?: 'ACCOUNT_ID') . '/ai/v1'],

    // Ollama rodando na sua propria maquina: sem chave, sem limite.
    'local'        => ['url' => 'http://127.0.0.1:11434/v1'],

    // --- Dialeto Anthropic (traduzido por dentro) ---------------------
    'anthropic'    => ['url' => 'https://api.anthropic.com/v1', 'type' => 'anthropic'],

    // --- Pagos ou creditos de trial, prontos para ligar ---------------
    'openai'       => ['url' => 'https://api.openai.com/v1'],
    'xai'          => ['url' => 'https://api.x.ai/v1'],
    'fireworks'    => ['url' => 'https://api.fireworks.ai/inference/v1'],
    'nebius'       => ['url' => 'https://api.studio.nebius.com/v1'],
    'novita'       => ['url' => 'https://api.novita.ai/v3/openai'],
    'zai'          => ['url' => 'https://open.bigmodel.cn/api/paas/v4'],
]);

// =====================================================================
// MODELOS
// A chave e o nome que SEU app pede; cada entrada aponta para
// um provedor de PROVIDERS e traz a CHAVE DE API do provedor.
// Campos em cada entrada de provedor:
//   provider -> nome em PROVIDERS (obrigatorio)
//   model    -> id do modelo NAQUELE provedor (muda com frequencia!)
//   key      -> chave de API do provedor (obrigatorio; '' para local)
//   weight   -> opcional (padrao 1): com STRATEGY 'random', peso 6 abre a
//               fila ~6x mais que peso 1. Nunca exclui da rotacao.
//   params   -> opcional: parametros so daquele provedor.
// O tipo de API (openai/anthropic) fica em PROVIDERS, nao aqui.
// Forma com parametros proprios do modelo:
//   'meu-modelo' => ['params' => [...], 'providers' => [ [...], ... ]]
// Precedencia: app > params do provedor > params do modelo > DEFAULT_PARAMS.
// =====================================================================

define('MODELS', [

    'gpt-oss-120b' => [
        'params' => [
            'temperature' => 0.7,
            'top_p'       => 0.95,
        ],
        'providers' => [
            [
                'provider' => 'groq',
                'model'    => 'openai/gpt-oss-120b',
                'key'      => getenv('GROQ_API_KEY') ?: '',
                'weight'   => 6,
            ],
            [
                'provider' => 'xai',
                'model'    => 'openai/gpt-oss-120b',
                'key'      => getenv('XAI_API_KEY') ?: '',
                'weight'   => 3,
            ],
            [
                'provider' => 'cerebras',
                'model'    => 'gpt-oss-120b',
                'key'      => getenv('CEREBRAS_API_KEY') ?: '',
                'weight'   => 3,
            ],
            [
                'provider' => 'openrouter',
                'model'    => 'openai/gpt-oss-120b:free',
                'key'      => getenv('OPENROUTER_KEY') ?: '',
            ],
        ],
    ],

    'gpt-oss-20b' => [
        [
            'provider' => 'groq',
            'model'    => 'openai/gpt-oss-20b',
            'key'      => getenv('GROQ_API_KEY') ?: '',
            'weight'   => 6,
        ],
        [
            'provider' => 'openrouter',
            'model'    => 'openai/gpt-oss-20b:free',
            'key'      => getenv('OPENROUTER_KEY') ?: '',
        ],
    ],

    'llama-3.3-70b' => [
        [
            'provider' => 'openrouter',
            'model'    => 'meta-llama/llama-3.3-70b-instruct:free',
            'key'      => getenv('OPENROUTER_KEY') ?: '',
        ],
    ],

    'gemini-flash' => [
        [
            'provider' => 'google',
            'model'    => 'gemini-2.5-flash',
            'key'      => getenv('GEMINI_API_KEY') ?: '',
            'weight'   => 3,
        ],
        [
            'provider' => 'google',
            'model'    => 'gemini-2.5-flash-lite',
            'key'      => getenv('GEMINI_API_KEY') ?: '',
        ],
    ],

    'qwen3-32b' => [
        [
            'provider' => 'cerebras',
            'model'    => 'qwen-3-32b',
            'key'      => getenv('CEREBRAS_API_KEY') ?: '',
        ],
        [
            'provider' => 'siliconflow',
            'model'    => 'Qwen/Qwen3-32B',
            'key'      => getenv('SILICONFLOW_KEY') ?: '',
            'params'   => ['temperature' => 0.4],
        ],
    ],

    // Dialetos misturados no mesmo modelo logico (Anthropic + OpenAI).
    'claude-sonnet' => [
        [
            'provider' => 'anthropic',
            'model'    => 'claude-sonnet-4-5',
            'key'      => getenv('ANTHROPIC_API_KEY') ?: '',
        ],
        [
            'provider' => 'openrouter',
            'model'    => 'anthropic/claude-sonnet-4.5',
            'key'      => getenv('OPENROUTER_KEY') ?: '',
        ],
    ],

]);

// =====================================================================
// OPCIONAIS
// =====================================================================

/** 'random' = sorteia ponderado pelo weight; 'priority' = segue a ordem do array. */
define('STRATEGY', 'random');

/** Teto de provedores tentados por requisicao. 0 remove o limite. */
define('MAX_ATTEMPTS', 4);

/** Parametros repassados intactos a provedores de dialeto openai (json mode, seed, reasoning...). */
define('PASSTHROUGH_OPENAI', [
    'seed', 'response_format', 'parallel_tool_calls', 'presence_penalty',
    'frequency_penalty', 'logit_bias', 'logprobs', 'top_logprobs',
    'reasoning_effort', 'service_tier', 'user', 'n',
]);

/** Devolve ao cliente qual provedor atendeu (X-Router-*). */
define('EXPOSE_PROVIDER_HEADER', true);

/** Fallback ENTRE modelos: se todos os provedores de um modelo falharem, continua no modelo indicado. Vazio desativa. */
define('MODEL_FALLBACKS', []);

/** Provedor que falhou (rate limit, credito, 5xx) fica de castigo por N segundos. 0 desativa. */
define('COOLDOWN_SECONDS', 0);

/** Teto de requisicoes/minuto do PROPRIO gateway (janela global). 0 desativa. */
define('RATE_LIMIT_PER_MINUTE', 0);

/** Arquivo de estado usado por COOLDOWN_SECONDS e RATE_LIMIT_PER_MINUTE. Em servidor sem .htaccess, aponte para fora do docroot. */
define('STATE_FILE', __DIR__ . '/data/state.json');

/** Allowlist de IPs. Vazio libera todos. Aceita IP exato ou prefixo ('192.168.', 'fd00:'). Usa REMOTE_ADDR. /health ignora. */
define('ALLOWED_IPS', []);

/** Recusa HTTP puro (chave trafegaria em texto claro). Localhost e isento. */
define('REQUIRE_HTTPS', true);

/** Proxies que terminam o TLS na frente do PHP. So destes IPs o gateway confia em X-Forwarded-Proto. Vazio = nenhum. */
define('TRUSTED_PROXIES', []);

// =====================================================================
// LOG E LIMITES
// =====================================================================

/** Arquivo de log das tentativas. Vazio desativa. Nunca grava chaves nem conteudo. Em servidor sem .htaccess, aponte para fora do docroot. */
define('LOG_FILE', __DIR__ . '/router.log');

/** Tamanho maximo do log antes de virar .1 e recomecar. */
define('LOG_MAX_BYTES', 5242880);

/** Limites de rede e de entrada. */
define('CONNECT_TIMEOUT', 10);      // segundos para abrir a conexao
define('REQUEST_TIMEOUT', 180);     // segundos por tentativa (nao-streaming)
define('STREAM_STALL_TIME', 60);    // segundos sem receber bytes -> aborta e rotaciona
define('MAX_BODY_BYTES', 8388608);  // 8 MB de corpo aceito do cliente

/** Valores usados quando nem o app nem o modelo definiram o parametro. null = nao envia (provedor aplica o padrao dele). */
define('DEFAULT_PARAMS', [
    'temperature' => null,
    'top_p'      => null,
    'top_k'      => null,
    'max_tokens' => null,
    'stop'       => [],
]);

/** Ultimo recurso para max_tokens (Anthropic exige o campo). */
define('DEFAULT_MAX_TOKENS', 4096);

/** Origem permitida para chamadas de navegador. Vazio = sem CORS. */
define('ALLOW_ORIGIN', '');

/** Versao da API Anthropic enviada aos provedores desse dialeto. */
define('ANTHROPIC_VERSION', '2023-06-01');

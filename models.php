<?php
/**
 * LocalRouter — catálogo de modelos (carregado pelo config.php)
 *
 * Este arquivo é incluído por config.php via:
 *   define('MODELS', require __DIR__ . '/models.php');
 * Modelos de embedding também ficam aqui, marcados com 'type' => 'embedding'
 * (ausente = 'chat').
 *
 * A função env() (definida em config.php) já está disponível no momento
 * do include, pois o require acontece depois da declaração dela.
 *
 * A ordem da lista é a ordem de tentativa. Campos de cada entrada:
 *   provider -> nome em PROVIDERS (obrigatório)
 *   model    -> id do modelo naquele provedor. Muda com frequência;
 *               confira com: php index.php sync
 *   key      -> opcional: outra conta do mesmo serviço, só para este modelo
 *   weight   -> opcional: com STRATEGY 'random', peso 6 abre a fila ~6x mais
 *               que peso 1. Nunca exclui ninguém da rotação
 *   params   -> opcional: parâmetros só daquele provedor
 *
 * Para parâmetros do modelo inteiro, use a forma com 'providers':
 *   'meu-modelo' => [
 *       'type'          => 'chat',        // opcional; padrão 'chat'
 *       'params'        => ['temperature' => 0.7],
 *       'system_prompt' => 'Responda de forma concisa.',
 *       'providers'     => [ ['provider' => ..., 'model' => ...] ],
 *   ]
 *
 * 'type' distingue o tipo de modelo (chat, embedding, e futuros) do
 * dialeto do provedor (openai/anthropic), que vive em PROVIDERS. Ausente
 * = 'chat'. 'embedding' atende em POST /embeddings.
 *
 * Precedência dos parâmetros: modelo > provedor > app. A configuração
 * sobrescreve o que o app enviou — o admin sabe o que o provedor exige.
 * Parâmetro não definido (null) simplesmente não é enviado; o provedor
 * aplica o padrão dele.
 * Nome de parâmetro não reconhecido é repassado aos provedores openai como
 * está, o que permite campos próprios de serviços como vLLM e LM Studio.
 * (A allowlist PASSTHROUGH_OPENAI filtra só o que vem do CLIENTE.)
 *
 * system_prompt entra ANTES das mensagens system do app, sem substituí-las.
 */

return [

    'auto-free' => [
        ['provider' => 'openrouter',    'model' => 'nvidia/nemotron-3-ultra-550b-a55b:free'],
        ['provider' => 'openrouter',    'model' => 'google/gemma-4-31b-it:free'],
    ],

    'gpt-oss-120b' => [
        'params' => [
            'temperature' => 0.7,
            'top_p' => 0.95,
        ],
        'providers' => [
            ['provider' => 'nvidia',     'model' => 'openai/gpt-oss-120b'],
            ['provider' => 'openrouter', 'model' => 'openai/gpt-oss-120b:free'],
        ],
    ],

    'grok-4.5' => [
        ['provider' => 'opencode_go',   'model' => 'grok-4.5'],
        ['provider' => 'openrouter',    'model' => 'x-ai/grok-4.5'],
    ],

    'gpt-5.6-luna' => [
        ['provider' => 'openrouter',    'model' => 'openai/gpt-5.6-luna'],
    ],

    'glm-5.2' => [
        ['provider' => 'nvidia',        'model' => 'z-ai/glm-5.2'],
        ['provider' => 'opencode_go',   'model' => 'glm-5.2'],
        ['provider' => 'openrouter',    'model' => 'z-ai/glm-5.2'],
    ],

    'kimi-k3' => [
        'params' => ['temperature' => 1],
        'providers' => [
            ['provider' => 'opencode_go', 'model' => 'kimi-k3'],
            ['provider' => 'openrouter',  'model' => 'moonshotai/kimi-k3'],
        ],
    ],

    'kimi-k2.7-code' => [
        'params' => ['temperature' => 1],
        'providers' => [
            ['provider' => 'opencode_go', 'model' => 'kimi-k2.7-code'],
            ['provider' => 'openrouter',  'model' => 'moonshotai/kimi-k2.7-code'],
        ],
    ],

    'kimi-k2.6' => [
        'params' => ['temperature' => 1],
        'providers' => [
            ['provider' => 'nvidia',      'model' => 'moonshotai/kimi-k2.6'],
            ['provider' => 'opencode_go', 'model' => 'kimi-k2.6'],
            ['provider' => 'openrouter',  'model' => 'moonshotai/kimi-k2.6'],
        ],
    ],

    'deepseek-v4-pro' => [
        ['provider' => 'nvidia',        'model' => 'deepseek-ai/deepseek-v4-pro'],
        ['provider' => 'opencode_go',   'model' => 'deepseek-v4-pro'],
        ['provider' => 'openrouter',    'model' => 'deepseek/deepseek-v4-pro'],
    ],

    'deepseek-v4-flash' => [
        ['provider' => 'nvidia',        'model' => 'deepseek-ai/deepseek-v4-flash'],
        ['provider' => 'opencode',      'model' => 'deepseek-v4-flash-free'],
        ['provider' => 'opencode_go',   'model' => 'deepseek-v4-flash'],
        ['provider' => 'openrouter',    'model' => 'deepseek/deepseek-v4-flash'],
    ],

    'qwen3.7-max' => [
        ['provider' => 'openrouter',    'model' => 'qwen/qwen3.7-max'],
    ],

    'gemma-4-26b-a4b' => [
        ['provider' => 'openrouter',    'model' => 'google/gemma-4-26b-a4b-it:free'],
    ],

    'gemma-4-31b' => [
        ['provider' => 'openrouter',    'model' => 'google/gemma-4-31b-it:free'],
    ],

    'claude-opus-5' => [
        ['provider' => 'opencode_anthropic', 'model' => 'claude-opus-5'],
        ['provider' => 'openrouter',         'model' => 'anthropic/claude-opus-5'],
    ],

    'claude-sonnet-5' => [
        ['provider' => 'opencode_anthropic', 'model' => 'claude-sonnet-5'],
        ['provider' => 'openrouter',         'model' => 'anthropic/claude-sonnet-5'],
    ],

    // --- Embeddings -------------------------------------------------------
    // 'type' distingue o tipo de modelo do dialeto do provedor (que vive em
    // PROVIDERS). Ausente = 'chat' (padrão). 'embedding' atende em
    // POST /embeddings; futuros types podem ser adicionados sem mudar schema.
    'embed-small' => [
        'type' => 'embedding',
        'providers' => [
            ['provider' => 'openrouter', 'model' => 'openai/text-embedding-3-small'],
        ],
    ],

];
